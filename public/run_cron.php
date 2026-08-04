<?php
declare(strict_types=1);

/**
 * HTTP trigger for the centralized cron scheduler (fallback for hosts
 * without CLI crontab — hit it every minute from any uptime/cron
 * service):
 *
 *   /run_cron.php?key=<REFILL_CRON_SECRET>                 -> master tick (all due jobs)
 *   /run_cron.php?key=<secret>&job=<job_key>               -> force-run one job
 *   /run_cron.php?key=<secret>&force_reset=true[&client_id=N]
 *                                                          -> legacy AI-buffer reset tool
 *
 * Configure a long random secret in `.env`:
 *   REFILL_CRON_SECRET=<64+ hex chars from openssl rand -hex 32>
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

header('Content-Type: text/plain; charset=utf-8');
ignore_user_abort(true);
@set_time_limit(600);

// Legacy tool: forced AI-buffer reset keeps its original behaviour.
if (isset($_GET['force_reset']) || isset($_GET['client_id'])) {
    if (isset($_GET['force_reset'])) {
        $_GET['force_reset'] = (string)$_GET['force_reset'];
    }
    if (isset($_GET['client_id'])) {
        $_GET['client_id'] = (string)$_GET['client_id'];
    }
    require __DIR__ . '/../cron/refill_buffer.php';
    exit;
}

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/services/CronService.php';

try {
    $service = new CronService();
    if (!$service->cronTablesExist()) {
        http_response_code(503);
        exit("Cron tables are not installed yet — open Admin → Cron Settings once.\n");
    }

    $jobKey = isset($_GET['job']) && is_string($_GET['job']) ? trim($_GET['job']) : '';
    if ($jobKey !== '') {
        $service->syncRegistry();
        $run = $service->runJob($jobKey, 'manual');
        echo "{$jobKey}: {$run['status']}" . ($run['summary'] !== '' ? ' — ' . $run['summary'] : '') . "\n";
        exit;
    }

    $results = $service->tick();
    if ($results === []) {
        echo "tick: nothing due\n";
    } else {
        foreach ($results as $job => $outcome) {
            echo "{$job}: {$outcome}\n";
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Cron trigger failed: ' . $e->getMessage() . "\n";
}
