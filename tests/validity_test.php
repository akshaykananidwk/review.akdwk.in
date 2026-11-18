<?php
declare(strict_types=1);

/**
 * Plan-expiry display in the client panel.
 *
 * A client reported that nothing in their panel told them when their
 * access ends — the date existed only on the recharge page, written as a
 * bare Y-m-d with no sense of how close it was. These tests pin the
 * boundaries of the status the panel now shows everywhere.
 *
 * The behavioural half runs against an in-memory SQLite database, so it
 * needs no TEST_DB_* configuration and still exercises the real query.
 */

require_once TestRunner::path('app/helpers/subscription_helper.php');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    TestRunner::skip('plan validity states', 'pdo_sqlite not available');
    return;
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, subscription_valid_until TEXT)');
$insert = $pdo->prepare('INSERT INTO clients (id, subscription_valid_until) VALUES (:i, :u)');

$today = new DateTimeImmutable('today');
$day = static fn(int $offset): string => $today->modify(($offset >= 0 ? '+' : '-') . abs($offset) . ' days')->format('Y-m-d');

// clientValidityStatus() caches per client id, so every case needs its own.
$cases = [
    1  => $day(60),
    2  => $day(11),
    3  => $day(10),
    4  => $day(4),
    5  => $day(3),
    6  => $day(1),
    7  => $day(0),
    8  => $day(-1),
    9  => $day(-45),
    10 => null,
    11 => 'not-a-date',
];
foreach ($cases as $id => $until) {
    $insert->execute([':i' => $id, ':u' => $until]);
}

$state = static fn(PDO $pdo, int $id): string => clientValidityStatus($pdo, $id)['state'];

// Thresholds: warning inside 10 days, critical inside 3.
TestRunner::same('60 days out is ok', 'ok', $state($pdo, 1));
TestRunner::same('11 days out is still ok', 'ok', $state($pdo, 2));
TestRunner::same('10 days out starts the warning', 'warning', $state($pdo, 3));
TestRunner::same('4 days out is still only a warning', 'warning', $state($pdo, 4));
TestRunner::same('3 days out is critical', 'critical', $state($pdo, 5));
TestRunner::same('tomorrow is critical', 'critical', $state($pdo, 6));
TestRunner::same('the last valid day is critical, not expired', 'critical', $state($pdo, 7));
TestRunner::same('yesterday is expired', 'expired', $state($pdo, 8));
TestRunner::same('long past is expired', 'expired', $state($pdo, 9));
TestRunner::same('no date set is reported as none', 'none', $state($pdo, 10));
TestRunner::same('an unparseable date is unknown, never guessed', 'unknown', $state($pdo, 11));
TestRunner::same('a missing client row is unknown', 'unknown', $state($pdo, 404));

// The panel must agree with the paywall about what "active" means:
// clientHasActiveSubscription() counts today as active.
$lastDay = clientValidityStatus($pdo, 7);
TestRunner::ok('the last valid day still counts as active', $lastDay['is_active'] === true);
TestRunner::same('the last valid day has 0 days left', 0, $lastDay['days_left']);
TestRunner::ok(
    'the last valid day says so in words',
    str_contains(strtolower($lastDay['headline']), 'today'),
    $lastDay['headline']
);
TestRunner::ok('an expired plan is not active', clientValidityStatus($pdo, 8)['is_active'] === false);
TestRunner::same('days_left goes negative once expired', -1, clientValidityStatus($pdo, 8)['days_left']);

// Dates are rendered for a human, not as Y-m-d.
$future = clientValidityStatus($pdo, 1);
TestRunner::ok(
    'the date is formatted for reading',
    (bool)preg_match('/^\d{2} [A-Z][a-z]{2} \d{4}$/', (string)$future['valid_until_human']),
    (string)$future['valid_until_human']
);
TestRunner::same('the machine-readable date is kept too', $cases[1], $future['valid_until']);

// The header chip must never render an empty or misleading label.
TestRunner::ok('chip shows days left while active', str_contains(validityChipText($future), 'days left'));
TestRunner::ok('chip says expired once past', str_contains(validityChipText(clientValidityStatus($pdo, 8)), 'expired'));
TestRunner::ok('chip says last day on the final day', str_contains(validityChipText($lastDay), 'last day'));
TestRunner::same('chip is empty when the state is unknown', '', validityChipText(clientValidityStatus($pdo, 11)));

