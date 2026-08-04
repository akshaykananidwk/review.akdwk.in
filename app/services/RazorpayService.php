<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/settings_helper.php';

/**
 * RazorpayService
 * -------------------------------------------------------------------
 * Lightweight Razorpay integration without the SDK.
 *
 *   - createOrder()              -> POST /v1/orders, returns order data
 *   - verifyPaymentSignature()   -> HMAC SHA256 of "{order_id}|{payment_id}"
 *   - verifyWebhookSignature()   -> HMAC SHA256 of raw body with webhook secret
 *   - fetchPayment()             -> GET /v1/payments/{id} (used as a defence
 *                                   in depth when verifying)
 *
 * All credentials are pulled from system_settings so they can be rotated
 * via Admin → Global Settings without code changes.
 */
final class RazorpayService
{
    private PDO $pdo;
    private string $keyId;
    private string $keySecret;
    private string $webhookSecret;
    private bool $enabled;
    private string $mode;
    private string $endpoint = 'https://api.razorpay.com';

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo           = $pdo ?? getPDO();
        $this->enabled       = (int)getSystemSetting($this->pdo, 'razorpay_enabled', '0') === 1;
        $this->mode          = trim(getSystemSetting($this->pdo, 'razorpay_mode', 'test'));
        $this->keyId         = trim(getSystemSetting($this->pdo, 'razorpay_key_id', ''));
        $this->keySecret     = trim(getSystemSetting($this->pdo, 'razorpay_key_secret', ''));
        $this->webhookSecret = trim(getSystemSetting($this->pdo, 'razorpay_webhook_secret', ''));
    }

    public function isConfigured(): bool
    {
        return $this->enabled && $this->keyId !== '' && $this->keySecret !== '';
    }

    public function getKeyId(): string
    {
        return $this->keyId;
    }

    public function getMode(): string
    {
        return $this->mode === 'live' ? 'live' : 'test';
    }

    /**
     * Create a Razorpay order.
     *
     * @param int $amountInr Amount in rupees (will be converted to paise).
     * @param string $receipt Internal receipt id (max 40 chars).
     * @param array<string,string> $notes Arbitrary key/value notes.
     * @return array{ok:bool, status:int, order:?array<string,mixed>, error:?string}
     */
    public function createOrder(int $amountInr, string $receipt, array $notes = []): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'status' => 0, 'order' => null, 'error' => 'Razorpay is not configured.'];
        }
        if ($amountInr <= 0) {
            return ['ok' => false, 'status' => 0, 'order' => null, 'error' => 'Amount must be greater than zero.'];
        }
        $amountPaise = $amountInr * 100;
        $payload = [
            'amount'          => $amountPaise,
            'currency'        => 'INR',
            'receipt'         => substr($receipt, 0, 40),
            'payment_capture' => 1,
            'notes'           => $notes,
        ];
        $resp = $this->httpJson('POST', '/v1/orders', $payload);
        if (!$resp['ok']) {
            return ['ok' => false, 'status' => $resp['status'], 'order' => null, 'error' => $resp['error']];
        }
        $order = json_decode($resp['body'], true);
        if (!is_array($order) || empty($order['id'])) {
            return ['ok' => false, 'status' => $resp['status'], 'order' => null, 'error' => 'Invalid order response.'];
        }
        return ['ok' => true, 'status' => $resp['status'], 'order' => $order, 'error' => null];
    }

    /**
     * Fetch a payment by id (used for additional verification).
     *
     * @return array{ok:bool, status:int, payment:?array<string,mixed>, error:?string}
     */
    public function fetchPayment(string $paymentId): array
    {
        if (!$this->isConfigured() || $paymentId === '') {
            return ['ok' => false, 'status' => 0, 'payment' => null, 'error' => 'Bad request.'];
        }
        $resp = $this->httpJson('GET', '/v1/payments/' . rawurlencode($paymentId), null);
        if (!$resp['ok']) {
            return ['ok' => false, 'status' => $resp['status'], 'payment' => null, 'error' => $resp['error']];
        }
        $payment = json_decode($resp['body'], true);
        if (!is_array($payment) || empty($payment['id'])) {
            return ['ok' => false, 'status' => $resp['status'], 'payment' => null, 'error' => 'Invalid payment response.'];
        }
        return ['ok' => true, 'status' => $resp['status'], 'payment' => $payment, 'error' => null];
    }

    /**
     * Verify a checkout success callback signature.
     *
     * @return bool true if the signature matches; false otherwise.
     */
    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        if ($orderId === '' || $paymentId === '' || $signature === '' || $this->keySecret === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $this->keySecret);
        return hash_equals($expected, $signature);
    }

    /**
     * Verify a webhook payload signature using the webhook secret.
     */
    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        if ($rawBody === '' || $signature === '' || $this->webhookSecret === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);
        return hash_equals($expected, $signature);
    }

    // ---------------------- low-level helpers ----------------------

    /**
     * @param string $method  HTTP verb.
     * @param string $path    Path beginning with '/'.
     * @param array<string,mixed>|null $body  Body for non-GET requests.
     * @return array{ok:bool,status:int,body:string,error:?string}
     */
    private function httpJson(string $method, string $path, ?array $body): array
    {
        $url = $this->endpoint . $path;
        $payload = '';
        $headers = [
            'Accept: application/json',
        ];
        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Failed to encode payload.'];
            }
            $headers[] = 'Content-Type: application/json';
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'cURL init failed.'];
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_USERPWD        => $this->keyId . ':' . $this->keySecret,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }
            $response = curl_exec($ch);
            $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err      = curl_error($ch);
            curl_close($ch);

            if (!is_string($response)) {
                return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $err ?: 'Empty response.'];
            }
            $ok = $status >= 200 && $status < 300;
            return ['ok' => $ok, 'status' => $status, 'body' => $response, 'error' => $ok ? null : ('HTTP ' . $status . ': ' . substr($response, 0, 240))];
        }

        // Stream fallback (less ideal — Razorpay strongly prefers cURL).
        $opts = [
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", array_merge($headers, [
                    'Authorization: Basic ' . base64_encode($this->keyId . ':' . $this->keySecret),
                ])),
                'timeout'       => 25,
                'ignore_errors' => true,
            ],
        ];
        if ($body !== null) {
            $opts['http']['content'] = $payload;
        }
        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
        if (!is_string($response)) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => 'Stream call failed.'];
        }
        $ok = $status >= 200 && $status < 300;
        return ['ok' => $ok, 'status' => $status, 'body' => $response, 'error' => $ok ? null : ('HTTP ' . $status)];
    }
}
