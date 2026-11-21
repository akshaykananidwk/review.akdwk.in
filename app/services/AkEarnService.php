<?php
declare(strict_types=1);

require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/../helpers/settings_helper.php';

/**
 * AK Earn connector — outbox side.
 * -------------------------------------------------------------------
 * The wire format belongs entirely to app/lib/ak-connect.php, the SDK
 * shipped by the master. This class never builds a payload, never signs
 * a request and never invents an event id: it records which SDK call to
 * make with which arguments, and the cron makes that exact call later.
 *
 * Why an outbox instead of calling the SDK inline:
 *
 *   The SDK allows 3s to connect and 6s to read. Inline, that sits in
 *   the middle of a registration or a Razorpay success callback — up to
 *   nine seconds of a paying customer staring at a spinner because a
 *   *reporting* system is slow. So the request does one local INSERT
 *   (microseconds) and returns; the master cron delivers within a
 *   minute. A customer action can never be slowed down or failed by
 *   earn.akdwk.in being unreachable, which is the whole requirement.
 *
 * Double counting is prevented twice over: event_id is UNIQUE in
 * ak_outbox so the same event cannot be queued twice, and the SDK's ids
 * are deterministic so the master discards a duplicate even if one
 * somehow escaped.
 *
 * Every public method here is best effort and swallows its own errors.
 * Nothing in this file may ever break a page.
 */
final class AkEarnService
{
    /** Attempts before a row is parked as failed for the admin to retry. */
    private const MAX_ATTEMPTS = 8;

    /** Backoff per attempt, in minutes. The last value repeats. */
    private const BACKOFF_MINUTES = [1, 2, 5, 15, 30, 60, 180];

    private PDO $pdo;

    /** 'env' | 'database' | 'none', decided once per request by boot(). */
    private static ?string $secretSource = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        self::boot($pdo);

