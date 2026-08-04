<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function getSystemSetting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = :k LIMIT 1");
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetch();
    if (!$row || !isset($row['setting_value'])) {
        return $default;
    }
    return (string)$row['setting_value'];
}

function upsertSystemSetting(PDO $pdo, string $key, string $value, string $type = 'string', ?int $adminId = null): void
{
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value, value_type, updated_by_admin_id, created_at, updated_at)
        VALUES (:k, :v, :t, :admin_id, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            value_type = VALUES(value_type),
            updated_by_admin_id = VALUES(updated_by_admin_id),
            updated_at = NOW()
    ");
    $stmt->execute([
        ':k' => $key,
        ':v' => $value,
        ':t' => $type,
        ':admin_id' => $adminId,
    ]);
}