// ---------------------------------------------------------------------
// Wiring: the status has to reach every page, not just the dashboard
// ---------------------------------------------------------------------
$layout = TestRunner::source('app/views/client/partials/layout_head.php');
TestRunner::ok(
    'the shared client layout resolves the validity itself',
    str_contains($layout, 'clientValidityStatus('),
    'computing it per controller would miss pages and reintroduce the complaint'
);
TestRunner::ok(
    'the layout renders the plan chip in the header',
    str_contains($layout, 'plan-chip') && str_contains($layout, 'validityChipText('),
    ''
);
TestRunner::ok(
    'the layout warns before expiry, not only after',
    str_contains($layout, "'warning', 'critical', 'expired'"),
    'a notice that appears only once access is cut off is too late to act on'
);
// Both render guards are whitelists of states. Neither may admit
// 'unknown': a failed lookup must produce no claim about the expiry date
// at all, rather than a chip reading "No expiry set".
preg_match_all("/in_array\(\\\$validity\['state'\], \[(.*?)\], true\)/", $layout, $guards);
TestRunner::ok(
    'the state guards were found',
    count($guards[1] ?? []) >= 2,
    count($guards[1] ?? []) . ' guard(s)'
);
foreach ($guards[1] ?? [] as $i => $list) {
    TestRunner::ok(
        'state guard #' . ($i + 1) . ' excludes the unknown state',
        !str_contains($list, 'unknown'),
        trim($list)
    );
}
TestRunner::ok(
    'the notice links to the recharge page',
    str_contains($layout, 'client_recharge.php'),
    ''
);
TestRunner::ok(
    'the dashboard carries a Plan Valid Until tile',
    str_contains(TestRunner::source('app/views/client/dashboard.php'), 'Plan Valid Until'),
    ''
);

// ---------------------------------------------------------------------
// The platform is paid: no free offers anywhere
//
// This started as a three-year Devbhumi Dwarka offer, was cut to one
// year, and is now withdrawn entirely along with the sign-up trial. Copy
// is the part that rots: the page once advertised three years free and a
// three-month trial while the settings granted neither.
// ---------------------------------------------------------------------
TestRunner::same('365 days reads as one year', '1 year', humanValidityPeriod(365)['en']);
TestRunner::same('365 days reads as one year in Gujarati', '1 વર્ષ', humanValidityPeriod(365)['gu']);
TestRunner::same('90 days reads as three months', '3 months', humanValidityPeriod(90)['en']);
TestRunner::same('30 days is singular', '1 month', humanValidityPeriod(30)['en']);
TestRunner::same('30 days is singular in Gujarati', '1 મહિનો', humanValidityPeriod(30)['gu']);
TestRunner::same('a period that is neither is given in days', '45 days', humanValidityPeriod(45)['en']);
TestRunner::same('zero is not dressed up as a free period', 'no free period', humanValidityPeriod(0)['en']);
TestRunner::same('a negative setting cannot promise anything', 'no free period', humanValidityPeriod(-5)['en']);

// The location offer is gone root and branch. Facility names in the
// catalogue migrations legitimately contain the word "free" (Free
// Installation, Charcoal Free), so this looks for the offer itself.
$offerTraces = [];
foreach (['app', 'public'] as $dir) {
    $base = TestRunner::path($dir);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') {
            continue;
        }
        if (str_contains((string)file_get_contents($f->getPathname()), 'special_district')) {
            $offerTraces[] = substr($f->getPathname(), strlen(TestRunner::path('')));
        }
    }
}
TestRunner::ok(
    'no code still reads or writes the special-district offer',
    $offerTraces === [],
    $offerTraces === [] ? '' : 'still references it: ' . implode(', ', $offerTraces)
);

