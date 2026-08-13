<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/settings_helper.php';

/**
 * GitHubUpdateService
 * -------------------------------------------------------------------
 * Production-safe one-click updater that deploys the application
 * directly from a GitHub repository (no more ZIP uploads).
 *
 * Flow (each step is an idempotent AJAX call driven by the admin UI):
 *   check    -> compare local commit vs latest commit on the branch
 *   start    -> create a system_updates row + acquire the update lock
 *   backup   -> zip current code + dump the database
 *   download -> fetch the GitHub zipball for the target commit
 *   verify   -> extract to staging, zip-slip guard, required files,
 *               php -l syntax check of staged files (when exec allowed)
 *   deploy   -> copy staged files over the app, protected paths skipped
 *   migrate  -> run new database/migrations/*.sql (tracked in
 *               system_migrations; existing files are baselined first)
 *   finalize -> restore permissions, clear caches, record new version
 *
 * On any failure after deploy started, rollback() restores the file
 * backup and the database dump. Protected paths (.env, config.php,
 * public/uploads, storage) are never written by deploy or rollback.
 */
final class GitHubUpdateService
{
    private const API_BASE = 'https://api.github.com';
    private const USER_AGENT = 'KrishnaReviewSystem-Updater';
    private const HTTP_TIMEOUT = 60;
    private const DOWNLOAD_TIMEOUT = 300;
    private const KEEP_BACKUPS_DEFAULT = 10;
    private const LOCK_STALE_SECONDS = 1800;

    /** Relative paths (prefix match, "/" suffix = directory) never overwritten or deleted. */
    private const PROTECTED_PATHS = [
        '.env',
        'app/config/config.php',
        'public/uploads/',
        'storage/',
        '.git/',
        'public/.well-known/',
    ];

    /** Files that must exist in a release for it to be considered valid. */
    private const REQUIRED_FILES = [
        'public/index.php',
        'public/login.php',
        'app/config/database.php',
        'app/config/bootstrap_env.php',
    ];

    public const STEPS = ['backup', 'download', 'verify', 'deploy', 'migrate', 'finalize'];

    public const STEP_PROGRESS = [
        'backup' => 20,
        'download' => 40,
        'verify' => 55,
        'deploy' => 75,
        'migrate' => 90,
        'finalize' => 100,
    ];

    private PDO $pdo;
    private string $rootDir;
    private string $backupsDir;
    private string $updatesDir;
    private string $logFile;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? getPDO();
        $this->rootDir = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
        $this->backupsDir = $this->rootDir . '/storage/backups';
        $this->updatesDir = $this->rootDir . '/storage/updates';
        $this->logFile = $this->rootDir . '/storage/logs/updater.log';
        foreach ([$this->backupsDir, $this->updatesDir, dirname($this->logFile)] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

    public function getConfig(): array
    {
        return [
            'repo' => trim(getSystemSetting($this->pdo, 'github_repo', '')),
            'branch' => trim(getSystemSetting($this->pdo, 'github_branch', 'main')) ?: 'main',
            'token' => trim(getSystemSetting($this->pdo, 'github_token', '')),
        ];
    }

    public function isConfigured(): bool
    {
        $cfg = $this->getConfig();
        return $cfg['repo'] !== '' && preg_match('#^[\w.-]+/[\w.-]+$#', $cfg['repo']) === 1;
    }

    public function saveConfig(string $repo, string $branch, string $token, int $adminId): void
    {
        $repo = trim(str_replace(['https://github.com/', 'http://github.com/'], '', $repo), " /\t");
        if ($repo !== '' && preg_match('#^[\w.-]+/[\w.-]+$#', $repo) !== 1) {
            throw new InvalidArgumentException('Repository must be in "owner/name" format.');
        }
        $branch = trim($branch) !== '' ? trim($branch) : 'main';
        if (preg_match('#^[\w./-]{1,120}$#', $branch) !== 1) {
            throw new InvalidArgumentException('Invalid branch name.');
        }
        upsertSystemSetting($this->pdo, 'github_repo', $repo, 'string', $adminId);
        upsertSystemSetting($this->pdo, 'github_branch', $branch, 'string', $adminId);
        if ($token !== '') {
            upsertSystemSetting($this->pdo, 'github_token', trim($token), 'string', $adminId);
        }
    }

    public function getCurrentVersion(): string
    {
        $file = $this->rootDir . '/VERSION';
        if (is_readable($file)) {
            $v = trim((string)file_get_contents($file));
            if ($v !== '') {
                return $v;
            }
        }
        return '0.0.0';
    }

    public function getCurrentCommit(): string
    {
        return trim(getSystemSetting($this->pdo, 'app_current_commit', ''));
    }

    // ------------------------------------------------------------------
    // Check for update
    // ------------------------------------------------------------------

    /**
     * @return array{
     *   update_available:bool, current_version:string, current_commit:string,
     *   latest_version:string, latest_commit:string, commit_message:string,
     *   commit_author:string, commit_date:string, changed_files:array,
     *   commits_behind:int, release_notes:string, checked_at:string
     * }
     */
    public function checkForUpdate(): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('GitHub repository is not configured yet. Save the repository settings first.');
        }
        $cfg = $this->getConfig();
        $repo = $cfg['repo'];
        $branch = $cfg['branch'];

        $latest = $this->apiGet("/repos/{$repo}/commits/" . rawurlencode($branch));
        if (!is_array($latest) || empty($latest['sha'])) {
            throw new RuntimeException('Could not read the latest commit from GitHub. Check repository, branch and token.');
        }

        $latestSha = (string)$latest['sha'];
        $commit = $latest['commit'] ?? [];
        $currentCommit = $this->getCurrentCommit();
        $currentVersion = $this->getCurrentVersion();

        $updateAvailable = ($currentCommit === '' || !hash_equals($latestSha, $currentCommit));

