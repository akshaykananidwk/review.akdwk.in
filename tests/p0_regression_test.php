<?php
declare(strict_types=1);

/**
 * Regression guards for every P0 issue fixed in Phase 1.
 *
 * These are structural assertions against the real source. They exist so
 * that a future edit which silently reintroduces one of these defects
 * fails the suite instead of reaching production. They run anywhere — no
 * database required — which is precisely why they are the safety net for
 * a project whose critical paths cannot otherwise be exercised in CI.
 */

// ---------------------------------------------------------------------
// P0-1  Wallet deduction bypass (the review_id=0 short-circuit)
// ---------------------------------------------------------------------
$review = TestRunner::source('app/controllers/PublicReviewController.php');

TestRunner::ok(
    'markReviewUsedAjax no longer reads a client-supplied review_id',
    !preg_match('/markReviewUsedAjax.*?\$_POST\[.review_id.\]/s', $review),
    'client input must never identify which review was consumed'
);

TestRunner::ok(
    'the vulnerable "?? 0 === $reviewId" idempotency check is gone',
    !str_contains($review, "used_pre_generated_review_id'] ?? 0) === \$reviewId"),
    'this comparison matched 0 === 0 on a fresh session and skipped billing'
);

TestRunner::ok(
    'completion tracking performs no wallet debit',
    (static function () use ($review): bool {
        $start = strpos($review, 'public function markReviewUsedAjax');
        if ($start === false) {
            return false;
        }
        $end = strpos($review, 'private function enforcePublicRateLimit', $start);
        $body = substr($review, $start, ($end !== false ? $end - $start : 4000));
        return !str_contains($body, '->debit(');
    })(),
    'billing must not sit on a client-controlled callback'
);

TestRunner::ok(
    'billing happens at allocation time, inside a transaction',
    str_contains($review, 'private function allocateAndBillReview')
        && str_contains($review, 'beginTransaction')
        && str_contains($review, 'SOURCE_REVIEW_DEDUCT'),
    'allocation + debit must be atomic'
);

TestRunner::ok(
    'allocation is idempotent per session (row lock + already-allocated check)',
    str_contains($review, 'FROM review_sessions WHERE id = :id FOR UPDATE')
        && str_contains($review, '$alreadyAllocated > 0'),
    'a double tap must not bill twice'
);

TestRunner::ok(
    'review selection takes a row lock so two customers cannot get the same text',
    str_contains($review, 'FOR UPDATE SKIP LOCKED') || str_contains($review, 'FOR UPDATE'),
    'peek-without-lock caused duplicate delivery'
);

// ---------------------------------------------------------------------
// P0-14  Undefined method on the AI failure path + no diagnostics leak
// ---------------------------------------------------------------------
TestRunner::ok(
    'the undefined diagnoseClientApi() call is gone',
    !str_contains($review, 'diagnoseClientApi'),
    'it fataled whenever the buffer was empty and Gemini was unreachable'
);

TestRunner::ok(
    'gateway diagnostics are never returned to the public customer',
    !str_contains($review, 'API error: review generation failed'),
    'HTTP status / cURL errors must not reach an anonymous visitor'
);

TestRunner::ok(
    'empty buffer produces a graceful fallback, not a dead end',
    str_contains($review, "'fallback' => true"),
    'customer must still reach Google when no AI review is ready'
);

$reviewView = TestRunner::source('app/views/public/review.php');
TestRunner::ok(
    'the "Review reference missing" dead end is removed from the UI',
    !str_contains($reviewView, 'Review reference missing'),
    'this blocked the customer at the moment of highest intent'
);

// ---------------------------------------------------------------------
// P0-4/5/6/7  Rate limiting
// ---------------------------------------------------------------------
TestRunner::ok(
    'login is rate limited on both email and IP',
    str_contains(TestRunner::source('app/controllers/AuthController.php'), "rateLimitHit('login:email'")
        && str_contains(TestRunner::source('app/controllers/AuthController.php'), "rateLimitHit('login:ip'"),
    'a single dimension can be bypassed by rotating the other'
);

TestRunner::ok(
    'failed logins are recorded',
    str_contains(TestRunner::source('app/controllers/AuthController.php'), 'logFailedLoginAttempt')
        && str_contains(TestRunner::source('app/helpers/audit_helper.php'), 'function logFailedLoginAttempt'),
    'brute force was previously invisible'
);

TestRunner::ok(
    'registration is rate limited',
    str_contains(TestRunner::source('app/controllers/ClientController.php'), "rateLimitHit('register:ip'"),
    'signup grants bonus credits and free validity'
);

