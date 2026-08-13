<?php
declare(strict_types=1);

/**
 * Logger
 * -------------------------------------------------------------------
 * Structured application logging with severity, channels and a
 * user-facing reference ID.
 *
 * Design rules (this class is load-bearing for incident response):
 *  - It MUST NEVER throw. A logging failure must not break a request.
 *  - It writes JSON lines to storage/logs/app-YYYY-MM-DD.log so logs
 *    are greppable and machine-readable.
 *  - warning and above are additionally persisted to system_event_logs
 *    so they are queryable from the admin panel (best effort — if the
 *    table is missing the file log still succeeds).
 *  - Every error/critical entry gets a reference ID of the form
 *    ERR-YYYYMMDD-XXXXXX which is safe to show to end users, while the
 *    exception, stack trace and context stay in the logs.
 *
 * Usage:
 *   Logger::error(Logger::CH_PAYMENT, 'Wallet credit failed', ['order' => $id], $e);
 *   $ref = Logger::exception(Logger::CH_APP, $e);   // returns the reference ID
 */
final class Logger
{
    public const CH_APP      = 'app';
    public const CH_AUTH     = 'auth';
    public const CH_SECURITY = 'security';
    public const CH_PAYMENT  = 'payment';
    public const CH_WALLET   = 'wallet';
    public const CH_AI       = 'ai';
    public const CH_WHATSAPP = 'whatsapp';
    public const CH_API      = 'api';
    public const CH_CRON     = 'cron';
    public const CH_UPDATE   = 'update';

    public const DEBUG    = 'debug';
    public const INFO     = 'info';
    public const WARNING  = 'warning';
    public const ERROR    = 'error';
    public const CRITICAL = 'critical';

    /** Severities that also get persisted to the database. */
    private const DB_SEVERITIES = [self::WARNING, self::ERROR, self::CRITICAL];

    /** Context keys whose values are masked before they reach any log. */
    private const SECRET_KEYS = [
        'password', 'password_hash', 'new_password', 'current_password', 'confirm_password',
        'api_key', 'master_api_key', 'token', 'github_token', 'csrf_token', 'otp',
        'razorpay_key_secret', 'razorpay_webhook_secret', 'whatsapp_api_key', 'whatsapp_token',
        'secret', 'authorization', 'reset_token', 'signature',
    ];

    private static ?PDO $pdo = null;
    private static bool $dbUnavailable = false;

