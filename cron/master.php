<?php
declare(strict_types=1);

/**
 * MASTER CRON — the only server cron this application needs.
 * -------------------------------------------------------------------
 * Configure exactly one crontab line, running every minute:
 *
 *   * * * * *  /usr/bin/php /path/to/cron/master.php >> /dev/null 2>&1
 *
 * Every scheduled task (buffer refill, daily reports, reminders,
 * notification queue, backups, cleanup, and anything registered in
 * app/cron_jobs/registry.php later) is executed internally by the
 * scheduler when due. Locking makes overlapping ticks and duplicate
 * job runs impossible. Manage everything from Admin → Cron Settings.
 *
 * Hosts without CLI cron can call the HTTP fallback instead:
 *   https://<domain>/run_cron.php?key=<REFILL_CRON_SECRET>
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only. Use public/run_cron.php for the HTTP trigger.');
}

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/services/CronService.php';

try {
    $service = new CronService();
    if (!$service->cronTablesExist()) {
        fwrite(STDERR, "Cron tables are not installed yet — open Admin → Cron Settings once, or run database/migrations/2026_08_05_centralized_cron_scheduler.sql\n");
        exit(1);
    }
    $results = $service->tick();
    if ($results === []) {
        echo "[" . date('Y-m-d H:i:s') . "] tick: nothing due\n";
    } else {
        foreach ($results as $job => $outcome) {
            echo "[" . date('Y-m-d H:i:s') . "] {$job}: {$outcome}\n";
        }
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Master cron failed: ' . $e->getMessage() . "\n");
    exit(1);
}
