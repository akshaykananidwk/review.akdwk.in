<?php
declare(strict_types=1);

header('Content-Type: application/json');

// પાથ સુધારીને ../../app/ કર્યા છે
require_once __DIR__ . '/../../app/config/config.php';
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/services/ReviewInviteService.php';

$auth = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
if ($auth === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Missing Authorization header.']);
    exit;
}
if (stripos($auth, 'Bearer ') === 0) {
    $apiKey = trim(substr($auth, 7));
} else {
    $apiKey = $auth;
}
if ($apiKey === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Invalid API key.']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'JSON body required.']);
    exit;
}

$name = trim((string)($data['name'] ?? ''));
$mobile = trim((string)($data['mobile'] ?? ''));
if ($name === '' || $mobile === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Fields "name" and "mobile" are required.']);
    exit;
}

$pdo = getPDO();
$stmt = $pdo->prepare('SELECT id FROM clients WHERE api_key = :k AND is_active = 1 LIMIT 1');
$stmt->execute([':k' => $apiKey]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Invalid API key.']);
    exit;
}

$clientId = (int)$row['id'];
$result = ReviewInviteService::sendInvite($pdo, $clientId, $name, $mobile);
http_response_code($result['ok'] ? 200 : 400);
echo json_encode($result);
