<?php
declare(strict_types=1);

/**
 * Minimal test runner (no Composer / PHPUnit in this project).
 *
 *   php tests/run.php            run everything
 *   php tests/run.php security   run one suite by filename fragment
 *
 * Two kinds of test live here:
 *   *_test.php     pure logic + source-structure regression guards.
 *                  Run anywhere, no database needed.
 *   *_db_test.php  integration tests against a real MySQL/MariaDB.
 *                  Skipped unless TEST_DB_* environment variables are
 *                  set, so they never touch production data.
 *
 * Integration setup (on a server, against a THROWAWAY database):
 *   TEST_DB_HOST=127.0.0.1 TEST_DB_NAME=krs_test \
 *   TEST_DB_USER=root TEST_DB_PASSWORD=secret php tests/run.php
 */

require_once __DIR__ . '/bootstrap.php';

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/*_test.php') ?: [];
sort($files);

foreach ($files as $file) {
    if ($filter !== '' && !str_contains(basename($file), $filter)) {
        continue;
    }
    TestRunner::suite(basename($file, '.php'));
    require $file;
}

exit(TestRunner::report());
