<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/settings_helper.php';

/**
 * WhatsAppService
 * -------------------------------------------------------------------
 * Thin wrapper around the bulk.akdwk.in WhatsApp HTTP gateway used
 * by the platform for OTP, reset links and notifications.
 *
 * All credentials/endpoints are pulled from system_settings so the
 * super admin can update them at runtime via Global Settings.
 */
final class WhatsAppService
{
    private PDO $pdo;
    private string $endpoint;
    private string $apiKey;
    private string $sessionId;
    private string $instanceId;
    private string $token;
    private string $countryCode;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo          = $pdo ?? getPDO();
        $this->endpoint     = trim(getSystemSetting($this->pdo, 'whatsapp_endpoint', 'https://bulk.akdwk.in/api.php'));
        $this->apiKey       = trim(getSystemSetting($this->pdo, 'whatsapp_api_key', ''));
        $this->sessionId    = trim(getSystemSetting($this->pdo, 'whatsapp_session_id', ''));
        $this->instanceId   = trim(getSystemSetting($this->pdo, 'whatsapp_instance_id', ''));
        $this->token        = trim(getSystemSetting($this->pdo, 'whatsapp_token', ''));
        $this->countryCode  = trim(getSystemSetting($this->pdo, 'whatsapp_country_code', '91')) ?: '91';

        if ($this->endpoint === '') {
            $this->endpoint = 'https://bulk.akdwk.in/api.php';
        }
    }

    public function isConfigured(): bool
    {
        return $this->endpoint !== '' && $this->apiKey !== '' && $this->sessionId !== '';
    }

    /**
     * Send a plain text WhatsApp message.
     *
     * @return array{ok:bool,status:int,response:string,error:?string}
     */
    public function sendText(string $rawMobile, string $message): array
    {
        $number = $this->normalizeMobile($rawMobile);
        if ($number === '') {
            return ['ok' => false, 'status' => 0, 'response' => '', 'error' => 'Invalid mobile number.'];
        }
        if (!$this->isConfigured()) {
            return ['ok' => false, 'status' => 0, 'response' => '', 'error' => 'WhatsApp gateway is not configured.'];
        }

        $payload = [
            'api_key'    => $this->apiKey,
            'number'     => $number,
            'message'    => $message,
            'session_id' => $this->sessionId,
        ];
        if ($this->instanceId !== '') {
            $payload['instance_id'] = $this->instanceId;
        }
        if ($this->token !== '') {
            $payload['token'] = $this->token;
        }

        return $this->postJson($this->endpoint, $payload);
    }

    /**
     * Send a media (image / video / pdf) message with caption.
     *
     * @return array{ok:bool,status:int,response:string,error:?string}
     */
    public function sendMedia(string $rawMobile, string $caption, string $mediaUrl): array
    {
        $number = $this->normalizeMobile($rawMobile);
        if ($number === '') {
            return ['ok' => false, 'status' => 0, 'response' => '', 'error' => 'Invalid mobile number.'];
        }
        if ($mediaUrl === '' || !filter_var($mediaUrl, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'status' => 0, 'response' => '', 'error' => 'Invalid media URL.'];
        }
        if (!$this->isConfigured()) {
            return ['ok' => false, 'status' => 0, 'response' => '', 'error' => 'WhatsApp gateway is not configured.'];
        }

        $payload = [
            'api_key'    => $this->apiKey,
            'number'     => $number,
            'message'    => $caption,
            'session_id' => $this->sessionId,
            'media_url'  => $mediaUrl,
        ];
        if ($this->instanceId !== '') {
            $payload['instance_id'] = $this->instanceId;
        }
        if ($this->token !== '') {
            $payload['token'] = $this->token;
        }

        return $this->postJson($this->endpoint, $payload);
    }

    /**
     * Normalises a mobile number to international form expected by the
     * gateway (e.g. 919876543210). Strips spaces, dashes, plus signs and
     * any leading 0; prepends the configured country code if missing.
     */
    public function normalizeMobile(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return '';
        }
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) === 10) {
            return $this->countryCode . $digits;
        }
        if (str_starts_with($digits, $this->countryCode) && strlen($digits) >= strlen($this->countryCode) + 10) {
            return $digits;
        }
        if (strlen($digits) > 10) {
            return $digits;
        }
        return '';
    }

    /**
     * @param array<string,mixed> $data
     * @return array{ok:bool,status:int,response:string,error:?string}
     */
    private function postJson(string $url, array $data): array
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return ['ok' => false, 'status' => 0, 'response' => '', 'error' => 'Failed to encode payload.'];
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return ['ok' => false, 'status' => 0, 'response' => '', 'error' => 'cURL init failed.'];
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $response = curl_exec($ch);
            $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if (!is_string($response)) {
                return ['ok' => false, 'status' => $status, 'response' => '', 'error' => $curlErr ?: 'Empty response.'];
            }
            $ok = $status >= 200 && $status < 300;
            return ['ok' => $ok, 'status' => $status, 'response' => $response, 'error' => $ok ? null : ('HTTP ' . $status)];
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content'       => $body,
                'timeout'       => 20,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
        if (!is_string($response)) {
            return ['ok' => false, 'status' => $status, 'response' => '', 'error' => 'Stream call failed.'];
        }
        $ok = $status >= 200 && $status < 300;
        return ['ok' => $ok, 'status' => $status, 'response' => $response, 'error' => $ok ? null : ('HTTP ' . $status)];
    }
}