    /**
     * Give the logger a PDO handle so warnings and above can be
     * persisted. Optional — file logging works without it.
     */
    public static function useDatabase(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function debug(string $channel, string $message, array $context = []): string
    {
        return self::log(self::DEBUG, $channel, $message, $context, null);
    }

    public static function info(string $channel, string $message, array $context = []): string
    {
        return self::log(self::INFO, $channel, $message, $context, null);
    }

    public static function warning(string $channel, string $message, array $context = [], ?Throwable $e = null): string
    {
        return self::log(self::WARNING, $channel, $message, $context, $e);
    }

    public static function error(string $channel, string $message, array $context = [], ?Throwable $e = null): string
    {
        return self::log(self::ERROR, $channel, $message, $context, $e);
    }

    public static function critical(string $channel, string $message, array $context = [], ?Throwable $e = null): string
    {
        return self::log(self::CRITICAL, $channel, $message, $context, $e);
    }

    /** Convenience wrapper for a caught exception. Returns the reference ID. */
    public static function exception(string $channel, Throwable $e, array $context = [], string $severity = self::ERROR): string
    {
        return self::log($severity, $channel, $e->getMessage(), $context, $e);
    }

    /**
     * Security events are always at least a warning and always carry the
     * request fingerprint, so abuse can be traced without extra plumbing.
     */
    public static function security(string $message, array $context = [], string $severity = self::WARNING): string
    {
        return self::log($severity, self::CH_SECURITY, $message, $context, null);
    }

    /**
     * @return string reference ID (ERR-YYYYMMDD-XXXXXX)
     */
    public static function log(
        string $severity,
        string $channel,
        string $message,
        array $context = [],
        ?Throwable $e = null
    ): string {
        $reference = self::newReference();

        try {
            $entry = [
                'ts' => date('c'),
                'ref' => $reference,
                'severity' => $severity,
                'channel' => $channel,
                'message' => self::truncate($message, 1000),
                'context' => self::sanitize($context),
                'request' => self::requestFingerprint(),
            ];
            if ($e !== null) {
                $entry['exception'] = [
                    'class' => get_class($e),
                    'message' => self::truncate($e->getMessage(), 1000),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => self::truncate($e->getTraceAsString(), 4000),
                ];
            }

            self::writeFile($entry);

            if (in_array($severity, self::DB_SEVERITIES, true)) {
                self::writeDatabase($entry);
            }
        } catch (Throwable) {
            // Logging must never break the request. Deliberately silent:
            // a failure here has nowhere left to be reported.
        }

        return $reference;
    }

    public static function newReference(): string
    {
        try {
            $suffix = strtoupper(bin2hex(random_bytes(3)));
        } catch (Throwable) {
            $suffix = strtoupper(substr(md5((string)microtime(true)), 0, 6));
        }
        return 'ERR-' . date('Ymd') . '-' . $suffix;
    }

    // ------------------------------------------------------------------
    // Writers
    // ------------------------------------------------------------------

    private static function writeFile(array $entry): void
    {
        $dir = self::logDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            $line = json_encode([
                'ts' => date('c'),
                'ref' => $entry['ref'] ?? '',
                'severity' => $entry['severity'] ?? 'error',
                'channel' => $entry['channel'] ?? 'app',
                'message' => 'Log entry could not be encoded to JSON',
            ]);
        }
        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function writeDatabase(array $entry): void
    {
        if (self::$dbUnavailable) {
            return;
        }
        $pdo = self::$pdo;
        if (!$pdo instanceof PDO) {
            return;
        }
        try {
            $stmt = $pdo->prepare("
                INSERT INTO system_event_logs
                    (reference_id, severity, channel, message, context_json, exception_class,
                     exception_message, exception_file, exception_line, request_uri, request_method,
                     ip_address, user_agent, actor_type, actor_id, created_at)
                VALUES
                    (:ref, :sev, :ch, :msg, :ctx, :ecls, :emsg, :efile, :eline, :uri, :method,
                     :ip, :ua, :atype, :aid, NOW())
            ");
            $req = $entry['request'] ?? [];
            $exc = $entry['exception'] ?? [];
            $stmt->execute([
                ':ref' => $entry['ref'],
                ':sev' => $entry['severity'],
                ':ch' => $entry['channel'],
                ':msg' => self::truncate((string)$entry['message'], 1000),
                ':ctx' => json_encode($entry['context'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':ecls' => $exc['class'] ?? null,
                ':emsg' => isset($exc['message']) ? self::truncate((string)$exc['message'], 2000) : null,
                ':efile' => $exc['file'] ?? null,
                ':eline' => isset($exc['line']) ? (int)$exc['line'] : null,
                ':uri' => $req['uri'] ?? null,
                ':method' => $req['method'] ?? null,
                ':ip' => $req['ip'] ?? null,
                ':ua' => isset($req['ua']) ? self::truncate((string)$req['ua'], 500) : null,
                ':atype' => $req['actor_type'] ?? null,
                ':aid' => isset($req['actor_id']) ? (int)$req['actor_id'] : null,
            ]);
        } catch (Throwable) {
            // Table missing (migration not yet run) or DB down — stop
            // retrying for this request; the file log already has it.
            self::$dbUnavailable = true;
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static function logDir(): string
    {
        return dirname(__DIR__, 2) . '/storage/logs';
    }

    /**
     * @return array<string,mixed>
     */
    private static function requestFingerprint(): array
    {
        $actorType = null;
        $actorId = null;
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!empty($_SESSION['admin_id'])) {
                $actorType = (string)($_SESSION['admin_role'] ?? 'admin');
                $actorId = (int)$_SESSION['admin_id'];
            } elseif (!empty($_SESSION['client_id'])) {
                $actorType = 'client';
                $actorId = (int)$_SESSION['client_id'];
            }
        }

        return [
            'uri' => isset($_SERVER['REQUEST_URI']) ? self::truncate((string)$_SERVER['REQUEST_URI'], 500) : null,
            'method' => $_SERVER['REQUEST_METHOD'] ?? (PHP_SAPI === 'cli' ? 'CLI' : null),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'ua' => isset($_SERVER['HTTP_USER_AGENT']) ? self::truncate((string)$_SERVER['HTTP_USER_AGENT'], 500) : null,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
        ];
    }

    /**
     * Recursively mask secrets and clip oversized values so credentials
     * never reach a log file.
     *
     * @param array<mixed> $context
     * @return array<mixed>
     */
    private static function sanitize(array $context, int $depth = 0): array
    {
        if ($depth > 4) {
            return ['_truncated' => 'max depth reached'];
        }
        $clean = [];
        foreach ($context as $key => $value) {
            $keyLower = is_string($key) ? strtolower($key) : (string)$key;
            $isSecret = false;
            foreach (self::SECRET_KEYS as $secret) {
                if (str_contains($keyLower, $secret)) {
                    $isSecret = true;
                    break;
                }
            }
            if ($isSecret) {
                $clean[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = self::sanitize($value, $depth + 1);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = is_string($value) ? self::truncate($value, 500) : $value;
            } else {
                $clean[$key] = '[' . get_debug_type($value) . ']';
            }
        }
        return $clean;
    }

    private static function truncate(string $value, int $max): string
    {
        if (strlen($value) <= $max) {
            return $value;
        }
        return substr($value, 0, $max) . '…[truncated]';
    }
}
