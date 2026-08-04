<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/controllers/ClientSettingsController.php';

$controller = new ClientSettingsController();
$controller->index();
