<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/settings_helper.php';
require_once __DIR__ . '/../helpers/whatsapp_notify_helper.php';
require_once __DIR__ . '/../helpers/notification_queue_helper.php';

/**
 * Subscription & plan expiry check + payment-due reminders.
 *
 * Reminder days come from the `subscription_reminder_days` setting
 * (comma-separated days-before-expiry; default "3,1,0" — 0 = expiry
 * day itself). Each reminder is queued via notification_queue with a
 * dedupe key, so a client is never messaged twice for the same
 * expiry date + reminder offset, even across reruns.
 */
final class SubscriptionExpiryJob
{
    public function run(PDO $pdo, CronLogger $log): string
    {
        try {
            $pdo->query("SELECT subscription_valid_until FROM clients LIMIT 1");
        } catch (Throwable) {
            return 'skipped: clients.subscription_valid_until column not present';
        }

        $daysRaw = getSystemSetting($pdo, 'subscription_reminder_days', '3,1,0');
        $offsets = [];
        foreach (explode(',', $daysRaw) as $part) {
            $part = trim($part);
            if ($part !== '' && is_numeric($part)) {
                $offsets[(int)$part] = true;
            }
        }
        $offsets = array_keys($offsets);
        if ($offsets === []) {
            $offsets = [3, 1, 0];
        }

        $systemName = getSystemSetting($pdo, 'system_name', 'Krishna Review System');
        $helpline = getSystemSetting($pdo, 'helpline_number', getSystemSetting($pdo, 'support_mobile', ''));
        $renewUrl = APP_URL . '/client_subscription_renew.php';

        $stmt = $pdo->prepare("
            SELECT id, business_name, owner_name, mobile, subscription_valid_until
            FROM clients
            WHERE is_active = 1
              AND subscription_valid_until IS NOT NULL
              AND subscription_valid_until = :target
        ");

        $queued = 0;
        $expiredNoticed = 0;
        foreach ($offsets as $daysBefore) {
            $targetDate = date('Y-m-d', strtotime('+' . $daysBefore . ' days'));
            $stmt->execute([':target' => $targetDate]);
            foreach ($stmt->fetchAll() as $client) {
                $clientId = (int)$client['id'];
                $business = trim((string)$client['business_name']);
                $mobile = trim((string)($client['mobile'] ?? ''));
                if ($mobile === '') {
                    continue;
                }

                if ($daysBefore === 0) {
                    $message = "Namaste {$business}! ⚠️\n"
                        . "Your {$systemName} plan expires TODAY ({$targetDate}).\n"
                        . "Renew now to keep collecting 5-star reviews without interruption:\n{$renewUrl}";
                    $expiredNoticed++;
                } else {
                    $message = "Namaste {$business}! 🔔\n"
                        . "Your {$systemName} plan expires in {$daysBefore} day" . ($daysBefore > 1 ? 's' : '') . " (on {$targetDate}).\n"
                        . "Renew in advance so your review collection never stops:\n{$renewUrl}";
                }
                if ($helpline !== '') {
                    $message .= "\nHelp: {$helpline}";
                }
                $message .= "\n\n— {$systemName}";

                $id = queueWhatsAppMessage(
                    $pdo,
                    $clientId,
                    $mobile,
                    $message,
                    'payment_due_reminder',
                    'sub_expiry_' . $clientId . '_' . $targetDate . '_d' . $daysBefore
                );
                if ($id > 0) {
                    $queued++;
                    $log->line("queued reminder for client #{$clientId} \"{$business}\" (expires {$targetDate}, {$daysBefore}d before)");
                }
            }
        }

        return "reminders queued={$queued} (expiry-day notices={$expiredNoticed}, offsets=" . implode(',', $offsets) . ')';
    }
}
