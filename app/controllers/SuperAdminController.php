<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/admin_session_helper.php';
require_once __DIR__ . '/../helpers/audit_helper.php';
require_once __DIR__ . '/../helpers/settings_helper.php';
require_once __DIR__ . '/../services/WhatsAppService.php';
require_once __DIR__ . '/../services/WalletService.php';
require_once __DIR__ . '/../services/AiReviewService.php';
require_once __DIR__ . '/../helpers/whatsapp_notify_helper.php';
require_once __DIR__ . '/../helpers/subscription_helper.php';

final class SuperAdminController
{
    public function dashboard(): void
    {
        requireAdminLogin();
        $pdo = getPDO();
        $flash = '';

        $totalClients = (int)$pdo->query("SELECT COUNT(*) c FROM clients")->fetch()['c'];
        $totalActiveClients = (int)$pdo->query("SELECT COUNT(*) c FROM clients WHERE is_active = 1")->fetch()['c'];
        $totalSuspendedClients = (int)$pdo->query("SELECT COUNT(*) c FROM clients WHERE is_active = 0")->fetch()['c'];
        $totalRedirected = (int)$pdo->query("SELECT COUNT(*) c FROM review_sessions WHERE customer_rating IN (4,5) AND flow_type = 'google_redirect'")->fetch()['c'];
        $totalInternalFeedback = (int)$pdo->query("SELECT COUNT(*) c FROM internal_feedback")->fetch()['c'];
        $totalReviewsGenerated = (int)$pdo->query("SELECT COUNT(*) c FROM pre_generated_reviews")->fetch()['c'];

        require __DIR__ . '/../views/admin/super_admin_dashboard.php';
    }

    public function manageBusinesses(): void
    {
        requireAdminLogin();
        $pdo = getPDO();
        $flash = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string)($_POST['action'] ?? '');
            $adminId = (int)$_SESSION['admin_id'];

