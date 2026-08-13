<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/Logger.php';

/**
 * AppBootstrap
 * -------------------------------------------------------------------
 * Centralized error handling for every entry point.
 *
 * Wired in from app/config/config.php, which every public script and
 * cron job already includes, so no route needs to opt in.
 *
 * Responsibilities:
 *  - Hide internal errors from end users in production, while logging
 *    the full exception with a reference ID the user can quote.
 *  - Convert PHP warnings/notices into logged events instead of text
 *    printed into the middle of a page or a JSON body.
 *  - Catch fatal errors on shutdown so they are recorded rather than
 *    disappearing into a blank 500.
 *
 * Responses adapt to the caller: JSON for API/AJAX endpoints, a plain
 * HTML page for browsers, stderr for CLI.
 */
final class AppBootstrap
{
    private static bool $installed = false;
    private static bool $handlingFatal = false;

    public static function install(): void
    {
        if (self::$installed) {
            return;
        }
        self::$installed = true;

        $isProduction = self::isProduction();

        // Never leak internals to the browser in production; always log.
        ini_set('display_errors', $isProduction ? '0' : '1');
        ini_set('display_startup_errors', $isProduction ? '0' : '1');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);

        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function isProduction(): bool
    {
        return !defined('APP_ENV') || APP_ENV !== 'development';
    }

    /**
     * Uncaught exception: log it, then show a reference-only message.
     */
    public static function handleException(Throwable $e): void
    {
        $reference = Logger::critical(
            Logger::CH_APP,
            'Uncaught ' . get_class($e) . ': ' . $e->getMessage(),
            ['handler' => 'exception'],
            $e
        );
        self::respond($reference);
    }

    /**
     * PHP warnings/notices: log and swallow. Returning true stops PHP
     * from printing the message into the response body.
     */
    public static function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return true; // suppressed with @ — respect the author's intent
        }

        $map = [
            E_WARNING => Logger::WARNING,
            E_NOTICE => Logger::INFO,
            E_DEPRECATED => Logger::INFO,
            E_USER_WARNING => Logger::WARNING,
            E_USER_NOTICE => Logger::INFO,
            E_USER_DEPRECATED => Logger::INFO,
            E_USER_ERROR => Logger::ERROR,
            E_RECOVERABLE_ERROR => Logger::ERROR,
        ];
        $level = $map[$severity] ?? Logger::WARNING;

        Logger::log($level, Logger::CH_APP, $message, [
            'handler' => 'error',
            'php_severity' => $severity,
            'file' => $file,
            'line' => $line,
        ]);

        return true;
    }

    /**
     * Fatal errors (parse/OOM/undefined method) never reach the
     * exception handler — catch them here so they are recorded.
     */
    public static function handleShutdown(): void
    {
        if (self::$handlingFatal) {
            return;
        }
        $error = error_get_last();
        if ($error === null) {
            return;
        }
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($error['type'], $fatal, true)) {
            return;
        }
        self::$handlingFatal = true;

        $reference = Logger::critical(Logger::CH_APP, 'Fatal error: ' . $error['message'], [
            'handler' => 'shutdown',
            'file' => $error['file'] ?? '',
            'line' => $error['line'] ?? 0,
        ]);
        self::respond($reference);
    }

    /**
     * Emit a user-safe message carrying only the reference ID.
     */
    private static function respond(string $reference): void
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Fatal error. Reference: {$reference} (see storage/logs/app-" . date('Y-m-d') . ".log)\n");
            return;
        }

        if (self::wantsJson()) {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'ok' => false,
                'message' => 'Something went wrong. Reference: ' . $reference,
                'reference' => $reference,
            ], JSON_UNESCAPED_SLASHES);
            return;
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        $safeRef = htmlspecialchars($reference, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Something went wrong</title></head>'
            . '<body style="margin:0;font-family:Segoe UI,Arial,sans-serif;background:#f7f8fc;color:#1f2937">'
            . '<div style="max-width:520px;margin:14vh auto;padding:28px;background:#fff;border-radius:14px;'
            . 'border-top:5px solid #f4b400;box-shadow:0 10px 26px rgba(0,95,143,.12)">'
            . '<h1 style="margin:0 0 10px;font-size:1.3rem;color:#005f8f">Something went wrong</h1>'
            . '<p style="margin:0 0 14px;color:#475569">We could not complete that request. '
            . 'Please try again in a moment.</p>'
            . '<p style="margin:0 0 6px;color:#475569">If you contact support, please quote this reference:</p>'
            . '<p style="margin:0;font-family:ui-monospace,Menlo,Consolas,monospace;font-weight:700;'
            . 'background:#f1f5f9;border-radius:8px;padding:10px 12px">' . $safeRef . '</p>'
            . '</div></body></html>';
    }

    /**
     * True when the caller expects JSON rather than a HTML page.
     */
    private static function wantsJson(): bool
    {
        foreach (headers_list() as $header) {
            if (stripos($header, 'content-type:') === 0 && stripos($header, 'json') !== false) {
                return true;
            }
        }
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return true;
        }
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        if ($accept !== '' && str_contains($accept, 'application/json') && !str_contains($accept, 'text/html')) {
            return true;
        }
        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        return in_array($script, [
            'review_api.php', 'register.php', 'register_facilities.php', 'dashboard_stats.php',
            'razorpay_verify.php', 'razorpay_webhook.php', 'client_recharge.php',
            'trigger_invite.php', 'admin_system_update.php',
        ], true) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }
}
