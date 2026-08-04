<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/admin_session_helper.php';
require_once __DIR__ . '/../helpers/settings_helper.php';

final class AdminReportsController
{
    /** Display the report dashboard (HTML view). */
    public function index(): void
    {
        requireAdminLogin();
        $pdo = getPDO();

        [$preset, $fromDate, $toDate] = $this->resolveDateRange();
        $rangeLabel = $this->humanRange($preset, $fromDate, $toDate);
        $stats = $this->fetchStats($pdo, $fromDate, $toDate);
        $perBusiness = $this->fetchPerBusiness($pdo, $fromDate, $toDate);
        $dailyBreakdown = $this->fetchDailyBreakdown($pdo, $fromDate, $toDate);

        $systemName = getSystemSetting($pdo, 'system_name', APP_NAME);

        require __DIR__ . '/../views/admin/reports.php';
    }

    /**
     * Print-optimized version of the same report. The user clicks "Download PDF",
     * which opens this view and triggers the browser's print dialog
     * (Save As PDF). Works on every device with no extra dependency. If you
     * later add TCPDF/DomPDF, swap this method to render to PDF directly.
     */
    public function printable(): void
    {
        requireAdminLogin();
        $pdo = getPDO();

        [$preset, $fromDate, $toDate] = $this->resolveDateRange();
        $rangeLabel = $this->humanRange($preset, $fromDate, $toDate);
        $stats = $this->fetchStats($pdo, $fromDate, $toDate);
        $perBusiness = $this->fetchPerBusiness($pdo, $fromDate, $toDate);
        $dailyBreakdown = $this->fetchDailyBreakdown($pdo, $fromDate, $toDate);
        $systemName = getSystemSetting($pdo, 'system_name', APP_NAME);
        $generatedAt = date('Y-m-d H:i:s');

        require __DIR__ . '/../views/admin/reports_printable.php';
    }

    /**
     * @return array{0:string,1:string,2:string} [preset, fromDate(Y-m-d), toDate(Y-m-d)]
     */
    private function resolveDateRange(): array
    {
        $preset = (string)($_GET['preset'] ?? 'today');
        $today = new DateTimeImmutable('today');
        $from = $today; $to = $today;

        switch ($preset) {
            case 'today':
                $from = $today; $to = $today; break;
            case 'week':
                // Last 7 days, inclusive of today.
                $from = $today->modify('-6 days'); $to = $today; break;
            case 'month':
                // Calendar month-to-date.
                $from = $today->modify('first day of this month'); $to = $today; break;
            case '3months':
                // Last 3 calendar months including current.
                $from = $today->modify('first day of -2 months'); $to = $today; break;
            case 'custom':
                $fromRaw = (string)($_GET['from'] ?? '');
                $toRaw = (string)($_GET['to'] ?? '');
                $from = $this->safeDate($fromRaw, $today->modify('-7 days'));
                $to = $this->safeDate($toRaw, $today);
                if ($from > $to) {
                    [$from, $to] = [$to, $from];
                }
                break;
            default:
                $preset = 'today';
                $from = $today; $to = $today;
        }

        return [$preset, $from->format('Y-m-d'), $to->format('Y-m-d')];
    }

    private function safeDate(string $raw, DateTimeImmutable $fallback): DateTimeImmutable
    {
        if ($raw === '') return $fallback;
        try { return new DateTimeImmutable($raw); }
        catch (Throwable) { return $fallback; }
    }

    private function humanRange(string $preset, string $from, string $to): string
    {
        $label = match ($preset) {
            'today'    => 'Today',
            'week'     => 'Last 7 Days',
            'month'    => 'This Month',
            '3months'  => 'Last 3 Months',
            'custom'   => 'Custom Range',
            default    => 'Today',
        };
        return $label . ' (' . $from . ' to ' . $to . ')';
    }

