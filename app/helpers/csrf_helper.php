<?php
declare(strict_types=1);

function csrfEnsureSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? '1' : '0');
        ini_set('session.cookie_samesite', 'Lax');
        session_start();
    }
}

function csrfGenerateToken(): string
{
    csrfEnsureSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfValidateToken(?string $token): bool
{
    csrfEnsureSession();
    if (!$token || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Hidden input to drop inside every state-changing <form>.
 * Usage in a view:  <?= csrfField() ?>
 */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrfGenerateToken(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Enforce CSRF on the current request and stop it dead if the token is
 * missing or wrong. Logs a security event, then answers in the format
 * the caller expects.
 *
 * Call at the top of every POST handler:
 *     csrfRequireValidToken();
 *
 * Only enforces on state-changing verbs, so it is safe to call
 * unconditionally at the top of a mixed GET/POST controller method.
 */
function csrfRequireValidToken(?string $token = null): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }

    $token = $token ?? (isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null);
    if (csrfValidateToken($token)) {
        return;
    }

    require_once __DIR__ . '/../services/Logger.php';

    // A POST body larger than post_max_size arrives with $_POST and $_FILES
    // both empty, which looks exactly like a missing CSRF token. Telling an
    // admin "your session expired" when they uploaded a 30 MB image would
    // send them chasing the wrong problem.
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 0 && $_POST === [] && $_FILES === []) {
        $limit = (string)ini_get('post_max_size');
        Logger::warning(Logger::CH_APP, 'POST body exceeded post_max_size', [
            'content_length' => $contentLength,
            'post_max_size' => $limit,
            'script' => basename((string)($_SERVER['SCRIPT_NAME'] ?? '')),
        ]);
        if (!headers_sent()) {
            http_response_code(413);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!doctype html><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<div style="max-width:520px;margin:14vh auto;padding:28px;font-family:Segoe UI,Arial,sans-serif;'
            . 'background:#fff;border-radius:14px;border-top:5px solid #f4b400">'
            . '<h1 style="margin:0 0 10px;font-size:1.3rem;color:#005f8f">File too large</h1>'
            . '<p style="color:#475569;margin:0">That upload exceeds the server limit of '
            . htmlspecialchars($limit, ENT_QUOTES, 'UTF-8')
            . '. Please use a smaller file, or ask your host to raise <code>post_max_size</code>.</p></div>';
        exit;
    }
    $reference = Logger::security('CSRF validation failed — request rejected', [
        'script' => basename((string)($_SERVER['SCRIPT_NAME'] ?? '')),
        'action' => isset($_POST['action']) ? substr((string)$_POST['action'], 0, 60) : null,
        'token_present' => $token !== null && $token !== '',
        'referer' => isset($_SERVER['HTTP_REFERER']) ? substr((string)$_SERVER['HTTP_REFERER'], 0, 300) : null,
    ]);

    $wantsJson = false;
    foreach (headers_list() as $header) {
        if (stripos($header, 'content-type:') === 0 && stripos($header, 'json') !== false) {
            $wantsJson = true;
            break;
        }
    }

    if (!headers_sent()) {
        http_response_code(419); // "Authentication Timeout" — conventional for expired CSRF
    }

    if ($wantsJson) {
        echo json_encode([
            'ok' => false,
            'message' => 'Your session expired. Please reload the page and try again. Reference: ' . $reference,
            'reference' => $reference,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    $safeRef = htmlspecialchars($reference, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Session expired</title></head>'
        . '<body style="margin:0;font-family:Segoe UI,Arial,sans-serif;background:#f7f8fc;color:#1f2937">'
        . '<div style="max-width:520px;margin:14vh auto;padding:28px;background:#fff;border-radius:14px;'
        . 'border-top:5px solid #f4b400;box-shadow:0 10px 26px rgba(0,95,143,.12)">'
        . '<h1 style="margin:0 0 10px;font-size:1.3rem;color:#005f8f">Session expired</h1>'
        . '<p style="margin:0 0 14px;color:#475569">For your security this request was blocked. '
        . 'Please go back, reload the page and submit the form again.</p>'
        . '<p style="margin:0;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.85rem;'
        . 'background:#f1f5f9;border-radius:8px;padding:10px 12px">' . $safeRef . '</p>'
        . '</div></body></html>';
    exit;
}
