<?php
declare(strict_types=1);

/**
 * Regression guards for the self-update path.
 *
 * These exist because of a real production incident on 2026-11-07:
 * a migration ran `EXECUTE stmt` where the prepared statement was a
 * SELECT. PDO::exec() sends a query but never reads the result set back,
 * so that result stayed on the wire and every later statement on the
 * connection failed with "Commands out of sync" — including the two
 * writes that were supposed to report the failure. The update was left
 * marked "running" with no error in its log, and the lock file was never
 * released, so the next attempt was refused for 30 minutes.
 *
 * Three independent things had to go wrong for that outcome. Each one is
 * guarded below.
 */

$svc = TestRunner::source('app/services/GitHubUpdateService.php');

// ---------------------------------------------------------------------
// 1. Migration statements must leave the connection clean
// ---------------------------------------------------------------------
TestRunner::ok(
    'migration statements run through runSqlStatement(), not PDO::exec()',
    str_contains($svc, 'private function runSqlStatement(string $statement): void')
        && !preg_match('/foreach \(\$this->splitSqlStatements\([^)]*\) as \$statement\) \{\s*\$this->pdo->exec/', $svc),
    'exec() cannot read a result set back, so a row-returning statement poisons the connection'
);
TestRunner::ok(
    'runSqlStatement frees the result set',
    str_contains($svc, '$stmt->closeCursor();') && str_contains($svc, '$stmt->nextRowset()'),
    'closeCursor() is what releases the connection for the next statement'
);

// ---------------------------------------------------------------------
// 2. The failure path must survive an unusable database connection
// ---------------------------------------------------------------------
$failure = TestRunner::between($svc, 'private function handleStepFailure', "\n    /** logUpdate() that cannot throw");
TestRunner::ok(
    'the failure handler exists and was located for inspection',
    $failure !== '',
    'the assertions below are meaningless if this is empty'
);
TestRunner::ok(
    'the failure handler never calls logUpdate() unguarded',
    !str_contains($failure, '$this->logUpdate('),
    'logUpdate() writes to the database, which is exactly what may be broken here'
);
TestRunner::ok(
    'marking the update failed is wrapped in try/catch',
    (bool)preg_match('/try \{\s*\$stmt = \$this->pdo->prepare\("\s*UPDATE system_updates\s*SET status/', $failure),
    'an exception here skipped the lock release'
);
TestRunner::ok(
    'the lock is released on every path',
    str_contains($failure, '$this->releaseLock();')
        && substr_count($failure, 'releaseLock') === 1
        && strpos($failure, 'releaseLock') > strpos($failure, 'cleanupStaging'),
    'releaseLock() must be last and unconditional, or the updater wedges for 30 minutes'
);
TestRunner::ok(
    'cleanupStaging cannot block the lock release',
    (bool)preg_match('/try \{\s*\$this->cleanupStaging\(\);\s*\} catch/', $failure),
    ''
);
TestRunner::ok(
    'safeLog falls back to the file log',
    str_contains($svc, 'private function safeLog(int $updateId, string $message): void')
        && (bool)preg_match('/function safeLog.*?catch \(Throwable \$le\).*?writeLogFile/s', $svc),
    'the database copy of the log is the one that disappears in this failure mode'
);

// ---------------------------------------------------------------------
// 3. The on-disk log must not depend on the database
// ---------------------------------------------------------------------
$logUpdate = TestRunner::between($svc, 'private function logUpdate', 'private function writeLogFile');
TestRunner::ok(
    'logUpdate writes the file line before the database row',
    strpos($logUpdate, 'writeLogFile') !== false
        && strpos($logUpdate, 'writeLogFile') < strpos($logUpdate, '$stmt->execute('),
    'written second, the file line is lost whenever the database write throws'
);

// ---------------------------------------------------------------------
// 4. No shipped migration may use a row-returning no-op branch
//
//    IF(<guard>, '<ddl>', 'SELECT 1') is the idiom that caused the
//    incident: on the branch where the schema change is already present,
//    EXECUTE returns a row.
// ---------------------------------------------------------------------
$migrationDir = TestRunner::path('database/migrations');
$offenders = [];
foreach (glob($migrationDir . '/*.sql') ?: [] as $file) {
    $sql = (string)file_get_contents($file);
    if (!str_contains($sql, 'PREPARE stmt FROM')) {
        continue;
    }
    if (preg_match("/,\s*'SELECT\s/i", $sql)) {
        $offenders[] = basename($file);
    }
}
TestRunner::ok(
    'no migration prepares a SELECT as its no-op branch',
    $offenders === [],
    $offenders === [] ? '' : 'offending files: ' . implode(', ', $offenders)
);

// The category migration added for Varni Associate must stay re-runnable:
// facility_name is UNIQUE table-wide, so an unguarded INSERT would abort a
// second attempt and roll the whole update back.
$categoryMigration = TestRunner::source('database/migrations/2026_11_07_insurance_advisor_category.sql');
TestRunner::ok(
    'the category migration is guarded against re-running',
    substr_count($categoryMigration, 'NOT EXISTS') === 2,
    'one guard for the category row, one for the facility rows'
);
TestRunner::ok(
    'the category migration uses FROM DUAL where it has a WHERE clause',
    str_contains($categoryMigration, 'FROM DUAL'),
    'MySQL rejects WHERE on a SELECT with no FROM'
);
