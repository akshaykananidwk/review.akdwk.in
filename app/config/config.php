<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap_env.php';
BootstrapEnv::load();

define('APP_NAME', BootstrapEnv::envString('APP_NAME', 'Krishna Review System'));
define('APP_URL', rtrim(BootstrapEnv::envString('APP_URL', 'https://review.akdwk.in'), '/'));
define('APP_ENV', BootstrapEnv::envString('APP_ENV', 'production'));

date_default_timezone_set('Asia/Kolkata');

// Centralized error handling + structured logging for every entry point.
// Must come after APP_ENV so production hides internals; see
// app/config/bootstrap_app.php.
require_once __DIR__ . '/bootstrap_app.php';
AppBootstrap::install();
