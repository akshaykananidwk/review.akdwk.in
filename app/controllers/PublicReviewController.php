<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/csrf_helper.php';
require_once __DIR__ . '/../helpers/settings_helper.php';
require_once __DIR__ . '/../services/AiReviewService.php';
require_once __DIR__ . '/../helpers/subscription_helper.php';
require_once __DIR__ . '/../helpers/rate_limit_helper.php';
require_once __DIR__ . '/../services/WalletService.php';
require_once __DIR__ . '/../services/Logger.php';

/**
 * Raised when a review cannot be billed because the client's wallet
 * cannot cover it. Distinct from a technical failure so the customer
 * sees a polite message instead of an error reference.
 */
final class InsufficientCreditsException extends RuntimeException
{
}

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

        // Session creation is an unauthenticated write. Cap it per IP and
        // per QR token so a script cannot flood review_sessions or force
        // buffer/AI consumption downstream.
        // Tuning note: customers at a busy venue often share one WiFi/NAT
        // address, so per-IP caps are deliberately generous; the tight
        // control is per-session (a session can only ever bill once).
        $ipGate = rateLimitHit('review_page:ip', $ip, 150, 600);
        $qrGate = rateLimitHit('review_page:qr', $token, 300, 600);
        if (!$ipGate['allowed'] || !$qrGate['allowed']) {
            Logger::security('Review page rate limit hit', [
                'ip_hits' => $ipGate['hits'],
                'qr_hits' => $qrGate['hits'],
            ]);
            http_response_code(429);
            header('Retry-After: ' . max(1, (int)max($ipGate['retry_after'], $qrGate['retry_after'])));
            exit('Too many requests. Please wait a moment and scan again.');
        }

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

        if (!$this->enforcePublicRateLimit('review_rate', $sessionUuid, 12, 300)) {
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

    /**
     * Deliver the AI review for a 5-star session.
     * -------------------------------------------------------------------
     * REVENUE-CRITICAL. Allocation and billing happen here, atomically,
     * at the moment the review text is handed to the customer — not on a
     * later client-controlled callback. The client never supplies a
     * review id, so review-id tampering is structurally impossible.
     *
     * Idempotent: a session that already holds an allocated review gets
     * the same text back and is never billed twice (page refresh,
     * double-tap, retry after a dropped connection).
     *
     * When the buffer is empty the customer is NOT dead-ended: they are
     * still sent to Google to write in their own words, nothing is
     * billed, and a background refill is queued.
     */
    public function getPositiveReviewAjax(): void
    {
        header('Content-Type: application/json');
        $pdo = getPDO();
        $sessionUuid = trim($_POST['session_uuid'] ?? '');
        if ($sessionUuid === '') {
            echo json_encode(['ok' => false, 'message' => 'Invalid request.']);
            return;
        }

        if (!$this->enforcePublicRateLimit('review_fetch', $sessionUuid, 6, 250)) {
            return;
        }

        $ss = $pdo->prepare("
            SELECT rs.id, rs.client_id, rs.customer_rating, rs.used_pre_generated_review_id, c.google_place_id
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

        $googleUrl = 'https://search.google.com/local/writereview?placeid='
            . urlencode((string)$session['google_place_id']);

        // Re-check validity here, not only on page load: a session opened
        // before expiry must not keep spending the wallet afterwards.
        if (!clientHasActiveSubscription($pdo, (int)$session['client_id'])) {
            echo json_encode([
                'ok' => false,
                'code' => 'inactive',
                'message' => 'Thank you for visiting! Review collection is paused for this business.',
            ]);
            return;
        }

        // Already allocated for this session — replay the same text, free.
        $existingId = (int)($session['used_pre_generated_review_id'] ?? 0);
        if ($existingId > 0) {
            $existing = $pdo->prepare('SELECT review_text FROM pre_generated_reviews WHERE id = :id LIMIT 1');
            $existing->execute([':id' => $existingId]);
            $text = (string)($existing->fetchColumn() ?: '');
            if ($text !== '') {
                echo json_encode([
                    'ok' => true,
                    'review_text' => $text,
                    'google_review_url' => $googleUrl,
                    'billed' => false,
                ]);
                return;
            }
        }

        try {
            $allocated = $this->allocateAndBillReview($pdo, (int)$session['id'], (int)$session['client_id']);
        } catch (InsufficientCreditsException) {
            echo json_encode([
                'ok' => false,
                'code' => 'insufficient_credits',
                'message' => 'This business has paused review collection. Thank you for visiting!',
            ]);
            return;
        } catch (Throwable $e) {
            $ref = Logger::error(Logger::CH_WALLET, 'Review allocation failed', [
                'session_id' => (int)$session['id'],
                'client_id' => (int)$session['client_id'],
            ], $e);
            echo json_encode([
                'ok' => false,
                'message' => 'Something went wrong. Reference: ' . $ref,
                'reference' => $ref,
            ]);
            return;
        }

        if ($allocated === null) {
            // Buffer empty. Graceful path: still send them to Google, do
            // not bill, and ask the background worker to refill.
            $this->queueBufferRefill($pdo, (int)$session['client_id']);
            Logger::warning(Logger::CH_AI, 'Review buffer empty at customer request', [
                'client_id' => (int)$session['client_id'],
                'session_id' => (int)$session['id'],
            ]);
            echo json_encode([
                'ok' => true,
                'fallback' => true,
                'review_text' => '',
                'google_review_url' => $googleUrl,
                'message' => 'Please share your experience in your own words on Google.',
                'billed' => false,
            ]);
            return;
        }

        echo json_encode([
            'ok' => true,
            'review_text' => $allocated['review_text'],
            'google_review_url' => $googleUrl,
            'billed' => true,
        ]);
    }

    /**
     * Reserve one unused review for this session and debit the wallet in
     * a single transaction. Returns null when the buffer is empty.
     *
     * @throws InsufficientCreditsException when the client cannot pay
     * @return array{id:int,review_text:string}|null
     */
    private function allocateAndBillReview(PDO $pdo, int $sessionId, int $clientId): ?array
    {
        $pricePerReview = $this->getPricePerReview($pdo);
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            // Serialize concurrent calls for the SAME session so a
            // double-tap cannot allocate (and bill) twice.
            $lock = $pdo->prepare('SELECT used_pre_generated_review_id FROM review_sessions WHERE id = :id FOR UPDATE');
            $lock->execute([':id' => $sessionId]);
            $lockedRow = $lock->fetch();
            $alreadyAllocated = (int)($lockedRow['used_pre_generated_review_id'] ?? 0);
            if ($alreadyAllocated > 0) {
                $again = $pdo->prepare('SELECT id, review_text FROM pre_generated_reviews WHERE id = :id LIMIT 1');
                $again->execute([':id' => $alreadyAllocated]);
                $row = $again->fetch();
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return $row ? ['id' => (int)$row['id'], 'review_text' => (string)$row['review_text']] : null;
            }

            $review = $this->lockOneUnusedReview($pdo, $clientId);
            if ($review === null) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return null;
            }

            // WalletService::debit() returns null for BOTH an insufficient
            // balance and an internal error. Read the balance under the same
            // transaction first so the customer gets the right message and a
            // real fault still produces an error reference.
            $balanceStmt = $pdo->prepare('SELECT wallet_balance FROM clients WHERE id = :id LIMIT 1');
            $balanceStmt->execute([':id' => $clientId]);
            $currentBalance = (int)($balanceStmt->fetchColumn() ?: 0);
            if ($currentBalance < $pricePerReview) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                throw new InsufficientCreditsException('Wallet balance is below the price per review.');
            }

            $wallet = new WalletService($pdo);
            $newBalance = $wallet->debit(
                $clientId,
                $pricePerReview,
                WalletService::SOURCE_REVIEW_DEDUCT,
                'Review delivered to customer (5-star flow)',
                $sessionId
            );
            if ($newBalance === null) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                // Balance was sufficient a moment ago, so this is a fault,
                // not a business rejection.
                throw new RuntimeException('Wallet debit failed despite sufficient balance.');
            }

            $pdo->prepare("
                UPDATE pre_generated_reviews
                SET status = 'used', used_in_session_id = :session_id, used_at = NOW()
                WHERE id = :id
            ")->execute([':session_id' => $sessionId, ':id' => (int)$review['id']]);

            $pdo->prepare("
                UPDATE review_sessions
                SET used_pre_generated_review_id = :review_id
                WHERE id = :id
            ")->execute([':review_id' => (int)$review['id'], ':id' => $sessionId]);

            if ($ownsTransaction) {
                $pdo->commit();
            }

            Logger::info(Logger::CH_WALLET, 'Review allocated and billed', [
                'client_id' => $clientId,
                'session_id' => $sessionId,
                'review_id' => (int)$review['id'],
                'charged' => $pricePerReview,
                'balance_after' => $newBalance,
            ]);

            $this->queueBufferRefill($pdo, $clientId);

            return ['id' => (int)$review['id'], 'review_text' => (string)$review['review_text']];
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Take one unused review with a row lock so two customers of the
     * same business are never handed identical text. SKIP LOCKED lets
     * concurrent sessions pick different rows instead of queueing;
     * falls back to a plain lock on engines that lack it.
     *
     * @return array{id:int,review_text:string}|null
     */
    private function lockOneUnusedReview(PDO $pdo, int $clientId): ?array
    {
        $sql = "
            SELECT id, review_text
            FROM pre_generated_reviews
            WHERE client_id = :client_id AND status = 'unused'
            ORDER BY id ASC
            LIMIT 1
            FOR UPDATE SKIP LOCKED
        ";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':client_id' => $clientId]);
        } catch (Throwable $e) {
            // Older engines lack SKIP LOCKED. Fall back to a plain lock, but
            // record why so this never degrades silently forever.
            Logger::warning(Logger::CH_APP, 'SKIP LOCKED unavailable, using plain row lock', [
                'client_id' => $clientId,
            ], $e);
            $stmt = $pdo->prepare(str_replace('FOR UPDATE SKIP LOCKED', 'FOR UPDATE', $sql));
            $stmt->execute([':client_id' => $clientId]);
        }
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return ['id' => (int)$row['id'], 'review_text' => (string)$row['review_text']];
    }

    /** Ask the background worker to top the buffer back up. Never throws. */
    private function queueBufferRefill(PDO $pdo, int $clientId): void
    {
        try {
            $pdo->prepare("
                INSERT INTO ai_generation_jobs
                    (client_id, trigger_source, status, requested_count, generated_count, attempt_count, created_at)
                VALUES (:client_id, 'review_consumed', 'queued', 1, 0, 0, NOW())
            ")->execute([':client_id' => $clientId]);
        } catch (Throwable $e) {
            Logger::warning(Logger::CH_AI, 'Could not queue buffer refill', ['client_id' => $clientId], $e);
        }
    }

    /**
     * Completion tracking only — billing already happened at allocation.
     * Carries no financial effect, so it cannot be abused for credit.
     * Idempotent: re-posting simply returns the Google URL again.
     */
    public function markReviewUsedAjax(): void
    {
        header('Content-Type: application/json');
        $pdo = getPDO();
        $sessionUuid = trim($_POST['session_uuid'] ?? '');

        if ($sessionUuid === '') {
            echo json_encode(['ok' => false, 'message' => 'Invalid usage payload.']);
            return;
        }

        if (!$this->enforcePublicRateLimit('review_complete', $sessionUuid, 10, 300)) {
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

        try {
            $pdo->prepare("
                UPDATE review_sessions
                SET user_copied_review = 1,
                    redirected_to_google = 1,
                    completed_at = COALESCE(completed_at, NOW())
                WHERE id = :id
            ")->execute([':id' => (int)$session['id']]);
        } catch (Throwable $e) {
            Logger::warning(Logger::CH_APP, 'Could not record review completion', [
                'session_id' => (int)$session['id'],
            ], $e);
        }

        echo json_encode([
            'ok' => true,
            'used' => true,
            'google_review_url' => 'https://search.google.com/local/writereview?placeid='
                . urlencode((string)$session['google_place_id']),
        ]);
    }

    /**
     * Shared limiter for the anonymous review endpoints: caps both the
     * individual session and the source IP, so neither a scripted single
     * session nor a spread of sessions from one host can drain a wallet
     * or burn AI quota. Emits the JSON refusal itself.
     */
    private function enforcePublicRateLimit(string $action, string $sessionUuid, int $perSession, int $perIp): bool
    {
        $window = 600; // 10 minutes

        $bySession = rateLimitHit($action . ':session', $sessionUuid, $perSession, $window);
        if (!$bySession['allowed']) {
            Logger::security('Public review endpoint rate limit hit (session)', [
                'action' => $action,
                'hits' => $bySession['hits'],
            ]);
            echo json_encode([
                'ok' => false,
                'code' => 'rate_limited',
                'message' => 'Too many attempts. Please wait a moment and try again.',
            ]);
            return false;
        }

        $byIp = rateLimitHit($action . ':ip', rateLimitClientIp(), $perIp, $window);
        if (!$byIp['allowed']) {
            Logger::security('Public review endpoint rate limit hit (ip)', [
                'action' => $action,
                'hits' => $byIp['hits'],
            ]);
            echo json_encode([
                'ok' => false,
                'code' => 'rate_limited',
                'message' => 'Too many attempts from this network. Please try again shortly.',
            ]);
            return false;
        }

        return true;
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



    private function getPricePerReview(PDO $pdo): int
    {
        try {
            return max(1, (int)getSystemSetting($pdo, 'price_per_review', '1'));
        } catch (Throwable) {
            return 1;
        }
    }
}