TestRunner::ok(
    'the public review flow is rate limited on session and IP',
    str_contains($review, 'enforcePublicRateLimit')
        && str_contains($review, "rateLimitHit('review_page:ip'"),
    'unthrottled scans drain wallets and AI quota'
);

$invite = TestRunner::source('public/api/v1/trigger_invite.php');
TestRunner::ok(
    'POS invite API enforces per-key hourly and daily quotas',
    str_contains($invite, 'invite_api:key_hour') && str_contains($invite, 'invite_api:key_day'),
    'a leaked key was an unlimited WhatsApp relay'
);
TestRunner::ok(
    'POS invite API throttles credential guessing',
    str_contains($invite, 'invite_api:auth_fail'),
    ''
);
TestRunner::ok(
    'POS invite API validates the mobile number',
    str_contains($invite, 'preg_replace(\'/\D+/\''),
    'arbitrary strings were forwarded to the gateway'
);
TestRunner::ok(
    'POS invite API checks platform validity before spending a message',
    str_contains($invite, 'clientHasActiveSubscription'),
    ''
);
TestRunner::ok(
    'POS invite API does not reflect raw gateway errors',
    !str_contains($invite, "'WhatsApp send failed: '"),
    ''
);

// ---------------------------------------------------------------------
// P0-8/9  CSRF
// ---------------------------------------------------------------------
$superAdmin = TestRunner::source('app/controllers/SuperAdminController.php');
$postBlocks = substr_count($superAdmin, "if (\$_SERVER['REQUEST_METHOD'] === 'POST') {");
$guards = substr_count($superAdmin, 'csrfRequireValidToken();');
TestRunner::same('every admin POST block is CSRF guarded', $postBlocks, $guards);
TestRunner::ok('admin POST blocks exist to guard', $postBlocks >= 8, "found {$postBlocks}");

TestRunner::ok(
    'client settings enforces CSRF',
    str_contains(TestRunner::source('app/controllers/ClientSettingsController.php'), 'csrfRequireValidToken();'),
    ''
);

$viewsMissingToken = [];
foreach (glob(TestRunner::path('app/views/admin/*.php')) ?: [] as $file) {
    $src = (string)file_get_contents($file);
    if (preg_match_all('/<form\b[^>]*method\s*=\s*["\']post["\'][^>]*>/i', $src, $m) === 0) {
        continue;
    }
    $forms = count($m[0]);
    $tokens = substr_count($src, 'csrfField()') + substr_count($src, 'name="csrf_token"');
    if ($tokens < $forms) {
        $viewsMissingToken[] = basename($file) . " ({$tokens}/{$forms})";
    }
}
TestRunner::ok(
    'every admin POST form carries a CSRF token',
    $viewsMissingToken === [],
    implode(', ', $viewsMissingToken)
);

TestRunner::ok(
    'CSRF failures are rejected and logged, not silently ignored',
    str_contains(TestRunner::source('app/helpers/csrf_helper.php'), 'function csrfRequireValidToken')
        && str_contains(TestRunner::source('app/helpers/csrf_helper.php'), 'Logger::security'),
    ''
);

// ---------------------------------------------------------------------
// P0-10  TLS verification
// ---------------------------------------------------------------------
$disabledTls = [];
foreach (glob(TestRunner::path('app/services/*.php')) ?: [] as $file) {
    $src = (string)file_get_contents($file);
    if (preg_match('/CURLOPT_SSL_VERIFYPEER\s*=>\s*(false|0)\b/i', $src)
        || preg_match('/CURLOPT_SSL_VERIFYHOST\s*=>\s*0\b/', $src)) {
        $disabledTls[] = basename($file);
    }
}
TestRunner::ok(
    'no service disables TLS certificate verification',
    $disabledTls === [],
    implode(', ', $disabledTls)
);
TestRunner::ok(
    'the Gemini call verifies certificates explicitly',
    str_contains(TestRunner::source('app/services/AiReviewService.php'), 'CURLOPT_SSL_VERIFYPEER => true'),
    'the request carries the API key in a header'
);

