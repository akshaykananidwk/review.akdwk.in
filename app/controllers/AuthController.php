<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/session_helper.php';
require_once __DIR__ . '/../helpers/audit_helper.php';
require_once __DIR__ . '/../helpers/rate_limit_helper.php';
require_once __DIR__ . '/../services/Logger.php';

final class AuthController
{
    /** Attempts allowed per identity, per window, before lockout. */
    private const LOGIN_MAX_PER_EMAIL = 8;
    private const LOGIN_MAX_PER_IP = 25;
    private const LOGIN_WINDOW_SECONDS = 900; // 15 minutes

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

        // Brute-force protection on two independent dimensions: rotating
        // IPs cannot grind one account, and one IP cannot spray many.
        // Count FAILURES only. Peek before verifying, record a hit only when
        // authentication fails: a shared office IP doing many successful
        // logins must never lock itself out.
        $ip = rateLimitClientIp();
        $emailFailures = rateLimitPeek('login:email', strtolower($email), self::LOGIN_WINDOW_SECONDS);
        $ipFailures = rateLimitPeek('login:ip', $ip, self::LOGIN_WINDOW_SECONDS);
        if ($emailFailures >= self::LOGIN_MAX_PER_EMAIL || $ipFailures >= self::LOGIN_MAX_PER_IP) {
            $blocked = [
                'hits' => max($emailFailures, $ipFailures),
                'retry_after' => self::LOGIN_WINDOW_SECONDS,
            ];
            $emailGate = ['allowed' => $emailFailures < self::LOGIN_MAX_PER_EMAIL, 'hits' => $emailFailures];
            $ipGate = ['allowed' => $ipFailures < self::LOGIN_MAX_PER_IP, 'hits' => $ipFailures];
            Logger::security('Login blocked by rate limit', [
                'email' => $email,
                'dimension' => !$emailGate['allowed'] ? 'email' : 'ip',
                'hits' => $blocked['hits'],
            ], Logger::WARNING);
            $minutes = max(1, (int)ceil($blocked['retry_after'] / 60));
            $error = 'Too many login attempts. Please try again in about ' . $minutes . ' minute'
                . ($minutes > 1 ? 's' : '') . '.';
            http_response_code(429);
            require __DIR__ . '/../views/auth/login.php';
            return;
        }

        $pdo = getPDO();
        $adminStmt = $pdo->prepare("SELECT id, email, password_hash, role, is_active FROM admins WHERE email = :e LIMIT 1");
        $adminStmt->execute([':e' => $email]);
        $admin = $adminStmt->fetch();
        if ($admin && (int)$admin['is_active'] === 1 && password_verify($password, (string)$admin['password_hash'])) {
            $role = (string)$admin['role'];
            // Fail CLOSED on an unrecognised role. Previously an unknown
            // value was promoted to super_admin, which would turn any
            // future low-privilege role into full platform access.
            if (!in_array($role, ['super_admin', 'reseller'], true)) {
                Logger::security('Login denied: unrecognised admin role', [
                    'admin_id' => (int)$admin['id'],
                    'role' => $role,
                ], Logger::CRITICAL);
                $error = 'Your account role is not permitted to sign in. Please contact support.';
                require __DIR__ . '/../views/auth/login.php';
                return;
            }
            $this->clearLoginFailures($email, $ip);
            $pdo->prepare("UPDATE admins SET last_login_at = NOW() WHERE id = :id")->execute([':id' => (int)$admin['id']]);
            logLoginHistory($pdo, 'admin', (int)$admin['id'], null, (string)$admin['email']);
            session_regenerate_id(true);
            unset($_SESSION['csrf_token']); // new identity, new token
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
            $this->clearLoginFailures($email, $ip);
            session_regenerate_id(true);
            unset($_SESSION['csrf_token']); // new identity, new token
            $_SESSION['client_id'] = (int)$client['id'];
            $_SESSION['client_business_name'] = (string)$client['business_name'];
            $_SESSION['client_email'] = (string)$client['email'];
            unset($_SESSION['admin_id'], $_SESSION['admin_role']);
            logLoginHistory($pdo, 'client', null, (int)$client['id'], (string)$client['email']);
            header('Location: ' . APP_URL . '/dashboard.php');
            exit;
        }

        rateLimitHit('login:email', strtolower($email), self::LOGIN_MAX_PER_EMAIL, self::LOGIN_WINDOW_SECONDS);
        rateLimitHit('login:ip', $ip, self::LOGIN_MAX_PER_IP, self::LOGIN_WINDOW_SECONDS);

        // Failed attempts were previously invisible: login_history only
        // recorded successes, so credential stuffing left no trace.
        Logger::security('Failed login attempt', [
            'email' => $email,
            'account_exists' => ($admin !== false && $admin !== null) || ($client !== false && $client !== null),
            'failures_for_email' => $emailFailures + 1,
            'failures_for_ip' => $ipFailures + 1,
        ]);
        try {
            logFailedLoginAttempt(
                $pdo,
                $admin ? 'admin' : ($client ? 'client' : 'unknown'),
                $admin ? (int)$admin['id'] : null,
                $client ? (int)$client['id'] : null,
                $email
            );
        } catch (Throwable $e) {
            Logger::warning(Logger::CH_AUTH, 'Could not record failed login', ['email' => $email], $e);
        }

        $error = 'Invalid email or password.';
        require __DIR__ . '/../views/auth/login.php';
        return;
    }

    /** Clear both failure budgets after a genuine sign-in. */
    private function clearLoginFailures(string $email, string $ip): void
    {
        rateLimitReset('login:email', strtolower($email));
        rateLimitReset('login:ip', $ip);
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
