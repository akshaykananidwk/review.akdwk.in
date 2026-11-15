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

// Catalogue migrations (a business category plus its facilities) must
// stay re-runnable: facility_name is UNIQUE table-wide, so one unguarded
// INSERT would abort a second attempt and roll the whole update back.
//
// Discovered rather than listed, so a new catalogue migration is covered
// the moment it is added instead of when somebody remembers to edit this
// file.
// Comments are stripped first, exactly as GitHubUpdateService's splitter
// does before execution. Counting them would make a sentence in a header
// comment stand in for a real guard.
$stripComments = static function (string $sql): string {
    $kept = [];
    foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
        if (str_starts_with(ltrim($line), '--') || str_starts_with(ltrim($line), '#')) {
            continue;
        }
        $kept[] = $line;
    }
    return implode("\n", $kept);
};

$catalogue = [];
foreach (glob($migrationDir . '/*.sql') ?: [] as $file) {
    $sql = $stripComments((string)file_get_contents($file));
    if (str_contains($sql, 'INSERT INTO business_categories')) {
        $catalogue[basename($file)] = $sql;
    }
}
TestRunner::ok('catalogue migrations were found', $catalogue !== [], count($catalogue) . ' file(s)');

/** @var array<string,string> $nameOwner facility name => file that declares it */
$nameOwner = [];
$collisions = [];

foreach ($catalogue as $name => $sql) {
    $categoryInserts = substr_count($sql, 'INSERT INTO business_categories');
    $facilityInserts = substr_count($sql, 'INSERT INTO facilities');

    TestRunner::same("{$name} declares one facility block per category", $categoryInserts, $facilityInserts);
    TestRunner::ok(
        "{$name} guards every insert against re-running",
        substr_count($sql, 'NOT EXISTS') === $categoryInserts + $facilityInserts,
        'expected ' . ($categoryInserts + $facilityInserts) . ' guards, found ' . substr_count($sql, 'NOT EXISTS')
    );
    TestRunner::same(
        "{$name} uses FROM DUAL on every category insert",
        $categoryInserts,
        substr_count($sql, 'FROM DUAL')
    );

    preg_match_all('/CROSS JOIN \((.*?)\) AS t/s', $sql, $blocks);
    $names = [];
    foreach ($blocks[1] ?? [] as $block) {
        preg_match_all("/SELECT\s+'([^']+)'/", $block, $found);
        $names = array_merge($names, $found[1] ?? []);
    }
    TestRunner::ok(
        "{$name} declares facilities with no internal duplicates",
        $names !== [] && count($names) === count(array_unique($names)),
        count($names) . ' name(s) found'
    );
    TestRunner::ok(
        "{$name} keeps every facility name within the 120-char column",
        array_filter($names, static fn(string $n): bool => strlen($n) > 120) === []
    );

    foreach ($names as $facility) {
        if (isset($nameOwner[$facility])) {
            $collisions[] = $facility . ' (' . $nameOwner[$facility] . ' / ' . $name . ')';
        }
        $nameOwner[$facility] = $name;
    }
}

// facility_name is UNIQUE table-wide, so a name claimed by two categories
// is silently dropped from whichever one is inserted second — the client
// never sees it in their settings, and no error is raised.
TestRunner::ok(
    'no facility name is claimed by two categories',
    $collisions === [],
    $collisions === [] ? count($nameOwner) . ' distinct names' : 'collides: ' . implode(', ', $collisions)
);

// The eight names seeded without a category would collide the same way.
preg_match_all("/\('([^']+)', 1\)/", TestRunner::source('database/seed.sql'), $seeded);
$seedClash = array_intersect($seeded[1] ?? [], array_keys($nameOwner));
TestRunner::ok(
    'no catalogue facility reuses a seeded facility name',
    $seedClash === [],
    $seedClash === [] ? '' : 'collides with seed.sql: ' . implode(', ', $seedClash)
);
