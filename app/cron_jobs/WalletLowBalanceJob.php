<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/settings_helper.php';
require_once __DIR__ . '/../helpers/notification_queue_helper.php';

/**
 * Wallet & billing task: reminds clients whose wallet balance is below
 * the threshold (`wallet_low_balance_threshold` setting; 0 = automatic
 * = 5 × price_per_review). At most one reminder per client per ISO
 * week via the queue's dedupe key.
 */
final class WalletLowBalanceJob
{
    public function run(PDO $pdo, CronLogger $log): string
    {
        $threshold = (int)getSystemSetting($pdo, 'wallet_low_balance_threshold', '0');
        if ($threshold <= 0) {
            $threshold = 5 * max(1, (int)getSystemSetting($pdo, 'price_per_review', '1'));
        }

        $systemName = getSystemSetting($pdo, 'system_name', 'Krishna Review System');
        $rechargeUrl = APP_URL . '/client_recharge.php';
        $week = date('o_W'); // ISO year_week for the dedupe key

        $clients = $pdo->prepare("
            SELECT id, business_name, mobile, wallet_balance
            FROM clients
            WHERE is_active = 1 AND wallet_balance < :t
        ");
        $clients->execute([':t' => $threshold]);

        $queued = 0;
        foreach ($clients->fetchAll() as $client) {
            $clientId = (int)$client['id'];
            $mobile = trim((string)($client['mobile'] ?? ''));
            if ($mobile === '') {
                continue;
            }
            $business = trim((string)$client['business_name']);
            $balance = (int)$client['wallet_balance'];

            $message = "Namaste {$business}! 💰\n"
                . "Your {$systemName} wallet balance is low: ₹{$balance} (below ₹{$threshold}).\n"
                . "Recharge now so your 5-star review collection keeps running:\n{$rechargeUrl}\n\n— {$systemName}";

            $id = queueWhatsAppMessage(
                $pdo,
                $clientId,
                $mobile,
                $message,
                'wallet_low_balance',
                'wallet_low_' . $clientId . '_' . $week
            );
            if ($id > 0) {
                $queued++;
                $log->line("queued low-balance reminder for client #{$clientId} \"{$business}\" (balance {$balance})");
            }
        }

        return "threshold={$threshold} reminders queued={$queued}";
    }
}
