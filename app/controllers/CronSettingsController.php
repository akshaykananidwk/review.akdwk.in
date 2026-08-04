<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/admin_session_helper.php';
require_once __DIR__ . '/../helpers/csrf_helper.php';
require_once __DIR__ . '/../helpers/settings_helper.php';
require_once __DIR__ . '/../helpers/audit_helper.php';
require_once __DIR__ . '/../services/CronService.php';

/**
 * CronSettingsController — Admin → Cron Settings.
 * One place to monitor and control every scheduled task: status, next
 * run, execution history with logs, enable/disable, manual run, retry,
 * plus health monitoring for the master cron itself.
 */
final class CronSettingsController
{
    public function index(): void
    {
        requireAdminLogin();
        $pdo = getPDO();
        $service = new CronService($pdo);
        $adminId = (int)$_SESSION['admin_id'];
        $flash = '';
        $flashError = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrfValidateToken($_POST['csrf_token'] ?? null)) {
                $flashError = 'Security token expired — please try again.';
            } else {
                $action = (string)($_POST['action'] ?? '');
                $jobKey = trim((string)($_POST['job_key'] ?? ''));
                try {
                    switch ($action) {
                        case 'install_cron_tables':
                            $service->installCronTables();
                            logAdminActivity($pdo, $adminId, 'INSTALL_CRON_SCHEDULER', 'cron_jobs', null, 'Installed centralized cron scheduler tables');
                            $flash = 'Cron scheduler installed. Now add the single master crontab line shown below.';
                            break;

                        case 'toggle_job':
                            $enable = (string)($_POST['enable'] ?? '') === '1';
                            $service->setJobEnabled($jobKey, $enable);
                            logAdminActivity($pdo, $adminId, $enable ? 'ENABLE_CRON_JOB' : 'DISABLE_CRON_JOB', 'cron_jobs', null, ($enable ? 'Enabled' : 'Disabled') . ' cron job ' . $jobKey);
                            $flash = 'Job "' . $jobKey . '" ' . ($enable ? 'enabled' : 'disabled') . '.';
                            break;

                        case 'run_job':
                        case 'retry_job':
                            $service->syncRegistry();
                            $trigger = $action === 'retry_job' ? 'retry' : 'manual';
                            $run = $service->runJob($jobKey, $trigger, $adminId);
                            logAdminActivity($pdo, $adminId, 'RUN_CRON_JOB', 'cron_job_runs', $run['run_id'], ucfirst($trigger) . ' run of ' . $jobKey . ': ' . $run['status']);
                            if ($run['status'] === 'failed') {
                                $flashError = 'Job "' . $jobKey . '" failed — see the execution log below.';
                            } elseif ($run['status'] === 'skipped') {
                                $flash = 'Job "' . $jobKey . '" was skipped: ' . $run['summary'];
                            } else {
                                $flash = 'Job "' . $jobKey . '" ran successfully'
                                    . ($run['summary'] !== '' ? ' (' . $run['summary'] . ')' : '') . '.';
                            }
                            break;

                        case 'save_task_settings':
                            $reminderDays = trim((string)($_POST['subscription_reminder_days'] ?? '3,1,0'));
                            if (preg_match('/^\d{1,3}(\s*,\s*\d{1,3})*$/', $reminderDays) !== 1) {
                                $reminderDays = '3,1,0';
                            }
                            $walletThreshold = max(0, (int)($_POST['wallet_low_balance_threshold'] ?? 0));
                            upsertSystemSetting($pdo, 'subscription_reminder_days', $reminderDays, 'string', $adminId);
                            upsertSystemSetting($pdo, 'wallet_low_balance_threshold', (string)$walletThreshold, 'int', $adminId);
                            logAdminActivity($pdo, $adminId, 'UPDATE_CRON_TASK_SETTINGS', 'system_settings', null, 'Updated reminder/threshold settings');
                            $flash = 'Task settings saved.';
                            break;
                    }
                } catch (Throwable $e) {
                    $flashError = $e->getMessage();
                }
            }
        }

        $tablesReady = $service->cronTablesExist();
        $jobs = [];
        $health = null;
        $recentRuns = [];
        $historyJob = isset($_GET['job']) && is_string($_GET['job']) ? trim($_GET['job']) : '';
        if ($tablesReady) {
            $service->syncRegistry();
            $jobs = $service->listJobs();
            $health = $service->health();
            $recentRuns = $service->getRecentRuns($historyJob !== '' ? $historyJob : null, 30);
        }

        $reminderDays = getSystemSetting($pdo, 'subscription_reminder_days', '3,1,0');
        $walletThreshold = (int)getSystemSetting($pdo, 'wallet_low_balance_threshold', '0');
        $cronSecretSet = strlen(BootstrapEnv::envString('REFILL_CRON_SECRET', '')) >= 32;
        $projectRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 2)), '/');
        $csrfToken = csrfGenerateToken();

        $pageTitle = 'Cron Settings';
        $activeMenu = 'cron';
        require __DIR__ . '/../views/admin/cron_settings.php';
    }
}
