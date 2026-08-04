<?php
declare(strict_types=1);

/**
 * Loads key=value pairs from project-root `.env` into getenv()/putenv().
 * Safe to require_once multiple times. Does not override existing getenv() values
 * (so real server environment variables always win).
 */
final class BootstrapEnv
{
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        $root = dirname(__DIR__, 2);
        $path = $root . DIRECTORY_SEPARATOR . '.env';
        if (!is_readable($path)) {
            return;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            if ($name === '' || preg_match('/[^A-Za-z0-9_]/', $name)) {
                continue;
            }
            $value = trim($value);
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }
            // Unescape common sequences in double-quoted style
            $value = str_replace(['\\n', '\\r', '\\"'], ["\n", "\r", '"'], $value);

            if (getenv($name) !== false) {
                continue;
            }
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }
    }

    public static function envString(string $key, string $default = ''): string
    {
        $v = getenv($key);
        if ($v === false || $v === '') {
            return $default;
        }
        return trim((string)$v);
    }
}
