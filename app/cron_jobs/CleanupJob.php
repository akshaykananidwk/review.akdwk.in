<?php
declare(strict_types=1);

/**
 * Daily housekeeping: purges rows nothing will ever read again.
 * Every DELETE is defensive — a missing table (feature not installed
 * yet) just skips that cleanup instead of failing the job.
 */
final class CleanupJob
{
    private const KEEP_CRON_RUNS_DAYS = 30;
    private const KEEP_QUEUE_ROWS_DAYS = 30;
    private const KEEP_RATE_LIMIT_DAYS = 7;

    public function run(PDO $pdo, CronLogger $log): string
    {
        $parts = [];

        $parts[] = 'password_resets=' . $this->safeDelete($pdo, $log, "
            DELETE FROM password_resets
            WHERE (expires_at IS NOT NULL AND expires_at < NOW())
               OR created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)
        ");

        $parts[] = 'ip_rate_limits=' . $this->safeDelete($pdo, $log, "
            DELETE FROM ip_rate_limits
            WHERE created_at < DATE_SUB(NOW(), INTERVAL " . self::KEEP_RATE_LIMIT_DAYS . " DAY)
        ");

        // New in v1.4.0: unique bucket keys accumulate one row each.
        $parts[] = 'rate_limit_buckets=' . $this->safeDelete($pdo, $log, "
            DELETE FROM rate_limit_buckets
            WHERE window_started_at < DATE_SUB(NOW(), INTERVAL 2 DAY)
        ");

        $parts[] = 'system_event_logs=' . $this->safeDelete($pdo, $log, "
            DELETE FROM system_event_logs
            WHERE severity IN ('debug','info','warning')
              AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");

        $parts[] = 'cron_job_runs=' . $this->safeDelete($pdo, $log, "
            DELETE FROM cron_job_runs
            WHERE started_at < DATE_SUB(NOW(), INTERVAL " . self::KEEP_CRON_RUNS_DAYS . " DAY)
        ");

        $parts[] = 'notification_queue=' . $this->safeDelete($pdo, $log, "
            DELETE FROM notification_queue
            WHERE status IN ('sent','failed','cancelled')
              AND updated_at < DATE_SUB(NOW(), INTERVAL " . self::KEEP_QUEUE_ROWS_DAYS . " DAY)
        ");

        // Leftover update staging files (crashed run) older than a day.
        $updatesDir = dirname(__DIR__, 2) . '/storage/updates';
        $removed = 0;
        if (is_dir($updatesDir)) {
            foreach (scandir($updatesDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === '.gitkeep') {
                    continue;
                }
                $path = $updatesDir . '/' . $entry;
                if (filemtime($path) !== false && filemtime($path) < time() - 86400) {
                    if (is_dir($path)) {
                        $this->removeDirectory($path);
                    } else {
                        @unlink($path);
                    }
                    $removed++;
                }
            }
        }
        $parts[] = 'staging_files=' . $removed;

        return 'purged: ' . implode(', ', $parts);
    }

    private function safeDelete(PDO $pdo, CronLogger $log, string $sql): string
    {
        try {
            return (string)$pdo->exec($sql);
        } catch (Throwable $e) {
            $log->line('skip (' . substr($e->getMessage(), 0, 120) . ')');
            return 'skipped';
        }
    }

    private function removeDirectory(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
