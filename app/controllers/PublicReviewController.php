<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/csrf_helper.php';
require_once __DIR__ . '/../helpers/settings_helper.php';
require_once __DIR__ . '/../services/AiReviewService.php';
require_once __DIR__ . '/../helpers/subscription_helper.php';
require_once __DIR__ . '/../services/WalletService.php';

final class PublicReviewController
{
    public function showReviewPage(): void
    {
        $token = trim($_GET['t'] ?? '');
        if ($token === '') {
            http_response_code(400);
            exit('Invalid review link.');
        }

        $ip = $this->getIpAddress() ?? '0.0.0.0';

        $pdo = getPDO();
        $s = $pdo->prepare("
            SELECT q.id qr_id, q.client_id, q.is_active qr_active, c.business_name, c.google_place_id, c.is_active client_active
            FROM client_qr_codes q
            INNER JOIN clients c ON c.id = q.client_id
            WHERE q.qr_token = :t
            LIMIT 1
        ");
        $s->execute([':t' => $token]);
        $row = $s->fetch();
        if (!$row || (int)$row['qr_active'] !== 1 || (int)$row['client_active'] !== 1) {
            http_response_code(404);
            exit('This review link is invalid or inactive.');
        }

        if (!clientHasActiveSubscription($pdo, (int)$row['client_id'])) {
            http_response_code(403);
            exit('This review link is temporarily unavailable because the business validity has expired. Please ask the owner to recharge.');
        }

        $businessName = $row['business_name'];
        $googleReviewUrl = 'https://search.google.com/local/writereview?placeid=' . urlencode($row['google_place_id']);
        $sessionUuid = $this->createUuidV4();

        // Branding & lead-generation values for the public footer.
        $systemName = getSystemSetting($pdo, 'system_name', defined('APP_NAME') ? APP_NAME : 'Krishna Review System');
        $helplineNumber = getSystemSetting($pdo, 'helpline_number', getSystemSetting($pdo, 'support_mobile', ''));
        $helplineTel = preg_replace('/[^0-9+]/', '', $helplineNumber) ?? '';

        $ins = $pdo->prepare("
            INSERT INTO review_sessions (session_uuid, client_id, qr_code_id, flow_type, ip_address, user_agent, device_type, created_at)
            VALUES (:u,:c,:q,'pending',:ip,:ua,:d,NOW())
        ");
        $ins->execute([
            ':u' => $sessionUuid,
            ':c' => (int)$row['client_id'],
            ':q' => (int)$row['qr_id'],
            ':ip' => $ip,
            ':ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 1000),
            ':d' => $this->detectDeviceType($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ]);

        $csrfToken = csrfGenerateToken();
        require __DIR__ . '/../views/public/review.php';
    }

    public function handleRatingAjax(): void
    {
        header('Content-Type: application/json');
        $pdo = getPDO();
        $sessionUuid = trim($_POST['session_uuid'] ?? '');
        $rating = (int)($_POST['rating'] ?? 0);
        if ($sessionUuid === '' || $rating < 1 || $rating > 5) {
            echo json_encode(['ok' => false, 'message' => 'Invalid rating payload.']);
            return;
        }

        $s = $pdo->prepare("SELECT id FROM review_sessions WHERE session_uuid = :u LIMIT 1");
        $s->execute([':u' => $sessionUuid]);
        $session = $s->fetch();
        if (!$session) {
            echo json_encode(['ok' => false, 'message' => 'Session not found.']);
            return;
        }

        $flowType = $rating <= 3 ? 'internal_feedback' : 'google_redirect';
        $u = $pdo->prepare("UPDATE review_sessions SET customer_rating = :r, flow_type = :f, rating_submitted_at = NOW() WHERE id = :id");
        $u->execute([':r' => $rating, ':f' => $flowType, ':id' => (int)$session['id']]);

        echo json_encode(['ok' => true, 'path' => $rating <= 3 ? 'feedback' : 'positive']);
    }

    public function submitFeedbackAjax(): void
    {
        header('Content-Type: application/json');
        $pdo = getPDO();
        $sessionUuid = trim($_POST['session_uuid'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        $feedbackText = trim($_POST['feedback_text'] ?? '');
        if ($sessionUuid === '' || $feedbackText === '') {
            echo json_encode(['ok' => false, 'message' => 'Feedback text is required.']);
            return;
        }

        $s = $pdo->prepare("SELECT id, client_id, customer_rating FROM review_sessions WHERE session_uuid = :u LIMIT 1");
        $s->execute([':u' => $sessionUuid]);
        $session = $s->fetch();
        if (!$session || (int)$session['customer_rating'] > 3 || (int)$session['customer_rating'] < 1) {
            echo json_encode(['ok' => false, 'message' => 'Invalid feedback state.']);
            return;
        }

        $i = $pdo->prepare("
            INSERT INTO internal_feedback (review_session_id, client_id, rating, customer_name, customer_mobile, feedback_text, status, created_at)
            VALUES (:s,:c,:r,:n,:m,:f,'new',NOW())
        ");
        $i->execute([
            ':s' => (int)$session['id'],
            ':c' => (int)$session['client_id'],
            ':r' => (int)$session['customer_rating'],
            ':n' => $name ?: null,
            ':m' => $mobile ?: null,
            ':f' => $feedbackText,
        ]);

        $pdo->prepare("UPDATE review_sessions SET completed_at = NOW() WHERE id = :id")->execute([':id' => (int)$session['id']]);
        echo json_encode(['ok' => true, 'message' => 'Thank you for your feedback.']);
    }

    public function getPositiveReviewAjax(): void
    {
        header('Content-Type: application/json');
        $pdo = getPDO();
        $sessionUuid = trim($_POST['session_uuid'] ?? '');
        if ($sessionUuid === '') {
            echo json_encode(['ok' => false, 'message' => 'Invalid request.']);
            return;
        }

        $ss = $pdo->prepare("
            SELECT rs.id, rs.client_id, rs.customer_rating, c.google_place_id
            FROM review_sessions rs
            INNER JOIN clients c ON c.id = rs.client_id
            WHERE rs.session_uuid = :u
            LIMIT 1
        ");
        $ss->execute([':u' => $sessionUuid]);
        $session = $ss->fetch();
        if (!$session || (int)$session['customer_rating'] < 4) {
            echo json_encode(['ok' => false, 'message' => 'Invalid positive flow state.']);
            return;
        }

        $ai = new AiReviewService($pdo);
        $pricePerReview = $this->getPricePerReview($pdo);
        $walletBalance = $this->getWalletBalance($pdo, (int)$session['client_id']);
        if ($walletBalance < $pricePerReview) {
            echo json_encode([
                'ok' => false,
                'message' => 'Review system currently unavailable.',
            ]);
            return;
        }

        $review = $this->peekOneUnusedReview($pdo, (int)$session['client_id']);

        // Keep the pre-generated buffer full proactively.
        if ($review === null) {
            try {
                $ai->fillBufferToTarget((int)$session['client_id'], AiReviewService::BUFFER_TARGET);
            } catch (Throwable) {
                // ignore and try direct one-shot generation below
            }
            $review = $this->peekOneUnusedReview($pdo, (int)$session['client_id']);
        }

        // Same request hard fallback: generate one review and store as unused.
        if ($review === null) {
            try {
                $generated = $ai->generateAndStoreOneUnusedReview((int)$session['client_id']);
                if ($generated !== null) {
                    $review = $generated;
                }
            } catch (Throwable) {
                // final error handled below
            }
        }

        if ($review === null) {
            $liveText = $ai->generateRealtimeReviewForClient((int)$session['client_id']);
            if ($liveText === null || trim($liveText) === '') {
                $diag = $ai->diagnoseClientApi((int)$session['client_id']);
                $status = (int)($diag['http_status'] ?? 0);
                $err = trim((string)($diag['error'] ?? ''));
                $curlErr = trim((string)($diag['curl_error'] ?? ''));
                $parts = [];
                if ($status > 0) {
                    $parts[] = 'HTTP ' . $status;
                }
                if ($err !== '') {
                    $parts[] = $err;
                }
                if ($curlErr !== '') {
                    $parts[] = 'cURL: ' . $curlErr;
                }
                $diagMessage = empty($parts) ? 'Unknown gateway error' : implode(' | ', $parts);
                echo json_encode([
                    'ok' => false,
                    'message' => 'API error: review generation failed. ' . $diagMessage,
                ]);
                return;
            }
            echo json_encode([
                'ok' => true,
                'review_id' => 0,
                'review_text' => $liveText,
                'google_review_url' => 'https://search.google.com/local/writereview?placeid=' . urlencode($session['google_place_id']),
            ]);
            return;
        }

        echo json_encode([
            'ok' => true,
            'review_id' => (int)$review['id'],
            'review_text' => $review['review_text'],
            'google_review_url' => 'https://search.google.com/local/writereview?placeid=' . urlencode($session['google_place_id']),
        ]);
    }

    public function markReviewUsedAjax(): void
    {
        header('Content-Type: application/json');
        $pdo = getPDO();
        $sessionUuid = trim($_POST['session_uuid'] ?? '');
        $reviewId = (int)($_POST['review_id'] ?? 0);
        $reviewText = trim((string)($_POST['review_text'] ?? ''));

        if ($sessionUuid === '') {
            echo json_encode(['ok' => false, 'message' => 'Invalid usage payload.']);
            return;
        }

        $ss = $pdo->prepare("
            SELECT rs.id, rs.client_id, rs.customer_rating, c.google_place_id
            FROM review_sessions rs
            INNER JOIN clients c ON c.id = rs.client_id
            WHERE rs.session_uuid = :u
            LIMIT 1
        ");
        $ss->execute([':u' => $sessionUuid]);
        $session = $ss->fetch();
        if (!$session || (int)$session['customer_rating'] < 4) {
            echo json_encode(['ok' => false, 'message' => 'Invalid usage state.']);
            return;
        }

        $ai = new AiReviewService($pdo);

        try {
            $pdo->beginTransaction();

            // Idempotent behavior if already marked for this session.
            $already = $pdo->prepare("SELECT used_pre_generated_review_id FROM review_sessions WHERE id = :id LIMIT 1");
            $already->execute([':id' => (int)$session['id']]);
            $alreadyRow = $already->fetch();
            if ($alreadyRow && (int)($alreadyRow['used_pre_generated_review_id'] ?? 0) === $reviewId) {
                $pdo->commit();
                echo json_encode([
                    'ok' => true,
                    'used' => true,
                    'google_review_url' => 'https://search.google.com/local/writereview?placeid=' . urlencode($session['google_place_id']),
                ]);
                return;
            }

            if ($reviewId > 0) {
                $pick = $pdo->prepare("
                    SELECT id
                    FROM pre_generated_reviews
                    WHERE id = :review_id AND client_id = :client_id AND status = 'unused'
                    LIMIT 1
                    FOR UPDATE
                ");
                $pick->execute([
                    ':review_id' => $reviewId,
                    ':client_id' => (int)$session['client_id']
                ]);
                $row = $pick->fetch();
                if (!$row) {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'message' => 'Review is no longer available.']);
                    return;
                }

                $pdo->prepare("
                    UPDATE pre_generated_reviews
                    SET status = 'used', used_in_session_id = :session_id, used_at = NOW()
                    WHERE id = :id
                ")->execute([
                    ':session_id' => (int)$session['id'],
                    ':id' => $reviewId
                ]);
            } else {
                if ($reviewText === '') {
                    $pdo->rollBack();
                    echo json_encode(['ok' => false, 'message' => 'Missing review text.']);
                    return;
                }
                $reviewHash = hash('sha256', strtolower(trim((string)preg_replace('/\s+/', ' ', $reviewText))));
                try {
                    $ins = $pdo->prepare("
                        INSERT INTO pre_generated_reviews (client_id, review_text, review_hash, status, generated_by, used_in_session_id, generated_at, used_at)
                        VALUES (:client_id, :review_text, :review_hash, 'used', 'ai', :session_id, NOW(), NOW())
                    ");
                    $ins->execute([
                        ':client_id' => (int)$session['client_id'],
                        ':review_text' => $reviewText,
                        ':review_hash' => $reviewHash,
                        ':session_id' => (int)$session['id'],
                    ]);
                    $reviewId = (int)$pdo->lastInsertId();
                } catch (Throwable) {
                    $existing = $pdo->prepare("
                        SELECT id, status
                        FROM pre_generated_reviews
                        WHERE client_id = :client_id AND review_hash = :review_hash
                        LIMIT 1
                        FOR UPDATE
                    ");
                    $existing->execute([
                        ':client_id' => (int)$session['client_id'],
                        ':review_hash' => $reviewHash
                    ]);
                    $ex = $existing->fetch();
                    if (!$ex) {
                        $pdo->rollBack();
                        echo json_encode(['ok' => false, 'message' => 'Could not persist review usage.']);
                        return;
                    }
                    $reviewId = (int)$ex['id'];
                    if ((string)$ex['status'] === 'unused') {
                        $pdo->prepare("
                            UPDATE pre_generated_reviews
                            SET status = 'used', used_in_session_id = :session_id, used_at = NOW()
                            WHERE id = :id
                        ")->execute([
                            ':session_id' => (int)$session['id'],
                            ':id' => $reviewId
                        ]);
                    }
                }
            }

            $pricePerReview = $this->getPricePerReview($pdo);
            $wallet = new WalletService($pdo);
            $newBalance = $wallet->debit(
                (int)$session['client_id'],
                $pricePerReview,
                WalletService::SOURCE_REVIEW_DEDUCT,
                'Review consumed by customer (5-star flow)',
                (int)$session['id']
            );
            if ($newBalance === null) {
                $pdo->rollBack();
                echo json_encode([
                    'ok' => false,
                    'message' => 'Review system currently unavailable.',
                ]);
                return;
            }

            $pdo->prepare("
                UPDATE review_sessions
                SET used_pre_generated_review_id = :review_id,
                    user_copied_review = 1,
                    redirected_to_google = 1,
                    completed_at = NOW()
                WHERE id = :id
            ")->execute([
                ':review_id' => $reviewId,
                ':id' => (int)$session['id']
            ]);

            $pdo->prepare("
                INSERT INTO ai_generation_jobs (client_id, trigger_source, status, requested_count, generated_count, attempt_count, created_at)
                VALUES (:client_id, 'review_consumed', 'queued', 1, 0, 0, NOW())
            ")->execute([':client_id' => (int)$session['client_id']]);

            $pdo->commit();
            echo json_encode([
                'ok' => true,
                'used' => true,
                'google_review_url' => 'https://search.google.com/local/writereview?placeid=' . urlencode($session['google_place_id']),
            ]);
        } catch (Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['ok' => false, 'message' => 'Could not mark review as used.']);
        }
    }

    private function getIpAddress(): ?string
    {
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    private function detectDeviceType(string $ua): string
    {
        $ua = strtolower($ua);
        if (str_contains($ua, 'mobile')) return 'mobile';
        if (str_contains($ua, 'tablet') || str_contains($ua, 'ipad')) return 'tablet';
        if ($ua !== '') return 'desktop';
        return 'unknown';
    }

    private function createUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function peekOneUnusedReview(PDO $pdo, int $clientId): ?array
    {
        $pick = $pdo->prepare("
            SELECT id, review_text
            FROM pre_generated_reviews
            WHERE client_id = :client_id AND status = 'unused'
            ORDER BY id ASC
            LIMIT 1
        ");
        $pick->execute([':client_id' => $clientId]);
        $review = $pick->fetch();
        return $review ?: null;
    }

    private function getWalletBalance(PDO $pdo, int $clientId): int
    {
        try {
            $stmt = $pdo->prepare("SELECT wallet_balance FROM clients WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $clientId]);
            return (int)($stmt->fetch()['wallet_balance'] ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    private function getPricePerReview(PDO $pdo): int
    {
        try {
            return max(1, (int)getSystemSetting($pdo, 'price_per_review', '1'));
        } catch (Throwable) {
            return 1;
        }
    }
}
