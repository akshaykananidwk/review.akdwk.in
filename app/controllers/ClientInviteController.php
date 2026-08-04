<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/session_helper.php';
require_once __DIR__ . '/../helpers/csrf_helper.php';
require_once __DIR__ . '/../services/ReviewInviteService.php';

final class ClientInviteController
{
    public function page(): void
    {
        requireClientPanelAccess();
        $pdo = getPDO();
        $clientId = (int)$_SESSION['client_id'];
        $flash = '';
        $error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!csrfValidateToken($_POST['csrf_token'] ?? null)) {
                $error = 'Invalid session token. Please refresh.';
            } else {
                $name = trim((string)($_POST['customer_name'] ?? ''));
                $mobile = trim((string)($_POST['customer_mobile'] ?? ''));
                $res = ReviewInviteService::sendInvite($pdo, $clientId, $name, $mobile);
                if ($res['ok']) {
                    $flash = $res['message'];
                } else {
                    $error = $res['message'];
                }
            }
        }

        $clientStmt = $pdo->prepare('SELECT id, business_name, email, wallet_balance FROM clients WHERE id = :id LIMIT 1');
        $clientStmt->execute([':id' => $clientId]);
        $client = $clientStmt->fetch();
        if (!$client) {
            header('Location: ' . APP_URL . '/logout.php');
            exit;
        }

        $csrfToken = csrfGenerateToken();
        require __DIR__ . '/../views/client/send_invite.php';
    }

    public function submitAjax(): void
    {
        requireClientPanelAccess();
        header('Content-Type: application/json');
        if (!csrfValidateToken($_POST['csrf_token'] ?? null)) {
            echo json_encode(['ok' => false, 'message' => 'Invalid session token.']);
            return;
        }
        $pdo = getPDO();
        $clientId = (int)$_SESSION['client_id'];
        $name = trim((string)($_POST['customer_name'] ?? ''));
        $mobile = trim((string)($_POST['customer_mobile'] ?? ''));
        $res = ReviewInviteService::sendInvite($pdo, $clientId, $name, $mobile);
        echo json_encode($res);
    }
}
