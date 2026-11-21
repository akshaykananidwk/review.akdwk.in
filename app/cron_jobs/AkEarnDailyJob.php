<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/AkEarnService.php';
require_once __DIR__ . '/../helpers/settings_helper.php';

/**
 * Once a day: send yesterday's totals and re-sync the plan list, so the
 * master dashboard agrees with this site's own numbers.
 *
 * Yesterday rather than today, because a day that is still running would
 * be sent incomplete and then have to be corrected.
 *
 * On its first successful run it also backfills the last 12 months of
 * daily totals, so the master does not start with an empty history. That
 * runs once and is remembered in system_settings.
 */
final class AkEarnDailyJob
{
    public function run(PDO $pdo, CronLogger $log): string
    {
        if (!AkEarnService::isConfigured()) {
            return 'skipped: AK_EARN_SECRET not set in .env';
        }

        $svc = new AkEarnService($pdo);
        $parts = [];

        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $stats = $svc->pushDailyStats($yesterday);
        $statsOk = ($stats['status'] ?? '') === 'ok';
        $log->line('stats ' . $yesterday . ': ' . ($statsOk ? 'ok' : 'FAILED — ' . (string)($stats['message'] ?? '')));
        $parts[] = 'stats=' . ($statsOk ? 'ok' : 'failed');

        $products = $svc->syncProducts();
        $productsOk = ($products['status'] ?? '') === 'ok';
        $log->line('products: ' . ($productsOk ? 'ok' : 'FAILED — ' . (string)($products['message'] ?? '')));
        $parts[] = 'products=' . ($productsOk ? 'ok' : 'failed');

        // One-time history upload, only after a healthy stats push so we
        // do not hammer a master that is already refusing us.
        if ($statsOk && (int)getSystemSetting($pdo, 'ak_earn_backfill_done', '0') !== 1) {
            $back = $svc->backfillDailyStats(365, function (string $d, array $r) use ($log): void {
                if (($r['status'] ?? '') !== 'ok') {
                    $log->line('backfill ' . $d . ' failed: ' . (string)($r['message'] ?? ''));
                }
            });
            $log->line(sprintf('backfill: %d day(s) sent, %d failed', $back['sent'], $back['failed']));
            $parts[] = 'backfill=' . $back['sent'] . '/' . ($back['sent'] + $back['failed']);
            if ($back['failed'] === 0) {
                upsertSystemSetting($pdo, 'ak_earn_backfill_done', '1', 'bool', null);
            }
        }

        return implode(', ', $parts);
    }
}
