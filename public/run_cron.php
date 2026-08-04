<?php
declare(strict_types=1);

/**
 * HTTP trigger for AI buffer refill cron.
 *
 * Configure a long random secret in `.env`:
 *   REFILL_CRON_SECRET=<64+ hex chars from openssl rand -hex 32>
 *
 * Call:
 *   /run_cron.php?key=<exact_secret>
 */

require_once __DIR__ . '/../app/config/bootstrap_env.php';
BootstrapEnv::load();

$secret = BootstrapEnv::envString('REFILL_CRON_SECRET', '');
if (strlen($secret) < 32) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Cron HTTP trigger is not configured: set REFILL_CRON_SECRET (32+ characters) in .env');
}

$providedKey = $_GET['key'] ?? '';
if (!is_string($providedKey) || !hash_equals($secret, $providedKey)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden: invalid key');
}

if (isset($_GET['force_reset'])) {
    $_GET['force_reset'] = (string)$_GET['force_reset'];
}
if (isset($_GET['client_id'])) {
    $_GET['client_id'] = (string)$_GET['client_id'];
}

require_once __DIR__ . '/../cron/refill_buffer.php';