    private function fetchStats(PDO $pdo, string $from, string $to): array
    {
        $params = [
            ':from' => $from . ' 00:00:00',
            ':to'   => $to   . ' 23:59:59',
        ];

        // Total reviews redirected to Google (5-star flow consumed).
        $totalRedirect = (int)$this->one($pdo, "
            SELECT COUNT(*) c FROM review_sessions
            WHERE flow_type = 'google_redirect'
              AND completed_at IS NOT NULL
              AND completed_at BETWEEN :from AND :to
        ", $params);

        // Internal feedback (1-3 stars).
        $totalInternal = (int)$this->one($pdo, "
            SELECT COUNT(*) c FROM internal_feedback
            WHERE created_at BETWEEN :from AND :to
        ", $params);

        // New business registrations.
        $newBusinesses = (int)$this->one($pdo, "
            SELECT COUNT(*) c FROM clients
            WHERE created_at BETWEEN :from AND :to
        ", $params);

        // Active businesses overall.
        $activeBusinesses = (int)$pdo->query("SELECT COUNT(*) c FROM clients WHERE is_active = 1")->fetch()['c'];

        // Wallet activity in range.
        $totalCredited = (int)$this->one($pdo, "
            SELECT COALESCE(SUM(amount),0) c FROM wallet_transactions
            WHERE txn_type = 'credit' AND created_at BETWEEN :from AND :to
        ", $params);
        $totalDebited = (int)$this->one($pdo, "
            SELECT COALESCE(SUM(amount),0) c FROM wallet_transactions
            WHERE txn_type = 'debit' AND created_at BETWEEN :from AND :to
        ", $params);

        return [
            'total_5star_redirected' => $totalRedirect,
            'total_internal_feedback' => $totalInternal,
            'new_businesses' => $newBusinesses,
            'active_businesses' => $activeBusinesses,
            'total_credited' => $totalCredited,
            'total_debited' => $totalDebited,
        ];
    }

    private function fetchPerBusiness(PDO $pdo, string $from, string $to): array
    {
        $stmt = $pdo->prepare("
            SELECT
                c.id,
                c.business_name,
                c.email,
                c.mobile,
                bc.category_name,
                c.wallet_balance,
                COALESCE(rs.redirected,0) AS total_5star,
                COALESCE(ifc.internal_count,0) AS total_internal
            FROM clients c
            INNER JOIN business_categories bc ON bc.id = c.category_id
            LEFT JOIN (
                SELECT client_id, COUNT(*) AS redirected
                FROM review_sessions
                WHERE flow_type = 'google_redirect'
                  AND completed_at IS NOT NULL
                  AND completed_at BETWEEN :from AND :to
                GROUP BY client_id
            ) rs ON rs.client_id = c.id
            LEFT JOIN (
                SELECT client_id, COUNT(*) AS internal_count
                FROM internal_feedback
                WHERE created_at BETWEEN :from2 AND :to2
                GROUP BY client_id
            ) ifc ON ifc.client_id = c.id
            ORDER BY total_5star DESC, c.business_name ASC
            LIMIT 200
        ");
        $stmt->execute([
            ':from'  => $from . ' 00:00:00',
            ':to'    => $to   . ' 23:59:59',
            ':from2' => $from . ' 00:00:00',
            ':to2'   => $to   . ' 23:59:59',
        ]);
        return $stmt->fetchAll();
    }

    private function fetchDailyBreakdown(PDO $pdo, string $from, string $to): array
    {
        $stmt = $pdo->prepare("
            SELECT DATE(completed_at) AS d, COUNT(*) AS reviews
            FROM review_sessions
            WHERE flow_type = 'google_redirect'
              AND completed_at IS NOT NULL
              AND completed_at BETWEEN :from AND :to
            GROUP BY DATE(completed_at)
            ORDER BY d DESC
            LIMIT 100
        ");
        $stmt->execute([
            ':from' => $from . ' 00:00:00',
            ':to'   => $to   . ' 23:59:59',
        ]);
        return $stmt->fetchAll();
    }

    private function one(PDO $pdo, string $sql, array $params): int
    {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        $r = $s->fetch();
        return (int)($r['c'] ?? 0);
    }
}
