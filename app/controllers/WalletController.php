<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/session_helper.php';
require_once __DIR__ . '/../helpers/settings_helper.php';
require_once __DIR__ . '/../services/WalletService.php';

final class WalletController
{
    public function clientHistory(): void
    {
        requireClientPanelAccess();
        $pdo = getPDO();
        $clientId = (int)$_SESSION['client_id'];

        $clientStmt = $pdo->prepare("SELECT id, business_name, email, wallet_balance FROM clients WHERE id = :id LIMIT 1");
        $clientStmt->execute([':id' => $clientId]);
        $client = $clientStmt->fetch();
        if (!$client) {
            header('Location: ' . APP_URL . '/logout.php');
            exit;
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;
        $wallet = new WalletService($pdo);
        $rows = $wallet->getHistory($clientId, $perPage, $offset);
        $total = $wallet->countHistory($clientId);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $pricePerReview = max(1, (int)getSystemSetting($pdo, 'price_per_review', '1'));

        require __DIR__ . '/../views/client/wallet_history.php';
    }
}
