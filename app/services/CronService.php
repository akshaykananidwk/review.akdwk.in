<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/settings_helper.php';

/**
 * Collects per-run log lines for a cron job (stored in cron_job_runs
 * and appended to storage/logs/cron_master.log).
 */
final class CronLogger
{
    /** @var string[] */
    private array $lines = [];
    private string $jobKey;
    private string $logFile;

    public function __construct(string $jobKey, string $logFile)
    {
        $this->jobKey = $jobKey;
        $this->logFile = $logFile;
    }

    public function line(string $message): void
    {
        $stamped = '[' . date('Y-m-d H:i:s') . '] ' . $message;
        $this->lines[] = $stamped;
        @file_put_contents($this->logFile, '[' . $this->jobKey . '] ' . $stamped . "\n", FILE_APPEND | LOCK_EX);
        if (PHP_SAPI === 'cli') {
            echo '[' . $this->jobKey . '] ' . $stamped . "\n";
        }
    }

    public function text(): string
    {
        return implode("\n", $this->lines);
    }
}

/**
 * CronService — centralized scheduler.
 * -------------------------------------------------------------------
 * A single server cron runs `cron/master.php` every minute; tick()
 * executes every registered job that is due. Jobs live as classes in
 * app/cron_jobs/ and are listed in app/cron_jobs/registry.php — adding
 * a new scheduled task means adding a class + one registry line, never
 * a new server cron.
 *
 * Safety:
 *  - MySQL GET_LOCK guards the master tick and every individual job,
 *    so overlapping ticks or double executions are impossible even
 *    with multiple web servers pointing at the same database
 *    (locks auto-release if a process dies).
 *  - Every run is recorded in cron_job_runs with duration, per-line
 *    logs, summary and error; job rows keep aggregate status/health.
 */
final class CronService
{
    public const MASTER_LOCK = 'krs_cron_master';
    public const JOB_LOCK_PREFIX = 'krs_cron_job_';
    public const MASTER_STALL_SECONDS = 180; // health warning threshold
    public const OVERDUE_GRACE_SECONDS = 300;

    private PDO $pdo;
    private string $rootDir;
    private string $logFile;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? getPDO();
        $this->rootDir = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
        $this->logFile = $this->rootDir . '/storage/logs/cron_master.log';
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    // ------------------------------------------------------------------
    // Registry
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{class:string,name:string,description:string,
     *                             schedule_type:string,schedule_value:string,enabled_by_default:bool}>
     */
    public function registry(): array
    {
        return require __DIR__ . '/../cron_jobs/registry.php';
    }

