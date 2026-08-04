<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/controllers/AuthController.php';

$controller = new AuthController();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->loginSubmit();
} else {
    $controller->loginForm();
}
