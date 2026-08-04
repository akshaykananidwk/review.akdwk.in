<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function checkAndHitRateLimit(string $actionKey, string $ip, int $dailyLimit): bool
{
    $pdo = getPDO();

    $upsert = $pdo->prepare("
        INSERT INTO ip_rate_limits (action_key, ip_address, request_date, hit_count, created_at, updated_at)
        VALUES (:action_key, :ip_address, CURDATE(), 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
          hit_count = hit_count + 1,
          updated_at = NOW()
    ");
    $upsert->execute([
        ':action_key' => $actionKey,
        ':ip_address' => $ip,
    ]);

    $q = $pdo->prepare("
        SELECT hit_count
        FROM ip_rate_limits
        WHERE action_key = :action_key AND ip_address = :ip_address AND request_date = CURDATE()
        LIMIT 1
    ");
    $q->execute([
        ':action_key' => $actionKey,
        ':ip_address' => $ip,
    ]);
    $row = $q->fetch();

    return (int)($row['hit_count'] ?? 0) <= $dailyLimit;
}
