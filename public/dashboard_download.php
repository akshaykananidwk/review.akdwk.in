<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/helpers/session_helper.php';

requireClientPanelAccess();

$clientId = (int)($_SESSION['client_id'] ?? 0);
$type = (string)($_GET['type'] ?? 'qr');
if ($clientId <= 0 || !in_array($type, ['qr', 'standee'], true)) {
    http_response_code(400);
    exit('Invalid download request.');
}

$pdo = getPDO();
$stmt = $pdo->prepare("
    SELECT qr_image_path
    FROM client_qr_codes
    WHERE client_id = :client_id AND is_active = 1
    ORDER BY id DESC
    LIMIT 1
");
$stmt->execute([':client_id' => $clientId]);
$row = $stmt->fetch();
if (!$row || empty($row['qr_image_path'])) {
    http_response_code(404);
    exit('No downloadable file found.');
}

$qrPath = (string)$row['qr_image_path'];
$relativePath = $type === 'standee'
    ? str_replace('uploads/qr/', 'uploads/standee/', (string)preg_replace('/\.png$/', '_standee.png', $qrPath))
    : $qrPath;

$absolutePath = __DIR__ . '/' . ltrim($relativePath, '/');
if (!is_file($absolutePath)) {
    if ($type === 'standee') {
        $absolutePath = __DIR__ . '/' . ltrim($qrPath, '/');
        $relativePath = $qrPath;
    } else {
        http_response_code(404);
        exit('File not found.');
    }
}

$ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'png' => 'image/png',
    'jpg', 'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    default => 'application/octet-stream',
};
$downloadName = $type === 'standee' ? 'krishna-standee.' . $ext : 'krishna-qr.' . $ext;

header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . (string)filesize($absolutePath));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: public');
readfile($absolutePath);
exit;
