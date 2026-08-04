<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/controllers/ResellerController.php';

$controller = new ResellerController();
$controller->addClient();