        $changedFiles = [];
        $commitsBehind = 0;
        if ($updateAvailable && $currentCommit !== '') {
            $cmp = $this->apiGet("/repos/{$repo}/compare/{$currentCommit}...{$latestSha}", true);
            if (is_array($cmp)) {
                $commitsBehind = (int)($cmp['ahead_by'] ?? 0);
                foreach (($cmp['files'] ?? []) as $f) {
                    $changedFiles[] = [
                        'filename' => (string)($f['filename'] ?? ''),
                        'status' => (string)($f['status'] ?? ''),
                        'additions' => (int)($f['additions'] ?? 0),
                        'deletions' => (int)($f['deletions'] ?? 0),
                    ];
                }
            }
        }

        $latestVersion = $this->fetchRemoteVersion($repo, $latestSha) ?? $currentVersion;
        $releaseNotes = '';
        $release = $this->apiGet("/repos/{$repo}/releases/latest", true);
        if (is_array($release) && !empty($release['body'])) {
            $releaseNotes = (string)$release['body'];
        }

        $result = [
            'update_available' => $updateAvailable,
            'current_version' => $currentVersion,
            'current_commit' => $currentCommit,
            'latest_version' => $latestVersion,
            'latest_commit' => $latestSha,
            'commit_message' => (string)($commit['message'] ?? ''),
            'commit_author' => (string)($commit['author']['name'] ?? ($latest['author']['login'] ?? '')),
            'commit_date' => (string)($commit['author']['date'] ?? ''),
            'changed_files' => $changedFiles,
            'commits_behind' => $commitsBehind,
            'release_notes' => $releaseNotes,
            'checked_at' => date('Y-m-d H:i:s'),
        ];

