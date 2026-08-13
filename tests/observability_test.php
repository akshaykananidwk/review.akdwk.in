<?php
declare(strict_types=1);

/**
 * Phase 2 (observability) unit tests. No database required.
 *
 * The redaction assertions matter most: the logger now receives request
 * context from every layer, so a leak here would write live credentials
 * into a plain-text file on disk.
 */

require_once TestRunner::path('app/services/Logger.php');

// ---------------------------------------------------------------------
// Reference IDs
// ---------------------------------------------------------------------
$ref = Logger::newReference();
TestRunner::ok(
    'reference ID matches ERR-YYYYMMDD-XXXXXX',
    (bool)preg_match('/^ERR-\d{8}-[0-9A-F]{6}$/', $ref),
    $ref
);
TestRunner::ok(
    'reference IDs are unique across calls',
    count(array_unique(array_map(static fn() => Logger::newReference(), range(1, 200)))) > 190,
    'collisions would make support references ambiguous'
);

// ---------------------------------------------------------------------
// Secret redaction (uses reflection: sanitize() is deliberately private)
// ---------------------------------------------------------------------
$sanitize = new ReflectionMethod(Logger::class, 'sanitize');
$sanitize->setAccessible(true);

$dirty = [
    'email' => 'owner@example.com',
    'password' => 'hunter2',
    'api_key' => 'abcdef1234567890',
    'razorpay_key_secret' => 'rzp_secret_value',
    'whatsapp_token' => 'wa-token-value',
    'csrf_token' => 'deadbeef',
    'otp' => '123456',
    'nested' => [
        'master_api_key' => 'gc_livekey',
        'client_id' => 42,
        'Authorization' => 'Bearer abc',
    ],
];
$clean = $sanitize->invoke(null, $dirty, 0);

foreach (['password', 'api_key', 'razorpay_key_secret', 'whatsapp_token', 'csrf_token', 'otp'] as $secretKey) {
    TestRunner::same("redacts {$secretKey}", '[redacted]', $clean[$secretKey]);
}
TestRunner::same('redacts nested master_api_key', '[redacted]', $clean['nested']['master_api_key']);
TestRunner::same('redacts Authorization header value', '[redacted]', $clean['nested']['Authorization']);
TestRunner::same('keeps non-secret values', 'owner@example.com', $clean['email']);
TestRunner::same('keeps nested scalars', 42, $clean['nested']['client_id']);

$serialized = json_encode($clean);
foreach (['hunter2', 'abcdef1234567890', 'rzp_secret_value', 'wa-token-value', 'gc_livekey'] as $leak) {
    TestRunner::ok("secret value '{$leak}' never reaches the log payload", !str_contains((string)$serialized, $leak));
}

// Oversized values are clipped so one bad request cannot fill the disk.
$long = $sanitize->invoke(null, ['blob' => str_repeat('x', 5000)], 0);
TestRunner::ok('long values are truncated', strlen((string)$long['blob']) < 700, strlen((string)$long['blob']) . ' chars');

// Deep structures terminate.
$deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'too deep']]]]]];
TestRunner::ok(
    'recursion is bounded',
    str_contains((string)json_encode($sanitize->invoke(null, $deep, 0)), '_truncated')
);

// ---------------------------------------------------------------------
// Logging never throws, and writes a readable JSON line
// ---------------------------------------------------------------------
$logDir = TestRunner::path('storage/logs');
$logFile = $logDir . '/app-' . date('Y-m-d') . '.log';
$before = is_file($logFile) ? (int)filesize($logFile) : 0;

$thrown = null;
try {
    Logger::error(Logger::CH_PAYMENT, 'test: synthetic payment failure', [
        'order' => 'order_TEST123',
        'razorpay_key_secret' => 'must-not-appear',
    ], new RuntimeException('synthetic'));
} catch (Throwable $e) {
    $thrown = $e;
}
TestRunner::ok('Logger never throws', $thrown === null, $thrown ? $thrown->getMessage() : '');

if (is_file($logFile)) {
    $written = (string)file_get_contents($logFile);
    $tail = substr($written, $before);
    $decoded = json_decode(trim(strtok($tail, "\n") ?: '{}'), true);
    TestRunner::ok('log line is valid JSON', is_array($decoded));
    TestRunner::same('severity recorded', 'error', $decoded['severity'] ?? null);
    TestRunner::same('channel recorded', 'payment', $decoded['channel'] ?? null);
    TestRunner::ok('exception captured', isset($decoded['exception']['class']));
    TestRunner::ok('secret redacted on disk', !str_contains($tail, 'must-not-appear'));
    TestRunner::ok('reference present on disk', (bool)preg_match('/ERR-\d{8}-[0-9A-F]{6}/', $tail));
} else {
    TestRunner::skip('log file assertions', 'storage/logs not writable here');
}

// ---------------------------------------------------------------------
// Error handler wiring
// ---------------------------------------------------------------------
$bootstrap = TestRunner::source('app/config/bootstrap_app.php');
TestRunner::ok('exception handler installed', str_contains($bootstrap, 'set_exception_handler'));
TestRunner::ok('error handler installed', str_contains($bootstrap, 'set_error_handler'));
TestRunner::ok('fatal shutdown handler installed', str_contains($bootstrap, 'register_shutdown_function'));
TestRunner::ok(
    'display_errors is off in production',
    str_contains($bootstrap, "ini_set('display_errors', \$isProduction ? '0' : '1')")
);
TestRunner::ok(
    'user-facing message carries only the reference',
    str_contains($bootstrap, 'Something went wrong. Reference: ')
        && !preg_match('/echo.*getMessage\(\)/', $bootstrap),
    'internal exception text must never render'
);
TestRunner::ok(
    'bootstrap is wired into config.php for every entry point',
    str_contains(TestRunner::source('app/config/config.php'), 'AppBootstrap::install()')
);

// ---------------------------------------------------------------------
// Rate limiter: key derivation is deterministic and namespaced
// ---------------------------------------------------------------------
$limiter = TestRunner::source('app/helpers/rate_limit_helper.php');
TestRunner::ok(
    'limiter increments and resets the window atomically',
    str_contains($limiter, 'ON DUPLICATE KEY UPDATE')
        && str_contains($limiter, 'IF(window_started_at <'),
    'a read-then-write limiter can be raced'
);
TestRunner::ok(
    'limiter fails open but logs CRITICAL when unavailable',
    str_contains($limiter, 'Rate limiter unavailable') && str_contains($limiter, 'Logger::critical'),
    'a broken limiter must not take the site down silently'
);
TestRunner::ok(
    'limiter never trusts X-Forwarded-For',
    str_contains($limiter, "REMOTE_ADDR") && !str_contains($limiter, 'HTTP_X_FORWARDED_FOR'),
    'spoofable headers would defeat IP limits'
);
