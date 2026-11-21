<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/admin_session_helper.php';
require_once __DIR__ . '/../helpers/csrf_helper.php';
require_once __DIR__ . '/../helpers/settings_helper.php';
require_once __DIR__ . '/../helpers/audit_helper.php';
require_once __DIR__ . '/../services/AkEarnService.php';

/**
 * AkEarnController — Admin → AK Earn Connection.
 *
 * Shows whether this site can reach earn.akdwk.in, what it has sent, and
 * what failed, with a retry. Read-only apart from the explicit buttons,
 * and every action is CSRF-checked.
 */
final class AkEarnController
{
    public function index(): void
    {
        requireAdminLogin();
        $pdo = getPDO();
        $service = new AkEarnService($pdo);
        $adminId = (int)$_SESSION['admin_id'];
        $flash = '';
        $flashError = '';
        $pingResult = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrfValidateToken($_POST['csrf_token'] ?? null)) {
                $flashError = 'Security token expired — please try again.';
            } else {
                $action = (string)($_POST['action'] ?? '');
                try {
                    switch ($action) {
                        case 'ping':
                            $pingResult = $service->ping();
                            $flash = ($pingResult['status'] ?? '') === 'ok'
                                ? 'Connected. The master answered correctly.'
                                : '';
                            if ($flash === '') {
                                $flashError = 'Could not reach the master: '
                                    . (string)($pingResult['message'] ?? 'unknown error');
                            }
                            break;

                        case 'retry_one':
                            $id = (int)($_POST['outbox_id'] ?? 0);
                            $flash = $service->retry($id)
                                ? 'Event queued again. The next cron run (within a minute) will send it.'
                                : '';
                            if ($flash === '') {
                                $flashError = 'That event could not be queued again — it may already have been sent.';
                            }
                            logAdminActivity($pdo, $adminId, 'AK_EARN_RETRY', 'ak_outbox', $id, 'Retried AK Earn event');
                            break;

                        case 'retry_all':
                            $n = $service->retryAllFailed();
                            $flash = $n . ' failed event(s) queued again.';
                            logAdminActivity($pdo, $adminId, 'AK_EARN_RETRY_ALL', 'ak_outbox', null, 'Retried all failed AK Earn events');
                            break;

                        case 'send_now':
                            $r = $service->deliverPending(50);
                            $flash = sprintf('Sent %d, failed %d, %d still pending.', $r['sent'], $r['failed'], $r['remaining']);
                            break;

                        case 'sync_products':
                            $r = $service->syncProducts();
                            if (($r['status'] ?? '') === 'ok') {
                                $flash = 'Plan and price list sent to the master.';
                            } else {
                                $flashError = 'Product sync failed: ' . (string)($r['message'] ?? 'unknown error');
                            }
                            logAdminActivity($pdo, $adminId, 'AK_EARN_SYNC_PRODUCTS', 'payment_plans', null, 'Sent product list to AK Earn');
                            break;

                        case 'push_stats':
                            $date = trim((string)($_POST['stats_date'] ?? ''));
                            if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                                $date = date('Y-m-d', strtotime('-1 day'));
                            }
                            $r = $service->pushDailyStats($date);
                            if (($r['status'] ?? '') === 'ok') {
                                $flash = 'Daily totals for ' . $date . ' sent.';
                            } else {
                                $flashError = 'Stats push failed: ' . (string)($r['message'] ?? 'unknown error');
                            }
                            break;

                        case 'backfill':
                            $back = $service->backfillDailyStats(365);
                            $flash = sprintf('History upload: %d day(s) sent, %d failed.', $back['sent'], $back['failed']);
                            if ($back['failed'] === 0) {
                                upsertSystemSetting($pdo, 'ak_earn_backfill_done', '1', 'bool', $adminId);
                            }
                            logAdminActivity($pdo, $adminId, 'AK_EARN_BACKFILL', null, null, 'Uploaded 12 months of history to AK Earn');
                            break;

                        default:
                            $flashError = 'Unknown action.';
                    }
                } catch (Throwable $e) {
                    $flashError = 'Action failed: ' . $e->getMessage();
                }
            }
        }

        $configured = AkEarnService::isConfigured();
        $counts = $service->counts();
        $recent = $service->recentEvents(40);
        $failed = $service->failedEvents(50);
        $lastSent = $service->lastSentAt();
        $todayTotals = $service->totalsFor(date('Y-m-d'));
        $yesterdayTotals = $service->totalsFor(date('Y-m-d', strtotime('-1 day')));
        $backfillDone = (int)getSystemSetting($pdo, 'ak_earn_backfill_done', '0') === 1;
        $siteKey = defined('AK_SITE_KEY') ? (string)AK_SITE_KEY : '';
        $masterUrl = defined('AK_MASTER_URL') ? (string)AK_MASTER_URL : '';

        require __DIR__ . '/../views/admin/ak_earn.php';
    }
}