// ---------------------------------------------------------------------
// P0-11  Fail-open paywall
// ---------------------------------------------------------------------
$subscription = TestRunner::source('app/helpers/subscription_helper.php');
TestRunner::ok(
    'the subscription check fails CLOSED on error',
    (static function () use ($subscription): bool {
        $start = strpos($subscription, 'function clientHasActiveSubscription');
        if ($start === false) {
            return false;
        }
        // Body runs until the next top-level function declaration.
        $next = strpos($subscription, "\nfunction ", $start + 10);
        $body = substr($subscription, $start, $next !== false ? $next - $start : null);
        $catch = strpos($body, 'catch (Throwable');
        if ($catch === false) {
            return false;
        }
        $catchBody = substr($body, $catch);
        return str_contains($catchBody, 'return false;') && !str_contains($catchBody, 'return true;');
    })(),
    'a DB error previously granted every expired client free access'
);

// ---------------------------------------------------------------------
// P0-12  Unknown admin role must be denied
// ---------------------------------------------------------------------
$auth = TestRunner::source('app/controllers/AuthController.php');
TestRunner::ok(
    'an unrecognised admin role is denied, not promoted to super_admin',
    !preg_match("/in_array\(\\\$role, \['super_admin', 'reseller'\], true\)\) \{\s*\\\$role = 'super_admin';/", $auth),
    'fail-open role assignment'
);
TestRunner::ok(
    'the deny path is logged as a security event',
    str_contains($auth, 'unrecognised admin role'),
    ''
);

// ---------------------------------------------------------------------
// P0-13  Dead/broken unauthenticated endpoints removed
// ---------------------------------------------------------------------
foreach (['client_models.php', 'client_test_api.php', 'dashboard_refill.php', 'dashboard_regenerate_qr.php'] as $dead) {
    TestRunner::ok(
        "dead endpoint removed: public/{$dead}",
        !file_exists(TestRunner::path('public/' . $dead)),
        ''
    );
}
TestRunner::ok(
    'no code still links to the removed endpoints',
    (static function (): bool {
        foreach (array_merge(
            glob(TestRunner::path('app/views/**/*.php')) ?: [],
            glob(TestRunner::path('app/views/*.php')) ?: [],
            glob(TestRunner::path('public/*.php')) ?: []
        ) as $file) {
            $src = (string)file_get_contents($file);
            foreach (['client_models.php', 'client_test_api.php', 'dashboard_refill.php', 'dashboard_regenerate_qr.php'] as $dead) {
                if (str_contains($src, $dead)) {
                    return false;
                }
            }
        }
        return true;
    })(),
    ''
);

// ---------------------------------------------------------------------
// P0-2  No production dump or committed secrets in the working tree
// ---------------------------------------------------------------------
TestRunner::ok(
    'the production database dump is not in the repository',
    !file_exists(TestRunner::path('database/Database.sql')),
    'it contained customer PII, password hashes and every API key'
);

$secretsInMigrations = [];
foreach (glob(TestRunner::path('database/migrations/*.sql')) ?: [] as $file) {
    $src = (string)file_get_contents($file);
    // A seeded credential looks like ('<key name>', '<long literal>')
    if (preg_match("/\\('(whatsapp_api_key|whatsapp_token|razorpay_key_secret|razorpay_webhook_secret|master_api_key|ai_api_key)'\\s*,\\s*'[A-Za-z0-9_\\-]{12,}'/", $src)) {
        $secretsInMigrations[] = basename($file);
    }
}
TestRunner::ok(
    'no migration seeds a live credential literal',
    $secretsInMigrations === [],
    implode(', ', $secretsInMigrations)
);

// ---------------------------------------------------------------------
// Deploy safety: retired files must actually leave the server.
// The updater only adds/overwrites, so a deleted endpoint would stay
// live forever without an explicit removal manifest.
// ---------------------------------------------------------------------
$updater = TestRunner::source('app/services/GitHubUpdateService.php');
TestRunner::ok(
    'deploy processes a removal manifest',
    str_contains($updater, 'applyRemovalManifest') && str_contains($updater, 'deploy/removals.txt'),
    'without this, files deleted from the repo remain live on production'
);
TestRunner::ok(
    'removal manifest refuses protected paths and traversal',
    str_contains($updater, 'Removal refused (protected path)')
        && str_contains($updater, 'Removal refused (unsafe path)')
        && str_contains($updater, 'Removal refused (escapes project root)'),
    ''
);
TestRunner::ok(
    'removal manifest exists and lists every file deleted in this release',
    (static function (): bool {
        $manifest = TestRunner::source('deploy/removals.txt');
        if ($manifest === '') {
            return false;
        }
        foreach ([
            'public/client_models.php',
            'public/client_test_api.php',
            'public/dashboard_refill.php',
            'public/dashboard_regenerate_qr.php',
            'database/Database.sql',
        ] as $expected) {
            if (!str_contains($manifest, $expected)) {
                return false;
            }
        }
        return true;
    })(),
    'a retired endpoint that is not listed stays reachable after deploy'
);

