<?php
declare(strict_types=1);
/**
 * Daily Review Summary Cron — runs 22:00 IST (10:00 PM) every day.
 *
 * Crontab (Asia/Kolkata) on Linux:
 *   0 22 * * *   /usr/bin/php /path/to/cron/daily_review_summary.php
 *
 * If server is UTC:
 *   30 16 * * *  /usr/bin/php /path/to/cron/daily_review_summary.php
 */

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/helpers/settings_helper.php';
require_once __DIR__ . '/../app/helpers/whatsapp_notify_helper.php';

$logFile = __DIR__ . '/../storage/logs/cron_daily_summary.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }

function logLine(string $file, string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND);
    if (PHP_SAPI === 'cli') {
        echo $line;
    }
}

logLine($logFile, '--- Daily review summary cron started ---');

try {
    $pdo = getPDO();
} catch (Throwable $e) {
    logLine($logFile, 'FATAL: cannot open DB: ' . $e->getMessage());
    exit(1);
}

$wa = new WhatsAppService($pdo);
if (!$wa->isConfigured()) {
    logLine($logFile, 'SKIP: WhatsApp gateway is not configured (set credentials in Global Settings).');
    exit(0);
}

$hasSummaryPrefs = false;
try {
    $hasSummaryPrefs = (bool)$pdo->query("SHOW COLUMNS FROM clients LIKE 'daily_summary_whatsapp_enabled'")->fetch();
} catch (Throwable) {
    $hasSummaryPrefs = false;
}

$systemName = getSystemSetting($pdo, 'system_name', 'Krishna Review System');

$todayStart     = date('Y-m-d 00:00:00');
$todayEnd       = date('Y-m-d 23:59:59');
$yesterdayStart = date('Y-m-d 00:00:00', strtotime('-1 day'));
$yesterdayEnd   = date('Y-m-d 23:59:59', strtotime('-1 day'));

if ($hasSummaryPrefs) {
    $clientsStmt = $pdo->query("
        SELECT id, business_name, owner_name, mobile, extra_whatsapp_numbers, daily_summary_whatsapp_enabled
        FROM clients
        WHERE is_active = 1
          AND COALESCE(daily_summary_whatsapp_enabled, 1) = 1
    ");
} else {
    $clientsStmt = $pdo->query("
        SELECT id, business_name, owner_name, mobile
        FROM clients
        WHERE is_active = 1
    ");
}
$clients = $clientsStmt->fetchAll();

if (empty($clients)) {
    logLine($logFile, 'No eligible clients found.');
    exit(0);
}

$countStmt = $pdo->prepare("
    SELECT COUNT(*) c
    FROM review_sessions
    WHERE client_id = :id
      AND flow_type = 'google_redirect'
      AND completed_at IS NOT NULL
      AND completed_at BETWEEN :from AND :to
");

$totalSent = 0;
$totalSkipped = 0;
$totalFailed = 0;

foreach ($clients as $client) {
    $clientId    = (int)$client['id'];
    $business    = trim((string)$client['business_name']);
    $mobile      = trim((string)($client['mobile'] ?? ''));
    $extra       = $hasSummaryPrefs ? trim((string)($client['extra_whatsapp_numbers'] ?? '')) : '';

    $numbers = whatsapp_collect_notify_numbers($pdo, $mobile, $extra !== '' ? $extra : null);
    if (empty($numbers)) {
        $totalSkipped++;
        continue;
    }

    $countStmt->execute([':id' => $clientId, ':from' => $todayStart,     ':to' => $todayEnd]);
    $today = (int)($countStmt->fetch()['c'] ?? 0);

    $countStmt->execute([':id' => $clientId, ':from' => $yesterdayStart, ':to' => $yesterdayEnd]);
    $yesterday = (int)($countStmt->fetch()['c'] ?? 0);

    $message  = "Jay Dwarkadhish {$business}! 🌟" . "\n";
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
            $totalSent++;
            logLine($logFile, "OK client_id={$clientId} business=\"{$business}\" today={$today} yest={$yesterday} mobile={$num}");
        } else {
            $totalFailed++;
            $err = $resp['error'] ?? 'unknown';
            $http = $resp['status'] ?? 0;
            logLine($logFile, "FAIL client_id={$clientId} business=\"{$business}\" mobile={$num} http={$http} err={$err}");
        }
        usleep(150000);
    }
}

logLine($logFile, "--- Done. sent={$totalSent} failed={$totalFailed} skipped={$totalSkipped} clients=" . count($clients) . ' ---');
