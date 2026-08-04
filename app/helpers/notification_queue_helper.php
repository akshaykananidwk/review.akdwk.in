<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Notification queue — write side.
 * -------------------------------------------------------------------
 * Any module can queue a WhatsApp (or, later, email) message instead of
 * sending synchronously; the NotificationQueueJob cron delivers pending
 * rows every minute with retries and backoff.
 *
 * $dedupeKey (optional) makes an enqueue idempotent — a second insert
 * with the same key is silently ignored, which is how the reminder jobs
 * avoid messaging the same client twice for the same event.
 * $sendAfter (optional, Y-m-d H:i:s) schedules delivery in the future.
 *
 * Returns the queue row id, or 0 when deduplicated / not queued.
 */
function queueNotification(
    PDO $pdo,
    string $channel,
    ?int $clientId,
    string $recipient,
    string $message,
    string $source = 'system',
    ?string $dedupeKey = null,
    ?string $sendAfter = null,
    ?string $subject = null,
    ?string $mediaUrl = null
): int {
    $recipient = trim($recipient);
    if ($recipient === '' || trim($message) === '') {
        return 0;
    }
    if (!in_array($channel, ['whatsapp', 'email'], true)) {
        return 0;
    }
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO notification_queue
            (channel, client_id, recipient, subject, message, media_url, source, dedupe_key, status, send_after, created_at)
        VALUES
            (:ch, :cid, :rcpt, :subj, :msg, :media, :src, :dk, 'pending', COALESCE(:after, NOW()), NOW())
    ");
    $stmt->execute([
        ':ch' => $channel,
        ':cid' => $clientId,
        ':rcpt' => substr($recipient, 0, 190),
        ':subj' => $subject !== null ? substr($subject, 0, 255) : null,
        ':msg' => $message,
        ':media' => $mediaUrl !== null ? substr($mediaUrl, 0, 500) : null,
        ':src' => substr($source, 0, 60),
        ':dk' => $dedupeKey !== null ? substr($dedupeKey, 0, 190) : null,
        ':after' => $sendAfter,
    ]);
    return $stmt->rowCount() > 0 ? (int)$pdo->lastInsertId() : 0;
}

function queueWhatsAppMessage(
    PDO $pdo,
    ?int $clientId,
    string $mobile,
    string $message,
    string $source = 'system',
    ?string $dedupeKey = null,
    ?string $sendAfter = null
): int {
    return queueNotification($pdo, 'whatsapp', $clientId, $mobile, $message, $source, $dedupeKey, $sendAfter);
}
