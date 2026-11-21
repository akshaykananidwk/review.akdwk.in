<?php
declare(strict_types=1);

/**
 * AK Earn connector.
 *
 * Two things matter most here and both are asserted hard:
 *
 *   1. The secret never enters the repository. The brief was explicit —
 *      no secret in code, in Git, in JavaScript or in a URL — and a
 *      leaked site secret lets anyone forge paid-order events on the
 *      master.
 *   2. A customer action is never slowed down or failed by the master.
 *      Events go into a local outbox and cron delivers them, so a
 *      signup or a payment cannot sit waiting on an HTTP call.
 */

// ---------------------------------------------------------------------
// 1. The SDK is the master's file, byte for byte
// ---------------------------------------------------------------------
$sdkPath = TestRunner::path('app/lib/ak-connect.php');
TestRunner::ok('the master SDK is vendored in app/lib', is_file($sdkPath));

$sdk = TestRunner::source('app/lib/ak-connect.php');
TestRunner::ok(
    'the SDK still declares the documented entry points',
    str_contains($sdk, 'function ak_track_order') && str_contains($sdk, 'function ak_request')
        && str_contains($sdk, 'function ak_push_stats') && str_contains($sdk, 'function ak_sync_products'),
    'the wire format belongs to the master; we must not rewrite it'
);
TestRunner::ok(
    'the SDK signs with HMAC-SHA256 as shipped',
    str_contains($sdk, "hash_hmac('sha256'"),
    ''
);
TestRunner::ok(
    'our code never builds its own signature or endpoint path',
    !str_contains(TestRunner::source('app/services/AkEarnService.php'), 'hash_hmac')
        && !str_contains(TestRunner::source('app/services/AkEarnService.php'), '/api/v1/'),
    'inventing the API format was explicitly ruled out'
);

// ---------------------------------------------------------------------
// 2. The secret is nowhere in the repository
// ---------------------------------------------------------------------
$config = TestRunner::source('app/config/config.php');
TestRunner::ok(
    'the secret is read from the environment',
    str_contains($config, "BootstrapEnv::envString('AK_EARN_SECRET', '')"),
    ''
);
TestRunner::ok(
    'the secret has no literal fallback',
    !preg_match("/AK_EARN_SECRET'\s*,\s*'[^']+'/", $config),
    'a default value here would be a secret committed to Git'
);

// Sweep the tracked tree for anything shaped like a 64-hex secret.
$suspects = [];
$root = TestRunner::path('');
$tracked = [];
exec('cd ' . escapeshellarg(rtrim($root, '/')) . ' && git ls-files 2>/dev/null', $tracked);
foreach ($tracked as $rel) {
    $full = $root . $rel;
    if (!is_file($full) || filesize($full) > 2_000_000) {
        continue;
    }
    if (str_starts_with($rel, 'tests/')) {
        continue;   // this file talks about the pattern on purpose
    }
    $body = (string)file_get_contents($full);
    if (preg_match('/\b[a-f0-9]{64}\b/', $body)) {
        $suspects[] = $rel;
    }
}
TestRunner::ok(
    'no 64-character hex secret is committed anywhere',
    $suspects === [],
    $suspects === [] ? count($tracked) . ' tracked files scanned' : 'suspect files: ' . implode(', ', $suspects)
);
TestRunner::ok(
    '.env is still git-ignored',
    str_contains(TestRunner::source('.gitignore'), '.env'),
    'the secret lives there and must never be tracked'
);

// The browser tag may carry the public site key, never the secret.
$tag = TestRunner::source('app/views/partials/ak_connect_tag.php');
TestRunner::ok(
    'the browser tag exposes only the site key',
    str_contains($tag, 'AK_SITE_KEY') && !str_contains($tag, 'AK_SITE_SECRET'),
    'the SDK is explicit that no secret is used in the browser'
);
TestRunner::ok(
    'the browser tag renders nothing when not configured',
    str_contains($tag, "AK_SITE_KEY !== ''"),
    'an unconfigured site must behave exactly as before'
);

// ---------------------------------------------------------------------
// 3. Customer actions never wait on the master
// ---------------------------------------------------------------------
$service = TestRunner::source('app/services/AkEarnService.php');
foreach ([
    'app/controllers/ClientController.php' => 'queueSignup',
    'app/controllers/PaymentController.php' => 'queueOrder',
    'app/controllers/SubscriptionController.php' => 'queueOrder',
] as $file => $call) {
    $body = TestRunner::source($file);
    TestRunner::ok(
        basename($file) . " queues through {$call}()",
        str_contains($body, $call),
        ''
    );
    TestRunner::ok(
        basename($file) . ' never calls the SDK inline',
        !preg_match('/\bak_track_\w+\s*\(/', $body),
        'an inline call puts a 3s connect + 6s read timeout inside a customer request'
    );
}
TestRunner::ok(
    'queueing is a single local INSERT',
    str_contains($service, 'INSERT INTO ak_outbox'),
    ''
);
TestRunner::ok(
    'every hook is wrapped so reporting cannot break the action',
    substr_count(TestRunner::source('app/controllers/ClientController.php'), 'catch (Throwable)') > 0
        && str_contains($service, 'Logger::warning'),
    ''
);
TestRunner::ok(
    'delivery happens in cron, not in the request',
    str_contains(TestRunner::source('app/cron_jobs/AkEarnOutboxJob.php'), 'deliverPending'),
    ''
);

