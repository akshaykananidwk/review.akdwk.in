<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/session_helper.php';
require_once __DIR__ . '/../helpers/csrf_helper.php';
require_once __DIR__ . '/../helpers/settings_helper.php';
require_once __DIR__ . '/../helpers/subscription_helper.php';
require_once __DIR__ . '/../services/WalletService.php';
require_once __DIR__ . '/../services/RazorpayService.php';
require_once __DIR__ . '/../services/WhatsAppService.php';
require_once __DIR__ . '/../helpers/whatsapp_notify_helper.php';

/**
 * PaymentController
 * -------------------------------------------------------------------
 * Handles the complete client-driven wallet recharge journey:
 *
 *   GET  /client_recharge.php           -> rechargePage()    (UI: plan picker + history)
 *   POST /client_recharge.php           -> createOrderAjax() (Razorpay order JSON)
 *   POST /razorpay_verify.php           -> verifyAndCredit() (success callback)
 *   POST /razorpay_webhook.php          -> handleWebhook()   (server-to-server)
 *
 * Wallet credit is **idempotent** — a payment_id is only ever credited once,
 * regardless of whether verify+webhook both fire (which they will).
 */
final class PaymentController
{
    public function rechargePage(): void
    {
        requireClientPanelAccess();
        $pdo = getPDO();
        $clientId = (int)$_SESSION['client_id'];

        $clientStmt = $pdo->prepare("
            SELECT id, business_name, owner_name, email, mobile, wallet_balance, subscription_valid_until
            FROM clients WHERE id = :id LIMIT 1
        ");
        $clientStmt->execute([':id' => $clientId]);
        $client = $clientStmt->fetch();
        if (!$client) {
            header('Location: ' . APP_URL . '/logout.php');
            exit;
        }

        $razorpay = new RazorpayService($pdo);
        $razorpayConfigured = $razorpay->isConfigured();
        $razorpayKeyId = $razorpay->getKeyId();
        $razorpayMode = $razorpay->getMode();

        $hasValidity = paymentPlansHaveDurationDays($pdo);
        $plansStmt = $pdo->query($hasValidity
            ? "SELECT id, name, description, price_inr, credits, bonus_credits, duration_days, is_popular
               FROM payment_plans WHERE is_active = 1 ORDER BY sort_order ASC, price_inr ASC"
            : "SELECT id, name, description, price_inr, credits, bonus_credits, 30 AS duration_days, is_popular
               FROM payment_plans WHERE is_active = 1 ORDER BY sort_order ASC, price_inr ASC");
        $plans = $plansStmt->fetchAll();

        $historyStmt = $pdo->prepare("
            SELECT id, gateway, gateway_payment_id, amount_inr, credits_credited, bonus_credited,
                   status, paid_at, created_at
            FROM payment_transactions
            WHERE client_id = :id
            ORDER BY id DESC
            LIMIT 25
        ");
        $historyStmt->execute([':id' => $clientId]);
        $payments = $historyStmt->fetchAll();

        $pricePerReview = max(1, (int)getSystemSetting($pdo, 'price_per_review', '1'));
        $csrfToken = csrfGenerateToken();

        require __DIR__ . '/../views/client/recharge.php';
    }

    /**
     * AJAX: create a Razorpay order for the chosen plan.
     */
    public function createOrderAjax(): void
    {
        requireClientPanelAccess();
        header('Content-Type: application/json');

        if (!csrfValidateToken($_POST['csrf_token'] ?? null)) {
            echo json_encode(['ok' => false, 'message' => 'Invalid session token. Please refresh the page.']);
            return;
        }

        $pdo = getPDO();
        $clientId = (int)$_SESSION['client_id'];
        $planId   = (int)($_POST['plan_id'] ?? 0);

        $planStmt = $pdo->prepare("SELECT * FROM payment_plans WHERE id = :id AND is_active = 1 LIMIT 1");
        $planStmt->execute([':id' => $planId]);
        $plan = $planStmt->fetch();
        if (!$plan) {
            echo json_encode(['ok' => false, 'message' => 'Plan not found or inactive.']);
            return;
        }

        $clientStmt = $pdo->prepare("SELECT business_name, email, mobile FROM clients WHERE id = :id LIMIT 1");
        $clientStmt->execute([':id' => $clientId]);
        $client = $clientStmt->fetch();
        if (!$client) {
            echo json_encode(['ok' => false, 'message' => 'Client not found.']);
            return;
        }

        $razorpay = new RazorpayService($pdo);
        if (!$razorpay->isConfigured()) {
            echo json_encode(['ok' => false, 'message' => 'Online payments are temporarily unavailable. Please contact support.']);
            return;
        }

        $receipt = 'rcpt_' . $clientId . '_' . time() . '_' . random_int(1000, 9999);
        $notes = [
            'client_id'    => (string)$clientId,
            'plan_id'      => (string)$plan['id'],
            'plan_name'    => substr((string)$plan['name'], 0, 64),
            'business'     => substr((string)$client['business_name'], 0, 64),
        ];

        $resp = $razorpay->createOrder((int)$plan['price_inr'], $receipt, $notes);
        if (!$resp['ok'] || !$resp['order']) {
            echo json_encode(['ok' => false, 'message' => 'Could not create payment order: ' . ($resp['error'] ?? 'unknown')]);
            return;
        }

        $orderId = (string)$resp['order']['id'];
        $insert = $pdo->prepare("
            INSERT INTO payment_transactions
              (client_id, plan_id, gateway, gateway_order_id, amount_inr, credits_credited, bonus_credited, status, notes, created_at)
            VALUES
              (:c, :p, 'razorpay', :oid, :amt, 0, 0, 'created', :notes, NOW())
        ");
        $insert->execute([
            ':c'     => $clientId,
            ':p'     => (int)$plan['id'],
            ':oid'   => $orderId,
            ':amt'   => (int)$plan['price_inr'],
            ':notes' => json_encode([
                'plan'   => $plan,
                'order'  => $resp['order'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        echo json_encode([
            'ok'        => true,
            'order_id'  => $orderId,
            'amount'    => (int)$plan['price_inr'] * 100, // paise for Razorpay JS
            'currency'  => 'INR',
            'key_id'    => $razorpay->getKeyId(),
            'name'      => getSystemSetting($pdo, 'system_name', 'Krishna Review System'),
            'description' => $plan['name'] . ' — ' . (int)$plan['credits'] . ' credits' .
                             ((int)$plan['bonus_credits'] > 0 ? ' + ' . (int)$plan['bonus_credits'] . ' bonus' : '') .
                             (paymentPlansHaveDurationDays($pdo)
                                 ? ' · ' . formatValidityDaysLabel(max(1, (int)($plan['duration_days'] ?? 30)))
                                 : ''),
            'prefill'   => [
                'name'    => (string)($client['business_name'] ?? ''),
                'email'   => (string)($client['email'] ?? ''),
                'contact' => (string)($client['mobile'] ?? ''),
            ],
            'theme'     => ['color' => '#005f8f'],
        ]);
    }

    /**
     * Browser-side success callback. Razorpay JS posts here with payment_id +
     * signature. We verify and credit atomically.
     */
    public function verifyAndCredit(): void
    {
        requireClientLogin();
        header('Content-Type: application/json');

        if (!csrfValidateToken($_POST['csrf_token'] ?? null)) {
            echo json_encode(['ok' => false, 'message' => 'Invalid session token.']);
            return;
        }

        $pdo = getPDO();
        $clientId  = (int)$_SESSION['client_id'];
        $orderId   = trim((string)($_POST['razorpay_order_id'] ?? ''));
        $paymentId = trim((string)($_POST['razorpay_payment_id'] ?? ''));
        $signature = trim((string)($_POST['razorpay_signature'] ?? ''));

        if ($orderId === '' || $paymentId === '' || $signature === '') {
            echo json_encode(['ok' => false, 'message' => 'Missing Razorpay payment fields.']);
            return;
        }

        $razorpay = new RazorpayService($pdo);
        if (!$razorpay->isConfigured()) {
            echo json_encode(['ok' => false, 'message' => 'Razorpay not configured.']);
            return;
        }

        if (!$razorpay->verifyPaymentSignature($orderId, $paymentId, $signature)) {
            echo json_encode(['ok' => false, 'message' => 'Signature verification failed.']);
            return;
        }

        $result = $this->creditPaymentIfPaid($pdo, $orderId, $paymentId, $signature, $clientId, 'browser_callback');

        if (!$result['ok']) {
            echo json_encode(['ok' => false, 'message' => $result['message']]);
            return;
        }

        echo json_encode([
            'ok'             => true,
            'message'        => $result['message'],
            'wallet_balance' => $result['wallet_balance'],
            'credited'       => $result['credited'],
        ]);
    }

    /**
     * Razorpay server-to-server webhook receiver.
     *
     * Configure in Razorpay Dashboard:
     *   URL:    https://<your-domain>/razorpay_webhook.php
     *   Events: payment.captured, payment.failed
     *   Secret: paste into Admin → Global Settings → Razorpay Webhook Secret
     */
    public function handleWebhook(): void
    {
        $rawBody  = (string)file_get_contents('php://input');
        $signature = (string)($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');

        $pdo = getPDO();
        $razorpay = new RazorpayService($pdo);
        if (!$razorpay->verifyWebhookSignature($rawBody, $signature)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Bad signature']);
            return;
        }

        $event = json_decode($rawBody, true);
        if (!is_array($event) || empty($event['event'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Bad payload']);
            return;
        }

        $eventName = (string)$event['event'];
        $payment   = $event['payload']['payment']['entity'] ?? [];
        $orderId   = (string)($payment['order_id'] ?? '');
        $paymentId = (string)($payment['id'] ?? '');

        if ($orderId === '' || $paymentId === '') {
            http_response_code(200); // Acknowledge so Razorpay doesn't retry forever.
            echo json_encode(['ok' => true, 'message' => 'No order/payment in payload']);
            return;
        }

        $find = $pdo->prepare("SELECT id, client_id FROM payment_transactions WHERE gateway_order_id = :oid LIMIT 1");
        $find->execute([':oid' => $orderId]);
        $row = $find->fetch();
        if (!$row) {
            require_once __DIR__ . '/SubscriptionController.php';
            $subResult = SubscriptionController::tryCreditFromWebhook($pdo, $orderId, $paymentId, $eventName);
            if ($subResult['ok']) {
                http_response_code(200);
                echo json_encode(['ok' => true, 'message' => $subResult['message']]);
                return;
            }
            http_response_code(200);
            echo json_encode(['ok' => true, 'message' => 'Order not tracked locally']);
            return;
        }
        $clientId = (int)$row['client_id'];

        if (in_array($eventName, ['payment.captured', 'order.paid', 'payment.authorized'], true)) {
            $result = $this->creditPaymentIfPaid($pdo, $orderId, $paymentId, '', $clientId, 'webhook');
            http_response_code(200);
            echo json_encode(['ok' => true, 'message' => $result['message']]);
            return;
        }

        if ($eventName === 'payment.failed') {
            $upd = $pdo->prepare("
                UPDATE payment_transactions
                SET status = 'failed', gateway_payment_id = :pid, updated_at = NOW()
                WHERE gateway_order_id = :oid AND status IN ('created','failed')
            ");
            $upd->execute([':pid' => $paymentId, ':oid' => $orderId]);
            http_response_code(200);
            echo json_encode(['ok' => true, 'message' => 'Marked failed']);
            return;
        }

        http_response_code(200);
        echo json_encode(['ok' => true, 'message' => 'Event ignored']);
    }

    // ---------------------- internal helpers ----------------------

    /**
     * @return array{ok:bool, message:string, wallet_balance?:int, credited?:int}
     */
    private function creditPaymentIfPaid(
        PDO $pdo,
        string $orderId,
        string $paymentId,
        string $signature,
        int $sessionClientId,
        string $sourceTag
    ): array {
        // Quick existence + ownership check (no lock).
        $peek = $pdo->prepare("SELECT id, client_id FROM payment_transactions WHERE gateway_order_id = :oid LIMIT 1");
        $peek->execute([':oid' => $orderId]);
        $peekRow = $peek->fetch();
        if (!$peekRow) {
            return ['ok' => false, 'message' => 'Order not found in our records.'];
        }
        $clientId = (int)$peekRow['client_id'];
        if ($sessionClientId > 0 && $sessionClientId !== $clientId) {
            return ['ok' => false, 'message' => 'Payment does not belong to your account.'];
        }

        $newBalance = 0;
        $creditedTotal = 0;
        $alreadyCredited = false;
        $amountInr = 0;
        $credits = 0;
        $bonus = 0;
        $validUntil = '';
        $validityDays = 0;

        // Atomic critical section: lock the row, re-check status, credit, persist.
        try {
            $pdo->beginTransaction();

            $lock = $pdo->prepare("SELECT * FROM payment_transactions WHERE gateway_order_id = :oid LIMIT 1 FOR UPDATE");
            $lock->execute([':oid' => $orderId]);
            $row = $lock->fetch();
            if (!$row) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Order vanished mid-flight.'];
            }
            $amountInr = (int)$row['amount_inr'];

            // Idempotent short-circuit.
            if ((string)$row['status'] === 'paid' && (int)$row['wallet_txn_id'] > 0) {
                $alreadyCredited = true;
                $creditedTotal = (int)$row['credits_credited'] + (int)$row['bonus_credited'];
                $newBalance = $this->getWalletBalance($pdo, $clientId);
                $pdo->commit();
            } else {
                $planId = (int)$row['plan_id'];
                $plan = null;
                if ($planId > 0) {
                    $hasValidity = paymentPlansHaveDurationDays($pdo);
                    $ps = $pdo->prepare($hasValidity
                        ? 'SELECT credits, bonus_credits, name, duration_days FROM payment_plans WHERE id = :id LIMIT 1'
                        : 'SELECT credits, bonus_credits, name, 30 AS duration_days FROM payment_plans WHERE id = :id LIMIT 1');
                    $ps->execute([':id' => $planId]);
                    $plan = $ps->fetch() ?: null;
                }
                $credits = $plan ? (int)$plan['credits'] : $amountInr; // Fallback: ₹1 = 1 credit.
                $bonus   = $plan ? (int)$plan['bonus_credits'] : 0;
                $total   = $credits + $bonus;
                if ($total <= 0) {
                    $pdo->rollBack();
                    return ['ok' => false, 'message' => 'Plan has no credits to issue.'];
                }

                $wallet = new WalletService($pdo);
                $description = sprintf(
                    'Razorpay payment %s (%s) — %d credits%s',
                    $paymentId,
                    $plan['name'] ?? 'recharge',
                    $credits,
                    $bonus > 0 ? ' + ' . $bonus . ' bonus' : ''
                );
                $newBalance = $wallet->credit($clientId, $total, WalletService::SOURCE_PAYMENT_GATEWAY, $description, null);
                if ($newBalance === null) {
                    $pdo->rollBack();
                    return ['ok' => false, 'message' => 'Failed to credit wallet.'];
                }
                // lastInsertId() reflects the wallet_transactions INSERT done inside credit().
                $ledgerId = (int)$pdo->lastInsertId();

                $upd = $pdo->prepare("
                    UPDATE payment_transactions
                    SET status = 'paid',
                        gateway_payment_id = :pid,
                        gateway_signature  = :sig,
                        credits_credited   = :credits,
                        bonus_credited     = :bonus,
                        wallet_txn_id      = :wtx,
                        paid_at            = NOW(),
                        updated_at         = NOW(),
                        notes              = JSON_SET(COALESCE(notes, JSON_OBJECT()), '$.credited_via', :tag)
                    WHERE id = :id
                ");
                $upd->execute([
                    ':pid'     => $paymentId,
                    ':sig'     => $signature !== '' ? $signature : null,
                    ':credits' => $credits,
                    ':bonus'   => $bonus,
                    ':wtx'     => $ledgerId > 0 ? $ledgerId : null,
                    ':tag'     => $sourceTag,
                    ':id'      => (int)$row['id'],
                ]);
                $creditedTotal = $total;
                $validityDays = $plan ? max(1, (int)($plan['duration_days'] ?? 30)) : 0;
                if ($validityDays > 0) {
                    $extended = extendClientValidityByDays($pdo, $clientId, $validityDays);
                    if ($extended !== null) {
                        $validUntil = $extended;
                    }
                }
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'message' => 'Internal error: ' . $e->getMessage()];
        }

        if ($alreadyCredited) {
            $vuStmt = $pdo->prepare('SELECT subscription_valid_until FROM clients WHERE id = :id LIMIT 1');
            $vuStmt->execute([':id' => $clientId]);
            $vuRow = $vuStmt->fetch();
            return [
                'ok'             => true,
                'message'        => 'Payment already credited.',
                'wallet_balance' => $newBalance,
                'credited'       => $creditedTotal,
                'valid_until'    => (string)($vuRow['subscription_valid_until'] ?? ''),
            ];
        }

        // Best-effort WhatsApp invoice (only on the FRESH credit, not duplicates).
        $this->sendInvoiceWhatsApp($pdo, $clientId, $paymentId, $amountInr, $credits, $bonus, $newBalance, $validUntil, $validityDays);

        $msg = sprintf('Payment successful! %d credits added to your wallet.', $creditedTotal);
        if ($validUntil !== '') {
            $msg .= ' Valid until ' . $validUntil . '.';
        }

        return [
            'ok'             => true,
            'message'        => $msg,
            'wallet_balance' => $newBalance,
            'credited'       => $creditedTotal,
            'valid_until'    => $validUntil,
        ];
    }

    private function getWalletBalance(PDO $pdo, int $clientId): int
    {
        $s = $pdo->prepare("SELECT wallet_balance FROM clients WHERE id = :id LIMIT 1");
        $s->execute([':id' => $clientId]);
        return (int)($s->fetch()['wallet_balance'] ?? 0);
    }

    private function sendInvoiceWhatsApp(
        PDO $pdo,
        int $clientId,
        string $paymentId,
        int $amountInr,
        int $credits,
        int $bonus,
        int $newBalance,
        string $validUntil = '',
        int $validityDays = 0
    ): void {
        try {
            $client = whatsapp_fetch_client_row_for_notify($pdo, $clientId);
            if (!$client || empty($client['mobile'])) {
                return;
            }
            $wa = new WhatsAppService($pdo);
            if (!$wa->isConfigured()) {
                return;
            }
            $systemName = getSystemSetting($pdo, 'system_name', 'Krishna Review System');

            $lines = [];
            $lines[] = 'Jay Dwarkadhish ' . $client['business_name'] . '! 🙏';
            $lines[] = '';
            $lines[] = '✅ Payment received successfully!';
            $lines[] = '';
            $lines[] = '💳 Amount Paid:  ₹' . $amountInr;
            $lines[] = '➕ Credits:       ' . $credits;
            if ($bonus > 0) {
                $lines[] = '🎁 Bonus:         ' . $bonus;
            }
            $lines[] = '💰 New Balance:   ₹' . $newBalance;
            if ($validUntil !== '') {
                $lines[] = '📅 Valid until:   ' . $validUntil
                    . ($validityDays > 0 ? ' (' . formatValidityDaysLabel($validityDays) . ')' : '');
            }
            $lines[] = '';
            $lines[] = 'Reference: ' . $paymentId;
            $lines[] = '';
            $lines[] = 'You can start generating 5-Star reviews right away.';
            $lines[] = 'Login: ' . APP_URL . '/login.php';
            $lines[] = '';
            $lines[] = '— ' . $systemName;

            $body = implode("\n", $lines);
            $extra = isset($client['extra_whatsapp_numbers']) ? (string)$client['extra_whatsapp_numbers'] : '';
            $targets = whatsapp_collect_notify_numbers($pdo, (string)$client['mobile'], $extra !== '' ? $extra : null);
            foreach ($targets as $num) {
                $wa->sendText($num, $body);
                usleep(120000);
            }
        } catch (Throwable) {
            // Silent — invoice is best-effort.
        }
    }
}
