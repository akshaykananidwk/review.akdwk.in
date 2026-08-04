<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/helpers/session_helper.php';
require_once __DIR__ . '/../app/helpers/csrf_helper.php';

requireClientPanelAccess();

csrfEnsureSession();
$rel = (string)($_SESSION['standee_dl_path'] ?? '');
$ts = (int)($_SESSION['standee_dl_ts'] ?? 0);
$clientId = (int)($_SESSION['client_id'] ?? 0);

if ($rel === '' || $ts < 1 || $clientId <= 0 || (time() - $ts) > 600) {
    http_response_code(404);
    exit('Download expired or missing. Generate your standee again from the gallery.');
}

if (!preg_match('#^uploads/standee/generated/c' . $clientId . '_t\d+_[a-f0-9]+\.png$#', str_replace('\\', '/', $rel))) {
    unset($_SESSION['standee_dl_path'], $_SESSION['standee_dl_ts']);
    http_response_code(403);
    exit('Invalid download.');
}

$absolutePath = __DIR__ . '/' . ltrim($rel, '/');
if (!is_file($absolutePath) || !str_starts_with(str_replace('\\', '/', realpath($absolutePath) ?: ''), str_replace('\\', '/', realpath(__DIR__) ?: ''))) {
    unset($_SESSION['standee_dl_path'], $_SESSION['standee_dl_ts']);
    http_response_code(404);
    exit('File not found.');
}

unset($_SESSION['standee_dl_path'], $_SESSION['standee_dl_ts']);

header('Content-Type: image/png');
header('Content-Disposition: attachment; filename="krishna-standee-print.png"');
header('Content-Length: ' . (string)filesize($absolutePath));
header('Cache-Control: no-store');
readfile($absolutePath);
exit;
