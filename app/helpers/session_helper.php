<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

function secureSessionStart(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? '1' : '0');
        ini_set('session.cookie_samesite', 'Lax');
        session_start();
    }
}

function requireClientLogin(): void
{
    secureSessionStart();
    if (empty($_SESSION['client_id'])) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
}

/**
 * Full client panel gate: authenticated + active platform validity (recharge plan days).
 * Exempt: wallet recharge + Razorpay return so expired clients can pay and renew.
 */
function requireClientPanelAccess(): void
{
    requireClientLogin();
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $exempt = [
        'client_recharge.php',
        'razorpay_verify.php',
        'client_subscription_renew.php',
        'subscription_create_order.php',
        'subscription_razorpay_verify.php',
    ];
    if (in_array($script, $exempt, true)) {
        return;
    }
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/subscription_helper.php';
    $pdo = getPDO();
    if (clientHasActiveSubscription($pdo, (int)$_SESSION['client_id'])) {
        return;
    }
    header('Location: ' . APP_URL . '/client_recharge.php?renew=1');
    exit;
}