// ---------------------------------------------------------------------
// Regression: a CSRF token accidentally emitted INSIDE an HTML attribute
// renders no form field at all, so the form silently starts failing with
// 419. This was introduced and caught during Phase 1 review - guard it.
//
// Detection: mark the token, strip every OTHER php block (so their angle
// brackets cannot confuse the scan), then check whether the marker sits
// inside an unclosed tag.
// ---------------------------------------------------------------------
$csrfInsideAttribute = static function (string $src): bool {
    $marked = preg_replace('/<\?=\s*csrfField\(\)\s*\?>/', "\x01", $src);
    $marked = preg_replace('/<\?.*?\?>/s', ' ', (string)$marked);
    $offset = 0;
    while (($at = strpos((string)$marked, "\x01", $offset)) !== false) {
        $offset = $at + 1;
        $before = substr((string)$marked, 0, $at);
        $lastOpen = strrpos($before, '<');
        $lastClose = strrpos($before, '>');
        if ($lastOpen !== false && ($lastClose === false || $lastOpen > $lastClose)) {
            return true;
        }
    }
    return false;
};

// Prove the detector actually works: this is the exact shape of the bug.
TestRunner::ok(
    'the detector catches a token emitted inside an onsubmit attribute',
    $csrfInsideAttribute(
        '<form method="post" onsubmit="return confirm(\'Delete &quot;<?= htmlspecialchars($x) ?>'
        . "\n<?= csrfField() ?>" . '&quot;?\');"><input name="a"></form>'
    ),
    'if this fails the guard below is meaningless'
);
TestRunner::ok(
    'the detector accepts a correctly placed token',
    !$csrfInsideAttribute('<form method="post" onsubmit="return confirm(\'x\');">' . "\n<?= csrfField() ?>" . '<input name="a"></form>'),
    ''
);

$badPlacement = [];
foreach (array_merge(
    glob(TestRunner::path('app/views/admin/*.php')) ?: [],
    glob(TestRunner::path('app/views/client/*.php')) ?: [],
    glob(TestRunner::path('app/views/reseller/*.php')) ?: []
) as $file) {
    if ($csrfInsideAttribute((string)file_get_contents($file))) {
        $badPlacement[] = basename($file);
    }
}
TestRunner::ok(
    'no CSRF token is emitted inside an HTML attribute',
    $badPlacement === [],
    implode(', ', $badPlacement)
);

// Regression: the invite API's auth-failure budget must only be consumed
// by real failures, or a busy legitimate integration locks itself out.
// ---------------------------------------------------------------------
TestRunner::ok(
    'invite API auth-failure limiter only counts genuine failures',
    str_contains($invite, "rateLimitPeek('invite_api:auth_fail'")
        && str_contains($invite, "rateLimitReset('invite_api:auth_fail'"),
    'peeking before auth and hitting only on failure'
);

// Review-driven hardening: these came out of the Phase 1 adversarial pass.
TestRunner::ok(
    'oversized uploads report a size error, not a bogus session expiry',
    str_contains(TestRunner::source('app/helpers/csrf_helper.php'), 'post_max_size'),
    'an admin uploading a large image must not be told their session expired'
);
TestRunner::ok(
    'login limiter counts failures only (peek before, hit after)',
    str_contains($auth, "rateLimitPeek('login:email'") && str_contains($auth, "rateLimitPeek('login:ip'"),
    'counting successes would lock out a shared office IP'
);
TestRunner::ok(
    'CSRF token is rotated across the authentication boundary',
    substr_count($auth, "unset(\$_SESSION['csrf_token'])") >= 2,
    ''
);
TestRunner::ok(
    'billing distinguishes insufficient credits from a technical fault',
    str_contains($review, 'Wallet debit failed despite sufficient balance')
        && str_contains($review, 'Wallet balance is below the price per review'),
    'both used to surface as "business paused review collection"'
);
TestRunner::ok(
    'platform validity is re-checked at billing time, not only on page load',
    (static function () use ($review): bool {
        $start = strpos($review, 'public function getPositiveReviewAjax');
        $end = strpos($review, 'private function allocateAndBillReview');
        return $start !== false && $end !== false
            && str_contains(substr($review, $start, $end - $start), 'clientHasActiveSubscription');
    })(),
    'a session opened before expiry could keep spending afterwards'
);
