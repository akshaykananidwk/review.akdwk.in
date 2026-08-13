<?php
declare(strict_types=1);

/**
 * POS / third-party integration endpoint.
 *
 *   POST /api/v1/trigger_invite.php
 *   Authorization: Bearer <client api_key>
 *   {"name":"Ramesh","mobile":"9876543210"}
 *
 * Sends a WhatsApp review invite through the platform gateway. Because
 * the gateway account belongs to the platform, abuse here is a platform
 * cost and reputation risk — so the endpoint is quota'd per key and per
 * source IP, gated on the client's platform validity, and audited.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/services/ReviewInviteService.php';
require_once __DIR__ . '/../../app/services/Logger.php';
require_once __DIR__ . '/../../app/helpers/rate_limit_helper.php';
require_once __DIR__ . '/../../app/helpers/subscription_helper.php';

/** Quotas — deliberately conservative; raise per plan later. */
const INVITE_MAX_PER_KEY_HOUR = 60;
const INVITE_MAX_PER_KEY_DAY = 300;
const INVITE_MAX_PER_IP_HOUR = 120;
const INVITE_MAX_AUTH_FAILURES_PER_IP = 20;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'message' => 'POST required.']);
    exit;
}

$clientIp = rateLimitClientIp();

/**
 * Some Apache/CGI stacks strip the Authorization header unless
 * CGIPassAuth is on, which would make this endpoint return 401 for every
 * request. Fall back to getallheaders() and the rewrite convention.
 */
$auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
if ($auth === '' && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $auth = trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
}
if ($auth === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $headerName => $headerValue) {
        if (strcasecmp((string)$headerName, 'Authorization') === 0) {
            $auth = trim((string)$headerValue);
            break;
        }
    }
}

$apiKey = stripos($auth, 'Bearer ') === 0 ? trim(substr($auth, 7)) : $auth;

if ($apiKey === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Missing or invalid Authorization header.']);
    exit;
}

// Throttle credential guessing. PEEK only: a hit is recorded further
// down when authentication actually fails, so a legitimate integration
// making many successful calls never trips the failure limiter.
if (rateLimitPeek('invite_api:auth_fail', $clientIp, 3600) >= INVITE_MAX_AUTH_FAILURES_PER_IP) {
    Logger::security('POS invite API: too many failed authentications', ['ip' => $clientIp]);
    http_response_code(429);
    header('Retry-After: 3600');
    echo json_encode(['ok' => false, 'message' => 'Too many failed attempts. Try again later.']);
    exit;
}

$ipGate = rateLimitHit('invite_api:ip', $clientIp, INVITE_MAX_PER_IP_HOUR, 3600);
if (!$ipGate['allowed']) {
    Logger::security('POS invite API: IP quota exceeded', ['hits' => $ipGate['hits']]);
    http_response_code(429);
    header('Retry-After: ' . max(1, (int)$ipGate['retry_after']));
    echo json_encode(['ok' => false, 'message' => 'Rate limit exceeded for this network.']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'JSON body required.']);
    exit;
}

$name = trim((string)($data['name'] ?? ''));
$mobile = trim((string)($data['mobile'] ?? ''));
if ($name === '' || $mobile === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Fields "name" and "mobile" are required.']);
    exit;
}
if (mb_strlen($name) > 80) {
    $name = mb_substr($name, 0, 80);
}
// Digits only, 10-15 digits (with or without country code).
$mobileDigits = preg_replace('/\D+/', '', $mobile) ?? '';
if (strlen($mobileDigits) < 10 || strlen($mobileDigits) > 15) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Field "mobile" must be a valid 10-15 digit number.']);
    exit;
}

$pdo = getPDO();
$stmt = $pdo->prepare('SELECT id, business_name FROM clients WHERE api_key = :k AND is_active = 1 LIMIT 1');
$stmt->execute([':k' => $apiKey]);
$row = $stmt->fetch();
if (!$row) {
    // Only a genuine auth failure consumes the failure budget.
    rateLimitHit('invite_api:auth_fail', $clientIp, INVITE_MAX_AUTH_FAILURES_PER_IP, 3600);
    Logger::security('POS invite API: invalid API key', ['key_prefix' => substr($apiKey, 0, 6)]);
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Invalid API key.']);
    exit;
}

// Valid key: clear any failure budget accumulated from earlier typos.
rateLimitReset('invite_api:auth_fail', $clientIp);

$clientId = (int)$row['id'];

// The invite costs the platform a WhatsApp message; an expired client
// should not keep spending it. Matches the gate on the review page.
if (!clientHasActiveSubscription($pdo, $clientId)) {
    http_response_code(402);
    echo json_encode(['ok' => false, 'message' => 'Platform validity expired. Please recharge to continue sending invites.']);
    exit;
}

$keyHourGate = rateLimitHit('invite_api:key_hour', (string)$clientId, INVITE_MAX_PER_KEY_HOUR, 3600);
$keyDayGate = rateLimitHit('invite_api:key_day', (string)$clientId, INVITE_MAX_PER_KEY_DAY, 86400);
if (!$keyHourGate['allowed'] || !$keyDayGate['allowed']) {
    $blocked = !$keyHourGate['allowed'] ? $keyHourGate : $keyDayGate;
    Logger::security('POS invite API: client quota exceeded', [
        'client_id' => $clientId,
        'window' => !$keyHourGate['allowed'] ? 'hour' : 'day',
        'hits' => $blocked['hits'],
    ]);
    http_response_code(429);
    header('Retry-After: ' . max(1, (int)$blocked['retry_after']));
    echo json_encode([
        'ok' => false,
        'message' => 'Invite quota reached (' . INVITE_MAX_PER_KEY_HOUR . '/hour, '
            . INVITE_MAX_PER_KEY_DAY . '/day). Please try again later.',
    ]);
    exit;
}

$result = ReviewInviteService::sendInvite($pdo, $clientId, $name, $mobileDigits);

// Audit every send: without this a spam complaint cannot be attributed.
Logger::log(
    !empty($result['ok']) ? Logger::INFO : Logger::WARNING,
    Logger::CH_API,
    'POS invite API send',
    [
        'client_id' => $clientId,
        'business' => (string)$row['business_name'],
        'mobile' => substr($mobileDigits, 0, 4) . '****' . substr($mobileDigits, -2),
        'ok' => !empty($result['ok']),
        'gateway_message' => isset($result['message']) ? substr((string)$result['message'], 0, 200) : null,
    ]
);

// Never reflect raw gateway/internal errors to an API caller.
if (empty($result['ok'])) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'Invite could not be sent. Please verify the number and try again.',
    ]);
    exit;
}

http_response_code(200);
echo json_encode([
    'ok' => true,
    'message' => $result['message'] ?? 'Invite sent.',
    'link' => $result['link'] ?? null,
]);
