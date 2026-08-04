<?php
declare(strict_types=1);

header('Content-Type: application/json');
echo json_encode([
    'ok' => false,
    'message' => 'Client API key testing is disabled. Master API key is managed by admin.'
]);
