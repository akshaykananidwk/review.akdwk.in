<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/settings_helper.php';
require_once __DIR__ . '/../helpers/csrf_helper.php';
require_once __DIR__ . '/../helpers/reseller_session_helper.php';
require_once __DIR__ . '/../services/ResellerWalletService.php';
require_once __DIR__ . '/../services/QrService.php';
require_once __DIR__ . '/../services/AiReviewService.php';
require_once __DIR__ . '/../services/WalletService.php';

final class ResellerController
{
    public function dashboard(): void
    {
        requireResellerLogin();
        $pdo = getPDO();
        $rid = (int)$_SESSION['admin_id'];
        $flash = '';
        $error = '';

        $adminStmt = $pdo->prepare('SELECT id, full_name, email, reseller_wallet_balance FROM admins WHERE id = :id LIMIT 1');
        $adminStmt->execute([':id' => $rid]);
        $admin = $adminStmt->fetch();
        if (!$admin) {
            header('Location: ' . APP_URL . '/logout.php');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'transfer_to_client' && csrfValidateToken($_POST['csrf_token'] ?? null)) {
                $clientId = (int)($_POST['client_id'] ?? 0);
                $amount = max(0, (int)($_POST['amount'] ?? 0));
                $res = ResellerWalletService::transferToClientWallet($pdo, $rid, $clientId, $amount, 'Manual transfer from reseller wallet');
                if ($res === null) {
                    $error = 'Transfer failed. Check client ownership, balance, and amount.';
                } else {
                    $flash = 'Transferred ' . $amount . ' credits. Client new balance: ' . (int)$res['client_balance'];
                    $adminStmt->execute([':id' => $rid]);
                    $admin = $adminStmt->fetch();
                }
            }

            if ($action === 'flush_ai_buffer' && csrfValidateToken($_POST['csrf_token'] ?? null)) {
                $clientId = (int)($_POST['client_id'] ?? 0);
                if ($clientId > 0) {
                    $own = $pdo->prepare('SELECT id FROM clients WHERE id = :cid AND reseller_id = :rid LIMIT 1');
                    $own->execute([':cid' => $clientId, ':rid' => $rid]);
                    if ($own->fetch()) {
                        try {
                            $ai = new AiReviewService($pdo);
                            $r = $ai->flushUnusedBufferAndRefill($clientId);
                            $target = AiReviewService::BUFFER_TARGET;
                            $flash = 'Old buffer cleared. Removed ' . (int)$r['deleted'] . ' unused review(s). '
                                . (int)$r['inserted'] . ' new review(s) generated toward the target of ' . $target . '.';
                            if ((int)$r['inserted'] < $target) {
                                $flash .= ' If the count is still low, check the AI gateway and storage/logs/ai_refill.log.';
                            }
                        } catch (Throwable $e) {
                            $error = 'Flush & regenerate failed: ' . $e->getMessage();
                        }
                    } else {
                        $error = 'That client is not linked to your reseller account.';
                    }
                }
            }
        }

        $clients = $pdo->prepare("
            SELECT c.id, c.business_name, c.email, c.mobile, c.wallet_balance, c.created_at,
                   (SELECT COUNT(*) FROM review_sessions rs WHERE rs.client_id = c.id) AS scan_count
            FROM clients c
            WHERE c.reseller_id = :rid
            ORDER BY c.id DESC
        ");
        $clients->execute([':rid' => $rid]);
        $clientRows = $clients->fetchAll();

        $csrfToken = csrfGenerateToken();
        require __DIR__ . '/../views/reseller/dashboard.php';
    }

