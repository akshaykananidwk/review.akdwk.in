<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/controllers/SuperAdminController.php';

$c = new SuperAdminController();
$c->subscriptionPlans();
