<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/settings_helper.php';
require_once __DIR__ . '/../helpers/whatsapp_notify_helper.php';

/**
 * Daily WhatsApp report to every active client: today's vs yesterday's
 * completed 5-star Google redirects. Migrated from the standalone
 * cron/daily_review_summary.php script (same message, same rules).
 */
final class DailyReviewSummaryJob
{
    public function run(PDO $pdo, CronLogger $log): string
    {
        $wa = new WhatsAppService($pdo);
        if (!$wa->isConfigured()) {
            return 'skipped: WhatsApp gateway not configured';
        }

        $hasSummaryPrefs = false;
        try {
            $hasSummaryPrefs = (bool)$pdo->query("SHOW COLUMNS FROM clients LIKE 'daily_summary_whatsapp_enabled'")->fetch();
        } catch (Throwable) {
            $hasSummaryPrefs = false;
        }

        $systemName = getSystemSetting($pdo, 'system_name', 'Krishna Review System');
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');
        $yesterdayStart = date('Y-m-d 00:00:00', strtotime('-1 day'));
        $yesterdayEnd = date('Y-m-d 23:59:59', strtotime('-1 day'));

        if ($hasSummaryPrefs) {
            $clients = $pdo->query("
                SELECT id, business_name, owner_name, mobile, extra_whatsapp_numbers
                FROM clients
                WHERE is_active = 1
                  AND COALESCE(daily_summary_whatsapp_enabled, 1) = 1
            ")->fetchAll();
        } else {
            $clients = $pdo->query("
                SELECT id, business_name, owner_name, mobile
                FROM clients
                WHERE is_active = 1
            ")->fetchAll();
        }
        if ($clients === []) {
            return 'no eligible clients';
        }

        $countStmt = $pdo->prepare("
            SELECT COUNT(*) c
            FROM review_sessions
            WHERE client_id = :id
              AND flow_type = 'google_redirect'
              AND completed_at IS NOT NULL
              AND completed_at BETWEEN :from AND :to
        ");

        $sent = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($clients as $client) {
            $clientId = (int)$client['id'];
            $business = trim((string)$client['business_name']);
            $extra = $hasSummaryPrefs ? trim((string)($client['extra_whatsapp_numbers'] ?? '')) : '';
            $numbers = whatsapp_collect_notify_numbers($pdo, (string)($client['mobile'] ?? ''), $extra !== '' ? $extra : null);
            if ($numbers === []) {
                $skipped++;
                continue;
            }

            $countStmt->execute([':id' => $clientId, ':from' => $todayStart, ':to' => $todayEnd]);
            $today = (int)($countStmt->fetch()['c'] ?? 0);
            $countStmt->execute([':id' => $clientId, ':from' => $yesterdayStart, ':to' => $yesterdayEnd]);
            $yesterday = (int)($countStmt->fetch()['c'] ?? 0);

            $message = "Jay Dwarkadhish {$business}! 🌟\n";
            $message .= "Today you received {$today} new 5-Star Reviews.\n";
            $message .= "Yesterday you received {$yesterday}.\n";
            if ($today < $yesterday) {
                $message .= "You got fewer reviews today! Make sure to ask your customers to scan the QR code tomorrow! 🚀";
            } elseif ($today > $yesterday) {
                $message .= "Great work — you grew today! Keep the momentum going tomorrow! 🚀";
            } else {
                $message .= "Steady pace today — push for one extra review tomorrow! 🚀";
            }
            $message .= "\n\n— {$systemName}";

            foreach ($numbers as $num) {
                $resp = $wa->sendText($num, $message);
                if (!empty($resp['ok'])) {
                    $sent++;
                    $log->line("OK client #{$clientId} \"{$business}\" today={$today} yest={$yesterday} -> {$num}");
                } else {
                    $failed++;
                    $log->line("FAIL client #{$clientId} \"{$business}\" -> {$num}: " . ($resp['error'] ?? 'unknown'));
                }
                usleep(150000);
            }
        }

        return "sent={$sent} failed={$failed} skipped={$skipped} clients=" . count($clients);
    }
}
