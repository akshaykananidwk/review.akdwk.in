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
TestRunner::same('both render guards were found', 2, count($guards[1] ?? []));
foreach ($guards[1] ?? [] as $i => $list) {
    TestRunner::ok(
        "render guard #" . ($i + 1) . " excludes the unknown state",
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
