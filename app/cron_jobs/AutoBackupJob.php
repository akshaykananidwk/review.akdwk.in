<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/GitHubUpdateService.php';

/**
 * Scheduled full backup (code ZIP + database dump) using the same
 * engine and storage as the GitHub update system, so automatic backups
 * appear in Admin → System Update → Backup History and can be rolled
 * back to. Old backups are pruned by the shared retention setting.
 */
final class AutoBackupJob
{
    public function run(PDO $pdo, CronLogger $log): string
    {
        $service = new GitHubUpdateService($pdo);
        if (!$service->updaterTablesExist()) {
            return 'skipped: update system tables not installed (Admin → System Update)';
        }
        $backupId = $service->createBackup(
            null,
            null,
            null,
            'auto',
            static function (string $msg) use ($log): void {
                $log->line($msg);
            }
        );
        $backup = $service->getBackup($backupId);
        return 'backup #' . $backupId . ' created (' . round(((int)$backup['size_bytes']) / 1048576, 1) . ' MB)';
    }
}
