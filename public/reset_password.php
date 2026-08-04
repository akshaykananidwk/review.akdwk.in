<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/controllers/PasswordResetController.php';

$controller = new PasswordResetController();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->submitReset();
} else {
    $controller->showResetForm();
}
