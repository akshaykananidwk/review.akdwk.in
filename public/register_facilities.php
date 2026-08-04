<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/controllers/ClientController.php';

$controller = new ClientController();
$controller->facilitiesByCategoryAjax();
