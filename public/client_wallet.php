<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/controllers/WalletController.php';
(new WalletController())->clientHistory();
