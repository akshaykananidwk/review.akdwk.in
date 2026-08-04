<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/admin_session_helper.php';
require_once __DIR__ . '/../helpers/csrf_helper.php';
require_once __DIR__ . '/../helpers/settings_helper.php';
require_once __DIR__ . '/../helpers/audit_helper.php';
require_once __DIR__ . '/../services/GitHubUpdateService.php';

/**
 * SystemUpdateController
 * -------------------------------------------------------------------
 * Admin page + AJAX API for the GitHub Auto Update System.
 *
 * The browser drives the update one step at a time (backup, download,
 * verify, deploy, migrate, finalize) so the UI can show a live progress
 * bar and per-step log while the server enforces order, locking, CSRF
 * and automatic rollback on failure.
 */
final class SystemUpdateController
{
    public function index(): void
    {
        requireAdminLogin();
        $pdo = getPDO();
        $service = new GitHubUpdateService($pdo);
        $adminId = (int)$_SESSION['admin_id'];
        $flash = '';
        $flashError = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrfValidateToken($_POST['csrf_token'] ?? null)) {
                $flashError = 'Security token expired — please try again.';
            } else {
                $action = (string)($_POST['action'] ?? '');
                try {
                    if ($action === 'save_github_settings') {
                        $service->saveConfig(
                            (string)($_POST['github_repo'] ?? ''),
                            (string)($_POST['github_branch'] ?? 'main'),
                            (string)($_POST['github_token'] ?? ''),
                            $adminId
                        );
                        logAdminActivity($pdo, $adminId, 'UPDATE_GITHUB_SETTINGS', 'system_settings', null, 'Updated GitHub auto-update configuration');
                        $flash = 'GitHub update settings saved.';
                    } elseif ($action === 'install_updater_tables') {
                        $service->installUpdaterTables();
                        logAdminActivity($pdo, $adminId, 'INSTALL_UPDATER', 'system_settings', null, 'Installed GitHub updater tables');
                        $flash = 'Update system tables installed successfully.';
                    }
                } catch (Throwable $e) {
                    $flashError = $e->getMessage();
                }
            }
        }

        $tablesReady = $service->updaterTablesExist();
        $config = $service->getConfig();
        $currentVersion = $service->getCurrentVersion();
        $currentCommit = $service->getCurrentCommit();
        $lastCheck = $tablesReady ? $service->getLastCheck() : null;
        $lastUpdateAt = getSystemSetting($pdo, 'app_last_update_at', '');
        $updateHistory = $tablesReady ? $service->getUpdateHistory(20) : [];
        $backupHistory = $tablesReady ? $service->getBackupHistory(20) : [];
        $csrfToken = csrfGenerateToken();

        $pageTitle = 'System Update';
        $activeMenu = 'system_update';
        require __DIR__ . '/../views/admin/system_update.php';
    }

    public function ajax(): void
    {
        requireAdminLogin();
        header('Content-Type: application/json; charset=utf-8');
        $pdo = getPDO();
        $adminId = (int)$_SESSION['admin_id'];

        $raw = file_get_contents('php://input');
        $input = json_decode($raw !== false ? $raw : '', true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValidateToken((string)($input['csrf_token'] ?? ''))) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid request or expired security token. Reload the page.']);
            return;
        }

        $service = new GitHubUpdateService($pdo);
        $action = (string)($input['action'] ?? '');

        try {
            switch ($action) {
                case 'check_update':
                    $result = $service->checkForUpdate();
                    logAdminActivity($pdo, $adminId, 'CHECK_UPDATE', 'system_updates', null, 'Checked GitHub for updates');
                    echo json_encode(['ok' => true, 'check' => $result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    return;

                case 'start_update':
                    $update = $service->startUpdate($adminId);
                    logAdminActivity($pdo, $adminId, 'START_UPDATE', 'system_updates', (int)$update['id'], 'Started GitHub update to ' . (string)$update['to_version']);
                    echo json_encode(['ok' => true, 'update' => $this->presentUpdate($update)]);
                    return;

                case 'run_step':
                    $updateId = (int)($input['update_id'] ?? 0);
                    $step = (string)($input['step'] ?? '');
                    if ($updateId <= 0 || !in_array($step, GitHubUpdateService::STEPS, true)) {
                        throw new RuntimeException('Invalid step request.');
                    }
                    $update = $service->runStep($updateId, $step, $adminId);
                    if ($update['status'] === 'success') {
                        logAdminActivity($pdo, $adminId, 'FINISH_UPDATE', 'system_updates', $updateId, 'Update completed: now on ' . (string)$update['to_version']);
                    }
                    echo json_encode(['ok' => true, 'update' => $this->presentUpdate($update)]);
                    return;

                case 'get_update':
                    $updateId = (int)($input['update_id'] ?? 0);
                    echo json_encode(['ok' => true, 'update' => $this->presentUpdate($service->getUpdate($updateId))]);
                    return;

                case 'rollback':
                    $backupId = (int)($input['backup_id'] ?? 0);
                    if ($backupId <= 0) {
                        throw new RuntimeException('Invalid backup selected.');
                    }
                    $rollbackId = $service->rollbackToBackup($backupId, $adminId);
                    logAdminActivity($pdo, $adminId, 'ROLLBACK', 'system_updates', $rollbackId, 'Rolled back to backup #' . $backupId);
                    echo json_encode(['ok' => true, 'update' => $this->presentUpdate($service->getUpdate($rollbackId))]);
                    return;

                default:
                    throw new RuntimeException('Unknown action.');
            }
        } catch (Throwable $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    private function presentUpdate(array $update): array
    {
        return [
            'id' => (int)$update['id'],
            'update_type' => (string)$update['update_type'],
            'status' => (string)$update['status'],
            'next_step' => $update['next_step'] !== null ? (string)$update['next_step'] : null,
            'progress' => (int)$update['progress'],
            'from_version' => (string)($update['from_version'] ?? ''),
            'to_version' => (string)($update['to_version'] ?? ''),
            'to_commit' => (string)($update['to_commit'] ?? ''),
            'error_message' => $update['error_message'] !== null ? (string)$update['error_message'] : null,
            'log_text' => (string)($update['log_text'] ?? ''),
        ];
    }
}
