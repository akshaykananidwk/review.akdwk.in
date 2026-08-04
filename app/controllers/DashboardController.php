<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/session_helper.php';
require_once __DIR__ . '/../helpers/settings_helper.php';
require_once __DIR__ . '/../services/AiReviewService.php';

final class DashboardController
{
    public function index(): void
    {
        requireClientPanelAccess();
        $clientId = (int)$_SESSION['client_id'];
        $pdo = getPDO();

        $s = $pdo->prepare("
            SELECT c.id, c.business_name, c.email, c.review_tone, c.wallet_balance, q.public_review_url, q.qr_image_path
            FROM clients c
            LEFT JOIN client_qr_codes q ON q.client_id = c.id AND q.is_active = 1
            WHERE c.id = :id
            LIMIT 1
        ");
        $s->execute([':id' => $clientId]);
        $client = $s->fetch();
        if (!$client) {
            header('Location: ' . APP_URL . '/logout.php');
            exit;
        }

        $stats = $this->buildClientStats($pdo, $clientId);
        $funnel = $this->buildFunnelStats($pdo, $clientId);
        $walletBalance = $stats['walletBalance'];
        $todayUsed = $stats['todayUsed'];
        $weekUsed = $stats['weekUsed'];
        $monthUsed = $stats['monthUsed'];
        $walletAlert = $stats['walletAlert'];
        $pricePerReview = $stats['pricePerReview'];

        $perPage = 10;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $countStmt = $pdo->prepare("SELECT COUNT(*) cnt FROM review_sessions WHERE client_id = :id");
        $countStmt->execute([':id' => $clientId]);
        $totalActivities = (int)($countStmt->fetch()['cnt'] ?? 0);
        $totalPages = max(1, (int)ceil($totalActivities / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;
        $srStart = $offset + 1;

        $recentStmt = $pdo->prepare("
            SELECT rs.customer_rating, rs.flow_type, rs.created_at, rs.completed_at, pgr.review_text
            FROM review_sessions rs
            LEFT JOIN pre_generated_reviews pgr ON pgr.id = rs.used_pre_generated_review_id
            WHERE rs.client_id = :id
            ORDER BY rs.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $recentStmt->bindValue(':id', $clientId, PDO::PARAM_INT);
        $recentStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $recentStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $recentStmt->execute();
        $recentActivities = $recentStmt->fetchAll();

        require __DIR__ . '/../views/client/dashboard.php';
    }

    public function statsAjax(): void
    {
        requireClientPanelAccess();
        header('Content-Type: application/json');
        $clientId = (int)$_SESSION['client_id'];
        $pdo = getPDO();

        $stats = $this->buildClientStats($pdo, $clientId);

        echo json_encode([
            'ok' => true,
            'walletBalance' => $stats['walletBalance'],
            'walletAlert' => $stats['walletAlert'],
            'todayUsed' => $stats['todayUsed'],
            'weekUsed' => $stats['weekUsed'],
            'monthUsed' => $stats['monthUsed'],
        ]);
    }

    /**
     * @return array{
     *   scans_today:int, scans_month:int,
     *   completed_today:int, completed_month:int,
     *   abandoned_today:int, abandoned_month:int,
     *   bounce_rate_today:float, bounce_rate_month:float
     * }
     */
    private function buildFunnelStats(PDO $pdo, int $clientId): array
    {
        $zero = [
            'scans_today' => 0, 'scans_month' => 0,
            'completed_today' => 0, 'completed_month' => 0,
            'abandoned_today' => 0, 'abandoned_month' => 0,
            'bounce_rate_today' => 0.0, 'bounce_rate_month' => 0.0,
        ];
        try {
            $todayScans = $pdo->prepare("
                SELECT COUNT(*) c FROM review_sessions
                WHERE client_id = :id AND DATE(created_at) = CURDATE()
            ");
            $todayScans->execute([':id' => $clientId]);
            $scansToday = (int)($todayScans->fetch()['c'] ?? 0);

            $monthScans = $pdo->prepare("
                SELECT COUNT(*) c FROM review_sessions
                WHERE client_id = :id AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())
            ");
            $monthScans->execute([':id' => $clientId]);
            $scansMonth = (int)($monthScans->fetch()['c'] ?? 0);

            $doneToday = $pdo->prepare("
                SELECT COUNT(*) c FROM review_sessions
                WHERE client_id = :id AND DATE(created_at) = CURDATE()
                  AND flow_type IN ('internal_feedback','google_redirect')
            ");
            $doneToday->execute([':id' => $clientId]);
            $completedToday = (int)($doneToday->fetch()['c'] ?? 0);

            $doneMonth = $pdo->prepare("
                SELECT COUNT(*) c FROM review_sessions
                WHERE client_id = :id AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())
                  AND flow_type IN ('internal_feedback','google_redirect')
            ");
            $doneMonth->execute([':id' => $clientId]);
            $completedMonth = (int)($doneMonth->fetch()['c'] ?? 0);

            $pendToday = $pdo->prepare("
                SELECT COUNT(*) c FROM review_sessions
                WHERE client_id = :id AND DATE(created_at) = CURDATE() AND flow_type = 'pending'
            ");
            $pendToday->execute([':id' => $clientId]);
            $abandonedToday = (int)($pendToday->fetch()['c'] ?? 0);

            $pendMonth = $pdo->prepare("
                SELECT COUNT(*) c FROM review_sessions
                WHERE client_id = :id AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())
                  AND flow_type = 'pending'
            ");
            $pendMonth->execute([':id' => $clientId]);
            $abandonedMonth = (int)($pendMonth->fetch()['c'] ?? 0);

            $bounceToday = $scansToday > 0 ? round(100.0 * $abandonedToday / $scansToday, 1) : 0.0;
            $bounceMonth = $scansMonth > 0 ? round(100.0 * $abandonedMonth / $scansMonth, 1) : 0.0;

            return [
                'scans_today' => $scansToday,
                'scans_month' => $scansMonth,
                'completed_today' => $completedToday,
                'completed_month' => $completedMonth,
                'abandoned_today' => $abandonedToday,
                'abandoned_month' => $abandonedMonth,
                'bounce_rate_today' => $bounceToday,
                'bounce_rate_month' => $bounceMonth,
            ];
        } catch (Throwable) {
            return $zero;
        }
    }

    private function buildClientStats(PDO $pdo, int $clientId): array
    {
        $todayStmt = $pdo->prepare("SELECT COUNT(*) cnt FROM pre_generated_reviews WHERE client_id = :id AND status = 'used' AND used_at >= NOW() - INTERVAL 1 DAY");
        $todayStmt->execute([':id' => $clientId]);
        $todayUsed = (int)($todayStmt->fetch()['cnt'] ?? 0);

        $weekStmt = $pdo->prepare("SELECT COUNT(*) cnt FROM pre_generated_reviews WHERE client_id = :id AND status = 'used' AND used_at >= NOW() - INTERVAL 7 DAY");
        $weekStmt->execute([':id' => $clientId]);
        $weekUsed = (int)($weekStmt->fetch()['cnt'] ?? 0);

        $monthStmt = $pdo->prepare("SELECT COUNT(*) cnt FROM pre_generated_reviews WHERE client_id = :id AND status = 'used' AND YEAR(used_at) = YEAR(CURDATE()) AND MONTH(used_at) = MONTH(CURDATE())");
        $monthStmt->execute([':id' => $clientId]);
        $monthUsed = (int)($monthStmt->fetch()['cnt'] ?? 0);

        $walletStmt = $pdo->prepare("SELECT wallet_balance FROM clients WHERE id = :id LIMIT 1");
        $walletStmt->execute([':id' => $clientId]);
        $walletBalance = (int)($walletStmt->fetch()['wallet_balance'] ?? 0);
        $pricePerReview = max(1, (int)getSystemSetting($pdo, 'price_per_review', '1'));
        $walletAlert = $walletBalance < $pricePerReview
            ? 'Review system currently unavailable. Please recharge plan.'
            : '';

        return [
            'walletBalance' => $walletBalance,
            'walletAlert' => $walletAlert,
            'pricePerReview' => $pricePerReview,
            'todayUsed' => $todayUsed,
            'weekUsed' => $weekUsed,
            'monthUsed' => $monthUsed,
        ];
    }
}
