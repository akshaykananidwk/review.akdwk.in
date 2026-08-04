<?php
declare(strict_types=1);

header('Content-Type: application/json');
echo json_encode([
    'ok' => false,
    'message' => 'Client model selection is disabled. Master model is managed by admin.'
]);
