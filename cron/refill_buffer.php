<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/bootstrap_env.php';
BootstrapEnv::load();
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/services/AiReviewService.php';

$isCli = (php_sapi_name() === 'cli');
$secretKey = BootstrapEnv::envString('REFILL_CRON_SECRET', '');
$forceReset = isset($_GET['force_reset']) && $_GET['force_reset'] === 'true';
$forceClientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;

if (!$isCli) {
    if (strlen($secretKey) < 32) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        exit('REFILL_CRON_SECRET is not configured (32+ characters required in .env). Use CLI or set the secret.');
    }
    $provided = (string)($_GET['key'] ?? '');
    if (!hash_equals($secretKey, $provided)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Invalid key');
    }
}

$pdo = getPDO();
$ai = new AiReviewService($pdo);
$targetBufferCount = AiReviewService::BUFFER_TARGET;

$q = $pdo->query("
    SELECT c.id AS client_id,
           COALESCE(SUM(CASE WHEN pgr.status = 'unused' THEN 1 ELSE 0 END), 0) AS unused_count
    FROM clients c
    LEFT JOIN pre_generated_reviews pgr ON pgr.client_id = c.id
    WHERE c.is_active = 1
    GROUP BY c.id
    HAVING unused_count < {$targetBufferCount}
");
$clients = $q->fetchAll();

$totalClients = count($clients);
$totalInserted = 0;

foreach ($clients as $row) {
    $clientId = (int)$row['client_id'];
    if ($forceClientId > 0 && $clientId !== $forceClientId) {
        continue;
    }

    if ($forceReset) {
        $resetStmt = $pdo->prepare("DELETE FROM pre_generated_reviews WHERE client_id = :client_id AND status = 'unused'");
        $resetStmt->execute([':client_id' => $clientId]);
    }

    $job = $pdo->prepare("
        INSERT INTO ai_generation_jobs (client_id, trigger_source, status, requested_count, generated_count, attempt_count, created_at)
        VALUES (:c, 'scheduled_check', 'processing', 0, 0, 1, NOW())
    ");
    $job->execute([':c' => $clientId]);
    $jobId = (int)$pdo->lastInsertId();

    try {
        $beforeStmt = $pdo->prepare("SELECT COUNT(*) c FROM pre_generated_reviews WHERE client_id = :c AND status = 'unused'");
        $beforeStmt->execute([':c' => $clientId]);
        $before = (int)$beforeStmt->fetch()['c'];
        $needed = max(0, $targetBufferCount - $before);

        $inserted = $ai->fillBufferToTarget($clientId, $targetBufferCount);
        $totalInserted += $inserted;

        $done = $pdo->prepare("
            UPDATE ai_generation_jobs
            SET status = 'completed', requested_count = :r, generated_count = :g, finished_at = NOW()
            WHERE id = :id
        ");
        $done->execute([':r' => $needed, ':g' => $inserted, ':id' => $jobId]);
    } catch (Throwable $e) {
        $fail = $pdo->prepare("
            UPDATE ai_generation_jobs
            SET status = 'failed', error_message = :e, finished_at = NOW()
            WHERE id = :id
        ");
        $fail->execute([':e' => substr($e->getMessage(), 0, 2000), ':id' => $jobId]);
    }
}

if ($isCli) {
    echo "Clients processed: {$totalClients}, reviews inserted: {$totalInserted}\n";
}
