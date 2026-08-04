<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/session_helper.php';
require_once __DIR__ . '/../helpers/csrf_helper.php';
require_once __DIR__ . '/../helpers/settings_helper.php';
require_once __DIR__ . '/../helpers/subscription_helper.php';
require_once __DIR__ . '/../services/RazorpayService.php';

final class SubscriptionController
{
    public function renewPage(): void
    {
        requireClientLogin();
        $pdo = getPDO();
        $clientId = (int)$_SESSION['client_id'];

        $clientStmt = $pdo->prepare('SELECT id, business_name, email, mobile, wallet_balance, subscription_valid_until FROM clients WHERE id = :id LIMIT 1');
        $clientStmt->execute([':id' => $clientId]);
        $client = $clientStmt->fetch();
        if (!$client) {
            header('Location: ' . APP_URL . '/logout.php');
            exit;
        }

        $active = clientHasActiveSubscription($pdo, $clientId);
        try {
            $plans = $pdo->query("
                SELECT id, name, billing_period, price_inr, duration_days, description, is_active, sort_order
                FROM subscription_plans
                WHERE is_active = 1
                ORDER BY sort_order ASC, price_inr ASC
            ")->fetchAll();
        } catch (Throwable) {
            $plans = [];
        }

        $razorpay = new RazorpayService($pdo);
        $razorpayConfigured = $razorpay->isConfigured();
        $razorpayKeyId = $razorpay->getKeyId();
        $csrfToken = csrfGenerateToken();

        require __DIR__ . '/../views/client/subscription_renew.php';
    }

    public function createOrderAjax(): void
    {
        requireClientLogin();
        header('Content-Type: application/json');

        if (!csrfValidateToken($_POST['csrf_token'] ?? null)) {
            echo json_encode(['ok' => false, 'message' => 'Invalid session token. Please refresh the page.']);
            return;
        }

        $pdo = getPDO();
        $clientId = (int)$_SESSION['client_id'];
        $planId = (int)($_POST['plan_id'] ?? 0);

        $planStmt = $pdo->prepare('SELECT * FROM subscription_plans WHERE id = :id AND is_active = 1 LIMIT 1');
        $planStmt->execute([':id' => $planId]);
        $plan = $planStmt->fetch();
        if (!$plan) {
            echo json_encode(['ok' => false, 'message' => 'Subscription plan not found.']);
            return;
        }

        $clientStmt = $pdo->prepare('SELECT business_name, email, mobile FROM clients WHERE id = :id LIMIT 1');
        $clientStmt->execute([':id' => $clientId]);
        $client = $clientStmt->fetch();
        if (!$client) {
            echo json_encode(['ok' => false, 'message' => 'Client not found.']);
            return;
        }

        $razorpay = new RazorpayService($pdo);
        if (!$razorpay->isConfigured()) {
            echo json_encode(['ok' => false, 'message' => 'Online payments are temporarily unavailable.']);
            return;
        }

        $receipt = 'sub_' . $clientId . '_' . time() . '_' . random_int(1000, 9999);
        $notes = [
            'client_id' => (string)$clientId,
            'subscription_plan_id' => (string)$plan['id'],
            'purpose' => 'subscription',
        ];

        $resp = $razorpay->createOrder((int)$plan['price_inr'], $receipt, $notes);
        if (!$resp['ok'] || empty($resp['order']['id'])) {
            echo json_encode(['ok' => false, 'message' => 'Could not create payment order: ' . ($resp['error'] ?? 'unknown')]);
            return;
        }

        $orderId = (string)$resp['order']['id'];
        $ins = $pdo->prepare("
            INSERT INTO subscription_orders
              (client_id, subscription_plan_id, gateway, gateway_order_id, amount_inr, status, notes, created_at)
            VALUES
              (:c, :p, 'razorpay', :oid, :amt, 'created', :notes, NOW())
        ");
        $ins->execute([
            ':c' => $clientId,
            ':p' => (int)$plan['id'],
            ':oid' => $orderId,
            ':amt' => (int)$plan['price_inr'],
            ':notes' => json_encode(['plan' => $plan, 'order' => $resp['order']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        echo json_encode([
            'ok' => true,
            'order_id' => $orderId,
            'amount' => (int)$plan['price_inr'] * 100,
            'currency' => 'INR',
            'key_id' => $razorpay->getKeyId(),
            'name' => getSystemSetting($pdo, 'system_name', 'Krishna Review System'),
            'description' => (string)$plan['name'] . ' — platform subscription',
            'prefill' => [
                'name' => (string)($client['business_name'] ?? ''),
                'email' => (string)($client['email'] ?? ''),
                'contact' => (string)($client['mobile'] ?? ''),
            ],
            'theme' => ['color' => '#005f8f'],
        ]);
    }

    public function verifyAndActivate(): void
    {
        requireClientLogin();
        header('Content-Type: application/json');

        if (!csrfValidateToken($_POST['csrf_token'] ?? null)) {
            echo json_encode(['ok' => false, 'message' => 'Invalid session token.']);
            return;
        }

        $pdo = getPDO();
        $clientId = (int)$_SESSION['client_id'];
        $orderId = trim((string)($_POST['razorpay_order_id'] ?? ''));
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

        $result = $this->markSubscriptionPaid($pdo, $orderId, $paymentId, $signature, $clientId);
        echo json_encode($result);
    }

    /**
     * Webhook path: credit subscription when wallet payment row is absent.
     *
     * @return array{ok:bool, message:string}
     */
    public static function tryCreditFromWebhook(PDO $pdo, string $orderId, string $paymentId, string $eventName): array
    {
        if (!in_array($eventName, ['payment.captured', 'order.paid', 'payment.authorized'], true)) {
            return ['ok' => false, 'message' => 'ignored event'];
        }

        $ctrl = new self();
        return $ctrl->markSubscriptionPaid($pdo, $orderId, $paymentId, '', 0);
    }

    /**
     * @return array{ok:bool, message:string, valid_until?:string}
     */
    private function markSubscriptionPaid(
        PDO $pdo,
        string $orderId,
        string $paymentId,
        string $signature,
        int $sessionClientId
    ): array {
        try {
            $pdo->beginTransaction();

            $lock = $pdo->prepare('SELECT * FROM subscription_orders WHERE gateway_order_id = :oid LIMIT 1 FOR UPDATE');
            $lock->execute([':oid' => $orderId]);
            $row = $lock->fetch();
            if (!$row) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Subscription order not found.'];
            }
            $clientId = (int)$row['client_id'];
            if ($sessionClientId > 0 && $sessionClientId !== $clientId) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Payment does not belong to your account.'];
            }

            if ((string)$row['status'] === 'paid') {
                $until = $pdo->prepare('SELECT subscription_valid_until FROM clients WHERE id = :id LIMIT 1');
                $until->execute([':id' => $clientId]);
                $urow = $until->fetch();
                $pdo->commit();
                return [
                    'ok' => true,
                    'message' => 'Subscription already active for this payment.',
                    'valid_until' => (string)($urow['subscription_valid_until'] ?? ''),
                ];
            }

            $planId = (int)$row['subscription_plan_id'];
            $ps = $pdo->prepare('SELECT duration_days FROM subscription_plans WHERE id = :id LIMIT 1');
            $ps->execute([':id' => $planId]);
            $plan = $ps->fetch();
            if (!$plan) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Plan missing.'];
            }
            $days = (int)$plan['duration_days'];

            $newEnd = extendClientSubscriptionByDays($pdo, $clientId, $planId, $days);
            if ($newEnd === null) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Could not extend subscription.'];
            }

            $upd = $pdo->prepare("
                UPDATE subscription_orders
                SET status = 'paid',
                    gateway_payment_id = :pid,
                    gateway_signature = :sig,
                    paid_at = NOW(),
                    updated_at = NOW(),
                    notes = JSON_SET(COALESCE(notes, JSON_OBJECT()), '$.credited_via', 'gateway')
                WHERE id = :id
            ");
            $upd->execute([
                ':pid' => $paymentId,
                ':sig' => $signature !== '' ? $signature : null,
                ':id' => (int)$row['id'],
            ]);

            $pdo->commit();
            return [
                'ok' => true,
                'message' => 'Subscription renewed successfully.',
                'valid_until' => $newEnd,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'message' => 'Internal error: ' . $e->getMessage()];
        }
    }
}