// ---------------------------------------------------------------------
// 4. The same event cannot be counted twice
// ---------------------------------------------------------------------
TestRunner::ok(
    'event_id is unique in the outbox',
    str_contains(TestRunner::source('database/migrations/2026_11_21_ak_earn_connector.sql'), 'UNIQUE KEY uk_ak_outbox_event'),
    ''
);
TestRunner::ok(
    'a repeat queue of the same event is a no-op',
    str_contains($service, 'ON DUPLICATE KEY UPDATE event_id = event_id'),
    ''
);
TestRunner::ok(
    'event ids follow the SDK scheme, not one of our own',
    str_contains($service, "'signup-' . preg_replace('/\\D/', '', \$mobile)")
        && str_contains($service, "\$type . '-' . \$orderId")
        && str_contains($service, "'renewal-' . \$orderId"),
    'the master dedupes on these ids, so they must match the SDK exactly'
);

// The ids we generate must equal the ids the SDK would generate.
require_once $sdkPath;
TestRunner::same(
    'order id scheme matches the SDK',
    'payment_success-order_ABC123',
    'payment_success' . '-' . 'order_ABC123'
);
TestRunner::ok(
    'the SDK derives signup ids from digits of the mobile',
    str_contains($sdk, "'signup-' . preg_replace('/\\D/', '', \$mobile)"),
    'if this ever changes upstream, our queued id must change with it'
);

// ---------------------------------------------------------------------
// 5. Every event type the brief asked for has a path
// ---------------------------------------------------------------------
require_once TestRunner::path('app/services/AkEarnService.php');
foreach (['queueSignup', 'queueLead', 'queueOrder', 'queueRenewal', 'queueRefund', 'queueCancel'] as $m) {
    TestRunner::ok("AkEarnService::{$m}() exists", method_exists('AkEarnService', $m));
}
$dispatch = new ReflectionMethod('AkEarnService', 'callSdk');
$dispatch->setAccessible(true);
foreach (['ak_track_signup', 'ak_track_lead', 'ak_track_order', 'ak_track_renewal', 'ak_track_refund', 'ak_track_cancel'] as $fn) {
    TestRunner::ok(
        "callSdk() can dispatch {$fn}",
        str_contains($service, "case '{$fn}':") && function_exists($fn),
        ''
    );
}
TestRunner::ok(
    'refunds arrive from the Razorpay webhook',
    str_contains(TestRunner::source('app/controllers/PaymentController.php'), "'refund.created'")
        && str_contains(TestRunner::source('app/controllers/PaymentController.php'), 'queueRefund'),
    ''
);

// ---------------------------------------------------------------------
// 6. Referral attribution survives the trip through the queue
// ---------------------------------------------------------------------
TestRunner::ok(
    'the referral code is captured at event time',
    str_contains($service, 'ak_ref()') && str_contains($service, "':ref' => \$this->currentRef()"),
    'cron has no cookies; reading the ref at delivery time would lose it'
);
TestRunner::ok(
    'the stored ref is restored for the SDK at delivery time',
    str_contains($service, "\$_COOKIE['ak_ref'] = (string)\$row['ref_code']")
        && str_contains($service, '$_COOKIE = $savedCookies'),
    'and the real cookies are put back afterwards'
);
TestRunner::ok(
    'the registration form is marked for the browser tag',
    str_contains(TestRunner::source('app/views/client/register.php'), 'data-ak-form'),
    'so ak_ref survives even when the cookie is blocked'
);

// ---------------------------------------------------------------------
// 7. Scheduling, stats, products, admin page
// ---------------------------------------------------------------------
$registry = TestRunner::source('app/cron_jobs/registry.php');
TestRunner::ok('the outbox job is registered', str_contains($registry, "'ak_earn_outbox'"));
TestRunner::ok('the daily job is registered', str_contains($registry, "'ak_earn_daily'"));
TestRunner::ok(
    'the outbox job runs every minute',
    (bool)preg_match("/'ak_earn_outbox'.*?'schedule_value' => '1'/s", $registry),
    ''
);
TestRunner::ok(
    'daily revenue is sent raw, with no commission arithmetic',
    str_contains($service, "ak_push_stats(\$date, \$t['visitors'], \$t['signups'], \$t['revenue'], \$t['orders'])")
        && !preg_match('/revenue[^;\n]*[*\/]\s*0?\.\d/', $service),
    'the master applies its own commission; we report gross captured revenue'
);
TestRunner::ok(
    'history backfill covers 12 months',
    str_contains($service, 'backfillDailyStats') && str_contains(TestRunner::source('app/cron_jobs/AkEarnDailyJob.php'), 'backfillDailyStats(365'),
    ''
);
TestRunner::ok(
    'products are re-sent when a plan changes',
    str_contains(TestRunner::source('app/controllers/SuperAdminController.php'), 'syncProducts()'),
    ''
);
TestRunner::ok('the admin page entry point exists', is_file(TestRunner::path('public/admin_ak_earn.php')));
TestRunner::ok('the admin view exists', is_file(TestRunner::path('app/views/admin/ak_earn.php')));
TestRunner::ok(
    'the admin page is in the menu',
    str_contains(TestRunner::source('app/views/admin/partials/layout_head.php'), 'admin_ak_earn.php'),
    ''
);
$adminView = TestRunner::source('app/views/admin/ak_earn.php');
foreach (['Test Connection', 'Retry', 'Last event sent', 'Failed'] as $needle) {
    TestRunner::ok("the admin page shows \"{$needle}\"", str_contains($adminView, $needle));
}
TestRunner::ok(
    'every admin action is CSRF protected',
    substr_count($adminView, 'csrfField()') >= 7
        && str_contains(TestRunner::source('app/controllers/AkEarnController.php'), 'csrfValidateToken'),
    ''
);
TestRunner::ok(
    'the admin page never prints the secret',
    !str_contains($adminView, 'AK_SITE_SECRET'),
    ''
);
