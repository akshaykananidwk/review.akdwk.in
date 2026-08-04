<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/controllers/SystemUpdateController.php';

$controller = new SystemUpdateController();
if (isset($_GET['ajax'])) {
    $controller->ajax();
} else {
    $controller->index();
}
