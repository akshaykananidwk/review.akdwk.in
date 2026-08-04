<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/controllers/AdminReportsController.php';

$controller = new AdminReportsController();
if (($_GET['format'] ?? '') === 'print') {
    $controller->printable();
} else {
    $controller->index();
}
