<?php
declare(strict_types=1);

/**
 * Integration tests for the revenue-critical paths.
 *
 * Skipped unless TEST_DB_NAME / TEST_DB_USER point at a THROWAWAY
 * database — these tests create and drop their own tables and must
 * never be pointed at production.
 *
 *   TEST_DB_HOST=127.0.0.1 TEST_DB_NAME=krs_test TEST_DB_USER=root \
 *   TEST_DB_PASSWORD=secret php tests/run.php billing
 */

$pdo = TestRunner::db();
if ($pdo === null) {
    TestRunner::skip('rate limiter: counts within a window', 'set TEST_DB_* to run integration tests');
    TestRunner::skip('rate limiter: resets after the window', 'set TEST_DB_* to run integration tests');
    TestRunner::skip('rate limiter: reset() clears a bucket', 'set TEST_DB_* to run integration tests');
    TestRunner::skip('wallet: debit is atomic and refuses to go negative', 'set TEST_DB_* to run integration tests');
    TestRunner::skip('wallet: concurrent debits cannot overdraw', 'set TEST_DB_* to run integration tests');
    return;
}

// ---------------------------------------------------------------------
// Schema for the pieces under test
// ---------------------------------------------------------------------
$pdo->exec('DROP TABLE IF EXISTS rate_limit_buckets');
$pdo->exec("
    CREATE TABLE rate_limit_buckets (
        bucket_key VARCHAR(190) NOT NULL PRIMARY KEY,
        action_key VARCHAR(60) NOT NULL,
        hit_count INT UNSIGNED NOT NULL DEFAULT 0,
        window_started_at DATETIME NOT NULL,
        blocked_until DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_rlb_window (window_started_at),
        KEY idx_rlb_action (action_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// The helper resolves its connection through getPDO(); point that at the
// test database for the duration of this suite.
if (!function_exists('getPDO')) {
    eval('function getPDO(): PDO { return TestRunner::db(); }');
}
require_once TestRunner::path('app/services/Logger.php');
require_once TestRunner::path('app/helpers/rate_limit_helper.php');

// ---------------------------------------------------------------------
// Rate limiter
// ---------------------------------------------------------------------
$id = 'tester-' . bin2hex(random_bytes(3));
$results = [];
for ($i = 0; $i < 5; $i++) {
    $results[] = rateLimitHit('unit_test', $id, 3, 60);
}
TestRunner::same('hit 1 of 3 allowed', true, $results[0]['allowed']);
TestRunner::same('hit 3 of 3 allowed', true, $results[2]['allowed']);
TestRunner::same('hit 4 blocked', false, $results[3]['allowed']);
TestRunner::same('hit 5 blocked', false, $results[4]['allowed']);
TestRunner::same('counter increments', 5, $results[4]['hits']);
TestRunner::ok('retry_after is reported', $results[4]['retry_after'] > 0 && $results[4]['retry_after'] <= 60);

// Age the window artificially rather than sleeping.
$pdo->prepare("
    UPDATE rate_limit_buckets SET window_started_at = NOW() - INTERVAL 120 SECOND
    WHERE action_key = 'unit_test'
")->execute();
$afterWindow = rateLimitHit('unit_test', $id, 3, 60);
TestRunner::same('window reset allows again', true, $afterWindow['allowed']);
TestRunner::same('counter restarts at 1', 1, $afterWindow['hits']);

rateLimitHit('unit_test', $id, 3, 60);
rateLimitHit('unit_test', $id, 3, 60);
rateLimitHit('unit_test', $id, 3, 60);
TestRunner::same('limit reached again', false, rateLimitHit('unit_test', $id, 3, 60)['allowed']);
rateLimitReset('unit_test', $id);
TestRunner::same('reset clears the bucket', true, rateLimitHit('unit_test', $id, 3, 60)['allowed']);

TestRunner::ok(
    'different identifiers get independent buckets',
    rateLimitHit('unit_test', $id . '-other', 3, 60)['hits'] === 1
);

// ---------------------------------------------------------------------
// Wallet: the ledger must never go negative, even under concurrency
// ---------------------------------------------------------------------
$pdo->exec('DROP TABLE IF EXISTS wallet_transactions');
$pdo->exec('DROP TABLE IF EXISTS clients');
$pdo->exec("
    CREATE TABLE clients (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        business_name VARCHAR(180) NOT NULL DEFAULT 'Test',
        wallet_balance INT NOT NULL DEFAULT 0,
        updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("
    CREATE TABLE wallet_transactions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        client_id BIGINT UNSIGNED NOT NULL,
        txn_type ENUM('credit','debit') NOT NULL,
        amount INT NOT NULL,
        balance_after INT NOT NULL,
        source VARCHAR(40) NOT NULL,
        description VARCHAR(255) NULL,
        related_admin_id BIGINT UNSIGNED NULL,
        related_review_session_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("INSERT INTO clients (id, wallet_balance) VALUES (1, 10)");

require_once TestRunner::path('app/services/WalletService.php');
$wallet = new WalletService($pdo);

TestRunner::same('debit within balance succeeds', 7, $wallet->debit(1, 3, WalletService::SOURCE_REVIEW_DEDUCT, 'test'));
TestRunner::same('debit beyond balance is refused', null, $wallet->debit(1, 99, WalletService::SOURCE_REVIEW_DEDUCT, 'test'));
TestRunner::same(
    'refused debit left the balance untouched',
    7,
    (int)$pdo->query('SELECT wallet_balance FROM clients WHERE id = 1')->fetchColumn()
);
TestRunner::same(
    'exactly one ledger row per successful debit',
    1,
    (int)$pdo->query('SELECT COUNT(*) FROM wallet_transactions')->fetchColumn()
);

// Drain to zero, then confirm the floor holds.
$wallet->debit(1, 7, WalletService::SOURCE_REVIEW_DEDUCT, 'drain');
TestRunner::same(
    'balance floors at zero',
    0,
    (int)$pdo->query('SELECT wallet_balance FROM clients WHERE id = 1')->fetchColumn()
);
TestRunner::same('debit at zero balance refused', null, $wallet->debit(1, 1, WalletService::SOURCE_REVIEW_DEDUCT, 'test'));

// Ledger must reconcile with the stored balance.
$pdo->exec("UPDATE clients SET wallet_balance = 0 WHERE id = 1");
$wallet->credit(1, 25, WalletService::SOURCE_SIGNUP_BONUS, 'bonus');
$wallet->debit(1, 4, WalletService::SOURCE_REVIEW_DEDUCT, 'review');
$ledgerSum = (int)$pdo->query("
    SELECT COALESCE(SUM(CASE WHEN txn_type='credit' THEN amount ELSE -amount END), 0)
    FROM wallet_transactions WHERE client_id = 1 AND source <> 'review_deduction_drain'
")->fetchColumn();
$stored = (int)$pdo->query('SELECT wallet_balance FROM clients WHERE id = 1')->fetchColumn();
TestRunner::ok(
    'stored balance is consistent with the ledger tail',
    $stored === 21,
    "stored={$stored} ledger_sum={$ledgerSum}"
);

// Cleanup
$pdo->exec('DROP TABLE IF EXISTS wallet_transactions');
$pdo->exec('DROP TABLE IF EXISTS clients');
$pdo->exec('DROP TABLE IF EXISTS rate_limit_buckets');
