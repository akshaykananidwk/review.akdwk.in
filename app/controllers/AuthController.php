<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/session_helper.php';
require_once __DIR__ . '/../helpers/audit_helper.php';

final class AuthController
{
    public function loginForm(): void
    {
        secureSessionStart();
        if (!empty($_SESSION['admin_id'])) {
            $role = (string)($_SESSION['admin_role'] ?? '');
            if ($role === 'super_admin') {
                header('Location: ' . APP_URL . '/super_admin.php');
                exit;
            }
            if ($role === 'reseller') {
                header('Location: ' . APP_URL . '/reseller_panel.php');
                exit;
            }
        }
        if (!empty($_SESSION['client_id'])) {
            header('Location: ' . APP_URL . '/dashboard.php');
            exit;
        }
        $error = '';
        require __DIR__ . '/../views/auth/login.php';
    }

    public function loginSubmit(): void
    {
        secureSessionStart();
        $error = '';
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            $error = 'Please enter valid credentials.';
            require __DIR__ . '/../views/auth/login.php';
            return;
        }

        $pdo = getPDO();
        $adminStmt = $pdo->prepare("SELECT id, email, password_hash, role, is_active FROM admins WHERE email = :e LIMIT 1");
        $adminStmt->execute([':e' => $email]);
        $admin = $adminStmt->fetch();
        if ($admin && (int)$admin['is_active'] === 1 && password_verify($password, (string)$admin['password_hash'])) {
            $role = (string)$admin['role'];
            if (!in_array($role, ['super_admin', 'reseller'], true)) {
                $role = 'super_admin';
            }
            $pdo->prepare("UPDATE admins SET last_login_at = NOW() WHERE id = :id")->execute([':id' => (int)$admin['id']]);
            logLoginHistory($pdo, 'admin', (int)$admin['id'], null, (string)$admin['email']);
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int)$admin['id'];
            $_SESSION['admin_role'] = $role;
            unset($_SESSION['client_id'], $_SESSION['client_business_name'], $_SESSION['client_email']);
            if ($role === 'reseller') {
                header('Location: ' . APP_URL . '/reseller_panel.php');
            } else {
                header('Location: ' . APP_URL . '/super_admin.php');
            }
            exit;
        }

        $clientStmt = $pdo->prepare("SELECT id, business_name, email, password_hash, is_active FROM clients WHERE email = :e LIMIT 1");
        $clientStmt->execute([':e' => $email]);
        $client = $clientStmt->fetch();
        if ($client && (int)$client['is_active'] === 1 && password_verify($password, (string)$client['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['client_id'] = (int)$client['id'];
            $_SESSION['client_business_name'] = (string)$client['business_name'];
            $_SESSION['client_email'] = (string)$client['email'];
            unset($_SESSION['admin_id'], $_SESSION['admin_role']);
            logLoginHistory($pdo, 'client', null, (int)$client['id'], (string)$client['email']);
            header('Location: ' . APP_URL . '/dashboard.php');
            exit;
        }

        $error = 'Invalid email or password.';
        require __DIR__ . '/../views/auth/login.php';
        return;
    }

    public function logout(): void
    {
        secureSessionStart();
        $_SESSION = [];
        session_destroy();
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
}
