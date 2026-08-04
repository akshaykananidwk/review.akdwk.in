<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/WhatsAppService.php';

/**
 * Delivers pending notification_queue rows (WhatsApp now; the email
 * channel is schema-ready and fails fast until a gateway is added).
 * Runs every minute; each attempt is claimed atomically so parallel
 * runs can never double-send. Failed sends retry with 5-minute backoff
 * up to max_attempts.
 */
final class NotificationQueueJob
{
    private const BATCH_SIZE = 50;
    private const RETRY_BACKOFF_MINUTES = 5;

    private const STUCK_PROCESSING_MINUTES = 10;

    public function run(PDO $pdo, CronLogger $log): string
    {
        $wa = new WhatsAppService($pdo);
        $sent = 0;
        $failed = 0;
        $exhausted = 0;

        // Recover rows stuck in 'processing' by a crashed run so they are
        // retried instead of being lost forever.
        $recovered = $pdo->exec("
            UPDATE notification_queue
            SET status = 'pending', updated_at = NOW()
            WHERE status = 'processing'
              AND updated_at < DATE_SUB(NOW(), INTERVAL " . self::STUCK_PROCESSING_MINUTES . " MINUTE)
        ");
        if ((int)$recovered > 0) {
            $log->line('recovered ' . (int)$recovered . ' message(s) stuck in processing');
        }

        $select = $pdo->prepare("
            SELECT id FROM notification_queue
            WHERE status = 'pending' AND send_after <= NOW()
            ORDER BY id
            LIMIT " . self::BATCH_SIZE . "
        ");
        $select->execute();
        $ids = $select->fetchAll(PDO::FETCH_COLUMN);
        if ($ids === []) {
            return 'queue empty';
        }

        $claim = $pdo->prepare("
            UPDATE notification_queue
            SET status = 'processing', attempts = attempts + 1, updated_at = NOW()
            WHERE id = :id AND status = 'pending'
        ");
        $load = $pdo->prepare('SELECT * FROM notification_queue WHERE id = :id LIMIT 1');

        foreach ($ids as $id) {
            $id = (int)$id;
            $claim->execute([':id' => $id]);
            if ($claim->rowCount() === 0) {
                continue; // claimed by a parallel run
            }
            $load->execute([':id' => $id]);
            $row = $load->fetch();
            if (!$row) {
                continue;
            }

            $ok = false;
            $error = '';
            if ((string)$row['channel'] === 'whatsapp') {
                if (!$wa->isConfigured()) {
                    $error = 'WhatsApp gateway is not configured.';
                } else {
                    $resp = !empty($row['media_url'])
                        ? $wa->sendMedia((string)$row['recipient'], (string)$row['message'], (string)$row['media_url'])
                        : $wa->sendText((string)$row['recipient'], (string)$row['message']);
                    $ok = !empty($resp['ok']);
                    if (!$ok) {
                        $error = 'HTTP ' . ($resp['status'] ?? 0) . ': ' . ($resp['error'] ?? 'send failed');
                    }
                }
            } else {
                $error = 'Email gateway is not configured yet.';
            }

            if ($ok) {
                $pdo->prepare("
                    UPDATE notification_queue
                    SET status = 'sent', sent_at = NOW(), last_error = NULL, updated_at = NOW()
                    WHERE id = :id
                ")->execute([':id' => $id]);
                $sent++;
                $log->line("sent #{$id} -> " . (string)$row['recipient'] . ' (' . (string)$row['source'] . ')');
                usleep(150000); // gateway rate courtesy
                continue;
            }

            if ((int)$row['attempts'] >= (int)$row['max_attempts']) {
                $pdo->prepare("
                    UPDATE notification_queue
                    SET status = 'failed', last_error = :e, updated_at = NOW()
                    WHERE id = :id
                ")->execute([':e' => substr($error, 0, 2000), ':id' => $id]);
                $exhausted++;
                $log->line("FAILED permanently #{$id} -> " . (string)$row['recipient'] . ': ' . $error);
            } else {
                $pdo->prepare("
                    UPDATE notification_queue
                    SET status = 'pending', last_error = :e,
                        send_after = DATE_ADD(NOW(), INTERVAL " . self::RETRY_BACKOFF_MINUTES . " MINUTE),
                        updated_at = NOW()
                    WHERE id = :id
                ")->execute([':e' => substr($error, 0, 2000), ':id' => $id]);
                $failed++;
                $log->line("retry scheduled #{$id} (attempt " . (int)$row['attempts'] . '): ' . $error);
            }
        }

        return "sent={$sent} retrying={$failed} failed={$exhausted}";
    }
}