    /**
     * Insert newly registered jobs and refresh name/description of the
     * existing ones. Admin-controlled fields (is_enabled, schedule) are
     * only seeded on first insert — the panel owns them afterwards.
     */
    public function syncRegistry(): void
    {
        $registry = $this->registry();
        $existing = $this->pdo->query('SELECT job_key FROM cron_jobs')->fetchAll(PDO::FETCH_COLUMN);
        $existingSet = array_flip(array_map('strval', $existing));

        $insert = $this->pdo->prepare("
            INSERT INTO cron_jobs
                (job_key, job_name, description, schedule_type, schedule_value, is_enabled, next_run_at, created_at)
            VALUES (:k, :n, :d, :st, :sv, :e, :next, NOW())
        ");
        $refresh = $this->pdo->prepare("
            UPDATE cron_jobs SET job_name = :n, description = :d WHERE job_key = :k
        ");
        $now = new DateTimeImmutable('now');
        foreach ($registry as $key => $def) {
            if (isset($existingSet[$key])) {
                $refresh->execute([':n' => $def['name'], ':d' => $def['description'], ':k' => $key]);
                continue;
            }
            // Interval jobs may start right away; daily/weekly jobs wait
            // for their proper slot (installing at noon must NOT fire the
            // 22:00 report immediately).
            $firstRun = $def['schedule_type'] === 'every_minutes'
                ? $now
                : $this->computeNextRun($def['schedule_type'], $def['schedule_value'], $now);
            $insert->execute([
                ':k' => $key,
                ':n' => $def['name'],
                ':d' => $def['description'],
                ':st' => $def['schedule_type'],
                ':sv' => $def['schedule_value'],
                ':e' => $def['enabled_by_default'] ? 1 : 0,
                ':next' => $firstRun->format('Y-m-d H:i:s'),
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Master tick
    // ------------------------------------------------------------------

    /**
     * Run everything that is due. Called every minute by cron/master.php
     * (or the HTTP fallback). Returns a per-job result map.
     *
     * @return array<string,string> job_key => outcome
     */
    public function tick(): array
    {
        if (!$this->acquireDbLock(self::MASTER_LOCK)) {
            return ['_master' => 'skipped: previous tick still running'];
        }

        $results = [];
        try {
            upsertSystemSetting($this->pdo, 'cron_master_last_tick', date('Y-m-d H:i:s'), 'string');
            $this->syncRegistry();

            $due = $this->pdo->query("
                SELECT job_key FROM cron_jobs
                WHERE is_enabled = 1
                  AND (next_run_at IS NULL OR next_run_at <= NOW())
                ORDER BY job_key
            ")->fetchAll(PDO::FETCH_COLUMN);

            foreach ($due as $key) {
                $key = (string)$key;
                try {
                    $run = $this->runJob($key, 'auto');
                    $results[$key] = $run['status'] . ($run['summary'] !== '' ? ': ' . $run['summary'] : '');
                } catch (Throwable $e) {
                    $results[$key] = 'failed: ' . $e->getMessage();
                }
            }
        } finally {
            $this->releaseDbLock(self::MASTER_LOCK);
        }
        return $results;
    }

    // ------------------------------------------------------------------
    // Job execution
    // ------------------------------------------------------------------

    /**
     * Execute one job now (used by the master tick, the panel's
     * "Run Now" button and "Retry"). Locking prevents double runs.
     *
     * @return array{status:string,summary:string,run_id:int}
     */
    public function runJob(string $jobKey, string $trigger = 'manual', ?int $adminId = null): array
    {
        $registry = $this->registry();
        if (!isset($registry[$jobKey])) {
            throw new RuntimeException('Unknown cron job: ' . $jobKey);
        }
        $job = $this->getJob($jobKey);

        if (!$this->acquireDbLock(self::JOB_LOCK_PREFIX . $jobKey)) {
            return ['status' => 'skipped', 'summary' => 'already running', 'run_id' => 0];
        }

        @set_time_limit(600);
        ignore_user_abort(true);

        $started = microtime(true);
        $this->pdo->prepare("
            INSERT INTO cron_job_runs (job_key, trigger_type, status, started_at, triggered_by_admin_id)
            VALUES (:k, :t, 'running', NOW(), :a)
        ")->execute([':k' => $jobKey, ':t' => $trigger, ':a' => $adminId]);
        $runId = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare("
            UPDATE cron_jobs SET last_status = 'running', last_run_at = NOW() WHERE job_key = :k
        ")->execute([':k' => $jobKey]);

        $logger = new CronLogger($jobKey, $this->logFile);
        $logger->line('Run #' . $runId . ' started (' . $trigger . ').');

        $status = 'success';
        $summary = '';
        $error = null;
        try {
            $instance = $this->instantiate($registry[$jobKey]['class']);
            $summary = (string)$instance->run($this->pdo, $logger);
            $logger->line('Done: ' . ($summary !== '' ? $summary : 'ok'));
        } catch (Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
            $logger->line('ERROR: ' . $error);
        }

        $durationMs = (int)round((microtime(true) - $started) * 1000);
        $this->pdo->prepare("
            UPDATE cron_job_runs
            SET status = :s, finished_at = NOW(), duration_ms = :d,
                summary = :sum, log_text = :log, error_message = :err
            WHERE id = :id
        ")->execute([
            ':s' => $status,
            ':d' => $durationMs,
            ':sum' => substr($summary, 0, 500),
            ':log' => $logger->text(),
            ':err' => $error !== null ? substr($error, 0, 5000) : null,
            ':id' => $runId,
        ]);

        $jobDef = $this->getJob($jobKey); // re-read: schedule may have been edited
        $nextRun = $this->computeNextRun(
            (string)$jobDef['schedule_type'],
            (string)$jobDef['schedule_value'],
            new DateTimeImmutable('now')
        );
        $this->pdo->prepare("
            UPDATE cron_jobs
            SET last_status = :s,
                last_duration_ms = :d,
                last_error = :err,
                last_success_at = IF(:s2 = 'success', NOW(), last_success_at),
                next_run_at = :next,
                run_count = run_count + 1,
                fail_count = fail_count + IF(:s3 = 'failed', 1, 0)
            WHERE job_key = :k
        ")->execute([
            ':s' => $status,
            ':d' => $durationMs,
            ':err' => $error !== null ? substr($error, 0, 5000) : null,
            ':s2' => $status,
            ':next' => $nextRun->format('Y-m-d H:i:s'),
            ':s3' => $status,
            ':k' => $jobKey,
        ]);

        $this->releaseDbLock(self::JOB_LOCK_PREFIX . $jobKey);
        return ['status' => $status, 'summary' => $summary, 'run_id' => $runId];
    }

    private function instantiate(string $class): object
    {
        $file = __DIR__ . '/../cron_jobs/' . $class . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Cron job class file missing: ' . $class);
        }
        require_once $file;
        if (!class_exists($class)) {
            throw new RuntimeException('Cron job class not defined: ' . $class);
        }
        return new $class();
    }

    // ------------------------------------------------------------------
    // Scheduling
    // ------------------------------------------------------------------

    /**
     * schedule_type/value:
     *  - every_minutes: "N"        -> now + N minutes
     *  - daily:         "HH:MM"    -> next occurrence of HH:MM
     *  - weekly:        "D HH:MM"  -> next occurrence, D = 0 (Sun) … 6 (Sat)
     */
    public function computeNextRun(string $type, string $value, DateTimeImmutable $from): DateTimeImmutable
    {
        switch ($type) {
            case 'every_minutes':
                $minutes = max(1, (int)$value);
                return $from->modify('+' . $minutes . ' minutes');

            case 'daily':
                [$h, $m] = $this->parseTime($value);
                $candidate = $from->setTime($h, $m, 0);
                return $candidate > $from ? $candidate : $candidate->modify('+1 day');

            case 'weekly':
                $parts = preg_split('/\s+/', trim($value)) ?: [];
                $day = max(0, min(6, (int)($parts[0] ?? 0)));
                [$h, $m] = $this->parseTime($parts[1] ?? '03:00');
                $candidate = $from->setTime($h, $m, 0);
                $currentDay = (int)$candidate->format('w');
                $diff = ($day - $currentDay + 7) % 7;
                $candidate = $candidate->modify('+' . $diff . ' days');
                return $candidate > $from ? $candidate : $candidate->modify('+7 days');

            default:
                return $from->modify('+60 minutes');
        }
    }

    /** @return array{0:int,1:int} */
    private function parseTime(string $value): array
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $m) === 1) {
            return [max(0, min(23, (int)$m[1])), max(0, min(59, (int)$m[2]))];
        }
        return [3, 0];
    }

    public static function describeSchedule(string $type, string $value): string
    {
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        switch ($type) {
            case 'every_minutes':
                $n = max(1, (int)$value);
                return $n === 1 ? 'Every minute' : 'Every ' . $n . ' minutes';
            case 'daily':
                return 'Daily at ' . trim($value);
            case 'weekly':
                $parts = preg_split('/\s+/', trim($value)) ?: [];
                $day = $days[max(0, min(6, (int)($parts[0] ?? 0)))];
                return 'Weekly on ' . $day . ' at ' . ($parts[1] ?? '03:00');
            default:
                return $type . ' ' . $value;
        }
    }

    // ------------------------------------------------------------------
    // Panel accessors
    // ------------------------------------------------------------------

    public function getJob(string $jobKey): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cron_jobs WHERE job_key = :k LIMIT 1');
        $stmt->execute([':k' => $jobKey]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Cron job is not registered yet: ' . $jobKey);
        }
        return $row;
    }

    public function listJobs(): array
    {
        return $this->pdo->query('SELECT * FROM cron_jobs ORDER BY job_name')->fetchAll();
    }

    public function setJobEnabled(string $jobKey, bool $enabled): void
    {
        $job = $this->getJob($jobKey);
        $this->pdo->prepare('UPDATE cron_jobs SET is_enabled = :e WHERE job_key = :k')
            ->execute([':e' => $enabled ? 1 : 0, ':k' => $jobKey]);

        // Re-enabling recomputes the slot from the schedule — a daily job
        // that sat disabled past its time must wait for the next proper
        // occurrence, not fire the moment it is switched back on.
        if ($enabled && ($job['next_run_at'] === null || (string)$job['next_run_at'] < date('Y-m-d H:i:s'))) {
            $now = new DateTimeImmutable('now');
            $next = (string)$job['schedule_type'] === 'every_minutes'
                ? $now
                : $this->computeNextRun((string)$job['schedule_type'], (string)$job['schedule_value'], $now);
            $this->pdo->prepare('UPDATE cron_jobs SET next_run_at = :n WHERE job_key = :k')
                ->execute([':n' => $next->format('Y-m-d H:i:s'), ':k' => $jobKey]);
        }
    }

    public function getRecentRuns(?string $jobKey = null, int $limit = 30): array
    {
        $limit = max(1, min(200, $limit));
        if ($jobKey !== null) {
            $stmt = $this->pdo->prepare("
                SELECT * FROM cron_job_runs WHERE job_key = :k ORDER BY id DESC LIMIT {$limit}
            ");
            $stmt->execute([':k' => $jobKey]);
            return $stmt->fetchAll();
        }
        return $this->pdo->query("SELECT * FROM cron_job_runs ORDER BY id DESC LIMIT {$limit}")->fetchAll();
    }

    /**
     * @return array{master_last_tick:?string,master_ok:bool,overdue_jobs:array,failed_jobs:array,queue_pending:int}
     */
    public function health(): array
    {
        $lastTick = getSystemSetting($this->pdo, 'cron_master_last_tick', '');
        $masterOk = false;
        if ($lastTick !== '') {
            $masterOk = (time() - (int)strtotime($lastTick)) <= self::MASTER_STALL_SECONDS;
        }

        $overdue = $this->pdo->query("
            SELECT job_key, job_name, next_run_at FROM cron_jobs
            WHERE is_enabled = 1
              AND next_run_at IS NOT NULL
              AND next_run_at < DATE_SUB(NOW(), INTERVAL " . self::OVERDUE_GRACE_SECONDS . " SECOND)
        ")->fetchAll();

        $failed = $this->pdo->query("
            SELECT job_key, job_name, last_error, last_run_at FROM cron_jobs
            WHERE last_status = 'failed'
        ")->fetchAll();

        $queuePending = 0;
        try {
            $queuePending = (int)$this->pdo->query("
                SELECT COUNT(*) FROM notification_queue WHERE status = 'pending'
            ")->fetchColumn();
        } catch (Throwable) {
            // table not installed yet
        }

        return [
            'master_last_tick' => $lastTick !== '' ? $lastTick : null,
            'master_ok' => $masterOk,
            'overdue_jobs' => $overdue,
            'failed_jobs' => $failed,
            'queue_pending' => $queuePending,
        ];
    }

    public function cronTablesExist(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM cron_jobs LIMIT 1');
            $this->pdo->query('SELECT 1 FROM cron_job_runs LIMIT 1');
            $this->pdo->query('SELECT 1 FROM notification_queue LIMIT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** One-time in-app installer (mirrors the System Update page pattern). */
    public function installCronTables(): void
    {
        $file = $this->rootDir . '/database/migrations/2026_08_05_centralized_cron_scheduler.sql';
        if (!is_file($file)) {
            throw new RuntimeException('Migration file missing: database/migrations/2026_08_05_centralized_cron_scheduler.sql');
        }
        foreach ($this->splitSql((string)file_get_contents($file)) as $statement) {
            $this->pdo->exec($statement);
        }
        try {
            $this->pdo->prepare("
                INSERT IGNORE INTO system_migrations (migration_file, applied_at)
                VALUES ('2026_08_05_centralized_cron_scheduler.sql', NOW())
            ")->execute();
        } catch (Throwable) {
            // updater tables not installed — migration tracking optional here
        }
        $this->syncRegistry();
    }

    /** @return string[] */
    private function splitSql(string $sql): array
    {
        $lines = array_filter(
            preg_split('/\r?\n/', $sql) ?: [],
            static fn(string $l): bool => !str_starts_with(ltrim($l), '--')
        );
        $statements = [];
        foreach (explode(';', implode("\n", $lines)) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk !== '') {
                $statements[] = $chunk;
            }
        }
        return $statements;
    }

    // ------------------------------------------------------------------
    // Locking (MySQL named locks — safe across processes and servers)
    // ------------------------------------------------------------------

    private function acquireDbLock(string $name): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:n, 0)');
        $stmt->execute([':n' => $name]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private function releaseDbLock(string $name): void
    {
        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:n)');
            $stmt->execute([':n' => $name]);
        } catch (Throwable) {
            // connection teardown releases it anyway
        }
    }
}