        // storage/ is a protected path, so a deploy never creates this
        // directory. Without it the SDK's own retry queue silently
        // discards (it writes with @), and a money event lost at the
        // network layer would never come back.
        if (defined('AK_QUEUE_DIR') && !is_dir(AK_QUEUE_DIR)) {
            @mkdir(AK_QUEUE_DIR, 0755, true);
        }
    }

    // ------------------------------------------------------------------
    // Availability
    // ------------------------------------------------------------------

    /**
     * Resolve the secret once per request.
     *
     * config.php defines AK_SITE_SECRET only when .env supplies one, so
     * .env always wins. Otherwise the value saved from Admin → AK Earn
     * Connection is used, which lets the site be connected without
     * touching a file on the server.
     *
     * Must run before anything calls the SDK. The constructor does it,
     * and the static entry points below do it too, so there is no order
     * in which a caller can get a half-configured connector.
     */
    public static function boot(PDO $pdo): void
    {
        if (self::$secretSource !== null) {
            return;
        }
        // config.php defines the constant only when .env supplied a
        // value, so finding it already defined means .env is the source.
        if (defined('AK_SITE_SECRET')) {
            self::$secretSource = AK_SITE_SECRET !== '' ? 'env' : 'none';
            return;
        }
        $fromDb = '';
        try {
            $fromDb = trim((string)getSystemSetting($pdo, 'ak_earn_secret', ''));
        } catch (Throwable) {
            // Table missing or database down: stay unconfigured rather
            // than guess. Nothing is sent, nothing breaks.
        }
        define('AK_SITE_SECRET', $fromDb);
        self::$secretSource = $fromDb !== '' ? 'database' : 'none';
    }

    /** boot() + isConfigured() in one, for callers that hold a PDO. */
    public static function ready(PDO $pdo): bool
    {
        self::boot($pdo);
        return self::isConfigured();
    }

    /**
     * Where the active secret came from: 'env', 'database' or 'none'.
     * Recorded by boot() rather than re-derived, because inferring it
     * afterwards from the constant alone cannot tell the two apart.
     */
    public static function secretSource(PDO $pdo): string
    {
        self::boot($pdo);
        return self::$secretSource ?? 'none';
    }

    /**
     * True when the site key, the secret and the master's SDK are all
     * present. The SDK check matters: config.php loads it defensively,
     * so a missing file leaves the site healthy but the connector off —
     * and calling ak_track_*() then would be a fatal error in cron.
     */
    public static function isConfigured(): bool
    {
        return defined('AK_SITE_KEY') && AK_SITE_KEY !== ''
            && defined('AK_SITE_SECRET') && AK_SITE_SECRET !== ''
            && defined('AK_MASTER_URL') && AK_MASTER_URL !== ''
            && function_exists('ak_track_order');
    }

    // ------------------------------------------------------------------
    // Queueing (called from the web request — must stay fast)
    // ------------------------------------------------------------------

    public function queueSignup(string $name, string $mobile, string $email, array $meta = []): void
    {
        $this->queue(
            'signup',
            'ak_track_signup',
            'signup-' . preg_replace('/\D/', '', $mobile),
            ['name' => $name, 'mobile' => $mobile, 'email' => $email, 'meta' => $meta]
        );
    }

    public function queueLead(string $name, string $mobile, ?string $productCode, string $note, array $meta = []): void
    {
        $this->queue(
            'lead',
            'ak_track_lead',
            'lead-' . md5(preg_replace('/\D/', '', $mobile) . '|' . $productCode . '|' . date('Y-m-d')),
            ['name' => $name, 'mobile' => $mobile, 'product_code' => $productCode, 'note' => $note, 'meta' => $meta]
        );
    }

    /** $status: 'created' (order placed) or 'paid' (payment succeeded). */
    public function queueOrder(
        string $orderId,
        float $amount,
        ?string $productCode,
        string $name,
        string $mobile,
        string $status = 'paid',
        array $meta = []
    ): void {
        $type = $status === 'created' ? 'order_created' : 'payment_success';
        $this->queue(
            $type,
            'ak_track_order',
            $type . '-' . $orderId,
            ['order_id' => $orderId, 'amount' => $amount, 'product_code' => $productCode,
             'name' => $name, 'mobile' => $mobile, 'status' => $status, 'meta' => $meta],
            $amount
        );
    }

    public function queueRenewal(
        string $orderId,
        float $amount,
        ?string $productCode,
        string $name,
        string $mobile,
        array $meta = []
    ): void {
        $this->queue(
            'renewal',
            'ak_track_renewal',
            'renewal-' . $orderId,
            ['order_id' => $orderId, 'amount' => $amount, 'product_code' => $productCode,
             'name' => $name, 'mobile' => $mobile, 'meta' => $meta],
            $amount
        );
    }

    public function queueRefund(string $orderId, string $reason = '', ?float $amount = null): void
    {
        $this->queue(
            'refund',
            'ak_track_refund',
            'refund-' . $orderId,
            ['order_id' => $orderId, 'reason' => $reason, 'amount' => $amount],
            $amount
        );
    }

    public function queueCancel(string $orderId, string $reason = ''): void
    {
        $this->queue(
            'cancel',
            'ak_track_cancel',
            'cancel-' . $orderId,
            ['order_id' => $orderId, 'reason' => $reason]
        );
    }

    /**
     * Record one event. Captures the referral code and visitor id from
     * the CURRENT request, because by the time cron delivers the event
     * there are no cookies to read them from.
     */
    private function queue(string $type, string $sdkCall, string $eventId, array $args, ?float $amount = null): void
    {
        try {
            if (!self::isConfigured()) {
                return;   // connector off: behave exactly like before
            }
            $stmt = $this->pdo->prepare("
                INSERT INTO ak_outbox
                    (event_id, event_type, sdk_call, payload_json, ref_code, visitor_id, amount,
                     status, attempts, next_attempt_at, created_at)
                VALUES
                    (:eid, :type, :call, :payload, :ref, :vid, :amount, 'pending', 0, NOW(), NOW())
                ON DUPLICATE KEY UPDATE event_id = event_id
            ");
            $stmt->execute([
                ':eid' => substr($eventId, 0, 100),
                ':type' => $type,
                ':call' => $sdkCall,
                ':payload' => json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':ref' => $this->currentRef() ?: null,
                ':vid' => $this->currentVisitorId() ?: null,
                ':amount' => $amount,
            ]);
        } catch (Throwable $e) {
            // A reporting failure must never surface to the customer.
            Logger::warning(Logger::CH_API, 'AK Earn: could not queue event', [
                'event_type' => $type,
                'event_id' => $eventId,
            ], $e);
        }
    }

    private function currentRef(): string
    {
        return function_exists('ak_ref') ? ak_ref() : '';
    }

    private function currentVisitorId(): string
    {
        return function_exists('ak_visitor_id') ? ak_visitor_id() : '';
    }

    // ------------------------------------------------------------------
    // Delivery (called from cron — slow is fine here)
    // ------------------------------------------------------------------

    /**
     * Deliver pending events. Returns [sent, failed, skipped].
     *
     * @return array{sent:int, failed:int, remaining:int}
     */
    public function deliverPending(int $limit = 25): array
    {
        $sent = 0;
        $failed = 0;
        if (!self::isConfigured()) {
            return ['sent' => 0, 'failed' => 0, 'remaining' => 0];
        }

        $rows = $this->pdo->query("
            SELECT * FROM ak_outbox
            WHERE status = 'pending'
              AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
            ORDER BY id ASC
            LIMIT " . max(1, min(200, $limit))
        )->fetchAll();

        foreach ($rows as $row) {
            $this->deliverRow($row) ? $sent++ : $failed++;
        }

        $remaining = (int)$this->pdo->query("
            SELECT COUNT(*) FROM ak_outbox WHERE status = 'pending'
        ")->fetchColumn();

        return ['sent' => $sent, 'failed' => $failed, 'remaining' => $remaining];
    }

    /** Re-queue a failed row so the next cron tick tries it again. */
    public function retry(int $outboxId): bool
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE ak_outbox
                SET status = 'pending', attempts = 0, next_attempt_at = NOW(), last_error = NULL
                WHERE id = :id AND status = 'failed'
            ");
            $stmt->execute([':id' => $outboxId]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            Logger::warning(Logger::CH_API, 'AK Earn: retry failed', ['outbox_id' => $outboxId], $e);
            return false;
        }
    }

    public function retryAllFailed(): int
    {
        try {
            return (int)$this->pdo->exec("
                UPDATE ak_outbox
                SET status = 'pending', attempts = 0, next_attempt_at = NOW(), last_error = NULL
                WHERE status = 'failed'
            ");
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Make the one SDK call this row describes.
     *
     * The referral code is restored into $_COOKIE before the call so the
     * SDK's own ak_ref()/ak_visitor_id() see exactly what the visitor's
     * browser had. That keeps event ids and attribution byte-identical
     * to an inline call, without this class ever touching the payload.
     */
    private function deliverRow(array $row): bool
    {
        $args = json_decode((string)$row['payload_json'], true);
        if (!is_array($args)) {
            $this->markFailed((int)$row['id'], 'Stored payload is not valid JSON', true);
            return false;
        }

        $savedCookies = $_COOKIE;
        if (!empty($row['ref_code'])) {
            $_COOKIE['ak_ref'] = (string)$row['ref_code'];
        }
        if (!empty($row['visitor_id'])) {
            $_COOKIE['ak_vid'] = (string)$row['visitor_id'];
        }

        try {
            $result = $this->callSdk((string)$row['sdk_call'], $args);
        } catch (Throwable $e) {
            $_COOKIE = $savedCookies;
            $this->markFailed((int)$row['id'], 'Exception: ' . $e->getMessage(), false);
            return false;
        }
        $_COOKIE = $savedCookies;

        $status = (string)($result['status'] ?? '');
        if ($status === 'ok') {
            $this->markSent((int)$row['id']);
            return true;
        }

        // The SDK itself queued this one (network / 5xx / 429) and will
        // retry it; treat as a soft failure and try again later.
        $soft = !empty($result['queued']);
        $this->markFailed((int)$row['id'], substr((string)($result['message'] ?? 'Unknown error'), 0, 500), !$soft && $this->isPermanent($result));
        return false;
    }

    /** 4xx answers are the master rejecting the data; retrying will not help. */
    private function isPermanent(array $result): bool
    {
        $message = strtolower((string)($result['message'] ?? ''));
        foreach (['http 400', 'http 401', 'http 403', 'http 404', 'http 409', 'http 422', 'invalid', 'unknown site'] as $marker) {
            if (str_contains($message, $marker)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    private function callSdk(string $call, array $a): array
    {
        switch ($call) {
            case 'ak_track_signup':
                return ak_track_signup((string)$a['name'], (string)$a['mobile'], ($a['email'] ?? '') ?: null, (array)($a['meta'] ?? []));
            case 'ak_track_lead':
                return ak_track_lead((string)$a['name'], (string)$a['mobile'], $a['product_code'] ?? null, $a['note'] ?? null, (array)($a['meta'] ?? []));
            case 'ak_track_order':
                return ak_track_order((string)$a['order_id'], (float)$a['amount'], $a['product_code'] ?? null,
                    (string)$a['name'], (string)$a['mobile'], (string)($a['status'] ?? 'paid'), (array)($a['meta'] ?? []));
            case 'ak_track_renewal':
                return ak_track_renewal((string)$a['order_id'], (float)$a['amount'], $a['product_code'] ?? null,
                    (string)$a['name'], (string)$a['mobile'], (array)($a['meta'] ?? []));
            case 'ak_track_refund':
                return ak_track_refund((string)$a['order_id'], (string)($a['reason'] ?? ''), isset($a['amount']) ? (float)$a['amount'] : null);
            case 'ak_track_cancel':
                return ak_track_cancel((string)$a['order_id'], (string)($a['reason'] ?? ''));
            default:
                return ['status' => 'error', 'message' => 'Unknown SDK call: ' . $call];
        }
    }

    private function markSent(int $id): void
    {
        $this->pdo->prepare("
            UPDATE ak_outbox
            SET status = 'sent', sent_at = NOW(), attempts = attempts + 1, last_error = NULL
            WHERE id = :id
        ")->execute([':id' => $id]);
    }

    private function markFailed(int $id, string $error, bool $permanent): void
    {
        $row = $this->pdo->prepare('SELECT attempts FROM ak_outbox WHERE id = :id LIMIT 1');
        $row->execute([':id' => $id]);
        $attempts = (int)($row->fetchColumn() ?: 0) + 1;

        $giveUp = $permanent || $attempts >= self::MAX_ATTEMPTS;
        $backoff = self::BACKOFF_MINUTES[min($attempts - 1, count(self::BACKOFF_MINUTES) - 1)];

        $this->pdo->prepare("
            UPDATE ak_outbox
            SET status = :st,
                attempts = :attempts,
                last_error = :err,
                next_attempt_at = CASE WHEN :giveup = 1 THEN NULL ELSE DATE_ADD(NOW(), INTERVAL :mins MINUTE) END
            WHERE id = :id
        ")->execute([
            ':st' => $giveUp ? 'failed' : 'pending',
            ':attempts' => $attempts,
            ':err' => substr($error, 0, 500),
            ':giveup' => $giveUp ? 1 : 0,
            ':mins' => $backoff,
            ':id' => $id,
        ]);
    }

    // ------------------------------------------------------------------
    // Products, stats, backfill
    // ------------------------------------------------------------------

    /**
     * Send the full plan list. Called daily and whenever an admin saves
     * a plan, so the master's price list never goes stale.
     *
     * @return array<string,mixed>
     */
    public function syncProducts(): array
    {
        if (!self::isConfigured()) {
            return ['status' => 'error', 'message' => 'Connector not configured'];
        }
        $products = [];

        foreach ($this->fetchAll("
            SELECT id, name, description, price_inr, credits, bonus_credits, is_active
            FROM payment_plans ORDER BY sort_order ASC, id ASC
        ") as $p) {
            $products[] = array_filter([
                'code' => 'recharge-' . (int)$p['id'],
                'name' => (string)$p['name'],
                'price' => (float)$p['price_inr'],
                'billing' => 'one-time',
                'description' => trim((string)($p['description'] ?? '')) !== ''
                    ? (string)$p['description']
                    : ((int)$p['credits'] + (int)$p['bonus_credits']) . ' review credits',
                'url' => APP_URL . '/client_recharge.php',
                'status' => (int)$p['is_active'] === 1 ? 'active' : 'inactive',
            ], static fn($v): bool => $v !== null && $v !== '');
        }

        // subscription_plans is optional — older installs may not have it.
        foreach ($this->fetchAll("
            SELECT id, name, price_inr, duration_days, is_active
            FROM subscription_plans ORDER BY id ASC
        ") as $p) {
            $products[] = array_filter([
                'code' => 'plan-' . (int)$p['id'],
                'name' => (string)$p['name'],
                'price' => (float)$p['price_inr'],
                'renewal_price' => (float)$p['price_inr'],
                'billing' => $this->billingLabel((int)($p['duration_days'] ?? 0)),
                'url' => APP_URL . '/client_subscription_renew.php',
                'status' => (int)$p['is_active'] === 1 ? 'active' : 'inactive',
            ], static fn($v): bool => $v !== null && $v !== '');
        }

        if ($products === []) {
            return ['status' => 'error', 'message' => 'No plans to send'];
        }
        return ak_sync_products($products);
    }

    private function billingLabel(int $days): string
    {
        if ($days >= 360 && $days <= 370) {
            return 'yearly';
        }
        if ($days >= 28 && $days <= 31) {
            return 'monthly';
        }
        return $days > 0 ? $days . '-day' : 'one-time';
    }

    /**
     * Daily totals for one date. Revenue counts only payments that were
     * actually captured, and carries no commission — the master applies
     * its own.
     *
     * @return array<string,mixed>
     */
    public function pushDailyStats(string $date): array
    {
        if (!self::isConfigured()) {
            return ['status' => 'error', 'message' => 'Connector not configured'];
        }
        $t = $this->totalsFor($date);
        return ak_push_stats($date, $t['visitors'], $t['signups'], $t['revenue'], $t['orders']);
    }

    /**
     * @return array{visitors:int, signups:int, orders:int, revenue:float}
     */
    public function totalsFor(string $date): array
    {
        $visitors = (int)($this->scalar(
            'SELECT visits FROM ak_daily_visits WHERE visit_date = :d',
            [':d' => $date]
        ) ?? 0);

        $signups = (int)($this->scalar(
            'SELECT COUNT(*) FROM clients WHERE DATE(created_at) = :d',
            [':d' => $date]
        ) ?? 0);

        $orders = (int)($this->scalar(
            "SELECT COUNT(*) FROM payment_transactions WHERE status = 'paid' AND DATE(COALESCE(paid_at, updated_at)) = :d",
            [':d' => $date]
        ) ?? 0);

        $revenue = (float)($this->scalar(
            "SELECT COALESCE(SUM(amount_inr), 0) FROM payment_transactions WHERE status = 'paid' AND DATE(COALESCE(paid_at, updated_at)) = :d",
            [':d' => $date]
        ) ?? 0);

        return ['visitors' => $visitors, 'signups' => $signups, 'orders' => $orders, 'revenue' => $revenue];
    }

    /**
     * Send the last N days of history as daily totals. Used once to give
     * the master the site's past, so its dashboard is not empty on day
     * one. No commission is implied by historical rows.
     *
     * @return array{days:int, sent:int, failed:int}
     */
    public function backfillDailyStats(int $days = 365, ?callable $progress = null): array
    {
        $sent = 0;
        $failed = 0;
        $days = max(1, min(400, $days));
        for ($i = $days; $i >= 1; $i--) {
            $date = date('Y-m-d', strtotime('-' . $i . ' days'));
            $t = $this->totalsFor($date);
            if ($t['signups'] === 0 && $t['orders'] === 0 && $t['visitors'] === 0) {
                continue;   // nothing happened; no point sending an empty day
            }
            $r = ak_push_stats($date, $t['visitors'], $t['signups'], $t['revenue'], $t['orders']);
            ($r['status'] ?? '') === 'ok' ? $sent++ : $failed++;
            if ($progress !== null) {
                $progress($date, $r);
            }
        }
        return ['days' => $days, 'sent' => $sent, 'failed' => $failed];
    }

    /** Count one public page view for today's stats. Never throws. */
    public static function countVisit(PDO $pdo): void
    {
        try {
            $pdo->prepare("
                INSERT INTO ak_daily_visits (visit_date, visits) VALUES (CURDATE(), 1)
                ON DUPLICATE KEY UPDATE visits = visits + 1
            ")->execute();
        } catch (Throwable) {
            // Table not migrated yet, or the database is busy. A missed
            // visit count must never cost us a page render.
        }
    }

    // ------------------------------------------------------------------
    // Admin panel helpers
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    public function ping(): array
    {
        if (!self::isConfigured()) {
            return ['status' => 'error', 'message' => 'Site key or secret missing in .env'];
        }
        try {
            return ak_ping();
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /** @return array<string,int> */
    public function counts(): array
    {
        $out = ['pending' => 0, 'sent' => 0, 'failed' => 0];
        foreach ($this->fetchAll('SELECT status, COUNT(*) c FROM ak_outbox GROUP BY status') as $r) {
            $out[(string)$r['status']] = (int)$r['c'];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public function recentEvents(int $limit = 40): array
    {
        return $this->fetchAll('SELECT * FROM ak_outbox ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)));
    }

    /** @return array<int,array<string,mixed>> */
    public function failedEvents(int $limit = 50): array
    {
        return $this->fetchAll("SELECT * FROM ak_outbox WHERE status = 'failed' ORDER BY id DESC LIMIT " . max(1, min(200, $limit)));
    }

    public function lastSentAt(): ?string
    {
        $v = $this->scalar("SELECT sent_at FROM ak_outbox WHERE status = 'sent' ORDER BY sent_at DESC LIMIT 1");
        return $v !== null ? (string)$v : null;
    }

    // ------------------------------------------------------------------
    // Small query helpers that tolerate a missing table
    // ------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    private function fetchAll(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private function scalar(string $sql, array $params = []): mixed
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $v = $stmt->fetchColumn();
            return $v === false ? null : $v;
        } catch (Throwable) {
            return null;
        }
    }
}
