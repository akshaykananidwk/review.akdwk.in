<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/AiReviewService.php';

/**
 * Tops up every active client's pre-generated AI review buffer to
 * AiReviewService::BUFFER_TARGET. Same logic the legacy standalone
 * cron/refill_buffer.php ran; job bookkeeping in ai_generation_jobs
 * is preserved so existing admin reporting keeps working.
 */
final class RefillBufferJob
{
    public function run(PDO $pdo, CronLogger $log): string
    {
        $ai = new AiReviewService($pdo);
        $target = AiReviewService::BUFFER_TARGET;

        $clients = $pdo->query("
            SELECT c.id AS client_id,
                   COALESCE(SUM(CASE WHEN pgr.status = 'unused' THEN 1 ELSE 0 END), 0) AS unused_count
            FROM clients c
            LEFT JOIN pre_generated_reviews pgr ON pgr.client_id = c.id
            WHERE c.is_active = 1
            GROUP BY c.id
            HAVING unused_count < {$target}
        ")->fetchAll();

        if ($clients === []) {
            return 'all buffers full';
        }

        $inserted = 0;
        $failures = 0;
        foreach ($clients as $row) {
            $clientId = (int)$row['client_id'];
            $pdo->prepare("
                INSERT INTO ai_generation_jobs (client_id, trigger_source, status, requested_count, generated_count, attempt_count, created_at)
                VALUES (:c, 'scheduled_check', 'processing', 0, 0, 1, NOW())
            ")->execute([':c' => $clientId]);
            $jobId = (int)$pdo->lastInsertId();

            try {
                $needed = max(0, $target - (int)$row['unused_count']);
                $count = $ai->fillBufferToTarget($clientId, $target);
                $inserted += $count;
                $pdo->prepare("
                    UPDATE ai_generation_jobs
                    SET status = 'completed', requested_count = :r, generated_count = :g, finished_at = NOW()
                    WHERE id = :id
                ")->execute([':r' => $needed, ':g' => $count, ':id' => $jobId]);
                if ($count > 0) {
                    $log->line("client #{$clientId}: +{$count} reviews");
                }
            } catch (Throwable $e) {
                $failures++;
                $pdo->prepare("
                    UPDATE ai_generation_jobs
                    SET status = 'failed', error_message = :e, finished_at = NOW()
                    WHERE id = :id
                ")->execute([':e' => substr($e->getMessage(), 0, 2000), ':id' => $jobId]);
                $log->line("client #{$clientId} FAILED: " . $e->getMessage());
            }
        }

        $summary = 'clients=' . count($clients) . " inserted={$inserted} failures={$failures}";
        if ($failures > 0 && $inserted === 0) {
            throw new RuntimeException('Buffer refill failed for all clients (' . $summary . ')');
        }
        return $summary;
    }
}