// Public-facing promises, by phrase rather than by the bare word, so
// "Free platform days" in an admin hint is not a false positive.
$promises = [
    'free trial', 'Free trial', 'completely FREE', 'FREE for', 'free plan',
    'Get Started Free', 'Start Free', 'બિલકુલ FREE', 'ફ્રી ટ્રાયલ', 'ત્રણ વર્ષ', '3 વર્ષ',
];
$copyFiles = [
    'public/index.php' => TestRunner::source('public/index.php'),
    'README.md' => TestRunner::source('README.md'),
    'app/views/admin/global_settings.php' => TestRunner::source('app/views/admin/global_settings.php'),
];
$found = [];
foreach ($copyFiles as $path => $body) {
    foreach ($promises as $phrase) {
        if (str_contains($body, $phrase)) {
            $found[] = $path . ': "' . $phrase . '"';
        }
    }
}
TestRunner::ok(
    'no page promises anything free',
    $found === [],
    $found === [] ? '' : implode(' | ', $found)
);

// Any wording about a trial must be generated from the setting that
// grants it, and that setting defaults to zero in every call site.
$landing = TestRunner::source('public/index.php');
TestRunner::ok(
    'the landing page derives trial wording from the setting',
    str_contains($landing, "getSystemSetting(\$pdo, 'signup_subscription_trial_days', '0')")
        && str_contains($landing, '$trialPeriod = $trialDays > 0'),
    'hard-coded copy is how the page came to advertise an offer that did not exist'
);
TestRunner::ok(
    'structured data no longer tells Google the product is free',
    !str_contains($landing, "'price' => '0'"),
    'price 0 in the Offer schema advertises free software in search results'
);

$zeroDefault = [];
foreach (['app/controllers/ClientController.php', 'app/controllers/ResellerController.php',
          'app/controllers/SuperAdminController.php', 'public/index.php'] as $path) {
    $body = TestRunner::source($path);
    if (!str_contains($body, 'signup_subscription_trial_days')) {
        continue;
    }
    if (preg_match("/signup_subscription_trial_days'[^)]*'([1-9]\d*)'/", $body, $m)) {
        $zeroDefault[] = $path . ' defaults to ' . $m[1];
    }
}
TestRunner::ok(
    'the sign-up trial defaults to zero everywhere it is read',
    $zeroDefault === [],
    $zeroDefault === [] ? '' : implode(', ', $zeroDefault)
);

// A business that has registered but not paid is the normal state now,
// so the panel has to point them at recharging, not at support.
TestRunner::ok(
    'the no-plan message tells the client how to fix it',
    str_contains(TestRunner::source('app/helpers/subscription_helper.php'), 'Recharge your wallet to activate'),
    'the old copy said "contact support", which is now the common case and a dead end'
);
TestRunner::ok(
    'the no-plan notice carries a recharge button',
    str_contains(TestRunner::source('app/views/client/partials/layout_head.php'),
        "in_array(\$validity['state'], ['expired', 'none'], true) ? 'Recharge Now'"),
    ''
);

// And the live database has to be cleaned, since a saved setting always
// beats a PHP default.
$cleanup = TestRunner::source('database/migrations/2026_11_18_platform_is_paid_remove_free_offers.sql');
TestRunner::ok('the cleanup migration exists', $cleanup !== '');
TestRunner::ok(
    'it deletes the special-district settings',
    str_contains($cleanup, "DELETE FROM system_settings")
        && str_contains($cleanup, "'special_district_names', 'special_district_trial_days'"),
    ''
);
TestRunner::ok(
    'it zeroes the sign-up trial',
    str_contains($cleanup, "signup_subscription_trial_days") && str_contains($cleanup, "SET setting_value = '0'"),
    ''
);
TestRunner::ok(
    'it turns the offer banner off',
    str_contains($cleanup, 'landing_offer_enabled'),
    ''
);
TestRunner::ok(
    'it clears stored copy that still advertises something free',
    str_contains($cleanup, 'landing_offer_text') && str_contains($cleanup, 'landing_faqs')
        && str_contains($cleanup, "LIKE '%ફ્રી%'"),
    'Gujarati copy would survive an English-only sweep'
);
TestRunner::ok(
    'it never shortens validity a client already holds',
    !str_contains($cleanup, 'UPDATE clients') && !str_contains($cleanup, 'DELETE FROM clients'),
    'cutting off a paying or promised customer is a business decision, not a migration'
);
TestRunner::ok(
    'the superseded migration is retired through the removal manifest',
    str_contains(TestRunner::source('deploy/removals.txt'), '2026_11_18_remove_three_year_free_offer.sql'),
    'otherwise it stays on servers that already deployed it'
);
