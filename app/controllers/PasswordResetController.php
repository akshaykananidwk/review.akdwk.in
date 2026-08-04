<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/session_helper.php';
require_once __DIR__ . '/../helpers/csrf_helper.php';
require_once __DIR__ . '/../helpers/settings_helper.php';
require_once __DIR__ . '/../helpers/audit_helper.php';
require_once __DIR__ . '/../helpers/rate_limit_helper.php';
require_once __DIR__ . '/../services/WhatsAppService.php';

/**
 * PasswordResetController
 * -------------------------------------------------------------------
 * Handles the WhatsApp-based "Forgot Password" flow for both
 * admins and clients (the unified login uses the same email pool).
 *
 * Flow:
 *   1. User submits email on /forgot_password.php
 *   2. We look up the user (admin first, then client), pull their
 *      registered mobile, generate a 6-digit OTP + 64-char reset
 *      token, store hashed in `password_resets`, then send both
 *      OTP & a reset link via the configured WhatsApp gateway.
 *   3. User opens the WhatsApp link -> verify OTP -> set new password.
 */
final class PasswordResetController
{
    private const OTP_TTL_MINUTES = 15;
    private const MAX_OTP_ATTEMPTS = 5;
    private const REQUEST_RATE_LIMIT_PER_DAY = 10;

    public function showRequestForm(): void
    {
        csrfEnsureSession();
        $csrfToken = csrfGenerateToken();
        $flash = '';
        $error = '';
        $maskedMobile = '';
        $resetToken = '';
        $stage = 'request';
        require __DIR__ . '/../views/auth/forgot_password.php';
    }

