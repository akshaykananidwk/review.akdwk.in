<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/settings_helper.php';
require_once __DIR__ . '/../helpers/session_helper.php';
require_once __DIR__ . '/../helpers/audit_helper.php';
require_once __DIR__ . '/../services/QrService.php';
require_once __DIR__ . '/../services/AiReviewService.php';
require_once __DIR__ . '/../services/StandeeComposerService.php';
require_once __DIR__ . '/../services/WalletService.php';
require_once __DIR__ . '/../services/WhatsAppService.php';

final class ClientController
{
    public function registerForm(): void
    {
        $pdo = getPDO();
        $categories = $pdo->query("SELECT id, category_name FROM business_categories WHERE is_active = 1 ORDER BY category_name")->fetchAll();
        if ($this->hasFacilitiesCategoryColumn($pdo)) {
            $facilities = $pdo->query("
                SELECT id, facility_name, category_id
                FROM facilities
                WHERE is_active = 1
                ORDER BY facility_name
            ")->fetchAll();
        } else {
            $facilities = $pdo->query("
                SELECT id, facility_name, NULL AS category_id
                FROM facilities
                WHERE is_active = 1
                ORDER BY facility_name
            ")->fetchAll();
        }
        require __DIR__ . '/../views/client/register.php';
    }

    public function registerSubmitAjax(): void
    {
        header('Content-Type: application/json');
        $pdo = getPDO();

        $businessName = trim($_POST['business_name'] ?? '');
        $ownerName = trim($_POST['owner_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $mobile = trim($_POST['mobile'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $googlePlaceId = trim($_POST['google_place_id'] ?? '');
        $selectedFacilities = $_POST['facilities'] ?? [];

        $errors = [];
        if ($businessName === '') $errors[] = 'Business Name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
        if ($mobile === '') $errors[] = 'Mobile number is required.';
        if ($address === '') $errors[] = 'Address is required.';
        if ($categoryId <= 0) $errors[] = 'Please select a category.';
        if ($googlePlaceId === '') $errors[] = 'Google Place ID is required.';

        if ($errors) {
            echo json_encode(['ok' => false, 'errors' => $errors]);
            return;
        }

        $c = $pdo->prepare("SELECT id FROM clients WHERE email = :email LIMIT 1");
        $c->execute([':email' => $email]);
        if ($c->fetch()) {
            echo json_encode(['ok' => false, 'errors' => ['Email already exists.']]);
            return;
        }

        try {
            $pdo->beginTransaction();
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $ins = $pdo->prepare("
                INSERT INTO clients (business_name, owner_name, email, password_hash, mobile, address, category_id, google_place_id, is_active, created_at, updated_at)
                VALUES (:b, :o, :e, :p, :m, :a, :c, :g, 1, NOW(), NOW())
            ");
            $ins->execute([
                ':b' => $businessName, ':o' => $ownerName ?: null, ':e' => $email, ':p' => $passwordHash,
                ':m' => $mobile, ':a' => $address, ':c' => $categoryId, ':g' => $googlePlaceId
            ]);
            $clientId = (int)$pdo->lastInsertId();

            if (is_array($selectedFacilities)) {
                if ($this->hasFacilitiesCategoryColumn($pdo)) {
                    $allowedFacilitiesStmt = $pdo->prepare("
                        SELECT id
                        FROM facilities
                        WHERE is_active = 1
                          AND category_id = :category_id
                    ");
                    $allowedFacilitiesStmt->execute([':category_id' => $categoryId]);
                } else {
                    $allowedFacilitiesStmt = $pdo->prepare("SELECT id FROM facilities WHERE is_active = 1");
                    $allowedFacilitiesStmt->execute();
                }
                $allowedMap = [];
                foreach ($allowedFacilitiesStmt->fetchAll() as $row) {
                    $allowedMap[(int)$row['id']] = true;
                }

                $if = $pdo->prepare("INSERT INTO client_facilities (client_id, facility_id, created_at) VALUES (:c,:f,NOW())");
                foreach ($selectedFacilities as $fId) {
                    $fId = (int)$fId;
                    if ($fId > 0 && isset($allowedMap[$fId])) {
                        $if->execute([':c' => $clientId, ':f' => $fId]);
                    }
                }
            }

            $token = QrService::createToken();
            $publicUrl = APP_URL . '/review.php?t=' . urlencode($token);
            $filename = 'client_' . $clientId . '_' . time() . '.png';
            $relative = 'uploads/qr/' . $filename;
            $absolute = __DIR__ . '/../../public/' . $relative;
            QrService::generate($publicUrl, $absolute);
            $standeeRelative = 'uploads/standee/' . preg_replace('/\.png$/', '_standee.png', $filename);
            $standeeAbsolute = __DIR__ . '/../../public/' . $standeeRelative;
            try {
                $systemNameForStandee = getSystemSetting($pdo, 'system_name', APP_NAME);
                QrService::generateStandee($businessName, $absolute, $standeeAbsolute, $systemNameForStandee);
            } catch (Throwable) {
                $standeeRelative = $relative;
            }

            $iq = $pdo->prepare("INSERT INTO client_qr_codes (client_id, qr_token, public_review_url, qr_image_path, is_active, generated_at) VALUES (:c,:t,:u,:p,1,NOW())");
            $iq->execute([':c' => $clientId, ':t' => $token, ':u' => $publicUrl, ':p' => $relative]);

            $ai = new AiReviewService($pdo);
            // Registration must never hang: do one quick refill attempt and defer remaining gap to background cron.
            $ai->fillBufferToTarget($clientId, AiReviewService::BUFFER_TARGET);
            $pdo->prepare("
                INSERT INTO ai_generation_jobs (client_id, trigger_source, status, requested_count, generated_count, attempt_count, created_at)
                VALUES (:client_id, 'client_registered', 'queued', :buf, 0, 0, NOW())
            ")->execute([':client_id' => $clientId, ':buf' => AiReviewService::BUFFER_TARGET]);

            // ---------- Sign-up bonus (configurable in Global Settings) ----------
            $signupBonus = max(0, (int)getSystemSetting($pdo, 'signup_bonus_amount', '0'));
            $bonusGiven = 0;
            if ($signupBonus > 0) {
                $wallet = new WalletService($pdo);
                $wallet->credit(
                    $clientId,
                    $signupBonus,
                    WalletService::SOURCE_SIGNUP_BONUS,
                    'Welcome bonus credited on registration'
                );
                $bonusGiven = $signupBonus;
            }

            $trialDays = max(0, (int)getSystemSetting($pdo, 'signup_subscription_trial_days', '30'));
            if ($trialDays > 0) {
                try {
                    $pdo->prepare("UPDATE clients SET subscription_valid_until = DATE_ADD(CURDATE(), INTERVAL {$trialDays} DAY) WHERE id = :id")
                        ->execute([':id' => $clientId]);
                } catch (Throwable) {
                    // subscription columns may not exist until migration is applied
                }
            }
            try {
                $apiKey = bin2hex(random_bytes(24));
                $pdo->prepare('UPDATE clients SET api_key = :k WHERE id = :id')->execute([':k' => $apiKey, ':id' => $clientId]);
            } catch (Throwable) {
                // optional column
            }

            $pdo->commit();

            secureSessionStart();
            session_regenerate_id(true);
            $_SESSION['client_id'] = $clientId;
            $_SESSION['client_business_name'] = $businessName;
            $_SESSION['client_email'] = $email;
            unset($_SESSION['admin_id'], $_SESSION['admin_role']);
            try {
                logLoginHistory($pdo, 'client', null, $clientId, $email);
            } catch (Throwable) {
                // non-fatal
            }

            $waMediaRel = $standeeRelative;
            $composed = StandeeComposerService::composeTemplateStandeeForClient($pdo, $clientId);
            if ($composed !== null) {
                $waMediaRel = $composed['relative'];
            }

            // Fire the welcome WhatsApp AFTER the wallet credit is committed
            // so the message reflects the actual credited balance. Failure to
            // dispatch must not break a successful registration response.
            $this->sendRegistrationWelcomeBundle(
                $pdo,
                $mobile,
                $email,
                $businessName,
                $bonusGiven,
                $waMediaRel
            );
            echo json_encode([
                'ok' => true,
                'redirect' => APP_URL . '/dashboard.php',
                'message' => $bonusGiven > 0
                    ? "Registration successful! Sign-up bonus of {$bonusGiven} credits added to your wallet."
                    : 'Registration successful.',
                'data' => [
                    'client_id' => $clientId,
                    'business_name' => $businessName,
                    'signup_bonus' => $bonusGiven,
                    'public_review_url' => $publicUrl,
                    'qr_image_url' => APP_URL . '/' . $relative,
                    'standee_image_url' => APP_URL . '/' . ltrim($waMediaRel, '/')
                ]
            ]);
        } catch (Throwable) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['ok' => false, 'errors' => ['Registration failed. Please try again.']]);
        }
    }

    public function facilitiesByCategoryAjax(): void
    {
        header('Content-Type: application/json');
        $pdo = getPDO();
        $categoryId = (int)($_GET['category_id'] ?? $_POST['category_id'] ?? 0);

        if ($categoryId <= 0) {
            echo json_encode(['ok' => false, 'message' => 'Invalid category.', 'facilities' => []]);
            return;
        }

        if ($this->hasFacilitiesCategoryColumn($pdo)) {
            $stmt = $pdo->prepare("
                SELECT id, facility_name
                FROM facilities
                WHERE is_active = 1 AND category_id = :category_id
                ORDER BY facility_name ASC
            ");
            $stmt->execute([':category_id' => $categoryId]);
        } else {
            $stmt = $pdo->prepare("
                SELECT id, facility_name
                FROM facilities
                WHERE is_active = 1
                ORDER BY facility_name ASC
            ");
            $stmt->execute();
        }

        $facilities = $stmt->fetchAll();
        echo json_encode(['ok' => true, 'facilities' => $facilities]);
    }

    private function hasFacilitiesCategoryColumn(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM facilities LIKE 'category_id'");
            return (bool)$stmt->fetch();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Welcome text (credentials) + optional standee image via WhatsApp.
     * Silent on failure — registration must always succeed for the user.
     */
    private function sendRegistrationWelcomeBundle(
        PDO $pdo,
        string $mobile,
        string $email,
        string $businessName,
        int $bonusGiven,
        string $standeeRelativePath
    ): void {
        try {
            $mobile = trim($mobile);
            if ($mobile === '') {
                return;
            }
            $wa = new WhatsAppService($pdo);
            if (!$wa->isConfigured()) {
                return;
            }

            $systemName = getSystemSetting($pdo, 'system_name', 'Krishna Review System');
            $loginUrl = APP_URL . '/login.php';

            $bonusLine = $bonusGiven > 0
                ? "Sign-up bonus: \xE2\x82\xB9{$bonusGiven} credits added to your wallet."
                : 'Your wallet is ready for pay-per-review usage.';

            $text  = "Jay Dwarkadhish! Welcome to {$systemName}.\n\n";
            $text .= "Your business \"{$businessName}\" is now active.\n\n";
            $text .= "Login: {$loginUrl}\n";
            $text .= "Email: {$email}\n";
            $text .= "Registered mobile: {$mobile}\n\n";
            $text .= "{$bonusLine}\n\n";
            $text .= "— {$systemName}";

            $wa->sendText($mobile, $text);

            $mediaUrl = APP_URL . '/' . ltrim($standeeRelativePath, '/');
            if (is_file(__DIR__ . '/../../public/' . ltrim($standeeRelativePath, '/'))) {
                $caption = "Your print-ready standee (QR + business name) — {$systemName}";
                $wa->sendMedia($mobile, $caption, $mediaUrl);
            }
        } catch (Throwable) {
            // Intentionally silent.
        }
    }
}
