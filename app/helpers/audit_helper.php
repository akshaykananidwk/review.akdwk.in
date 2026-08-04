<?php
declare(strict_types=1);

function getRequestIpAddress(): ?string
{
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

function getRequestUserAgent(): ?string
{
    if (empty($_SERVER['HTTP_USER_AGENT'])) {
        return null;
    }
    return substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 1000);
}

function logLoginHistory(PDO $pdo, string $userType, ?int $adminId, ?int $clientId, string $email): void
{
    $stmt = $pdo->prepare("
        INSERT INTO login_history (user_type, admin_id, client_id, email, ip_address, user_agent, login_at)
        VALUES (:user_type, :admin_id, :client_id, :email, :ip_address, :user_agent, NOW())
    ");
    $stmt->execute([
        ':user_type' => $userType,
        ':admin_id' => $adminId,
        ':client_id' => $clientId,
        ':email' => $email,
        ':ip_address' => getRequestIpAddress(),
        ':user_agent' => getRequestUserAgent(),
    ]);
}

function logAdminActivity(PDO $pdo, int $adminId, string $action, ?string $targetType = null, ?int $targetId = null, ?string $description = null): void
{
    $stmt = $pdo->prepare("
        INSERT INTO admin_activity_logs (admin_id, action, target_type, target_id, description, ip_address, created_at)
        VALUES (:admin_id, :action, :target_type, :target_id, :description, :ip_address, NOW())
    ");
    $stmt->execute([
        ':admin_id' => $adminId,
        ':action' => $action,
        ':target_type' => $targetType,
        ':target_id' => $targetId,
        ':description' => $description,
        ':ip_address' => getRequestIpAddress(),
    ]);
}
