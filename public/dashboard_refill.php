<?php
declare(strict_types=1);

header('Content-Type: application/json');
echo json_encode([
    'ok' => false,
    'message' => 'Manual refill is not available in client panel.'
]);
