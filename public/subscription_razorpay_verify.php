<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/config.php';

header('Location: ' . APP_URL . '/client_recharge.php?renew=1');
exit;
