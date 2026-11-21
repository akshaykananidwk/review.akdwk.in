<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap_env.php';
BootstrapEnv::load();

define('APP_NAME', BootstrapEnv::envString('APP_NAME', 'Krishna Review System'));
define('APP_URL', rtrim(BootstrapEnv::envString('APP_URL', 'https://review.akdwk.in'), '/'));
define('APP_ENV', BootstrapEnv::envString('APP_ENV', 'production'));

date_default_timezone_set('Asia/Kolkata');

// ---------------------------------------------------------------------
// AK Earn connector (earn.akdwk.in)
//
// The secret is read from .env and NEVER written into the repository.
// .env is git-ignored and is a protected path in the updater, so it
// survives every deploy. Add this line to it:
//
//     AK_EARN_SECRET=<secret from AK Earn admin>
//
// With no secret defined the SDK simply refuses to send and the site
// behaves exactly as before — nothing here can break a page.
// ---------------------------------------------------------------------
define('AK_MASTER_URL', BootstrapEnv::envString('AK_MASTER_URL', 'https://earn.akdwk.in'));
define('AK_SITE_KEY', BootstrapEnv::envString('AK_SITE_KEY', 'sk_c60c1e42649ca82fb679c9bb'));
define('AK_SITE_SECRET', BootstrapEnv::envString('AK_EARN_SECRET', ''));
// Queue file lives with our own storage, not in /tmp, so a server reboot
// or tmp cleanup cannot drop unsent money events.
define('AK_QUEUE_DIR', dirname(__DIR__, 2) . '/storage/ak');
// Guarded: config.php is required by every entry point, so a plain
// require_once of a file missing after a partial deploy would white-screen
// the entire site. Without the SDK the connector reports itself as not
// configured and the site runs exactly as it did before.
if (is_file(__DIR__ . '/../lib/ak-connect.php')) {
    require_once __DIR__ . '/../lib/ak-connect.php';
}

// Centralized error handling + structured logging for every entry point.
// Must come after APP_ENV so production hides internals; see
// app/config/bootstrap_app.php.
require_once __DIR__ . '/bootstrap_app.php';
AppBootstrap::install();