        upsertSystemSetting(
            $this->pdo,
            'updater_last_check',
            (string)json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'json'
        );
        return $result;
    }

    public function getLastCheck(): ?array
    {
        $raw = getSystemSetting($this->pdo, 'updater_last_check', '');
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    // ------------------------------------------------------------------
    // Update lifecycle
    // ------------------------------------------------------------------

    public function startUpdate(int $adminId): array
    {
        $this->acquireLock();
        try {
            $check = $this->checkForUpdate();
            if (!$check['update_available']) {
                $this->releaseLock();
                throw new RuntimeException('Already up to date — nothing to install.');
            }

            $this->baselineMigrations();

            $stmt = $this->pdo->prepare("
                INSERT INTO system_updates
                    (update_type, from_version, to_version, from_commit, to_commit,
                     commit_message, commit_author, commit_date, changed_files,
                     status, next_step, progress, initiated_by_admin_id, started_at, created_at)
                VALUES
                    ('update', :fv, :tv, :fc, :tc, :cm, :ca, :cd, :cf,
                     'running', 'backup', 5, :admin_id, NOW(), NOW())
            ");
            $stmt->execute([
                ':fv' => substr($check['current_version'], 0, 32),
                ':tv' => substr($check['latest_version'], 0, 32),
                ':fc' => substr($check['current_commit'], 0, 64),
                ':tc' => substr($check['latest_commit'], 0, 64),
                ':cm' => $check['commit_message'],
                ':ca' => substr($check['commit_author'], 0, 190),
                ':cd' => $check['commit_date'] !== '' ? date('Y-m-d H:i:s', strtotime($check['commit_date'])) : null,
                ':cf' => json_encode($check['changed_files'], JSON_UNESCAPED_SLASHES),
                ':admin_id' => $adminId,
            ]);
            $updateId = (int)$this->pdo->lastInsertId();
            $this->logUpdate($updateId, sprintf(
                'Update started by admin #%d: %s (%s) -> %s (%s)',
                $adminId,
                $check['current_version'],
                $check['current_commit'] !== '' ? substr($check['current_commit'], 0, 7) : 'unknown',
                $check['latest_version'],
                substr($check['latest_commit'], 0, 7)
            ));
            return $this->getUpdate($updateId);
        } catch (Throwable $e) {
            $this->releaseLock();
            throw $e;
        }
    }

    /**
     * Execute one named step of a running update. The UI calls this
     * repeatedly; the server enforces the step order via `next_step`.
     */
    public function runStep(int $updateId, string $step, int $adminId): array
    {
        $update = $this->getUpdate($updateId);
        if ($update['status'] !== 'running') {
            throw new RuntimeException('This update is not running (status: ' . $update['status'] . ').');
        }
        if ($update['next_step'] !== $step) {
            throw new RuntimeException('Out-of-order step "' . $step . '" (expected "' . $update['next_step'] . '").');
        }

        @set_time_limit(600);
        ignore_user_abort(true);

        try {
            switch ($step) {
                case 'backup':
                    $this->stepBackup($update, $adminId);
                    break;
                case 'download':
                    $this->stepDownload($update);
                    break;
                case 'verify':
                    $this->stepVerify($update);
                    break;
                case 'deploy':
                    $this->stepDeploy($update);
                    break;
                case 'migrate':
                    $this->stepMigrate($update);
                    break;
                case 'finalize':
                    $this->stepFinalize($update, $adminId);
                    break;
                default:
                    throw new RuntimeException('Unknown step: ' . $step);
            }
        } catch (Throwable $e) {
            $this->handleStepFailure($updateId, $step, $e);
            throw $e;
        }

        return $this->getUpdate($updateId);
    }

    private function advance(int $updateId, string $completedStep): void
    {
        $idx = array_search($completedStep, self::STEPS, true);
        $next = ($idx !== false && $idx < count(self::STEPS) - 1) ? self::STEPS[$idx + 1] : null;
        $progress = self::STEP_PROGRESS[$completedStep] ?? 0;
        $stmt = $this->pdo->prepare("UPDATE system_updates SET next_step = :n, progress = :p WHERE id = :id");
        $stmt->execute([':n' => $next, ':p' => $progress, ':id' => $updateId]);
    }

    private function handleStepFailure(int $updateId, string $step, Throwable $e): void
    {
        $this->logUpdate($updateId, 'ERROR in step "' . $step . '": ' . $e->getMessage());
        $rolledBack = false;

        // Files were touched from `deploy` onwards — restore the backup.
        if (in_array($step, ['deploy', 'migrate', 'finalize'], true)) {
            try {
                $update = $this->getUpdate($updateId);
                if (!empty($update['backup_id'])) {
                    $this->restoreBackup((int)$update['backup_id'], $updateId);
                    $rolledBack = true;
                    $this->logUpdate($updateId, 'Automatic rollback completed — previous code and database restored.');
                }
            } catch (Throwable $re) {
                $this->logUpdate($updateId, 'CRITICAL: automatic rollback failed: ' . $re->getMessage());
            }
        }

        $stmt = $this->pdo->prepare("
            UPDATE system_updates
            SET status = :st, error_message = :err, finished_at = NOW(), next_step = NULL
            WHERE id = :id
        ");
        $stmt->execute([
            ':st' => $rolledBack ? 'rolled_back' : 'failed',
            ':err' => substr($e->getMessage(), 0, 5000),
            ':id' => $updateId,
        ]);
        $this->cleanupStaging();
        $this->releaseLock();
    }

    // ------------------------------------------------------------------
    // Steps
    // ------------------------------------------------------------------

    private function stepBackup(array $update, int $adminId): void
    {
        $updateId = (int)$update['id'];
        $this->createBackup(
            $adminId,
            (string)$update['from_commit'],
            (string)$update['from_version'],
            'backup',
            function (string $msg) use ($updateId): void {
                $this->logUpdate($updateId, $msg);
            },
            $updateId
        );
        $this->advance($updateId, 'backup');
    }

    /**
     * Create a full code + database backup and record it in
     * system_update_backups. Used by the update flow (with $linkUpdateId)
     * and by the AutoBackupJob cron (standalone). Returns the backup id.
     */
    public function createBackup(
        ?int $adminId,
        ?string $commitHash = null,
        ?string $version = null,
        string $namePrefix = 'backup',
        ?callable $log = null,
        ?int $linkUpdateId = null
    ): int {
        $log = $log ?? static function (string $msg): void {};
        $commitHash = $commitHash !== null && $commitHash !== '' ? $commitHash : $this->getCurrentCommit();
        $version = $version !== null && $version !== '' ? $version : $this->getCurrentVersion();

        $name = $namePrefix . '_' . date('Ymd_His');
        $zipPath = $this->backupsDir . '/' . $name . '_files.zip';
        $dumpPath = $this->backupsDir . '/' . $name . '_db.sql';

        $log('Creating file backup: ' . basename($zipPath));
        $fileCount = $this->zipDirectory($this->rootDir, $zipPath);
        $log('File backup done (' . $fileCount . ' files, ' . $this->humanBytes((int)filesize($zipPath)) . ').');

        // Insert the backup record BEFORE dumping the database so that a
        // later DB rollback (which restores this dump) still contains the
        // backup row and the update -> backup linkage.
        $stmt = $this->pdo->prepare("
            INSERT INTO system_update_backups
                (backup_name, files_path, db_dump_path, commit_hash, app_version, size_bytes, status, created_by_admin_id, created_at)
            VALUES (:n, :f, :d, :c, :v, :s, 'created', :a, NOW())
        ");
        $stmt->execute([
            ':n' => $name,
            ':f' => 'storage/backups/' . basename($zipPath),
            ':d' => 'storage/backups/' . basename($dumpPath),
            ':c' => substr($commitHash, 0, 64),
            ':v' => substr($version, 0, 32),
            ':s' => (int)filesize($zipPath),
            ':a' => $adminId,
        ]);
        $backupId = (int)$this->pdo->lastInsertId();
        if ($linkUpdateId !== null) {
            $this->pdo->prepare("UPDATE system_updates SET backup_id = :b WHERE id = :id")
                ->execute([':b' => $backupId, ':id' => $linkUpdateId]);
        }

        $log('Creating database backup: ' . basename($dumpPath));
        $tables = $this->dumpDatabase($dumpPath);
        $log('Database backup done (' . $tables . ' tables, ' . $this->humanBytes((int)filesize($dumpPath)) . ').');
        $this->pdo->prepare("UPDATE system_update_backups SET size_bytes = :s WHERE id = :id")
            ->execute([':s' => (int)filesize($zipPath) + (int)filesize($dumpPath), ':id' => $backupId]);

        $this->pruneOldBackups();
        return $backupId;
    }

    private function stepDownload(array $update): void
    {
        $updateId = (int)$update['id'];
        $cfg = $this->getConfig();
        $sha = (string)$update['to_commit'];
        $zipPath = $this->updatesDir . '/release_' . substr($sha, 0, 12) . '.zip';

        $this->logUpdate($updateId, 'Downloading release zipball for commit ' . substr($sha, 0, 7) . ' ...');
        $this->downloadZipball($cfg['repo'], $sha, $zipPath);
        if (!is_file($zipPath) || (int)filesize($zipPath) < 100) {
            throw new RuntimeException('Downloaded release archive is missing or empty.');
        }
        $this->logUpdate($updateId, sprintf(
            'Download complete: %s (sha256 %s).',
            $this->humanBytes((int)filesize($zipPath)),
            hash_file('sha256', $zipPath)
        ));
        $this->advance($updateId, 'download');
    }

    private function stepVerify(array $update): void
    {
        $updateId = (int)$update['id'];
        $sha = (string)$update['to_commit'];
        $zipPath = $this->updatesDir . '/release_' . substr($sha, 0, 12) . '.zip';
        $stagingDir = $this->updatesDir . '/staging_' . substr($sha, 0, 12);

        if (!is_file($zipPath)) {
            throw new RuntimeException('Release archive not found — run the download step again.');
        }
        $this->removeDirectory($stagingDir);
        @mkdir($stagingDir, 0755, true);

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Release archive is corrupted (cannot open zip).');
        }
        // Zip-slip guard: refuse entries that escape the staging dir.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string)$zip->getNameIndex($i);
            if (str_contains($entry, '..') || str_starts_with($entry, '/')) {
                $zip->close();
                throw new RuntimeException('Release archive contains an unsafe path: ' . $entry);
            }
        }
        if (!$zip->extractTo($stagingDir)) {
            $zip->close();
            throw new RuntimeException('Could not extract the release archive.');
        }
        $entryCount = $zip->numFiles;
        $zip->close();

        $releaseRoot = $this->findReleaseRoot($stagingDir);
        foreach (self::REQUIRED_FILES as $required) {
            if (!is_file($releaseRoot . '/' . $required)) {
                throw new RuntimeException('Integrity check failed: required file missing in release: ' . $required);
            }
        }
        $this->logUpdate($updateId, 'Archive extracted (' . $entryCount . ' entries), required files present.');

        $lintResult = $this->lintStagedPhpFiles($releaseRoot);
        if ($lintResult['checked'] > 0) {
            $this->logUpdate($updateId, 'PHP syntax check passed for ' . $lintResult['checked'] . ' staged files.');
        } else {
            $this->logUpdate($updateId, 'PHP syntax check skipped (' . $lintResult['reason'] . ').');
        }

        // Writability preflight: fail HERE, before a single live file is
        // touched, instead of mid-deploy (which would force a rollback).
        $plan = $this->buildDeployPlan($releaseRoot);
        if ($plan['problems'] !== []) {
            $shown = array_slice($plan['problems'], 0, 10);
            throw new RuntimeException(
                'Deploy preflight failed — the PHP user cannot write ' . count($plan['problems'])
                . ' target path(s): ' . implode('; ', $shown)
                . (count($plan['problems']) > 10 ? '; …' : '')
                . '. Fix ownership/permissions on the server, e.g. chown the project to the web user'
                . ' or `chmod -R u+w` those directories, then run the update again. Nothing was changed.'
            );
        }
        $this->logUpdate($updateId, 'Write preflight passed: ' . count($plan['changed'])
            . ' file(s) to update, ' . $plan['identical'] . ' unchanged file(s) will be skipped.');
        $this->advance($updateId, 'verify');
    }

    /**
     * Compare every staged file against the live tree.
     *
     * @return array{changed:string[], identical:int, problems:string[]}
     *   changed  — relative paths whose content differs or that are new
     *   problems — human-readable descriptions of unwritable targets
     *              (only for paths that actually need writing)
     */
    private function buildDeployPlan(string $releaseRoot): array
    {
        $changed = [];
        $identical = 0;
        $problems = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($releaseRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if (!$item->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($releaseRoot))), '/');
            if ($relative === '' || $this->isProtectedPath($relative)) {
                continue;
            }
            $target = $this->rootDir . '/' . $relative;

            if (is_file($target) && hash_file('sha256', $target) === hash_file('sha256', $item->getPathname())) {
                $identical++;
                continue;
            }
            $changed[] = $relative;

            // The deploy writes a temp file next to the target and renames
            // it, so what must be writable is the containing directory
            // (nearest existing ancestor for brand-new paths).
            $dir = dirname($target);
            while (!is_dir($dir) && strlen($dir) > strlen($this->rootDir)) {
                $dir = dirname($dir);
            }
            if (!is_writable($dir)) {
                @chmod($dir, 0755); // cheap self-heal when the web user owns it
            }
            if (!is_writable($dir)) {
                $problems[] = $relative . ' (directory not writable: ' . $dir . ')';
            }
        }
        return ['changed' => $changed, 'identical' => $identical, 'problems' => array_values(array_unique($problems))];
    }

    private function stepDeploy(array $update): void
    {
        $updateId = (int)$update['id'];
        $sha = (string)$update['to_commit'];
        $stagingDir = $this->updatesDir . '/staging_' . substr($sha, 0, 12);
        $releaseRoot = $this->findReleaseRoot($stagingDir);

        $deployed = 0;
        $skipped = 0;
        $identical = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($releaseRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($releaseRoot))), '/');
            if ($relative === '' || $this->isProtectedPath($relative)) {
                $skipped++;
                continue;
            }
            $target = $this->rootDir . '/' . $relative;
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    @mkdir($target, 0755, true);
                }
                continue;
            }
            // Unchanged files are left completely untouched — this keeps
            // the write surface (and permission requirements) down to the
            // files that actually differ in this release.
            if (is_file($target) && hash_file('sha256', $target) === hash_file('sha256', $item->getPathname())) {
                $identical++;
                continue;
            }
            $dir = dirname($target);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            // Copy to a temp file in the same directory, then rename for
            // an atomic per-file swap (no half-written PHP is ever served).
            $tmp = $target . '.updater_tmp';
            error_clear_last();
            if (!@copy($item->getPathname(), $tmp)) {
                $reason = error_get_last()['message'] ?? 'unknown filesystem error';
                @unlink($tmp);
                throw new RuntimeException('Failed to write file during deploy: ' . $relative . ' — ' . $reason);
            }
            if (!@rename($tmp, $target)) {
                $reason = error_get_last()['message'] ?? 'unknown filesystem error';
                @unlink($tmp);
                throw new RuntimeException('Failed to activate file during deploy: ' . $relative . ' — ' . $reason);
            }
            @chmod($target, 0644);
            $deployed++;
        }

        $removed = $this->applyRemovalManifest($releaseRoot, $updateId);

        $this->logUpdate($updateId, 'Deploy complete: ' . $deployed . ' file(s) updated, '
            . $identical . ' unchanged file(s) skipped, ' . $skipped . ' protected path(s) preserved'
            . ($removed > 0 ? ', ' . $removed . ' obsolete file(s) removed' : '') . '.');
        $this->advance($updateId, 'deploy');
    }

    /**
     * Delete files that a release explicitly retires.
     *
     * The deploy step only adds and overwrites, so a file removed from
     * the repository would otherwise stay live on the server forever —
     * which matters when the removed file is a vulnerable or broken
     * endpoint. Rather than inferring deletions by diffing the tree
     * (which risks destroying anything the release does not carry, such
     * as user uploads), a release states its removals explicitly in
     * `deploy/removals.txt`.
     *
     * Every entry is validated: files only, no traversal, must resolve
     * inside the project, must sit in a code directory, and must not be
     * a protected path. Anything else is refused and logged.
     *
     * @return int number of files actually removed
     */
    private function applyRemovalManifest(string $releaseRoot, int $updateId): int
    {
        $manifest = $releaseRoot . '/deploy/removals.txt';
        if (!is_file($manifest)) {
            return 0;
        }

        $allowedRoots = ['app/', 'public/', 'cron/', 'tools/', 'database/'];
        $removed = 0;

        foreach (preg_split('/\r?\n/', (string)file_get_contents($manifest)) ?: [] as $rawLine) {
            $relative = trim($rawLine);
            if ($relative === '' || str_starts_with($relative, '#')) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', $relative), '/');

            if (str_contains($relative, '..') || str_contains($relative, "\0")) {
                $this->logUpdate($updateId, 'Removal refused (unsafe path): ' . $relative);
                continue;
            }
            $inAllowedRoot = false;
            foreach ($allowedRoots as $root) {
                if (str_starts_with($relative, $root)) {
                    $inAllowedRoot = true;
                    break;
                }
            }
            if (!$inAllowedRoot) {
                $this->logUpdate($updateId, 'Removal refused (outside code directories): ' . $relative);
                continue;
            }
            if ($this->isProtectedPath($relative)) {
                $this->logUpdate($updateId, 'Removal refused (protected path): ' . $relative);
                continue;
            }

            $target = $this->rootDir . '/' . $relative;
            if (!is_file($target)) {
                continue; // already gone — manifests are idempotent
            }
            $real = realpath($target);
            if ($real === false || !str_starts_with(str_replace('\\', '/', $real), $this->rootDir . '/')) {
                $this->logUpdate($updateId, 'Removal refused (escapes project root): ' . $relative);
                continue;
            }
            if (@unlink($target)) {
                $removed++;
                $this->logUpdate($updateId, 'Removed obsolete file: ' . $relative);
            } else {
                $this->logUpdate($updateId, 'Could not remove obsolete file: ' . $relative);
            }
        }

        return $removed;
    }

    private function stepMigrate(array $update): void
    {
        $updateId = (int)$update['id'];
        $applied = $this->runPendingMigrations($updateId);
        if ($applied === []) {
            $this->logUpdate($updateId, 'No new database migrations to run.');
        } else {
            $this->logUpdate($updateId, 'Applied ' . count($applied) . ' migration(s): ' . implode(', ', $applied));
        }
        $this->advance($updateId, 'migrate');
    }

    private function stepFinalize(array $update, int $adminId): void
    {
        $updateId = (int)$update['id'];

        // Restore permissions on writable runtime directories.
        foreach (['public/uploads', 'storage', 'storage/logs', 'storage/backups', 'storage/updates', 'storage/cache'] as $dir) {
            $abs = $this->rootDir . '/' . $dir;
            if (!is_dir($abs)) {
                @mkdir($abs, 0755, true);
            }
            @chmod($abs, 0755);
        }

        // Clear caches so the new code is served immediately.
        if (function_exists('opcache_reset')) {
            @opcache_reset();
            $this->logUpdate($updateId, 'OPcache cleared.');
        }
        $this->clearDirectory($this->rootDir . '/storage/cache');
        $this->cleanupStaging();

        upsertSystemSetting($this->pdo, 'app_current_commit', (string)$update['to_commit'], 'string', $adminId);
        upsertSystemSetting($this->pdo, 'app_last_update_at', date('Y-m-d H:i:s'), 'string', $adminId);

        $stmt = $this->pdo->prepare("
            UPDATE system_updates
            SET status = 'success', progress = 100, next_step = NULL, finished_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $updateId]);
        $this->logUpdate($updateId, 'Update finished successfully. Now running version ' . $this->getCurrentVersion()
            . ' (commit ' . substr((string)$update['to_commit'], 0, 7) . ').');
        $this->releaseLock();
    }

    // ------------------------------------------------------------------
    // Rollback / restore
    // ------------------------------------------------------------------

    /**
     * Manual rollback to a stored backup (creates its own history row).
     */
    public function rollbackToBackup(int $backupId, int $adminId): int
    {
        $backup = $this->getBackup($backupId);
        $this->acquireLock();

        $stmt = $this->pdo->prepare("
            INSERT INTO system_updates
                (update_type, from_version, to_version, from_commit, to_commit,
                 status, backup_id, initiated_by_admin_id, started_at, created_at)
            VALUES ('rollback', :fv, :tv, :fc, :tc, 'running', :b, :a, NOW(), NOW())
        ");
        $stmt->execute([
            ':fv' => $this->getCurrentVersion(),
            ':tv' => (string)$backup['app_version'],
            ':fc' => $this->getCurrentCommit(),
            ':tc' => (string)$backup['commit_hash'],
            ':b' => $backupId,
            ':a' => $adminId,
        ]);
        $rollbackId = (int)$this->pdo->lastInsertId();

        try {
            $this->restoreBackup($backupId, $rollbackId);
            upsertSystemSetting($this->pdo, 'app_current_commit', (string)$backup['commit_hash'], 'string', $adminId);
            $this->pdo->prepare("
                UPDATE system_updates SET status = 'success', progress = 100, finished_at = NOW() WHERE id = :id
            ")->execute([':id' => $rollbackId]);
            $this->logUpdate($rollbackId, 'Manual rollback to backup "' . $backup['backup_name'] . '" completed.');
        } catch (Throwable $e) {
            $this->pdo->prepare("
                UPDATE system_updates SET status = 'failed', error_message = :e, finished_at = NOW() WHERE id = :id
            ")->execute([':e' => substr($e->getMessage(), 0, 5000), ':id' => $rollbackId]);
            $this->releaseLock();
            throw $e;
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        $this->releaseLock();
        return $rollbackId;
    }

    private function restoreBackup(int $backupId, int $historyUpdateId): void
    {
        $backup = $this->getBackup($backupId);

        $zipAbs = $this->rootDir . '/' . ltrim((string)$backup['files_path'], '/');
        if (!is_file($zipAbs)) {
            throw new RuntimeException('Backup archive is missing on disk: ' . (string)$backup['files_path']);
        }
        $this->logUpdate($historyUpdateId, 'Restoring files from ' . basename($zipAbs) . ' ...');

        $zip = new ZipArchive();
        if ($zip->open($zipAbs) !== true) {
            throw new RuntimeException('Backup archive is corrupted (cannot open zip).');
        }
        $restored = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string)$zip->getNameIndex($i);
            if ($entry === '' || str_contains($entry, '..') || str_starts_with($entry, '/')) {
                continue;
            }
            if ($this->isProtectedPath($entry)) {
                continue;
            }
            if (str_ends_with($entry, '/')) {
                @mkdir($this->rootDir . '/' . rtrim($entry, '/'), 0755, true);
                continue;
            }
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                continue;
            }
            $target = $this->rootDir . '/' . $entry;
            $dir = dirname($target);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $tmp = $target . '.updater_tmp';
            if (@file_put_contents($tmp, $content) !== false && @rename($tmp, $target)) {
                @chmod($target, 0644);
                $restored++;
            } else {
                @unlink($tmp);
            }
        }
        $zip->close();
        $this->logUpdate($historyUpdateId, 'Files restored: ' . $restored . '.');

        $dumpAbs = $this->rootDir . '/' . ltrim((string)$backup['db_dump_path'], '/');
        if (is_file($dumpAbs)) {
            $this->logUpdate($historyUpdateId, 'Restoring database from ' . basename($dumpAbs) . ' ...');
            // The dump predates rows written since the backup was taken —
            // snapshot the updater's own history so the restore does not
            // erase update/backup records (or this very run's log).
            $preservedBackups = $this->pdo->query('SELECT * FROM system_update_backups')->fetchAll();
            $preservedUpdates = $this->pdo->query('SELECT * FROM system_updates')->fetchAll();
            $statements = $this->restoreDatabase($dumpAbs);
            $this->reinstateHistoryRows('system_update_backups', $preservedBackups);
            $this->reinstateHistoryRows('system_updates', $preservedUpdates);
            $this->logUpdate($historyUpdateId, 'Database restored (' . $statements . ' statements executed); update/backup history preserved.');
        }

        $this->pdo->prepare("
            UPDATE system_update_backups SET status = 'restored', restored_at = NOW() WHERE id = :id
        ")->execute([':id' => $backupId]);
    }

    /**
     * Upsert pre-restore history rows back into a freshly restored table
     * (by explicit id). Columns are intersected with the restored schema
     * so restoring an older schema can never make this throw.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function reinstateHistoryRows(string $table, array $rows): void
    {
        if ($rows === [] || preg_match('/^[a-z_]+$/', $table) !== 1) {
            return;
        }
        try {
            $columns = $this->pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable) {
            return;
        }
        $columnSet = array_flip(array_map('strval', $columns));

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($rows as $row) {
                $data = array_intersect_key($row, $columnSet);
                if ($data === [] || !isset($data['id'])) {
                    continue;
                }
                $cols = array_keys($data);
                $colSql = implode(',', array_map(static fn(string $c): string => "`{$c}`", $cols));
                $placeholders = implode(',', array_map(static fn(string $c): string => ':' . $c, $cols));
                $updates = implode(',', array_map(
                    static fn(string $c): string => "`{$c}` = VALUES(`{$c}`)",
                    array_diff($cols, ['id'])
                ));
                $sql = "INSERT INTO `{$table}` ({$colSql}) VALUES ({$placeholders})"
                    . ($updates !== '' ? " ON DUPLICATE KEY UPDATE {$updates}" : '');
                try {
                    $stmt = $this->pdo->prepare($sql);
                    foreach ($data as $col => $value) {
                        $stmt->bindValue(':' . $col, $value);
                    }
                    $stmt->execute();
                } catch (Throwable) {
                    // A single unrestorable row must never break the rollback.
                }
            }
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    // ------------------------------------------------------------------
    // Database dump / restore (pure PHP — no shell dependency)
    // ------------------------------------------------------------------

    private function dumpDatabase(string $dumpPath): int
    {
        $fh = fopen($dumpPath, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Cannot create the database dump file.');
        }
        fwrite($fh, "-- Krishna Review System database backup\n");
        fwrite($fh, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        $tables = $this->pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        $count = 0;
        foreach ($tables as $row) {
            $table = (string)$row[0];
            $type = (string)($row[1] ?? 'BASE TABLE');
            if ($type !== 'BASE TABLE') {
                continue; // skip views
            }
            $quoted = '`' . str_replace('`', '``', $table) . '`';
            $create = $this->pdo->query("SHOW CREATE TABLE {$quoted}")->fetch(PDO::FETCH_NUM);
            fwrite($fh, "DROP TABLE IF EXISTS {$quoted};\n");
            fwrite($fh, (string)$create[1] . ";\n\n");

            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $stmt = $this->pdo->query("SELECT * FROM {$quoted}");
            while ($dataRow = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $values = [];
                foreach ($dataRow as $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } else {
                        // Escape newlines so every statement ends with ";\n"
                        // and the restore parser can split on it safely.
                        $q = $this->pdo->quote((string)$value);
                        $values[] = str_replace(["\r", "\n"], ['\\r', '\\n'], $q);
                    }
                }
                fwrite($fh, "INSERT INTO {$quoted} VALUES (" . implode(',', $values) . ");\n");
            }
            $stmt->closeCursor();
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            fwrite($fh, "\n");
            $count++;
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
        return $count;
    }

    private function restoreDatabase(string $dumpPath): int
    {
        $fh = fopen($dumpPath, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Cannot open the database dump file.');
        }
        $executed = 0;
        $buffer = '';
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            while (($line = fgets($fh)) !== false) {
                if ($buffer === '' && (str_starts_with($line, '--') || trim($line) === '')) {
                    continue;
                }
                $buffer .= $line;
                // Our own dump format guarantees statements end with ";\n".
                if (str_ends_with(rtrim($line, "\r\n") , ';') && $this->statementLooksComplete($buffer)) {
                    $sql = trim($buffer);
                    $buffer = '';
                    if ($sql !== '') {
                        $this->pdo->exec($sql);
                        $executed++;
                    }
                }
            }
            if (trim($buffer) !== '') {
                $this->pdo->exec(trim($buffer));
                $executed++;
            }
        } finally {
            fclose($fh);
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        return $executed;
    }

    /**
     * CREATE TABLE statements span multiple lines; make sure we do not
     * split inside one by requiring balanced parentheses outside strings.
     */
    private function statementLooksComplete(string $sql): bool
    {
        $depth = 0;
        $inString = false;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($inString) {
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === "'") {
                    $inString = false;
                }
                continue;
            }
            if ($ch === "'") {
                $inString = true;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            }
        }
        return $depth === 0 && !$inString;
    }

    // ------------------------------------------------------------------
    // Migrations
    // ------------------------------------------------------------------

    /**
     * One-time baseline: mark every migration file already on disk as
     * applied — the production database is already current, so only
     * files added by future updates should ever execute. Guarded by a
     * settings flag so a later failed-then-retried update never
     * baselines its own (deployed but not yet applied) migrations away.
     */
    private function baselineMigrations(): void
    {
        if (getSystemSetting($this->pdo, 'updater_migrations_baselined', '') === '1') {
            return;
        }
        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO system_migrations (migration_file, applied_at) VALUES (:f, NOW())
        ");
        foreach ($this->listMigrationFiles() as $file) {
            $stmt->execute([':f' => $file]);
        }
        upsertSystemSetting($this->pdo, 'updater_migrations_baselined', '1', 'bool');
    }

    /** @return string[] applied file names */
    private function runPendingMigrations(int $updateId): array
    {
        $appliedRows = $this->pdo->query('SELECT migration_file FROM system_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $appliedSet = array_flip(array_map('strval', $appliedRows));

        $applied = [];
        foreach ($this->listMigrationFiles() as $file) {
            if (isset($appliedSet[$file])) {
                continue;
            }
            $path = $this->rootDir . '/database/migrations/' . $file;
            $sql = (string)file_get_contents($path);
            $this->logUpdate($updateId, 'Running migration: ' . $file);
            foreach ($this->splitSqlStatements($sql) as $statement) {
                $this->pdo->exec($statement);
            }
            $this->pdo->prepare("
                INSERT INTO system_migrations (migration_file, applied_at, update_id) VALUES (:f, NOW(), :u)
            ")->execute([':f' => $file, ':u' => $updateId]);
            $applied[] = $file;
        }
        return $applied;
    }

    /** @return string[] sorted migration file names */
    private function listMigrationFiles(): array
    {
        $dir = $this->rootDir . '/database/migrations';
        if (!is_dir($dir)) {
            return [];
        }
        $files = array_values(array_filter(
            scandir($dir) ?: [],
            static fn(string $f): bool => (bool)preg_match('/^[\w.-]+\.sql$/i', $f)
        ));
        sort($files, SORT_STRING);
        return $files;
    }

    /** @return string[] */
    private function splitSqlStatements(string $sql): array
    {
        // Strip line comments, then split on ";" outside quoted strings.
        $lines = preg_split('/\r?\n/', $sql) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            if (str_starts_with(ltrim($line), '--') || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            $clean[] = $line;
        }
        $sql = implode("\n", $clean);

        $statements = [];
        $buffer = '';
        $inString = false;
        $stringChar = '';
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($inString) {
                $buffer .= $ch;
                if ($ch === '\\') {
                    if ($i + 1 < $len) {
                        $buffer .= $sql[++$i];
                    }
                } elseif ($ch === $stringChar) {
                    $inString = false;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $inString = true;
                $stringChar = $ch;
                $buffer .= $ch;
                continue;
            }
            if ($ch === ';') {
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }
        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }
        return $statements;
    }

    // ------------------------------------------------------------------
    // History / accessors
    // ------------------------------------------------------------------

    public function getUpdate(int $updateId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM system_updates WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $updateId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Update record not found.');
        }
        return $row;
    }

    public function getBackup(int $backupId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM system_update_backups WHERE id = :id AND status <> 'deleted' LIMIT 1
        ");
        $stmt->execute([':id' => $backupId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Backup record not found.');
        }
        return $row;
    }

    public function getUpdateHistory(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        return $this->pdo->query("
            SELECT u.*, b.backup_name
            FROM system_updates u
            LEFT JOIN system_update_backups b ON b.id = u.backup_id
            ORDER BY u.id DESC
            LIMIT {$limit}
        ")->fetchAll();
    }

    public function getBackupHistory(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        return $this->pdo->query("
            SELECT * FROM system_update_backups
            WHERE status <> 'deleted'
            ORDER BY id DESC
            LIMIT {$limit}
        ")->fetchAll();
    }

    public function updaterTablesExist(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM system_updates LIMIT 1');
            $this->pdo->query('SELECT 1 FROM system_update_backups LIMIT 1');
            $this->pdo->query('SELECT 1 FROM system_migrations LIMIT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * One-time installer for the updater's own tables so the module can
     * be enabled from the UI without shell access to run the migration.
     */
    public function installUpdaterTables(): void
    {
        $file = $this->rootDir . '/database/migrations/2026_08_04_github_auto_update_system.sql';
        if (!is_file($file)) {
            throw new RuntimeException('Updater migration file is missing: database/migrations/2026_08_04_github_auto_update_system.sql');
        }
        foreach ($this->splitSqlStatements((string)file_get_contents($file)) as $statement) {
            $this->pdo->exec($statement);
        }
        // The freshly installed schema matches the migrations on disk, so
        // baseline them all — only future migration files should run.
        $this->baselineMigrations();
    }

    // ------------------------------------------------------------------
    // Lock handling
    // ------------------------------------------------------------------

    private function lockPath(): string
    {
        return $this->rootDir . '/storage/.update_lock';
    }

    private function acquireLock(): void
    {
        $path = $this->lockPath();
        if (is_file($path)) {
            $age = time() - (int)filemtime($path);
            if ($age < self::LOCK_STALE_SECONDS) {
                throw new RuntimeException('Another update is already in progress. Try again in a few minutes.');
            }
            @unlink($path); // stale lock from a crashed run
        }
        if (@file_put_contents($path, (string)getmypid(), LOCK_EX) === false) {
            throw new RuntimeException('Cannot create the update lock file (storage/ not writable?).');
        }
    }

    public function releaseLock(): void
    {
        @unlink($this->lockPath());
    }

    // ------------------------------------------------------------------
    // GitHub HTTP client
    // ------------------------------------------------------------------

    private function apiGet(string $path, bool $allowNotFound = false): ?array
    {
        $cfg = $this->getConfig();
        $ch = curl_init(self::API_BASE . $path);
        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: ' . self::USER_AGENT,
        ];
        if ($cfg['token'] !== '') {
            $headers[] = 'Authorization: Bearer ' . $cfg['token'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('GitHub API request failed: ' . ($err ?: 'network error'));
        }
        if ($status === 404 && $allowNotFound) {
            return null;
        }
        if ($status === 401 || $status === 403) {
            throw new RuntimeException('GitHub rejected the request (HTTP ' . $status . '). Check the access token and its repository permissions.');
        }
        if ($status === 404) {
            throw new RuntimeException('GitHub returned 404 — repository or branch not found (private repos need a valid token).');
        }
        if ($status >= 400) {
            throw new RuntimeException('GitHub API error (HTTP ' . $status . ').');
        }
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('GitHub API returned an unreadable response.');
        }
        return $decoded;
    }

    private function fetchRemoteVersion(string $repo, string $ref): ?string
    {
        $data = $this->apiGet("/repos/{$repo}/contents/VERSION?ref=" . rawurlencode($ref), true);
        if (is_array($data) && ($data['encoding'] ?? '') === 'base64' && !empty($data['content'])) {
            $decoded = base64_decode((string)$data['content'], true);
            if ($decoded !== false && trim($decoded) !== '') {
                return trim($decoded);
            }
        }
        return null;
    }

    private function downloadZipball(string $repo, string $ref, string $destination): void
    {
        $cfg = $this->getConfig();
        $fh = fopen($destination, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Cannot create the download file (storage/updates not writable?).');
        }
        $ch = curl_init(self::API_BASE . "/repos/{$repo}/zipball/" . rawurlencode($ref));
        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: ' . self::USER_AGENT,
        ];
        if ($cfg['token'] !== '') {
            $headers[] = 'Authorization: Bearer ' . $cfg['token'];
        }
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => self::DOWNLOAD_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($ok === false || $status >= 400) {
            @unlink($destination);
            throw new RuntimeException('Release download failed (HTTP ' . $status . ($err !== '' ? ', ' . $err : '') . ').');
        }
    }

    // ------------------------------------------------------------------
    // Filesystem helpers
    // ------------------------------------------------------------------

    private function isProtectedPath(string $relative): bool
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        foreach (self::PROTECTED_PATHS as $protected) {
            if (str_ends_with($protected, '/')) {
                if (str_starts_with($relative . '/', $protected)) {
                    return true;
                }
            } elseif ($relative === $protected) {
                return true;
            }
        }
        return false;
    }

    /** Backups skip protected runtime data too (uploads/storage stay in place). */
    private function isBackupExcluded(string $relative): bool
    {
        return $this->isProtectedPath($relative);
    }

    private function zipDirectory(string $sourceDir, string $zipPath): int
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the backup archive.');
        }
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($sourceDir))), '/');
            if ($relative === '' || $this->isBackupExcluded($relative)) {
                continue;
            }
            $zip->addFile($file->getPathname(), $relative);
            $count++;
        }
        if (!$zip->close()) {
            throw new RuntimeException('Failed to finish writing the backup archive.');
        }
        return $count;
    }

    private function findReleaseRoot(string $stagingDir): string
    {
        // GitHub zipballs wrap everything in a single "owner-repo-sha" folder.
        $entries = array_values(array_filter(
            scandir($stagingDir) ?: [],
            static fn(string $e): bool => $e !== '.' && $e !== '..'
        ));
        if (count($entries) === 1 && is_dir($stagingDir . '/' . $entries[0])) {
            return $stagingDir . '/' . $entries[0];
        }
        return $stagingDir;
    }

    /** @return array{checked:int,reason:string} */
    private function lintStagedPhpFiles(string $releaseRoot): array
    {
        if (!function_exists('exec')) {
            return ['checked' => 0, 'reason' => 'exec() disabled on this server'];
        }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (in_array('exec', $disabled, true)) {
            return ['checked' => 0, 'reason' => 'exec() disabled on this server'];
        }
        $phpBinary = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
        @exec(escapeshellarg($phpBinary) . ' -v 2>&1', $probe, $probeCode);
        if ($probeCode !== 0) {
            return ['checked' => 0, 'reason' => 'php CLI not available'];
        }

        $checked = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($releaseRoot, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $output = [];
            $code = 0;
            @exec(escapeshellarg($phpBinary) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $code);
            if ($code !== 0) {
                $relative = ltrim(substr($file->getPathname(), strlen($releaseRoot)), '/');
                throw new RuntimeException('Syntax error in release file ' . $relative . ': ' . implode(' ', $output));
            }
            $checked++;
        }
        return ['checked' => $checked, 'reason' => ''];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
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

    private function clearDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.gitkeep') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
    }

    public function cleanupStaging(): void
    {
        foreach (scandir($this->updatesDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.gitkeep') {
                continue;
            }
            $path = $this->updatesDir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
    }

    private function pruneOldBackups(): void
    {
        $keep = max(3, (int)getSystemSetting($this->pdo, 'updater_keep_backups', (string)self::KEEP_BACKUPS_DEFAULT));
        $rows = $this->pdo->query("
            SELECT id, files_path, db_dump_path FROM system_update_backups
            WHERE status <> 'deleted'
            ORDER BY id DESC
        ")->fetchAll();
        foreach (array_slice($rows, $keep) as $row) {
            foreach (['files_path', 'db_dump_path'] as $key) {
                if (!empty($row[$key])) {
                    $abs = $this->rootDir . '/' . ltrim((string)$row[$key], '/');
                    if (is_file($abs)) {
                        @unlink($abs);
                    }
                }
            }
            $this->pdo->prepare("UPDATE system_update_backups SET status = 'deleted' WHERE id = :id")
                ->execute([':id' => (int)$row['id']]);
        }
    }

    // ------------------------------------------------------------------
    // Logging
    // ------------------------------------------------------------------

    private function logUpdate(int $updateId, string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
        $stmt = $this->pdo->prepare("
            UPDATE system_updates
            SET log_text = CONCAT(COALESCE(log_text, ''), :line)
            WHERE id = :id
        ");
        $stmt->execute([':line' => $line . "\n", ':id' => $updateId]);
        @file_put_contents($this->logFile, '[update #' . $updateId . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