            if ($action === 'update_client') {
                $clientId = (int)($_POST['client_id'] ?? 0);
                $ownerName = trim((string)($_POST['owner_name'] ?? ''));
                $businessName = trim((string)($_POST['business_name'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $address = trim((string)($_POST['address'] ?? ''));
                $categoryId = (int)($_POST['category_id'] ?? 0);
                $googlePlaceId = trim((string)($_POST['google_place_id'] ?? ''));
                $mobile = trim((string)($_POST['mobile'] ?? ''));
                $customApiKey = trim((string)($_POST['custom_ai_api_key'] ?? ''));
                $reviewModelVersion = trim((string)($_POST['review_model_version'] ?? 'gemini-2.0-flash'));
                $reviewLogicType = trim((string)($_POST['review_logic_type'] ?? 'balanced'));
                $reviewTone = trim((string)($_POST['review_tone'] ?? 'Professional'));
                $isActive = (int)($_POST['is_active'] ?? 1);
                $extraWhatsapp = trim((string)($_POST['extra_whatsapp_numbers'] ?? ''));
                $dailySummaryOn = isset($_POST['daily_summary_whatsapp_enabled']) && (string)$_POST['daily_summary_whatsapp_enabled'] === '1' ? 1 : 0;
                $validUntilInput = trim((string)($_POST['subscription_valid_until'] ?? ''));

                if ($clientId > 0 && $businessName !== '' && $address !== '' && $categoryId > 0 && $googlePlaceId !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $validUntilDb = null;
                    if ($validUntilInput !== '') {
                        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $validUntilInput);
                        if ($parsed === false) {
                            $flash = 'Invalid expiry date. Use YYYY-MM-DD format.';
                        } else {
                            $validUntilDb = $parsed->format('Y-m-d');
                        }
                    }
                    if ($flash === '') {
                    if ($this->clientsHasWhatsappNotifyColumns($pdo)) {
                        $u = $pdo->prepare("
                        UPDATE clients
                        SET business_name = :business_name,
                            owner_name = :owner_name,
                            email = :email,
                            address = :address,
                            category_id = :category_id,
                            google_place_id = :google_place_id,
                            mobile = :mobile,
                            extra_whatsapp_numbers = :extra_wa,
                            daily_summary_whatsapp_enabled = :daily_sum,
                            custom_ai_api_key = :custom_ai_api_key,
                            review_model_version = :review_model_version,
                            review_logic_type = :review_logic_type,
                            review_tone = :review_tone,
                            is_active = :is_active,
                            subscription_valid_until = :valid_until,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                        $u->execute([
                            ':business_name' => $businessName,
                            ':owner_name' => $ownerName !== '' ? $ownerName : null,
                            ':email' => $email,
                            ':address' => $address,
                            ':category_id' => $categoryId,
                            ':google_place_id' => $googlePlaceId,
                            ':mobile' => $mobile,
                            ':extra_wa' => $extraWhatsapp !== '' ? $extraWhatsapp : null,
                            ':daily_sum' => $dailySummaryOn,
                            ':custom_ai_api_key' => $customApiKey !== '' ? $customApiKey : null,
                            ':review_model_version' => $reviewModelVersion !== '' ? $reviewModelVersion : 'gemini-2.0-flash',
                            ':review_logic_type' => $reviewLogicType !== '' ? $reviewLogicType : 'balanced',
                            ':review_tone' => $reviewTone !== '' ? $reviewTone : 'Professional',
                            ':is_active' => $isActive === 1 ? 1 : 0,
                            ':valid_until' => $validUntilDb,
                            ':id' => $clientId,
                        ]);
                    } else {
                        $u = $pdo->prepare("
                        UPDATE clients
                        SET business_name = :business_name,
                            owner_name = :owner_name,
                            email = :email,
                            address = :address,
                            category_id = :category_id,
                            google_place_id = :google_place_id,
                            mobile = :mobile,
                            custom_ai_api_key = :custom_ai_api_key,
                            review_model_version = :review_model_version,
                            review_logic_type = :review_logic_type,
                            review_tone = :review_tone,
                            is_active = :is_active,
                            subscription_valid_until = :valid_until,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                        $u->execute([
                            ':business_name' => $businessName,
                            ':owner_name' => $ownerName !== '' ? $ownerName : null,
                            ':email' => $email,
                            ':address' => $address,
                            ':category_id' => $categoryId,
                            ':google_place_id' => $googlePlaceId,
                            ':mobile' => $mobile,
                            ':custom_ai_api_key' => $customApiKey !== '' ? $customApiKey : null,
                            ':review_model_version' => $reviewModelVersion !== '' ? $reviewModelVersion : 'gemini-2.0-flash',
                            ':review_logic_type' => $reviewLogicType !== '' ? $reviewLogicType : 'balanced',
                            ':review_tone' => $reviewTone !== '' ? $reviewTone : 'Professional',
                            ':is_active' => $isActive === 1 ? 1 : 0,
                            ':valid_until' => $validUntilDb,
                            ':id' => $clientId,
                        ]);
                    }
                    logAdminActivity($pdo, $adminId, 'UPDATE_CLIENT', 'client', $clientId, 'Updated client business profile');
                    $flash = 'Business updated successfully.';
                    }
                } else {
                    $flash = 'Please fill all required business fields.';
                }
            }

            if ($action === 'toggle_client') {
                $clientId = (int)($_POST['client_id'] ?? 0);
                if ($clientId > 0) {
                    $current = $pdo->prepare("SELECT is_active FROM clients WHERE id = :id LIMIT 1");
                    $current->execute([':id' => $clientId]);
                    $row = $current->fetch();
                    if ($row) {
                        $newStatus = ((int)$row['is_active'] === 1) ? 0 : 1;
                        $pdo->prepare("UPDATE clients SET is_active = :status, updated_at = NOW() WHERE id = :id")
                            ->execute([':status' => $newStatus, ':id' => $clientId]);
                        $actionName = $newStatus === 1 ? 'ACTIVATE_CLIENT' : 'SUSPEND_CLIENT';
                        logAdminActivity($pdo, $adminId, $actionName, 'client', $clientId, 'Toggled client active status');
                        $flash = $newStatus === 1 ? 'Business activated.' : 'Business suspended.';
                    }
                }
            }

            if ($action === 'delete_client') {
                $clientId = (int)($_POST['client_id'] ?? 0);
                if ($clientId > 0) {
                    $pdo->prepare("DELETE FROM clients WHERE id = :id")->execute([':id' => $clientId]);
                    logAdminActivity($pdo, $adminId, 'DELETE_CLIENT', 'client', $clientId, 'Deleted client and related data');
                    $flash = 'Business deleted successfully.';
                }
            }

            if ($action === 'impersonate_client') {
                $clientId = (int)($_POST['client_id'] ?? 0);
                if ($clientId > 0) {
                    $s = $pdo->prepare("SELECT id, business_name, email, is_active FROM clients WHERE id = :id LIMIT 1");
                    $s->execute([':id' => $clientId]);
                    $client = $s->fetch();
                    if ($client && (int)$client['is_active'] === 1) {
                        $_SESSION['impersonator_admin_id'] = $adminId;
                        $_SESSION['client_id'] = (int)$client['id'];
                        $_SESSION['client_business_name'] = (string)$client['business_name'];
                        $_SESSION['client_email'] = (string)$client['email'];
                        logAdminActivity($pdo, $adminId, 'IMPERSONATE_CLIENT', 'client', (int)$client['id'], 'Admin logged in as client');
                        header('Location: ' . APP_URL . '/dashboard.php');
                        exit;
                    }
                    $flash = 'Only active clients can be impersonated.';
                }
            }

            if ($action === 'flush_ai_buffer') {
                $clientId = (int)($_POST['client_id'] ?? 0);
                if ($clientId > 0) {
                    $exists = $pdo->prepare('SELECT id FROM clients WHERE id = :id LIMIT 1');
                    $exists->execute([':id' => $clientId]);
                    if ($exists->fetch()) {
                        try {
                            $ai = new AiReviewService($pdo);
                            $r = $ai->flushUnusedBufferAndRefill($clientId);
                            logAdminActivity(
                                $pdo,
                                $adminId,
                                'FLUSH_AI_REVIEW_BUFFER',
                                'client',
                                $clientId,
                                'Cleared unused pre-generated reviews and refilled buffer'
                            );
                            $target = AiReviewService::BUFFER_TARGET;
                            $flash = 'Old buffer cleared. Removed ' . (int)$r['deleted'] . ' unused review(s). '
                                . (int)$r['inserted'] . ' new review(s) generated toward the target of ' . $target . '.';
                            if ((int)$r['inserted'] < $target) {
                                $flash .= ' If the count is still low, check the AI gateway and storage/logs/ai_refill.log.';
                            }
                        } catch (Throwable $e) {
                            $flash = 'Flush & regenerate failed: ' . $e->getMessage();
                        }
                    } else {
                        $flash = 'Business not found.';
                    }
                }
            }
        }

        $categories = $pdo->query("SELECT id, category_name FROM business_categories ORDER BY category_name ASC")->fetchAll();
        try {
            $clients = $pdo->query("
            SELECT c.id, c.business_name, c.owner_name, c.email, c.mobile, c.extra_whatsapp_numbers,
                   COALESCE(c.daily_summary_whatsapp_enabled, 1) AS daily_summary_whatsapp_enabled,
                   c.address, c.google_place_id,
                   c.custom_ai_api_key, c.review_model_version, c.review_logic_type, c.review_tone,
                   c.wallet_balance, c.subscription_valid_until, c.is_active, c.created_at, bc.category_name, c.category_id
            FROM clients c
            INNER JOIN business_categories bc ON bc.id = c.category_id
            ORDER BY c.created_at DESC
        ")->fetchAll();
        } catch (Throwable) {
            $clients = $pdo->query("
            SELECT c.id, c.business_name, c.owner_name, c.email, c.mobile, c.address, c.google_place_id,
                   c.custom_ai_api_key, c.review_model_version, c.review_logic_type, c.review_tone,
                   c.wallet_balance, c.subscription_valid_until, c.is_active, c.created_at, bc.category_name, c.category_id,
                   NULL AS extra_whatsapp_numbers, 1 AS daily_summary_whatsapp_enabled
            FROM clients c
            INNER JOIN business_categories bc ON bc.id = c.category_id
            ORDER BY c.created_at DESC
        ")->fetchAll();
        }

        require __DIR__ . '/../views/admin/manage_businesses.php';
    }

    public function clientWallet(): void
    {
        requireAdminLogin();
        $pdo = getPDO();
        $adminId = (int)$_SESSION['admin_id'];
        $flash = '';
        $error = '';

        $clientId = (int)($_GET['client_id'] ?? $_POST['client_id'] ?? 0);
        if ($clientId <= 0) {
            header('Location: ' . APP_URL . '/admin_businesses.php');
            exit;
        }

        $clientStmt = $pdo->prepare("
            SELECT c.id, c.business_name, c.owner_name, c.email, c.mobile, c.wallet_balance, c.is_active,
                   c.subscription_valid_until, c.created_at, bc.category_name
            FROM clients c
            INNER JOIN business_categories bc ON bc.id = c.category_id
            WHERE c.id = :id
            LIMIT 1
        ");
        $clientStmt->execute([':id' => $clientId]);
        $client = $clientStmt->fetch();
        if (!$client) {
            header('Location: ' . APP_URL . '/admin_businesses.php');
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string)($_POST['action'] ?? '');
            $amount = (int)($_POST['amount'] ?? 0);
            $description = trim((string)($_POST['description'] ?? ''));
            $wallet = new WalletService($pdo);

            if ($action === 'admin_topup' && $amount > 0) {
                $validityMode = (string)($_POST['validity_mode'] ?? 'extend_days');
                $validityDays = max(0, (int)($_POST['validity_days'] ?? 0));
                $validityUntilDate = trim((string)($_POST['validity_until_date'] ?? ''));
                $validUntilApplied = '';
                try {
                    $pdo->beginTransaction();
                    $newBal = $wallet->credit(
                        $clientId,
                        $amount,
                        WalletService::SOURCE_ADMIN_TOPUP,
                        $description !== '' ? $description : 'Manual top-up by admin',
                        $adminId
                    );
                    if ($newBal === null) {
                        $pdo->rollBack();
                        $error = 'Could not credit wallet. Please try again.';
                    } else {
                        $validUntilApplied = applyAdminTopupValidity($pdo, $clientId, $validityMode, $validityDays, $validityUntilDate) ?? '';
                        $pdo->commit();
                        logAdminActivity($pdo, $adminId, 'WALLET_CREDIT', 'client', $clientId, "Credited {$amount} credits");
                        $validMsg = $validUntilApplied !== '' ? ' Valid until ' . $validUntilApplied . '.' : '';
                        $flash = "Credited ₹{$amount} ({$amount} credits). New balance: ₹{$newBal}.{$validMsg} WhatsApp notification sent if gateway is configured.";
                        $client['wallet_balance'] = $newBal;
                        if ($validUntilApplied !== '') {
                            $client['subscription_valid_until'] = $validUntilApplied;
                        }
                        $this->notifyClientWalletAdminCreditWhatsApp(
                            $pdo,
                            $clientId,
                            $amount,
                            $newBal,
                            $description !== '' ? $description : 'Manual top-up by admin',
                            $validUntilApplied
                        );
                    }
                } catch (Throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'Could not complete top-up. Please try again.';
                }
            } elseif ($action === 'admin_deduct' && $amount > 0) {
                $newBal = $wallet->debit(
                    $clientId,
                    $amount,
                    WalletService::SOURCE_ADMIN_DEDUCT,
                    $description !== '' ? $description : 'Manual adjustment by admin',
                    null,
                    $adminId
                );
                if ($newBal !== null) {
                    logAdminActivity($pdo, $adminId, 'WALLET_DEBIT', 'client', $clientId, "Debited {$amount} credits");
                    $flash = "Debited {$amount} credits successfully. New balance: {$newBal}.";
                    $client['wallet_balance'] = $newBal;
                } else {
                    $error = 'Could not debit wallet (insufficient balance or error).';
                }
            } elseif ($action !== '') {
                $error = 'Please enter a valid amount.';
            }
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;
        $wallet = new WalletService($pdo);
        $rows = $wallet->getHistory($clientId, $perPage, $offset);
        $total = $wallet->countHistory($clientId);
        $totalPages = max(1, (int)ceil($total / $perPage));

        require __DIR__ . '/../views/admin/client_wallet.php';
    }

    public function auditLogs(): void
    {
        requireAdminLogin();
        $pdo = getPDO();

        $loginHistory = $pdo->query("
            SELECT lh.user_type, lh.email, lh.ip_address, lh.login_at
            FROM login_history lh
            ORDER BY lh.login_at DESC
            LIMIT 100
        ")->fetchAll();

        $registrationReport = $pdo->query("
            SELECT c.business_name, c.email, c.mobile, c.created_at, bc.category_name, c.is_active
            FROM clients c
            INNER JOIN business_categories bc ON bc.id = c.category_id
            ORDER BY c.created_at DESC
            LIMIT 200
        ")->fetchAll();

        $adminActivities = $pdo->query("
            SELECT aal.action, aal.target_type, aal.target_id, aal.description, aal.ip_address, aal.created_at, a.email AS admin_email
            FROM admin_activity_logs aal
            INNER JOIN admins a ON a.id = aal.admin_id
            ORDER BY aal.created_at DESC
            LIMIT 100
        ")->fetchAll();

        require __DIR__ . '/../views/admin/audit_logs.php';
    }

    public function categoryFacilities(): void
    {
        requireAdminLogin();
        $pdo = getPDO();
        $flash = '';
        $adminId = (int)$_SESSION['admin_id'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string)($_POST['action'] ?? '');

            if ($action === 'add_category') {
                $categoryName = trim((string)($_POST['category_name'] ?? ''));
                if ($categoryName === '') {
                    $flash = 'Category name is required.';
                } else {
                    $exists = $pdo->prepare("SELECT id FROM business_categories WHERE category_name = :n LIMIT 1");
                    $exists->execute([':n' => $categoryName]);
                    if ($exists->fetch()) {
                        $flash = 'A category with this name already exists.';
                    } else {
                        $ins = $pdo->prepare("INSERT INTO business_categories (category_name, is_active, created_at) VALUES (:n, 1, NOW())");
                        $ins->execute([':n' => $categoryName]);
                        logAdminActivity($pdo, $adminId, 'ADD_CATEGORY', 'category', (int)$pdo->lastInsertId(), 'Added category: ' . $categoryName);
                        $flash = 'Category added successfully.';
                    }
                }
            }

            if ($action === 'update_category') {
                $categoryId = (int)($_POST['category_id'] ?? 0);
                $categoryName = trim((string)($_POST['category_name'] ?? ''));
                $isActive = (int)($_POST['is_active'] ?? 1);
                if ($categoryId > 0 && $categoryName !== '') {
                    $u = $pdo->prepare("UPDATE business_categories SET category_name = :n, is_active = :a WHERE id = :id");
                    $u->execute([
                        ':n' => $categoryName,
                        ':a' => $isActive === 1 ? 1 : 0,
                        ':id' => $categoryId,
                    ]);
                    logAdminActivity($pdo, $adminId, 'UPDATE_CATEGORY', 'category', $categoryId, 'Updated category');
                    $flash = 'Category updated.';
                } else {
                    $flash = 'Provide category name and id.';
                }
            }

            if ($action === 'delete_category') {
                $categoryId = (int)($_POST['category_id'] ?? 0);
                if ($categoryId > 0) {
                    $cs = $pdo->prepare("SELECT COUNT(*) c FROM clients WHERE category_id = :id");
                    $cs->execute([':id' => $categoryId]);
                    $clientCount = (int)($cs->fetch()['c'] ?? 0);

                    $facilityCount = 0;
                    if ($this->hasFacilitiesCategoryColumn($pdo)) {
                        $fs = $pdo->prepare("SELECT COUNT(*) c FROM facilities WHERE category_id = :id");
                        $fs->execute([':id' => $categoryId]);
                        $facilityCount = (int)($fs->fetch()['c'] ?? 0);
                    }

                    if ($clientCount > 0) {
                        $flash = 'Cannot delete: this category still has ' . $clientCount . ' linked business(es). Reassign first.';
                    } elseif ($facilityCount > 0) {
                        $flash = 'Cannot delete: this category still has ' . $facilityCount . ' linked facility/ies. Delete or move them first.';
                    } else {
                        try {
                            $pdo->prepare("DELETE FROM business_categories WHERE id = :id")
                                ->execute([':id' => $categoryId]);
                            logAdminActivity($pdo, $adminId, 'DELETE_CATEGORY', 'category', $categoryId, 'Deleted category');
                            $flash = 'Category deleted successfully.';
                        } catch (Throwable $e) {
                            $flash = 'Could not delete category. It may still be referenced.';
                        }
                    }
                }
            }

            if ($action === 'add_facility') {
                $categoryId = (int)($_POST['category_id'] ?? 0);
                $facilityName = trim((string)($_POST['facility_name'] ?? ''));
                if ($categoryId > 0 && $facilityName !== '') {
                    if ($this->hasFacilitiesCategoryColumn($pdo)) {
                        $stmt = $pdo->prepare("
                            INSERT INTO facilities (category_id, facility_name, is_active, created_at)
                            VALUES (:category_id, :facility_name, 1, NOW())
                        ");
                        $stmt->execute([
                            ':category_id' => $categoryId,
                            ':facility_name' => $facilityName,
                        ]);
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO facilities (facility_name, is_active, created_at)
                            VALUES (:facility_name, 1, NOW())
                        ");
                        $stmt->execute([
                            ':facility_name' => $facilityName,
                        ]);
                    }
                    logAdminActivity($pdo, $adminId, 'ADD_FACILITY', 'facility', (int)$pdo->lastInsertId(), 'Added category-specific facility');
                    $flash = 'Facility added successfully.';
                } else {
                    $flash = 'Select category and enter facility name.';
                }
            }

            if ($action === 'update_facility') {
                $facilityId = (int)($_POST['facility_id'] ?? 0);
                $categoryId = (int)($_POST['category_id'] ?? 0);
                $facilityName = trim((string)($_POST['facility_name'] ?? ''));
                $isActive = (int)($_POST['is_active'] ?? 1);
                if ($facilityId > 0 && $categoryId > 0 && $facilityName !== '') {
                    if ($this->hasFacilitiesCategoryColumn($pdo)) {
                        $stmt = $pdo->prepare("
                            UPDATE facilities
                            SET category_id = :category_id,
                                facility_name = :facility_name,
                                is_active = :is_active
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':category_id' => $categoryId,
                            ':facility_name' => $facilityName,
                            ':is_active' => $isActive === 1 ? 1 : 0,
                            ':id' => $facilityId,
                        ]);
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE facilities
                            SET facility_name = :facility_name,
                                is_active = :is_active
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':facility_name' => $facilityName,
                            ':is_active' => $isActive === 1 ? 1 : 0,
                            ':id' => $facilityId,
                        ]);
                    }
                    logAdminActivity($pdo, $adminId, 'UPDATE_FACILITY', 'facility', $facilityId, 'Updated category-specific facility');
                    $flash = 'Facility updated.';
                } else {
                    $flash = 'Fill required fields for update.';
                }
            }

            if ($action === 'delete_facility') {
                $facilityId = (int)($_POST['facility_id'] ?? 0);
                if ($facilityId > 0) {
                    try {
                        $pdo->prepare("DELETE FROM client_facilities WHERE facility_id = :id")
                            ->execute([':id' => $facilityId]);
                        $pdo->prepare("DELETE FROM facilities WHERE id = :id")
                            ->execute([':id' => $facilityId]);
                        logAdminActivity($pdo, $adminId, 'DELETE_FACILITY', 'facility', $facilityId, 'Deleted facility');
                        $flash = 'Facility deleted successfully.';
                    } catch (Throwable $e) {
                        $flash = 'Could not delete facility. It may still be referenced.';
                    }
                }
            }
        }

        $categories = $pdo->query("SELECT id, category_name FROM business_categories WHERE is_active = 1 ORDER BY category_name ASC")->fetchAll();

        $hasFacilityCategoryColumn = $this->hasFacilitiesCategoryColumn($pdo);
        $allCategories = $pdo->query("
            SELECT bc.id, bc.category_name, bc.is_active,
                   (SELECT COUNT(*) FROM clients c WHERE c.category_id = bc.id) AS client_count" .
                   ($hasFacilityCategoryColumn
                       ? ", (SELECT COUNT(*) FROM facilities f WHERE f.category_id = bc.id) AS facility_count"
                       : ", 0 AS facility_count") . "
            FROM business_categories bc
            ORDER BY bc.category_name ASC
        ")->fetchAll();

        if ($hasFacilityCategoryColumn) {
            $facilities = $pdo->query("
                SELECT f.id, f.facility_name, f.category_id, f.is_active, bc.category_name
                FROM facilities f
                LEFT JOIN business_categories bc ON bc.id = f.category_id
                ORDER BY bc.category_name ASC, f.facility_name ASC
            ")->fetchAll();
        } else {
            $facilities = $pdo->query("
                SELECT f.id, f.facility_name, NULL AS category_id, f.is_active, NULL AS category_name
                FROM facilities f
                ORDER BY f.facility_name ASC
            ")->fetchAll();
        }

        require __DIR__ . '/../views/admin/category_facilities.php';
    }

    public function standeeTemplates(): void
    {
        requireAdminLogin();
        $pdo = getPDO();
        $flash = '';
        $error = '';
        $adminId = (int)$_SESSION['admin_id'];
        $appUrl = defined('APP_URL') ? APP_URL : '';

        $hasBoxColumns = $this->standeeTemplatesHasBoxColumns($pdo);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string)($_POST['action'] ?? '');

            if ($action === 'upload_template') {
                $title = trim((string)($_POST['title'] ?? ''));
                if (empty($_FILES['template_image']['tmp_name']) || !is_uploaded_file($_FILES['template_image']['tmp_name'])) {
                    $flash = 'Choose an image file.';
                } elseif ((int)($_FILES['template_image']['size'] ?? 0) > 12 * 1024 * 1024) {
                    $flash = 'Image must be 12 MB or smaller.';
                } else {
                    $ext = strtolower(pathinfo((string)($_FILES['template_image']['name'] ?? 'bg.png'), PATHINFO_EXTENSION));
                    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
                        $flash = 'Upload PNG, JPG, or WebP only.';
                    } else {
                        $dir = __DIR__ . '/../../public/uploads/standee_templates';
                        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                            $flash = 'Could not create upload directory.';
                        } else {
                            $fileName = 'tpl_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                            $dest = $dir . '/' . $fileName;
                            if (move_uploaded_file($_FILES['template_image']['tmp_name'], $dest)) {
                                $rel = 'uploads/standee_templates/' . $fileName;

                                // Read native pixel dimensions and pre-compute a sensible
                                // centred square QR box so the editor opens with a usable default.
                                [$nW, $nH] = $this->probeImageSize($dest);
                                $defaultSize = max(80, (int)round(min($nW, $nH) * 0.40));
                                $defaultX = max(0, (int)round(($nW - $defaultSize) / 2));
                                $defaultY = max(0, (int)round(($nH - $defaultSize) / 2));

                                $sortStmt = $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM standee_templates');
                                $sort = (int)($sortStmt->fetch()['n'] ?? 1);

                                if ($hasBoxColumns) {
                                    if ($this->standeeTemplatesHasBusinessNameColumns($pdo)) {
                                        $bnw = max(200, $nW - 80);
                                        $bnh = min(180, max(100, (int)round($nH * 0.14)));
                                        $stmt = $pdo->prepare('
                                        INSERT INTO standee_templates
                                          (title, image_path, qr_pos_x, qr_pos_y, qr_width, qr_height, native_width, native_height,
                                           business_name_enabled, business_name_pos_x, business_name_pos_y, business_name_box_w, business_name_box_h, business_name_font_pt, business_name_color,
                                           sort_order, is_active, created_at, updated_at)
                                        VALUES
                                          (:title, :path, :qx, :qy, :qw, :qh, :nw, :nh,
                                           1, 40, 40, :bnw, :bnh, 40, :bnc,
                                           :sort, 1, NOW(), NOW())
                                    ');
                                        $stmt->execute([
                                            ':title' => $title !== '' ? $title : null,
                                            ':path'  => $rel,
                                            ':qx'    => $defaultX,
                                            ':qy'    => $defaultY,
                                            ':qw'    => $defaultSize,
                                            ':qh'    => $defaultSize,
                                            ':nw'    => $nW,
                                            ':nh'    => $nH,
                                            ':bnw'   => $bnw,
                                            ':bnh'   => $bnh,
                                            ':bnc'   => '#0f172a',
                                            ':sort'  => $sort,
                                        ]);
                                    } else {
                                        $stmt = $pdo->prepare('
                                        INSERT INTO standee_templates
                                          (title, image_path, qr_pos_x, qr_pos_y, qr_width, qr_height, native_width, native_height, sort_order, is_active, created_at, updated_at)
                                        VALUES
                                          (:title, :path, :qx, :qy, :qw, :qh, :nw, :nh, :sort, 1, NOW(), NOW())
                                    ');
                                        $stmt->execute([
                                            ':title' => $title !== '' ? $title : null,
                                            ':path'  => $rel,
                                            ':qx'    => $defaultX,
                                            ':qy'    => $defaultY,
                                            ':qw'    => $defaultSize,
                                            ':qh'    => $defaultSize,
                                            ':nw'    => $nW,
                                            ':nh'    => $nH,
                                            ':sort'  => $sort,
                                        ]);
                                    }
                                } else {
                                    $stmt = $pdo->prepare('
                                        INSERT INTO standee_templates (title, image_path, sort_order, is_active, created_at, updated_at)
                                        VALUES (:title, :path, :sort, 1, NOW(), NOW())
                                    ');
                                    $stmt->execute([
                                        ':title' => $title !== '' ? $title : null,
                                        ':path'  => $rel,
                                        ':sort'  => $sort,
                                    ]);
                                }
                                $newId = (int)$pdo->lastInsertId();
                                logAdminActivity($pdo, $adminId, 'ADD_STANDEE_TEMPLATE', 'standee_templates', $newId, 'Uploaded standee template image');

                                if ($hasBoxColumns) {
                                    header('Location: ' . $appUrl . '/admin_standee_templates.php?edit=' . $newId . '&msg=' . rawurlencode('Template uploaded — drag the QR box to set its position.'));
                                    exit;
                                }
                                $flash = 'Template uploaded successfully. Run the QR-box migration to enable drag-and-drop positioning.';
                            } else {
                                $flash = 'Could not save uploaded file.';
                            }
                        }
                    }
                }
            }

            if ($action === 'save_box' && $hasBoxColumns) {
                $tid = (int)($_POST['template_id'] ?? 0);
                $qx  = (int)($_POST['qr_pos_x']  ?? 0);
                $qy  = (int)($_POST['qr_pos_y']  ?? 0);
                $qw  = (int)($_POST['qr_width']  ?? 0);
                $qh  = (int)($_POST['qr_height'] ?? 0);
                $title = trim((string)($_POST['title'] ?? ''));

                if ($tid <= 0) {
                    $error = 'Invalid template id.';
                } elseif ($qw < 20 || $qh < 20) {
                    $error = 'QR box is too small (minimum 20×20 px).';
                } else {
                    $stmt = $pdo->prepare('SELECT image_path, native_width, native_height FROM standee_templates WHERE id = :id LIMIT 1');
                    $stmt->execute([':id' => $tid]);
                    $row = $stmt->fetch();
                    if (!$row) {
                        $error = 'Template not found.';
                    } else {
                        $nW = (int)$row['native_width'];
                        $nH = (int)$row['native_height'];
                        if ($nW <= 0 || $nH <= 0) {
                            $abs = __DIR__ . '/../../public/' . ltrim((string)$row['image_path'], '/');
                            [$nW, $nH] = $this->probeImageSize($abs);
                        }

                        $qx = max(0, min($qx, max(0, $nW - 1)));
                        $qy = max(0, min($qy, max(0, $nH - 1)));
                        $qw = max(20, min($qw, $nW - $qx));
                        $qh = max(20, min($qh, $nH - $qy));

                        $hasBnCols = $this->standeeTemplatesHasBusinessNameColumns($pdo);
                        if ($hasBnCols) {
                            $ben = isset($_POST['business_name_enabled']) && (string)$_POST['business_name_enabled'] === '1' ? 1 : 0;
                            $bnx = max(0, (int)($_POST['business_name_pos_x'] ?? 0));
                            $bny = max(0, (int)($_POST['business_name_pos_y'] ?? 0));
                            $bnw = max(40, (int)($_POST['business_name_box_w'] ?? 0));
                            $bnh = max(30, (int)($_POST['business_name_box_h'] ?? 0));
                            $bnf = max(10, min(200, (int)($_POST['business_name_font_pt'] ?? 36)));
                            $bnc = trim((string)($_POST['business_name_color'] ?? '#0f172a'));
                            if ($bnc === '' || !preg_match('/^#?[0-9a-fA-F]{3,8}$/', ltrim($bnc, '#'))) {
                                $bnc = '#0f172a';
                            }
                            if ($bnc[0] !== '#') {
                                $bnc = '#' . $bnc;
                            }
                            if (strlen($bnc) === 4) {
                                $bnc = '#' . $bnc[1] . $bnc[1] . $bnc[2] . $bnc[2] . $bnc[3] . $bnc[3];
                            }
                            $bnw = min($bnw, max(40, $nW - $bnx));
                            $bnh = min($bnh, max(30, $nH - $bny));

                            $upd = $pdo->prepare('
                            UPDATE standee_templates
                               SET title = :title,
                                   qr_pos_x = :qx, qr_pos_y = :qy,
                                   qr_width = :qw, qr_height = :qh,
                                   native_width = :nw, native_height = :nh,
                                   business_name_enabled = :ben,
                                   business_name_pos_x = :bnx,
                                   business_name_pos_y = :bny,
                                   business_name_box_w = :bnw,
                                   business_name_box_h = :bnh,
                                   business_name_font_pt = :bnf,
                                   business_name_color = :bnc,
                                   updated_at = NOW()
                             WHERE id = :id
                        ');
                            $upd->execute([
                                ':title' => $title !== '' ? $title : null,
                                ':qx'    => $qx,
                                ':qy'    => $qy,
                                ':qw'    => $qw,
                                ':qh'    => $qh,
                                ':nw'    => $nW,
                                ':nh'    => $nH,
                                ':ben'   => $ben,
                                ':bnx'   => $bnx,
                                ':bny'   => $bny,
                                ':bnw'   => $bnw,
                                ':bnh'   => $bnh,
                                ':bnf'   => $bnf,
                                ':bnc'   => substr($bnc, 0, 16),
                                ':id'    => $tid,
                            ]);
                        } else {
                            $upd = $pdo->prepare('
                            UPDATE standee_templates
                               SET title = :title,
                                   qr_pos_x = :qx, qr_pos_y = :qy,
                                   qr_width = :qw, qr_height = :qh,
                                   native_width = :nw, native_height = :nh,
                                   updated_at = NOW()
                             WHERE id = :id
                        ');
                            $upd->execute([
                                ':title' => $title !== '' ? $title : null,
                                ':qx'    => $qx,
                                ':qy'    => $qy,
                                ':qw'    => $qw,
                                ':qh'    => $qh,
                                ':nw'    => $nW,
                                ':nh'    => $nH,
                                ':id'    => $tid,
                            ]);
                        }
                        logAdminActivity($pdo, $adminId, 'UPDATE_STANDEE_TEMPLATE_BOX', 'standee_templates', $tid, sprintf('QR box set to %dx%d at (%d,%d)', $qw, $qh, $qx, $qy));
                        header('Location: ' . $appUrl . '/admin_standee_templates.php?edit=' . $tid . '&msg=' . rawurlencode('QR position saved.'));
                        exit;
                    }
                }
            }

            if ($action === 'delete_template') {
                $tid = (int)($_POST['template_id'] ?? 0);
                if ($tid > 0) {
                    $st = $pdo->prepare('SELECT image_path FROM standee_templates WHERE id = :id LIMIT 1');
                    $st->execute([':id' => $tid]);
                    $row = $st->fetch();
                    $pdo->prepare('DELETE FROM standee_templates WHERE id = :id')->execute([':id' => $tid]);
                    if ($row && !empty($row['image_path'])) {
                        $abs = __DIR__ . '/../../public/' . ltrim((string)$row['image_path'], '/');
                        if (is_file($abs)) {
                            @unlink($abs);
                        }
                    }
                    logAdminActivity($pdo, $adminId, 'DELETE_STANDEE_TEMPLATE', 'standee_templates', $tid, 'Deleted standee template');
                    $flash = 'Template removed.';
                }
            }

            if ($action === 'toggle_template') {
                $tid = (int)($_POST['template_id'] ?? 0);
                if ($tid > 0) {
                    $pdo->prepare('UPDATE standee_templates SET is_active = 1 - is_active, updated_at = NOW() WHERE id = :id')
                        ->execute([':id' => $tid]);
                    logAdminActivity($pdo, $adminId, 'TOGGLE_STANDEE_TEMPLATE', 'standee_templates', $tid, 'Toggled template visibility');
                    $flash = 'Template updated.';
                }
            }
        }

        // Allow flash/error messages from redirects (POST -> GET pattern).
        if ($flash === '' && isset($_GET['msg'])) {
            $flash = (string)$_GET['msg'];
        }
        if ($error === '' && isset($_GET['err'])) {
            $error = (string)$_GET['err'];
        }

        // ----- Edit (drag/resize) view -----
        $editId = (int)($_GET['edit'] ?? 0);
        if ($editId > 0 && $hasBoxColumns) {
            $row = null;
            try {
                $stmt = $pdo->prepare('SELECT * FROM standee_templates WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $editId]);
                $row = $stmt->fetch();
            } catch (Throwable) {
                $row = null;
            }
            if (!$row) {
                header('Location: ' . $appUrl . '/admin_standee_templates.php?err=' . rawurlencode('Template not found.'));
                exit;
            }

            $nW = (int)$row['native_width'];
            $nH = (int)$row['native_height'];
            if ($nW <= 0 || $nH <= 0) {
                $abs = __DIR__ . '/../../public/' . ltrim((string)$row['image_path'], '/');
                [$nW, $nH] = $this->probeImageSize($abs);
                if ($nW > 0 && $nH > 0) {
                    $pdo->prepare('UPDATE standee_templates SET native_width = :w, native_height = :h WHERE id = :id')
                        ->execute([':w' => $nW, ':h' => $nH, ':id' => $editId]);
                    $row['native_width']  = $nW;
                    $row['native_height'] = $nH;
                }
            }

            // Backfill defaults if the row predates the QR box columns.
            if ((int)$row['qr_width'] <= 0 || (int)$row['qr_height'] <= 0) {
                $defaultSize = max(80, (int)round(min(max(1, $nW), max(1, $nH)) * 0.40));
                $row['qr_width']  = $defaultSize;
                $row['qr_height'] = $defaultSize;
                $row['qr_pos_x']  = max(0, (int)round((max(1, $nW) - $defaultSize) / 2));
                $row['qr_pos_y']  = max(0, (int)round((max(1, $nH) - $defaultSize) / 2));
            }

            $template = $row;
            require __DIR__ . '/../views/admin/standee_template_edit.php';
            return;
        }

        // ----- List view -----
        try {
            $sql = $hasBoxColumns
                ? 'SELECT id, title, image_path, qr_pos_x, qr_pos_y, qr_width, qr_height, native_width, native_height, sort_order, is_active, created_at
                     FROM standee_templates ORDER BY sort_order ASC, id DESC'
                : 'SELECT id, title, image_path, sort_order, is_active, created_at
                     FROM standee_templates ORDER BY sort_order ASC, id DESC';
            $templates = $pdo->query($sql)->fetchAll();
        } catch (Throwable) {
            $templates = [];
            if ($flash === '') {
                $flash = 'Standee templates table not found. Import database/migrations/2026_05_09_standee_templates.sql';
            }
        }

        if (!$hasBoxColumns && $error === '') {
            $error = 'Drag-and-drop QR positioning is disabled. Run database/migrations/2026_05_09_standee_template_qr_box.sql to enable it.';
        }

        require __DIR__ . '/../views/admin/standee_templates.php';
    }

    private function standeeTemplatesHasBoxColumns(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM standee_templates LIKE 'qr_pos_x'");
            return (bool)$stmt->fetch();
        } catch (Throwable) {
            return false;
        }
    }

    private function standeeTemplatesHasBusinessNameColumns(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM standee_templates LIKE 'business_name_enabled'");
            return (bool)$stmt->fetch();
        } catch (Throwable) {
            return false;
        }
    }

    private function clientsHasWhatsappNotifyColumns(PDO $pdo): bool
    {
        try {
            return (bool)$pdo->query("SHOW COLUMNS FROM clients LIKE 'extra_whatsapp_numbers'")->fetch();
        } catch (Throwable) {
            return false;
        }
    }

    private function notifyClientWalletAdminCreditWhatsApp(
        PDO $pdo,
        int $clientId,
        int $creditedAmount,
        int $newBalance,
        string $reason,
        string $validUntil = ''
    ): void {
        try {
            $client = whatsapp_fetch_client_row_for_notify($pdo, $clientId);
            if (!$client || trim((string)($client['mobile'] ?? '')) === '') {
                return;
            }
            $wa = new WhatsAppService($pdo);
            if (!$wa->isConfigured()) {
                return;
            }
            $systemName = getSystemSetting($pdo, 'system_name', 'Krishna Review System');
            if ($validUntil === '') {
                $vu = $pdo->prepare('SELECT subscription_valid_until FROM clients WHERE id = :id LIMIT 1');
                $vu->execute([':id' => $clientId]);
                $vrow = $vu->fetch();
                $validUntil = trim((string)($vrow['subscription_valid_until'] ?? ''));
            }
            $lines = [
                'Jay Dwarkadhish ' . trim((string)($client['business_name'] ?? 'Business')) . '! 🙏',
                '',
                '✅ Your account has been recharged by admin.',
                '',
                '➕ Amount credited: ₹' . $creditedAmount . ' (' . $creditedAmount . ' credits)',
                '💰 New wallet balance: ₹' . $newBalance,
            ];
            if ($validUntil !== '') {
                $lines[] = '📅 Plan valid until: ' . $validUntil;
            }
            $lines[] = '';
            $lines[] = 'Login: ' . APP_URL . '/login.php';
            $lines[] = '';
            $lines[] = '— ' . $systemName;
            $body = implode("\n", $lines);
            $extra = isset($client['extra_whatsapp_numbers']) ? (string)$client['extra_whatsapp_numbers'] : '';
            foreach (whatsapp_collect_notify_numbers($pdo, (string)$client['mobile'], $extra !== '' ? $extra : null) as $num) {
                $wa->sendText($num, $body);
                usleep(120000);
            }
        } catch (Throwable) {
            // best-effort only
        }
    }

    /**
     * @return array{0:int,1:int}
     */
    private function probeImageSize(string $absolutePath): array
    {
        if (!is_file($absolutePath)) {
            return [0, 0];
        }
        $info = @getimagesize($absolutePath);
        if (!is_array($info) || (int)($info[0] ?? 0) <= 0 || (int)($info[1] ?? 0) <= 0) {
            return [0, 0];
        }
        return [(int)$info[0], (int)$info[1]];
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

    public function globalSettings(): void
    {
        requireAdminLogin();
        $pdo = getPDO();
        $flash = '';
        $whatsappTestResult = null;
        $adminId = (int)$_SESSION['admin_id'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'save_global_settings') {
                $systemName = trim((string)($_POST['system_name'] ?? 'Krishna Review System'));
                $supportMobile = trim((string)($_POST['support_mobile'] ?? ''));
                $helplineNumber = trim((string)($_POST['helpline_number'] ?? ''));
                $masterApiKey = trim((string)($_POST['master_api_key'] ?? ''));
                $pricePerReview = max(1, (int)($_POST['price_per_review'] ?? 1));
                $signupBonusAmount = max(0, (int)($_POST['signup_bonus_amount'] ?? 0));
                $signupSubscriptionTrialDays = max(0, (int)($_POST['signup_subscription_trial_days'] ?? 30));
                $defaultWelcomeStandeeTpl = (int)($_POST['default_welcome_standee_template_id'] ?? 0);
                $reviewInviteTpl = trim((string)($_POST['review_invite_message_template'] ?? ''));
                if ($reviewInviteTpl === '') {
                    $reviewInviteTpl = 'Hi {name}, thank you for visiting us. Please rate your experience: {link}';
                }
                $howSteps = [
                    trim((string)($_POST['step1'] ?? '')),
                    trim((string)($_POST['step2'] ?? '')),
                    trim((string)($_POST['step3'] ?? '')),
                ];
                $howSteps = array_values(array_filter($howSteps, static fn(string $s): bool => $s !== ''));
                if (empty($howSteps)) {
                    $howSteps = ['Customer scans business QR', 'Chooses star rating', 'Gets guided Google review flow'];
                }

                upsertSystemSetting($pdo, 'system_name', $systemName !== '' ? $systemName : 'Krishna Review System', 'string', $adminId);
                upsertSystemSetting($pdo, 'support_mobile', $supportMobile, 'string', $adminId);
                upsertSystemSetting($pdo, 'helpline_number', $helplineNumber, 'string', $adminId);
                if ($masterApiKey !== '') {
                    upsertSystemSetting($pdo, 'master_api_key', $masterApiKey, 'string', $adminId);
                }
                upsertSystemSetting($pdo, 'price_per_review', (string)$pricePerReview, 'int', $adminId);
                upsertSystemSetting($pdo, 'signup_bonus_amount', (string)$signupBonusAmount, 'int', $adminId);
                upsertSystemSetting($pdo, 'signup_subscription_trial_days', (string)$signupSubscriptionTrialDays, 'int', $adminId);
                upsertSystemSetting($pdo, 'default_welcome_standee_template_id', (string)$defaultWelcomeStandeeTpl, 'int', $adminId);
                upsertSystemSetting($pdo, 'review_invite_message_template', $reviewInviteTpl, 'string', $adminId);
                upsertSystemSetting($pdo, 'homepage_how_it_works', json_encode($howSteps, JSON_UNESCAPED_SLASHES), 'json', $adminId);

                if (!empty($_FILES['global_logo']['tmp_name']) && is_uploaded_file($_FILES['global_logo']['tmp_name'])) {
                    $ext = strtolower(pathinfo((string)($_FILES['global_logo']['name'] ?? 'logo.png'), PATHINFO_EXTENSION));
                    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
                        $ext = 'png';
                    }
                    $dir = __DIR__ . '/../../public/uploads/settings';
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    $fileName = 'global_logo_' . time() . '.' . $ext;
                    $dest = $dir . '/' . $fileName;
                    if (move_uploaded_file($_FILES['global_logo']['tmp_name'], $dest)) {
                        upsertSystemSetting($pdo, 'global_logo_path', 'uploads/settings/' . $fileName, 'string', $adminId);
                    }
                }

                $flash = 'Global settings saved successfully.';
            }

            if ($action === 'save_razorpay_settings') {
                $rzpEnabled       = (int)(($_POST['razorpay_enabled'] ?? '0') === '1' ? 1 : 0);
                $rzpMode          = trim((string)($_POST['razorpay_mode'] ?? 'test'));
                $rzpKeyId         = trim((string)($_POST['razorpay_key_id'] ?? ''));
                $rzpKeySecret     = trim((string)($_POST['razorpay_key_secret'] ?? ''));
                $rzpWebhookSecret = trim((string)($_POST['razorpay_webhook_secret'] ?? ''));
                if (!in_array($rzpMode, ['test', 'live'], true)) { $rzpMode = 'test'; }

                upsertSystemSetting($pdo, 'razorpay_enabled',        (string)$rzpEnabled,    'bool',   $adminId);
                upsertSystemSetting($pdo, 'razorpay_mode',           $rzpMode,               'string', $adminId);
                upsertSystemSetting($pdo, 'razorpay_key_id',         $rzpKeyId,              'string', $adminId);
                if ($rzpKeySecret !== '') {
                    upsertSystemSetting($pdo, 'razorpay_key_secret', $rzpKeySecret,          'string', $adminId);
                }
                if ($rzpWebhookSecret !== '') {
                    upsertSystemSetting($pdo, 'razorpay_webhook_secret', $rzpWebhookSecret,  'string', $adminId);
                }
                logAdminActivity($pdo, $adminId, 'UPDATE_RAZORPAY_SETTINGS', 'system_settings', null, 'Updated Razorpay configuration');
                $flash = 'Razorpay settings saved.';
            }

            if ($action === 'save_landing_cms') {
                $headline    = trim((string)($_POST['landing_hero_headline'] ?? ''));
                $subheadline = trim((string)($_POST['landing_hero_subheadline'] ?? ''));
                $videoUrl    = trim((string)($_POST['landing_hero_video_url'] ?? ''));
                $offset      = (int)($_POST['landing_live_counter_offset'] ?? 0);
                $demoToken   = trim((string)($_POST['landing_demo_qr_token'] ?? ''));
                $showPricing = (int)(($_POST['landing_show_pricing'] ?? '0') === '1' ? 1 : 0);

                $rawTestimonials = trim((string)($_POST['landing_testimonials'] ?? '[]'));
                $decoded = json_decode($rawTestimonials, true);
                if (!is_array($decoded)) { $decoded = []; }
                // Normalise each testimonial.
                $clean = [];
                foreach ($decoded as $t) {
                    if (!is_array($t)) { continue; }
                    $clean[] = [
                        'name'     => substr((string)($t['name'] ?? ''), 0, 80),
                        'business' => substr((string)($t['business'] ?? ''), 0, 120),
                        'quote'    => substr((string)($t['quote'] ?? ''), 0, 400),
                        'rating'   => max(1, min(5, (int)($t['rating'] ?? 5))),
                    ];
                }

                upsertSystemSetting($pdo, 'landing_hero_headline',       $headline,    'string', $adminId);
                upsertSystemSetting($pdo, 'landing_hero_subheadline',    $subheadline, 'string', $adminId);
                upsertSystemSetting($pdo, 'landing_hero_video_url',      $videoUrl,    'string', $adminId);
                upsertSystemSetting($pdo, 'landing_live_counter_offset', (string)$offset, 'int', $adminId);
                upsertSystemSetting($pdo, 'landing_demo_qr_token',       $demoToken,   'string', $adminId);
                upsertSystemSetting($pdo, 'landing_show_pricing',        (string)$showPricing, 'bool', $adminId);
                upsertSystemSetting($pdo, 'landing_testimonials',        json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'json', $adminId);

                logAdminActivity($pdo, $adminId, 'UPDATE_LANDING_CMS', 'system_settings', null, 'Updated public landing page CMS');
                $flash = 'Landing page content saved.';
            }

            if ($action === 'save_whatsapp_settings') {
                $endpoint     = trim((string)($_POST['whatsapp_endpoint'] ?? ''));
                $apiKey       = trim((string)($_POST['whatsapp_api_key'] ?? ''));
                $sessionId    = trim((string)($_POST['whatsapp_session_id'] ?? ''));
                $instanceId   = trim((string)($_POST['whatsapp_instance_id'] ?? ''));
                $token        = trim((string)($_POST['whatsapp_token'] ?? ''));
                $senderName   = trim((string)($_POST['whatsapp_sender_name'] ?? ''));
                $countryCode  = trim((string)($_POST['whatsapp_country_code'] ?? '91'));
                if ($countryCode === '') { $countryCode = '91'; }

                upsertSystemSetting($pdo, 'whatsapp_endpoint',    $endpoint !== '' ? $endpoint : 'https://bulk.akdwk.in/api.php', 'string', $adminId);
                upsertSystemSetting($pdo, 'whatsapp_api_key',     $apiKey,     'string', $adminId);
                upsertSystemSetting($pdo, 'whatsapp_session_id',  $sessionId,  'string', $adminId);
                upsertSystemSetting($pdo, 'whatsapp_instance_id', $instanceId, 'string', $adminId);
                upsertSystemSetting($pdo, 'whatsapp_token',       $token,      'string', $adminId);
                upsertSystemSetting($pdo, 'whatsapp_sender_name', $senderName, 'string', $adminId);
                upsertSystemSetting($pdo, 'whatsapp_country_code',$countryCode,'string', $adminId);

                logAdminActivity($pdo, $adminId, 'UPDATE_WHATSAPP_SETTINGS', 'system_settings', null, 'Updated WhatsApp gateway configuration');
                $flash = 'WhatsApp gateway settings saved.';
            }

            if ($action === 'send_whatsapp_test') {
                $testMobile = trim((string)($_POST['test_mobile'] ?? ''));
                $testMessage = trim((string)($_POST['test_message'] ?? ''));
                if ($testMobile === '' || $testMessage === '') {
                    $whatsappTestResult = ['ok' => false, 'message' => 'Provide both mobile and message.'];
                } else {
                    $wa = new WhatsAppService($pdo);
                    $resp = $wa->sendText($testMobile, $testMessage);
                    if ($resp['ok']) {
                        $whatsappTestResult = ['ok' => true, 'message' => 'Test message dispatched (HTTP ' . $resp['status'] . ').'];
                        logAdminActivity($pdo, $adminId, 'WHATSAPP_TEST', 'system_settings', null, 'Sent WhatsApp test to ' . $testMobile);
                    } else {
                        $whatsappTestResult = ['ok' => false, 'message' => 'Failed: ' . ($resp['error'] ?? 'unknown') . ' (HTTP ' . $resp['status'] . ')'];
                    }
                }
            }

            if ($action === 'add_slider' && !empty($_FILES['slider_image']['tmp_name']) && is_uploaded_file($_FILES['slider_image']['tmp_name'])) {
                $ext = strtolower(pathinfo((string)($_FILES['slider_image']['name'] ?? 'slide.png'), PATHINFO_EXTENSION));
                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
                    $ext = 'png';
                }
                $dir = __DIR__ . '/../../public/uploads/sliders';
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $fileName = 'slider_' . time() . '_' . random_int(1000, 9999) . '.' . $ext;
                $dest = $dir . '/' . $fileName;
                if (move_uploaded_file($_FILES['slider_image']['tmp_name'], $dest)) {
                    $sortOrder = (int)($_POST['sort_order'] ?? 0);
                    $stmt = $pdo->prepare("INSERT INTO homepage_sliders (image_path, sort_order, is_active, created_at) VALUES (:p, :s, 1, NOW())");
                    $stmt->execute([':p' => 'uploads/sliders/' . $fileName, ':s' => $sortOrder]);
                    $flash = 'Slider added successfully.';
                }
            }

            if ($action === 'delete_slider') {
                $sliderId = (int)($_POST['slider_id'] ?? 0);
                if ($sliderId > 0) {
                    $stmt = $pdo->prepare("SELECT image_path FROM homepage_sliders WHERE id = :id LIMIT 1");
                    $stmt->execute([':id' => $sliderId]);
                    $row = $stmt->fetch();
                    $pdo->prepare("DELETE FROM homepage_sliders WHERE id = :id")->execute([':id' => $sliderId]);
                    if ($row && !empty($row['image_path'])) {
                        $abs = __DIR__ . '/../../public/' . $row['image_path'];
                        if (is_file($abs)) {
                            @unlink($abs);
                        }
                    }
                    $flash = 'Slider deleted.';
                }
            }
        }

        $systemName = getSystemSetting($pdo, 'system_name', APP_NAME);
        $supportMobile = getSystemSetting($pdo, 'support_mobile', '');
        $helplineNumber = getSystemSetting($pdo, 'helpline_number', $supportMobile);
        $masterApiKey = getSystemSetting($pdo, 'master_api_key', BootstrapEnv::envString('GAPI_API_KEY', ''));
        $pricePerReview = max(1, (int)getSystemSetting($pdo, 'price_per_review', '1'));
        $signupBonusAmount = max(0, (int)getSystemSetting($pdo, 'signup_bonus_amount', '0'));
        $signupSubscriptionTrialDays = max(0, (int)getSystemSetting($pdo, 'signup_subscription_trial_days', '30'));
        $defaultStandeeTemplateId = (int)getSystemSetting($pdo, 'default_welcome_standee_template_id', '0');
        $reviewInviteMessageTemplate = getSystemSetting(
            $pdo,
            'review_invite_message_template',
            'Hi {name}, thank you for visiting us. Please rate your experience: {link}'
        );
        try {
            $standeeTemplatesForSelect = $pdo->query('
                SELECT id, title, is_active FROM standee_templates ORDER BY sort_order ASC, id ASC
            ')->fetchAll();
        } catch (Throwable) {
            $standeeTemplatesForSelect = [];
        }
        $globalLogoPath = getSystemSetting($pdo, 'global_logo_path', '');
        $howItWorksRaw = getSystemSetting($pdo, 'homepage_how_it_works', '[]');
        $howItWorks = json_decode($howItWorksRaw, true);
        if (!is_array($howItWorks) || empty($howItWorks)) {
            $howItWorks = ['Customer scans business QR', 'Chooses star rating', 'Gets guided Google review flow'];
        }

        // WhatsApp gateway settings exposed to view.
        $whatsappEndpoint    = getSystemSetting($pdo, 'whatsapp_endpoint', 'https://bulk.akdwk.in/api.php');
        $whatsappApiKey      = getSystemSetting($pdo, 'whatsapp_api_key', '');
        $whatsappSessionId   = getSystemSetting($pdo, 'whatsapp_session_id', '');
        $whatsappInstanceId  = getSystemSetting($pdo, 'whatsapp_instance_id', '');
        $whatsappToken       = getSystemSetting($pdo, 'whatsapp_token', '');
        $whatsappSenderName  = getSystemSetting($pdo, 'whatsapp_sender_name', $systemName);
        $whatsappCountryCode = getSystemSetting($pdo, 'whatsapp_country_code', '91');
        $whatsappConfigured  = ($whatsappEndpoint !== '' && $whatsappApiKey !== '' && $whatsappSessionId !== '');

        $sliders = $pdo->query("SELECT id, image_path, sort_order, is_active, created_at FROM homepage_sliders WHERE is_active = 1 ORDER BY sort_order ASC, id DESC")->fetchAll();

        // Razorpay credentials (key secret + webhook secret are write-only blanks).
        $razorpayEnabled       = (int)getSystemSetting($pdo, 'razorpay_enabled', '0') === 1;
        $razorpayMode          = getSystemSetting($pdo, 'razorpay_mode', 'test');
        $razorpayKeyId         = getSystemSetting($pdo, 'razorpay_key_id', '');
        $razorpayKeySecretSet  = getSystemSetting($pdo, 'razorpay_key_secret', '') !== '';
        $razorpayWebhookSet    = getSystemSetting($pdo, 'razorpay_webhook_secret', '') !== '';

        // Landing CMS.
        $landingHeadline    = getSystemSetting($pdo, 'landing_hero_headline', 'Get More 5-Star Google Reviews — Automatically.');
        $landingSubheadline = getSystemSetting($pdo, 'landing_hero_subheadline', '');
        $landingVideoUrl    = getSystemSetting($pdo, 'landing_hero_video_url', '');
        $landingCounterOffset = (int)getSystemSetting($pdo, 'landing_live_counter_offset', '0');
        $landingDemoToken   = getSystemSetting($pdo, 'landing_demo_qr_token', '');
        $landingShowPricing = (int)getSystemSetting($pdo, 'landing_show_pricing', '1') === 1;
        $landingTestimonialsRaw = getSystemSetting($pdo, 'landing_testimonials', '[]');
        $landingTestimonials = json_decode($landingTestimonialsRaw, true);
        if (!is_array($landingTestimonials)) { $landingTestimonials = []; }

        require __DIR__ . '/../views/admin/global_settings.php';
    }

    /**
     * Admin: Recharge Plans CRUD.
     */
    public function paymentPlans(): void
    {
        requireAdminLogin();
        $pdo = getPDO();
        $flash = '';
        $error = '';
        $adminId = (int)$_SESSION['admin_id'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string)($_POST['action'] ?? '');

            if ($action === 'create_plan' || $action === 'update_plan') {
                $planId       = (int)($_POST['plan_id'] ?? 0);
                $name         = trim((string)($_POST['name'] ?? ''));
                $description  = trim((string)($_POST['description'] ?? ''));
                $priceInr     = max(1, (int)($_POST['price_inr'] ?? 0));
                $credits      = max(1, (int)($_POST['credits'] ?? 0));
                $bonusCredits = max(0, (int)($_POST['bonus_credits'] ?? 0));
                $durationDays = max(1, (int)($_POST['duration_days'] ?? 30));
                $isPopular    = (int)(($_POST['is_popular'] ?? '0') === '1' ? 1 : 0);
                $isActive     = (int)(($_POST['is_active']  ?? '0') === '1' ? 1 : 0);
                $sortOrder    = (int)($_POST['sort_order'] ?? 0);
                $hasValidityCol = paymentPlansHaveDurationDays($pdo);

                if ($name === '' || $priceInr <= 0 || $credits <= 0) {
                    $error = 'Name, price and credits are required.';
                } elseif ($action === 'create_plan') {
                    if ($hasValidityCol) {
                        $stmt = $pdo->prepare("
                            INSERT INTO payment_plans
                              (name, description, price_inr, credits, bonus_credits, duration_days, is_popular, is_active, sort_order, created_at, updated_at)
                            VALUES
                              (:name, :desc, :price, :credits, :bonus, :days, :pop, :active, :sort, NOW(), NOW())
                        ");
                        $stmt->execute([
                            ':name' => $name, ':desc' => $description !== '' ? $description : null,
                            ':price' => $priceInr, ':credits' => $credits, ':bonus' => $bonusCredits,
                            ':days' => $durationDays,
                            ':pop' => $isPopular, ':active' => $isActive, ':sort' => $sortOrder,
                        ]);
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO payment_plans
                              (name, description, price_inr, credits, bonus_credits, is_popular, is_active, sort_order, created_at, updated_at)
                            VALUES
                              (:name, :desc, :price, :credits, :bonus, :pop, :active, :sort, NOW(), NOW())
                        ");
                        $stmt->execute([
                            ':name' => $name, ':desc' => $description !== '' ? $description : null,
                            ':price' => $priceInr, ':credits' => $credits, ':bonus' => $bonusCredits,
                            ':pop' => $isPopular, ':active' => $isActive, ':sort' => $sortOrder,
                        ]);
                    }
                    logAdminActivity($pdo, $adminId, 'CREATE_PAYMENT_PLAN', 'payment_plans', (int)$pdo->lastInsertId(), 'Created plan ' . $name);
                    $flash = 'Plan created successfully.';
                } else {
                    if ($hasValidityCol) {
                        $stmt = $pdo->prepare("
                            UPDATE payment_plans
                            SET name = :name, description = :desc, price_inr = :price, credits = :credits,
                                bonus_credits = :bonus, duration_days = :days, is_popular = :pop, is_active = :active,
                                sort_order = :sort, updated_at = NOW()
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':name' => $name, ':desc' => $description !== '' ? $description : null,
                            ':price' => $priceInr, ':credits' => $credits, ':bonus' => $bonusCredits,
                            ':days' => $durationDays,
                            ':pop' => $isPopular, ':active' => $isActive, ':sort' => $sortOrder,
                            ':id' => $planId,
                        ]);
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE payment_plans
                            SET name = :name, description = :desc, price_inr = :price, credits = :credits,
                                bonus_credits = :bonus, is_popular = :pop, is_active = :active, sort_order = :sort,
                                updated_at = NOW()
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':name' => $name, ':desc' => $description !== '' ? $description : null,
                            ':price' => $priceInr, ':credits' => $credits, ':bonus' => $bonusCredits,
                            ':pop' => $isPopular, ':active' => $isActive, ':sort' => $sortOrder,
                            ':id' => $planId,
                        ]);
                    }
                    logAdminActivity($pdo, $adminId, 'UPDATE_PAYMENT_PLAN', 'payment_plans', $planId, 'Updated plan ' . $name);
                    $flash = 'Plan updated successfully.';
                }
            }

            if ($action === 'toggle_plan') {
                $planId = (int)($_POST['plan_id'] ?? 0);
                $cur = $pdo->prepare("SELECT is_active FROM payment_plans WHERE id = :id LIMIT 1");
                $cur->execute([':id' => $planId]);
                $row = $cur->fetch();
                if ($row) {
                    $newStatus = (int)$row['is_active'] === 1 ? 0 : 1;
                    $pdo->prepare("UPDATE payment_plans SET is_active = :s, updated_at = NOW() WHERE id = :id")
                        ->execute([':s' => $newStatus, ':id' => $planId]);
                    logAdminActivity($pdo, $adminId, 'TOGGLE_PAYMENT_PLAN', 'payment_plans', $planId, "Set is_active={$newStatus}");
                    $flash = $newStatus === 1 ? 'Plan activated.' : 'Plan deactivated.';
                }
            }

            if ($action === 'delete_plan') {
                $planId = (int)($_POST['plan_id'] ?? 0);
                if ($planId > 0) {
                    $pdo->prepare("DELETE FROM payment_plans WHERE id = :id")->execute([':id' => $planId]);
                    logAdminActivity($pdo, $adminId, 'DELETE_PAYMENT_PLAN', 'payment_plans', $planId, 'Deleted plan');
                    $flash = 'Plan deleted.';
                }
            }
        }

        $hasValidityCol = paymentPlansHaveDurationDays($pdo);
        $plans = $pdo->query($hasValidityCol
            ? "SELECT id, name, description, price_inr, credits, bonus_credits, duration_days, is_popular, is_active, sort_order, created_at
               FROM payment_plans ORDER BY sort_order ASC, price_inr ASC"
            : "SELECT id, name, description, price_inr, credits, bonus_credits, 30 AS duration_days, is_popular, is_active, sort_order, created_at
               FROM payment_plans ORDER BY sort_order ASC, price_inr ASC"
        )->fetchAll();

        $totalPaid    = (int)$pdo->query("SELECT COALESCE(SUM(amount_inr),0) s FROM payment_transactions WHERE status = 'paid'")->fetch()['s'];
        $countPaid    = (int)$pdo->query("SELECT COUNT(*) c FROM payment_transactions WHERE status = 'paid'")->fetch()['c'];
        $countAttempts = (int)$pdo->query("SELECT COUNT(*) c FROM payment_transactions")->fetch()['c'];

        require __DIR__ . '/../views/admin/payment_plans.php';
    }

    /** @deprecated Subscription plans merged into Recharge Plans (validity days). */
    public function subscriptionPlans(): void
    {
        requireAdminLogin();
        header('Location: ' . APP_URL . '/admin_payment_plans.php');
        exit;
    }

    public function resellerWallet(): void
    {
        requireAdminLogin();
        $pdo = getPDO();
        $flash = '';
        $error = '';
        $adminId = (int)$_SESSION['admin_id'];

        require_once __DIR__ . '/../services/ResellerWalletService.php';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'credit_reseller') {
                $rid = (int)($_POST['reseller_admin_id'] ?? 0);
                $amount = max(0, (int)($_POST['amount'] ?? 0));
                $note = trim((string)($_POST['note'] ?? ''));
                if ($rid <= 0 || $amount <= 0) {
                    $error = 'Select reseller and positive amount.';
                } else {
                    $chk = $pdo->prepare("SELECT id FROM admins WHERE id = :id AND role = 'reseller' LIMIT 1");
                    $chk->execute([':id' => $rid]);
                    if (!$chk->fetch()) {
                        $error = 'Invalid reseller.';
                    } else {
                        $nb = ResellerWalletService::creditResellerWallet($pdo, $rid, $amount, $adminId, $note !== '' ? $note : null);
                        if ($nb === null) {
                            $error = 'Could not credit reseller wallet.';
                        } else {
                            logAdminActivity($pdo, $adminId, 'RESELLER_WALLET_TOPUP', 'admins', $rid, 'Credited ' . $amount);
                            $flash = 'Reseller wallet credited. New balance: ' . $nb;
                        }
                    }
                }
            }
        }

        try {
            $resellers = $pdo->query("SELECT id, full_name, email, reseller_wallet_balance FROM admins WHERE role = 'reseller' ORDER BY id ASC")->fetchAll();
        } catch (Throwable) {
            $resellers = [];
        }

        require __DIR__ . '/../views/admin/reseller_wallet.php';
    }

    public function createReseller(): void
    {
        requireAdminLogin();
        $pdo = getPDO();
        $flash = '';
        $error = '';
        $adminId = (int)$_SESSION['admin_id'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name = trim((string)($_POST['full_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Valid name and email are required.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
            } else {
                $dup = $pdo->prepare('SELECT id FROM admins WHERE email = :e LIMIT 1');
                $dup->execute([':e' => $email]);
                if ($dup->fetch()) {
                    $error = 'That email is already registered.';
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    try {
                        $ins = $pdo->prepare("
                            INSERT INTO admins (full_name, email, password_hash, role, is_active, created_at, updated_at)
                            VALUES (:n, :e, :p, 'reseller', 1, NOW(), NOW())
                        ");
                        $ins->execute([':n' => $name, ':e' => $email, ':p' => $hash]);
                        $newId = (int)$pdo->lastInsertId();
                        try {
                            $pdo->prepare('UPDATE admins SET reseller_wallet_balance = COALESCE(reseller_wallet_balance, 0) WHERE id = :id')->execute([':id' => $newId]);
                        } catch (Throwable) {
                            // reseller_wallet_balance column may not exist on older schemas
                        }
                        logAdminActivity($pdo, $adminId, 'CREATE_RESELLER', 'admins', $newId, 'Created reseller ' . $email);
                        $flash = 'Reseller account created. They can sign in at the main login page with this email and password.';
                    } catch (Throwable) {
                        $error = 'Could not create reseller. Ensure migrations are applied (admins.role must allow reseller).';
                    }
                }
            }
        }

        require __DIR__ . '/../views/admin/create_reseller.php';
    }
}
