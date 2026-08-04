<?php
declare(strict_types=1);
/**
 * LEGACY ENTRY — Daily Review Summary.
 *
 * This task now lives in the centralized scheduler
 * (app/cron_jobs/DailyReviewSummaryJob.php) and is executed
 * automatically by cron/master.php — a separate crontab line is no
 * longer needed. This wrapper is kept so an old crontab entry keeps
 * working; it delegates to the scheduler, which records the run and
 * reschedules, so the master cron will not send the summary twice.
 */

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/services/CronService.php';
require_once __DIR__ . '/../app/cron_jobs/DailyReviewSummaryJob.php';

try {
    $pdo = getPDO();
    $service = new CronService($pdo);

    if ($service->cronTablesExist()) {
        $service->syncRegistry();
        $run = $service->runJob('daily_review_summary', 'manual');
        echo 'daily_review_summary: ' . $run['status']
            . ($run['summary'] !== '' ? ' — ' . $run['summary'] : '') . "\n";
        exit($run['status'] === 'failed' ? 1 : 0);
    }

    // Scheduler not installed yet — run the job directly (old behaviour).
    $logger = new CronLogger('daily_review_summary', __DIR__ . '/../storage/logs/cron_daily_summary.log');
    $job = new DailyReviewSummaryJob();
    echo $job->run($pdo, $logger) . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Daily summary failed: ' . $e->getMessage() . "\n");
    exit(1);
}
