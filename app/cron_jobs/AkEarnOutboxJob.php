<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/AkEarnService.php';

/**
 * Delivers queued AK Earn events to earn.akdwk.in.
 *
 * This is the only place that talks to the master during normal
 * operation. Customer-facing requests just write a row and move on, so
 * a slow or unreachable master costs a minute of reporting delay and
 * nothing else.
 */
final class AkEarnOutboxJob
{
    public function run(PDO $pdo, CronLogger $log): string
    {
        if (!AkEarnService::isConfigured()) {
            return 'skipped: AK_EARN_SECRET not set in .env';
        }

        $svc = new AkEarnService($pdo);
        $r = $svc->deliverPending(25);

        // The SDK keeps its own file queue for requests that failed at
        // the network layer. Drain it here too, so nothing is stranded
        // waiting for the next organic page view to flush it.
        $flushed = function_exists('ak_flush_queue') ? ak_flush_queue(25) : 0;

        $log->line(sprintf('sent=%d failed=%d remaining=%d sdk_queue_flushed=%d',
            $r['sent'], $r['failed'], $r['remaining'], $flushed));

        if ($r['sent'] === 0 && $r['failed'] === 0 && $flushed === 0) {
            return 'nothing to send';
        }
        return sprintf('sent %d, failed %d, %d still pending', $r['sent'], $r['failed'], $r['remaining']);
    }
}
