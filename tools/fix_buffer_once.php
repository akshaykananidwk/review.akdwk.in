<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/services/AiReviewService.php';

$selector = $argv[1] ?? 'admin@smartreview.local';
$target = isset($argv[2]) ? max(1, (int)$argv[2]) : AiReviewService::BUFFER_TARGET;

$pdo = getPDO();
$ai = new AiReviewService($pdo);

$client = null;

if (preg_match('/^id:(\d+)$/', $selector, $m)) {
    $clientIdInput = (int)$m[1];
    $clientStmt = $pdo->prepare("SELECT id, business_name, email, is_active FROM clients WHERE id = :id LIMIT 1");
    $clientStmt->execute([':id' => $clientIdInput]);
    $client = $clientStmt->fetch();
} else {
    $clientStmt = $pdo->prepare("SELECT id, business_name, email, is_active FROM clients WHERE email = :email LIMIT 1");
    $clientStmt->execute([':email' => $selector]);
    $client = $clientStmt->fetch();
}

if (!$client) {
    echo "Client not found for selector: {$selector}" . PHP_EOL;
    echo "Tip 1: pass a valid CLIENT email (from clients table), example:" . PHP_EOL;
    echo "php tools/fix_buffer_once.php client@example.com 5" . PHP_EOL;
    echo "Tip 2: pass client id directly, example:" . PHP_EOL;
    echo "php tools/fix_buffer_once.php id:12 5" . PHP_EOL;
    echo "Note: admin@smartreview.local is usually in admins table, not clients table." . PHP_EOL;
    exit(1);
}

$clientId = (int)$client['id'];
if ((int)$client['is_active'] !== 1) {
    echo "Client is not active. Please activate first. client_id={$clientId}" . PHP_EOL;
    exit(1);
}

echo "Fixing buffer for client_id={$clientId}, business={$client['business_name']}, email={$client['email']}" . PHP_EOL;

$pdo->beginTransaction();
try {
    $del = $pdo->prepare("DELETE FROM pre_generated_reviews WHERE client_id = :client_id");
    $del->execute([':client_id' => $clientId]);
    $deleted = $del->rowCount();
    $pdo->commit();
    echo "Deleted existing rows: {$deleted}" . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Delete phase failed: {$e->getMessage()}" . PHP_EOL;
    exit(1);
}

$inserted = $ai->fillBufferToTarget($clientId, $target);

// Hard guarantee: keep generating until target reached (bounded attempts).
$attempts = 0;
while ($attempts < 200) {
    $attempts++;
    $countStmt = $pdo->prepare("SELECT COUNT(*) cnt FROM pre_generated_reviews WHERE client_id = :client_id AND status = 'unused'");
    $countStmt->execute([':client_id' => $clientId]);
    $unused = (int)($countStmt->fetch()['cnt'] ?? 0);
    if ($unused >= $target) {
        echo "SUCCESS: New reviews added: {$unused}" . PHP_EOL;
        exit(0);
    }
    $row = $ai->generateAndStoreOneUnusedReview($clientId);
    if ($row === null) {
        // one more regular fill call to recover from transient failures
        $ai->fillBufferToTarget($clientId, $target);
    }
}

$countStmt = $pdo->prepare("SELECT COUNT(*) cnt FROM pre_generated_reviews WHERE client_id = :client_id AND status = 'unused'");
$countStmt->execute([':client_id' => $clientId]);
$unused = (int)($countStmt->fetch()['cnt'] ?? 0);
echo "PARTIAL: inserted={$inserted}, final_unused={$unused}, target={$target}" . PHP_EOL;
echo "Check logs: storage/logs/ai_refill.log" . PHP_EOL;
exit(2);
