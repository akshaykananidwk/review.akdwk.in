<?php
declare(strict_types=1);

/**
 * AK Earn connector bootstrap (earn.akdwk.in).
 *
 * This lives here and NOT in config.php on purpose.
 *
 * app/config/config.php is a protected path in GitHubUpdateService: the
 * updater never overwrites it, because it holds per-server settings that
 * must survive a deploy. Anything added to it in the repository
 * therefore never reaches a live server — which is exactly what happened
 * on the first attempt at this connector, and why the panel reported
 * "Site key or secret missing" while the secret was plainly saved.
 *
 * database.php requires this file, and every entry point requires
 * database.php, so the constants below exist wherever they are needed.
 *
 * Every define is guarded, so a server whose config.php still carries
 * the old inline block cannot fatal on a duplicate definition.
 */

require_once __DIR__ . '/bootstrap_env.php';
BootstrapEnv::load();

if (!defined('AK_MASTER_URL')) {
    define('AK_MASTER_URL', BootstrapEnv::envString('AK_MASTER_URL', 'https://earn.akdwk.in'));
}
if (!defined('AK_SITE_KEY')) {
    define('AK_SITE_KEY', BootstrapEnv::envString('AK_SITE_KEY', 'sk_c60c1e42649ca82fb679c9bb'));
}

// The secret is defined ONLY when .env supplies it. Left undefined,
// AkEarnService::boot() falls back to the value saved from Admin → AK
// Earn Connection. .env therefore always wins, and stays the safer
// place: a value in the database is stored as plain text.
$akEarnSecretFromEnv = BootstrapEnv::envString('AK_EARN_SECRET', '');
if ($akEarnSecretFromEnv !== '' && !defined('AK_SITE_SECRET')) {
    define('AK_SITE_SECRET', $akEarnSecretFromEnv);
}
unset($akEarnSecretFromEnv);

// Queue file lives with our own storage, not in /tmp, so a server reboot
// or a tmp cleanup cannot drop unsent money events.
if (!defined('AK_QUEUE_DIR')) {
    define('AK_QUEUE_DIR', dirname(__DIR__, 2) . '/storage/ak');
}

// Guarded: this file is reached by every entry point, so a plain
// require_once of an SDK missing after a partial deploy would
// white-screen the whole site. Without it the connector reports itself
// unconfigured and the site runs exactly as before.
if (is_file(__DIR__ . '/../lib/ak-connect.php')) {
    require_once __DIR__ . '/../lib/ak-connect.php';
}

// Phase 1's error handling is installed from config.php, which — being
// protected — may still be the pre-Phase-1 copy on an existing server.
// Installing it here as well costs nothing (install() is idempotent) and
// means the logging and the safe error page are actually active.
if (is_file(__DIR__ . '/bootstrap_app.php')) {
    require_once __DIR__ . '/bootstrap_app.php';
    if (class_exists('AppBootstrap')) {
        AppBootstrap::install();
    }
}
