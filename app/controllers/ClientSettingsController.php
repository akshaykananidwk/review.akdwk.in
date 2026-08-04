<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/session_helper.php';
require_once __DIR__ . '/../services/AiReviewService.php';

final class ClientSettingsController
{
    public function index(): void
    {
        requireClientPanelAccess();
        $pdo = getPDO();
        $clientId = (int)$_SESSION['client_id'];
        $flash = '';
        $error = '';
        $demoReview = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string)($_POST['action'] ?? '');

            if ($action === 'update_profile') {
                $businessName = trim((string)($_POST['business_name'] ?? ''));
                $address = trim((string)($_POST['address'] ?? ''));
                $categoryId = (int)($_POST['category_id'] ?? 0);
                $googlePlaceId = trim((string)($_POST['google_place_id'] ?? ''));
                $mobile = trim((string)($_POST['mobile'] ?? ''));
                $extraWhatsapp = trim((string)($_POST['extra_whatsapp_numbers'] ?? ''));
                $dailySummaryOn = isset($_POST['daily_summary_whatsapp_enabled']) && (string)$_POST['daily_summary_whatsapp_enabled'] === '1' ? 1 : 0;
                $reviewTone = trim((string)($_POST['review_tone'] ?? 'Professional'));
                $seoKeywordsRaw = trim((string)($_POST['seo_keywords'] ?? ''));
                $seoKeywordsNormalized = $this->normalizeSeoKeywords($seoKeywordsRaw);
                $selectedFacilities = (array)($_POST['facilities'] ?? []);

                if ($businessName === '' || $address === '' || $categoryId <= 0 || $googlePlaceId === '') {
                    $error = 'Please fill all required fields.';
                } else {
                    try {
                        $pdo->beginTransaction();

                        if ($this->clientsHasWhatsappNotifyColumns($pdo)) {
                            $stmt = $pdo->prepare("
                            UPDATE clients
                            SET business_name = :business_name,
                                address = :address,
                                category_id = :category_id,
                                google_place_id = :google_place_id,
                                mobile = :mobile,
                                extra_whatsapp_numbers = :extra_wa,
                                daily_summary_whatsapp_enabled = :daily_sum,
                                review_tone = :review_tone,
                                seo_keywords = :seo_keywords,
                                updated_at = NOW()
                            WHERE id = :id
                        ");
                            $stmt->execute([
                                ':business_name' => $businessName,
                                ':address' => $address,
                                ':category_id' => $categoryId,
                                ':google_place_id' => $googlePlaceId,
                                ':mobile' => $mobile,
                                ':extra_wa' => $extraWhatsapp !== '' ? $extraWhatsapp : null,
                                ':daily_sum' => $dailySummaryOn,
                                ':review_tone' => $reviewTone !== '' ? $reviewTone : 'Professional',
                                ':seo_keywords' => $seoKeywordsNormalized !== '' ? $seoKeywordsNormalized : null,
                                ':id' => $clientId,
                            ]);
                        } else {
                            $stmt = $pdo->prepare("
                            UPDATE clients
                            SET business_name = :business_name,
                                address = :address,
                                category_id = :category_id,
                                google_place_id = :google_place_id,
                                mobile = :mobile,
                                review_tone = :review_tone,
                                seo_keywords = :seo_keywords,
                                updated_at = NOW()
                            WHERE id = :id
                        ");
                            $stmt->execute([
                                ':business_name' => $businessName,
                                ':address' => $address,
                                ':category_id' => $categoryId,
                                ':google_place_id' => $googlePlaceId,
                                ':mobile' => $mobile,
                                ':review_tone' => $reviewTone !== '' ? $reviewTone : 'Professional',
                                ':seo_keywords' => $seoKeywordsNormalized !== '' ? $seoKeywordsNormalized : null,
                                ':id' => $clientId,
                            ]);
                        }

                        $this->syncClientFacilities($pdo, $clientId, $categoryId, $selectedFacilities);

                        $pdo->commit();
                        $flash = 'Profile updated successfully.';
                    } catch (Throwable) {
                        if ($pdo->inTransaction()) { $pdo->rollBack(); }
                        $error = 'Could not save profile. Please try again.';
                    }
                }
            }

            if ($action === 'regenerate_api_key') {
                if (!$this->clientsHasApiKeyColumn($pdo)) {
                    $error = 'API key feature requires database migration.';
                } else {
                    $newKey = bin2hex(random_bytes(24));
                    $pdo->prepare('UPDATE clients SET api_key = :k, updated_at = NOW() WHERE id = :id')
                        ->execute([':k' => $newKey, ':id' => $clientId]);
                    $flash = 'New API key (copy and store securely): ' . $newKey;
                }
            }

            if ($action === 'preview_demo') {
                try {
                    $ai = new AiReviewService($pdo);
                    $demoReview = $ai->generateRealtimeReviewForClient($clientId) ?? '';
                    if ($demoReview === '') {
                        $error = 'Could not generate demo right now.';
                    } else {
                        $flash = 'Demo review generated.';
                    }
                } catch (Throwable) {
                    $error = 'Could not generate demo right now.';
                }
            }

            if ($action === 'change_password') {
                $currentPassword = (string)($_POST['current_password'] ?? '');
                $newPassword = (string)($_POST['new_password'] ?? '');
                $confirmPassword = (string)($_POST['confirm_password'] ?? '');

                $q = $pdo->prepare("SELECT password_hash FROM clients WHERE id = :id LIMIT 1");
                $q->execute([':id' => $clientId]);
                $row = $q->fetch();

                if (!$row || !password_verify($currentPassword, (string)$row['password_hash'])) {
                    $error = 'Current password is incorrect.';
                } elseif (strlen($newPassword) < 8) {
                    $error = 'New password must be at least 8 characters.';
                } elseif ($newPassword !== $confirmPassword) {
                    $error = 'Confirm password does not match.';
                } else {
                    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
                    $u = $pdo->prepare("UPDATE clients SET password_hash = :hash, updated_at = NOW() WHERE id = :id");
                    $u->execute([':hash' => $newHash, ':id' => $clientId]);
                    $flash = 'Password changed successfully.';
                }
            }
        }

        $categories = $pdo->query("SELECT id, category_name FROM business_categories WHERE is_active = 1 ORDER BY category_name ASC")->fetchAll();

        $apiCol = $this->clientsHasApiKeyColumn($pdo) ? ', api_key' : '';

        if ($this->clientsHasWhatsappNotifyColumns($pdo)) {
            $clientStmt = $pdo->prepare("
            SELECT id, business_name, email, mobile, extra_whatsapp_numbers,
                   COALESCE(daily_summary_whatsapp_enabled, 1) AS daily_summary_whatsapp_enabled,
                   address, category_id, google_place_id, review_tone, seo_keywords, wallet_balance
                   {$apiCol}
            FROM clients
            WHERE id = :id
            LIMIT 1
        ");
        } else {
            $clientStmt = $pdo->prepare("
            SELECT id, business_name, email, mobile, address, category_id, google_place_id, review_tone, seo_keywords, wallet_balance,
                   NULL AS extra_whatsapp_numbers, 1 AS daily_summary_whatsapp_enabled
                   {$apiCol}
            FROM clients
            WHERE id = :id
            LIMIT 1
        ");
        }
        $clientStmt->execute([':id' => $clientId]);
        $client = $clientStmt->fetch();

        if (!$client) {
            header('Location: ' . APP_URL . '/logout.php');
            exit;
        }

        // Facilities the client currently has selected.
        $cf = $pdo->prepare("SELECT facility_id FROM client_facilities WHERE client_id = :id");
        $cf->execute([':id' => $clientId]);
        $selectedFacilityIds = array_map('intval', $cf->fetchAll(PDO::FETCH_COLUMN));

        // Available facilities for the client's current category (for first paint;
        // changing category re-fetches via AJAX from register_facilities.php).
        $facilitiesForCategory = $this->fetchFacilitiesForCategory($pdo, (int)$client['category_id']);

        require __DIR__ . '/../views/client/settings.php';
    }

    /**
     * Replaces the client's facility selection. Only facilities that belong to
     * the current category (when category_id column exists on facilities) and
     * are active are accepted, mirroring registration validation.
     *
     * @param array<int|string> $selectedIds
     */
    private function syncClientFacilities(PDO $pdo, int $clientId, int $categoryId, array $selectedIds): void
    {
        $allowed = [];
        if ($this->hasFacilitiesCategoryColumn($pdo)) {
            $stmt = $pdo->prepare("SELECT id FROM facilities WHERE is_active = 1 AND category_id = :c");
            $stmt->execute([':c' => $categoryId]);
        } else {
            $stmt = $pdo->query("SELECT id FROM facilities WHERE is_active = 1");
        }
        foreach ($stmt->fetchAll() as $row) {
            $allowed[(int)$row['id']] = true;
        }

        $pdo->prepare("DELETE FROM client_facilities WHERE client_id = :id")
            ->execute([':id' => $clientId]);

        if (empty($selectedIds)) {
            return;
        }
        $ins = $pdo->prepare("INSERT INTO client_facilities (client_id, facility_id, created_at) VALUES (:c, :f, NOW())");
        $seen = [];
        foreach ($selectedIds as $raw) {
            $fid = (int)$raw;
            if ($fid <= 0 || isset($seen[$fid]) || !isset($allowed[$fid])) {
                continue;
            }
            $seen[$fid] = true;
            $ins->execute([':c' => $clientId, ':f' => $fid]);
        }
    }

    /**
     * @return array<int,array{id:int,facility_name:string}>
     */
    private function fetchFacilitiesForCategory(PDO $pdo, int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [];
        }
        if ($this->hasFacilitiesCategoryColumn($pdo)) {
            $stmt = $pdo->prepare("
                SELECT id, facility_name
                FROM facilities
                WHERE is_active = 1 AND category_id = :c
                ORDER BY facility_name ASC
            ");
            $stmt->execute([':c' => $categoryId]);
        } else {
            $stmt = $pdo->query("
                SELECT id, facility_name
                FROM facilities
                WHERE is_active = 1
                ORDER BY facility_name ASC
            ");
        }
        return $stmt->fetchAll() ?: [];
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

    private function clientsHasWhatsappNotifyColumns(PDO $pdo): bool
    {
        try {
            return (bool)$pdo->query("SHOW COLUMNS FROM clients LIKE 'extra_whatsapp_numbers'")->fetch();
        } catch (Throwable) {
            return false;
        }
    }

    private function clientsHasApiKeyColumn(PDO $pdo): bool
    {
        try {
            return (bool)$pdo->query("SHOW COLUMNS FROM clients LIKE 'api_key'")->fetch();
        } catch (Throwable) {
            return false;
        }
    }

    private function normalizeSeoKeywords(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $parts = array_map('trim', explode(',', $raw));
        $parts = array_values(array_filter($parts, static fn(string $v): bool => $v !== ''));
        $parts = array_slice($parts, 0, 3);
        return implode(', ', $parts);
    }
}
