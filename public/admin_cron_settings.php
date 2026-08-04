<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/controllers/CronSettingsController.php';

$controller = new CronSettingsController();
$controller->index();
