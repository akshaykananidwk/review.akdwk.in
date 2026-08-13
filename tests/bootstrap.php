<?php
declare(strict_types=1);

/**
 * Test harness: assertions, suite grouping, and an optional throwaway
 * database connection for integration tests.
 */
final class TestRunner
{
    private static int $passed = 0;
    private static int $failed = 0;
    private static int $skipped = 0;
    private static string $suite = '';
    /** @var string[] */
    private static array $failures = [];

    public static function suite(string $name): void
    {
        self::$suite = $name;
        echo "\n\033[1m" . $name . "\033[0m\n";
    }

    public static function ok(string $description, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            self::$passed++;
            echo "  \033[32m✓\033[0m {$description}\n";
            return;
        }
        self::$failed++;
        self::$failures[] = self::$suite . ' → ' . $description . ($detail !== '' ? "  [{$detail}]" : '');
        echo "  \033[31m✗ {$description}\033[0m" . ($detail !== '' ? "  [{$detail}]" : '') . "\n";
    }

    public static function same(string $description, mixed $expected, mixed $actual): void
    {
        self::ok(
            $description,
            $expected === $actual,
            $expected === $actual ? '' : 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }

    public static function skip(string $description, string $why): void
    {
        self::$skipped++;
        echo "  \033[33m–\033[0m {$description} \033[2m({$why})\033[0m\n";
    }

    /** Assert that a callable throws the given exception class. */
    public static function throws(string $description, string $exceptionClass, callable $fn): void
    {
        try {
            $fn();
            self::ok($description, false, 'no exception thrown');
        } catch (Throwable $e) {
            self::ok($description, $e instanceof $exceptionClass, 'got ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    public static function report(): int
    {
        echo "\n" . str_repeat('─', 56) . "\n";
        if (self::$failed > 0) {
            echo "\033[31mFAILED\033[0m  ";
            foreach (self::$failures as $failure) {
                echo "\n  • {$failure}";
            }
            echo "\n\n";
        }
        printf(
            "%d passed, %d failed, %d skipped\n",
            self::$passed,
            self::$failed,
            self::$skipped
        );
        return self::$failed > 0 ? 1 : 0;
    }

    /** Absolute path to a project file. */
    public static function path(string $relative): string
    {
        return dirname(__DIR__) . '/' . ltrim($relative, '/');
    }

    /** Read a project source file (for structural regression guards). */
    public static function source(string $relative): string
    {
        $file = self::path($relative);
        return is_file($file) ? (string)file_get_contents($file) : '';
    }

    /**
     * Throwaway test database, or null when not configured.
     * Integration tests must skip rather than fail when this is null.
     */
    public static function db(): ?PDO
    {
        static $pdo = null;
        static $tried = false;
        if ($tried) {
            return $pdo;
        }
        $tried = true;

        $name = getenv('TEST_DB_NAME');
        $user = getenv('TEST_DB_USER');
        if ($name === false || $name === '' || $user === false || $user === '') {
            return null;
        }
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $pass = (string)(getenv('TEST_DB_PASSWORD') ?: '');

        try {
            $pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec("SET time_zone = '+05:30'");
        } catch (Throwable) {
            $pdo = null;
        }
        return $pdo;
    }
}
