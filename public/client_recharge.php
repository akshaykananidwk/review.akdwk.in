<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/controllers/PaymentController.php';

$controller = new PaymentController();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_order') {
    $controller->createOrderAjax();
    return;
}
$controller->rechargePage();