    public function submitRequest(): void
    {
        csrfEnsureSession();
        $csrfToken = csrfGenerateToken();
        $flash = '';
        $error = '';
        $maskedMobile = '';
        $resetToken = '';
        $stage = 'request';

        if (!csrfValidateToken((string)($_POST['csrf_token'] ?? ''))) {
            $error = 'Security token invalid. Please retry.';
            require __DIR__ . '/../views/auth/forgot_password.php';
            return;
        }

        $email = trim((string)($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid registered email.';
            require __DIR__ . '/../views/auth/forgot_password.php';
            return;
        }

        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!checkAndHitRateLimit('forgot_password_request', $ip, self::REQUEST_RATE_LIMIT_PER_DAY)) {
            $error = 'Too many reset requests from your IP. Please try again later.';
            require __DIR__ . '/../views/auth/forgot_password.php';
            return;
        }

        $pdo = getPDO();

        $userType = '';
        $userId = 0;
        $mobile = '';

        $aStmt = $pdo->prepare("SELECT id, mobile FROM admins WHERE email = :e AND is_active = 1 LIMIT 1");
        $aStmt->execute([':e' => $email]);
        if ($admin = $aStmt->fetch()) {
            $userType = 'admin';
            $userId = (int)$admin['id'];
            $mobile = (string)($admin['mobile'] ?? '');
        } else {
            $cStmt = $pdo->prepare("SELECT id, mobile FROM clients WHERE email = :e AND is_active = 1 LIMIT 1");
            $cStmt->execute([':e' => $email]);
            if ($client = $cStmt->fetch()) {
                $userType = 'client';
                $userId = (int)$client['id'];
                $mobile = (string)($client['mobile'] ?? '');
            }
        }

        // To avoid user enumeration we always return the same generic confirmation,
        // but we only actually dispatch a WhatsApp when the account exists with mobile.
        if ($userType === '' || $userId <= 0 || trim($mobile) === '') {
            $stage = 'sent';
            $maskedMobile = '';
            require __DIR__ . '/../views/auth/forgot_password.php';
            return;
        }

        $wa = new WhatsAppService($pdo);
        $normalized = $wa->normalizeMobile($mobile);
        if ($normalized === '') {
            $stage = 'sent';
            $maskedMobile = '';
            require __DIR__ . '/../views/auth/forgot_password.php';
            return;
        }

        try {
            $otp = (string)random_int(100000, 999999);
            $resetToken = bin2hex(random_bytes(32));
            $otpHash = hash('sha256', $otp);
            $expiresAt = (new DateTime())->modify('+' . self::OTP_TTL_MINUTES . ' minutes')->format('Y-m-d H:i:s');

            // Invalidate previous unused OTPs for this user.
            $pdo->prepare("UPDATE password_resets SET used = 1, used_at = NOW() WHERE user_type = :t AND user_id = :u AND used = 0")
                ->execute([':t' => $userType, ':u' => $userId]);

            $ins = $pdo->prepare("
                INSERT INTO password_resets (user_type, user_id, mobile, email, otp_hash, reset_token, expires_at, ip_address, created_at)
                VALUES (:t, :u, :m, :e, :h, :tok, :ex, :ip, NOW())
            ");
            $ins->execute([
                ':t' => $userType,
                ':u' => $userId,
                ':m' => $normalized,
                ':e' => $email,
                ':h' => $otpHash,
                ':tok' => $resetToken,
                ':ex' => $expiresAt,
                ':ip' => $ip,
            ]);

            $systemName = getSystemSetting($pdo, 'system_name', APP_NAME);
            $resetUrl = APP_URL . '/reset_password.php?token=' . urlencode($resetToken);
            $message = sprintf(
                "%s — Password Reset\n\nYour OTP is: %s\nValid for %d minutes.\n\nOr open this secure link to reset directly:\n%s\n\nIf you did not request this, ignore this message.",
                $systemName,
                $otp,
                self::OTP_TTL_MINUTES,
                $resetUrl
            );

            $resp = $wa->sendText($normalized, $message);
            if (!$resp['ok']) {
                logAdminActivity($pdo, $userType === 'admin' ? $userId : 0, 'PASSWORD_RESET_WA_FAIL', 'user', $userId,
                    'WhatsApp send failed: ' . ($resp['error'] ?? 'unknown'));
            }

            $stage = 'sent';
            $maskedMobile = $this->maskMobile($normalized);
        } catch (Throwable $e) {
            $error = 'Could not initiate password reset. Please try again later.';
        }

        require __DIR__ . '/../views/auth/forgot_password.php';
    }

    public function showResetForm(): void
    {
        csrfEnsureSession();
        $csrfToken = csrfGenerateToken();
        $flash = '';
        $error = '';
        $stage = 'verify';
        $resetToken = trim((string)($_GET['token'] ?? ''));
        $maskedMobile = '';

        if ($resetToken === '') {
            $error = 'Reset link is invalid or expired.';
            $stage = 'request';
            require __DIR__ . '/../views/auth/forgot_password.php';
            return;
        }

        $pdo = getPDO();
        $row = $this->fetchValidResetRow($pdo, $resetToken);
        if (!$row) {
            $error = 'Reset link is invalid or has expired. Request a new one.';
            $stage = 'request';
            require __DIR__ . '/../views/auth/forgot_password.php';
            return;
        }

        $maskedMobile = $this->maskMobile((string)$row['mobile']);
        require __DIR__ . '/../views/auth/forgot_password.php';
    }

    public function submitReset(): void
    {
        csrfEnsureSession();
        $csrfToken = csrfGenerateToken();
        $flash = '';
        $error = '';
        $stage = 'verify';
        $resetToken = trim((string)($_POST['reset_token'] ?? ''));
        $maskedMobile = '';

        if (!csrfValidateToken((string)($_POST['csrf_token'] ?? ''))) {
            $error = 'Security token invalid. Please retry.';
            require __DIR__ . '/../views/auth/forgot_password.php';
            return;
        }

        $otp = preg_replace('/\D+/', '', (string)($_POST['otp'] ?? '')) ?? '';
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($resetToken === '' || strlen($otp) !== 6) {
            $error = 'Invalid OTP format. Please re-enter the 6-digit code.';
            require __DIR__ . '/../views/auth/forgot_password.php';
            return;
        }
        if (strlen($newPassword) < 8) {
            $error = 'New password must be at least 8 characters long.';
            require __DIR__ . '/../views/auth/forgot_password.php';
            return;
        }
        if ($newPassword !== $confirmPassword) {
            $error = 'New password and confirmation do not match.';
            require __DIR__ . '/../views/auth/forgot_password.php';
            return;
        }

        $pdo = getPDO();
        $row = $this->fetchValidResetRow($pdo, $resetToken);
        if (!$row) {
            $error = 'Reset link is invalid or has expired. Request a new one.';
            $stage = 'request';
            require __DIR__ . '/../views/auth/forgot_password.php';
            return;
        }

        $maskedMobile = $this->maskMobile((string)$row['mobile']);

        if ((int)$row['attempts'] >= self::MAX_OTP_ATTEMPTS) {
            $pdo->prepare("UPDATE password_resets SET used = 1, used_at = NOW() WHERE id = :id")
                ->execute([':id' => (int)$row['id']]);
            $error = 'Too many attempts. Please request a fresh reset.';
            $stage = 'request';
            require __DIR__ . '/../views/auth/forgot_password.php';
            return;
        }

        if (!hash_equals((string)$row['otp_hash'], hash('sha256', $otp))) {
            $pdo->prepare("UPDATE password_resets SET attempts = attempts + 1 WHERE id = :id")
                ->execute([':id' => (int)$row['id']]);
            $error = 'Incorrect OTP. Please re-check your WhatsApp message.';
            require __DIR__ . '/../views/auth/forgot_password.php';
            return;
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        try {
            $pdo->beginTransaction();

            if ((string)$row['user_type'] === 'admin') {
                $pdo->prepare("UPDATE admins SET password_hash = :h, updated_at = NOW() WHERE id = :id")
                    ->execute([':h' => $newHash, ':id' => (int)$row['user_id']]);
                logAdminActivity($pdo, (int)$row['user_id'], 'PASSWORD_RESET', 'admin', (int)$row['user_id'], 'Password reset via WhatsApp OTP');
            } else {
                $pdo->prepare("UPDATE clients SET password_hash = :h, updated_at = NOW() WHERE id = :id")
                    ->execute([':h' => $newHash, ':id' => (int)$row['user_id']]);
            }

            $pdo->prepare("UPDATE password_resets SET used = 1, used_at = NOW() WHERE id = :id")
                ->execute([':id' => (int)$row['id']]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Could not update password. Please retry.';
            require __DIR__ . '/../views/auth/forgot_password.php';
            return;
        }

        $stage = 'done';
        $flash = 'Your password has been reset successfully. You can now login with your new password.';
        require __DIR__ . '/../views/auth/forgot_password.php';
    }

    private function fetchValidResetRow(PDO $pdo, string $resetToken): ?array
    {
        $stmt = $pdo->prepare("
            SELECT id, user_type, user_id, mobile, otp_hash, expires_at, used, attempts
            FROM password_resets
            WHERE reset_token = :tok
            LIMIT 1
        ");
        $stmt->execute([':tok' => $resetToken]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if ((int)$row['used'] === 1) {
            return null;
        }
        if (strtotime((string)$row['expires_at']) < time()) {
            return null;
        }
        return $row;
    }

    private function maskMobile(string $mobile): string
    {
        $digits = preg_replace('/\D+/', '', $mobile) ?? '';
        $len = strlen($digits);
        if ($len < 4) {
            return str_repeat('*', $len);
        }
        $visible = substr($digits, -4);
        return str_repeat('*', $len - 4) . $visible;
    }
}