    public function addClient(): void
    {
        requireResellerLogin();
        $pdo = getPDO();
        $rid = (int)$_SESSION['admin_id'];
        $flash = '';
        $error = '';

        $categories = $pdo->query("SELECT id, category_name FROM business_categories WHERE is_active = 1 ORDER BY category_name")->fetchAll();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfValidateToken($_POST['csrf_token'] ?? null)) {
            $businessName = trim((string)($_POST['business_name'] ?? ''));
            $ownerName = trim((string)($_POST['owner_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $mobile = trim((string)($_POST['mobile'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $googlePlaceId = trim((string)($_POST['google_place_id'] ?? ''));

            if ($businessName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8
                || $mobile === '' || $address === '' || $categoryId <= 0 || $googlePlaceId === '') {
                $error = 'Please fill all required fields correctly.';
            } else {
                $dup = $pdo->prepare('SELECT id FROM clients WHERE email = :e LIMIT 1');
                $dup->execute([':e' => $email]);
                if ($dup->fetch()) {
                    $error = 'Email already registered.';
                } else {
                    try {
                        $pdo->beginTransaction();
                        $hash = password_hash($password, PASSWORD_BCRYPT);
                        $ins = $pdo->prepare("
                            INSERT INTO clients (business_name, owner_name, email, password_hash, mobile, address, category_id, google_place_id, is_active, created_at, updated_at)
                            VALUES (:b, :o, :e, :p, :m, :a, :c, :g, 1, NOW(), NOW())
                        ");
                        $ins->execute([
                            ':b' => $businessName, ':o' => $ownerName ?: null, ':e' => $email, ':p' => $hash,
                            ':m' => $mobile, ':a' => $address, ':c' => $categoryId, ':g' => $googlePlaceId,
                        ]);
                        $clientId = (int)$pdo->lastInsertId();

                        try {
                            $pdo->prepare('UPDATE clients SET reseller_id = :r WHERE id = :id')->execute([':r' => $rid, ':id' => $clientId]);
                        } catch (Throwable) {
                            throw new RuntimeException('reseller_id column missing — run database migrations.');
                        }

                        $token = QrService::createToken();
                        $publicUrl = APP_URL . '/review.php?t=' . urlencode($token);
                        $filename = 'client_' . $clientId . '_' . time() . '.png';
                        $relative = 'uploads/qr/' . $filename;
                        $absolute = __DIR__ . '/../../public/' . $relative;
                        QrService::generate($publicUrl, $absolute);
                        $pdo->prepare("
                            INSERT INTO client_qr_codes (client_id, qr_token, public_review_url, qr_image_path, is_active, generated_at)
                            VALUES (:c,:t,:u,:p,1,NOW())
                        ")->execute([':c' => $clientId, ':t' => $token, ':u' => $publicUrl, ':p' => $relative]);

                        $trialDays = max(0, (int)getSystemSetting($pdo, 'signup_subscription_trial_days', '30'));
                        if ($trialDays > 0) {
                            try {
                                $pdo->prepare("UPDATE clients SET subscription_valid_until = DATE_ADD(CURDATE(), INTERVAL {$trialDays} DAY) WHERE id = :id")
                                    ->execute([':id' => $clientId]);
                            } catch (Throwable) {
                            }
                        }
                        try {
                            $apiKey = bin2hex(random_bytes(24));
                            $pdo->prepare('UPDATE clients SET api_key = :k WHERE id = :id')->execute([':k' => $apiKey, ':id' => $clientId]);
                        } catch (Throwable) {
                        }

                        $ai = new AiReviewService($pdo);
                        $ai->fillBufferToTarget($clientId, AiReviewService::BUFFER_TARGET);
                        $pdo->prepare("
                            INSERT INTO ai_generation_jobs (client_id, trigger_source, status, requested_count, generated_count, attempt_count, created_at)
                            VALUES (:client_id, 'client_registered', 'queued', :buf, 0, 0, NOW())
                        ")->execute([':client_id' => $clientId, ':buf' => AiReviewService::BUFFER_TARGET]);

                        $pdo->commit();
                        $flash = 'Client created with QR code. They can log in with the email and password you set.';
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $error = 'Could not create client: ' . $e->getMessage();
                    }
                }
            }
        }

        $csrfToken = csrfGenerateToken();
        require __DIR__ . '/../views/reseller/add_client.php';
    }
}
