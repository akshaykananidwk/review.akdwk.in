<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

function adminSessionStart(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? '1' : '0');
        ini_set('session.cookie_samesite', 'Lax');
        session_start();
    }
}

function requireAdminLogin(): void
{
    adminSessionStart();
    if (empty($_SESSION['admin_id']) || ($_SESSION['admin_role'] ?? '') !== 'super_admin') {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
}
