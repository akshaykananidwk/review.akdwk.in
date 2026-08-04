<?php
declare(strict_types=1);

// No session, no CSRF — webhook is server-to-server and authenticated by signature only.
require_once __DIR__ . '/../app/controllers/PaymentController.php';

$controller = new PaymentController();
$controller->handleWebhook();
