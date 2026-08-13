<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/Logger.php';

/**
 * Legacy per-calendar-day, per-IP limiter.
 *
 * Retained unchanged for the password-reset flow, which is the only
 * existing caller. New call sites should use rateLimitHit() below: the
 * daily bucket resets at midnight and cannot express "5 attempts per
 * 15 minutes", which is what abuse prevention actually needs.
 */
function checkAndHitRateLimit(string $actionKey, string $ip, int $dailyLimit): bool
{
    $pdo = getPDO();

    $upsert = $pdo->prepare("
        INSERT INTO ip_rate_limits (action_key, ip_address, request_date, hit_count, created_at, updated_at)
        VALUES (:action_key, :ip_address, CURDATE(), 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
          hit_count = hit_count + 1,
          updated_at = NOW()
    ");
    $upsert->execute([
        ':action_key' => $actionKey,
        ':ip_address' => $ip,
    ]);

    $q = $pdo->prepare("
        SELECT hit_count
        FROM ip_rate_limits
        WHERE action_key = :action_key AND ip_address = :ip_address AND request_date = CURDATE()
        LIMIT 1
    ");
    $q->execute([
        ':action_key' => $actionKey,
        ':ip_address' => $ip,
    ]);
    $row = $q->fetch();

    return (int)($row['hit_count'] ?? 0) <= $dailyLimit;
}

/**
 * Sliding-window rate limiter.
 * -------------------------------------------------------------------
 * Counts hits per (action, identifier) inside a rolling window. The
 * counter and the window reset are performed in a single atomic
 * INSERT … ON DUPLICATE KEY UPDATE, so concurrent requests cannot both
 * reset the window and slip through.
 *
 * Identifier is whatever dimension you want to limit on — an IP for
 * anonymous traffic, an email for login, a client id for API keys.
 * Passing several dimensions (separate calls) is the normal pattern:
 * limit by IP *and* by account, so neither an IP rotation nor a single
 * targeted account can bypass the control alone.
 *
 * Availability note: if the backing table is missing or the database is
 * unreachable this returns "allowed" and logs a CRITICAL event. A
 * broken limiter must not take the site down, but it must be loud.
 *
 * @param string $action      short label, e.g. 'login', 'register'
 * @param string $identifier  IP, email, client id …
 * @param int    $limit       max hits allowed inside the window
 * @param int    $windowSecs  window length in seconds
 * @return array{allowed:bool, hits:int, limit:int, retry_after:int}
 */
function rateLimitHit(string $action, string $identifier, int $limit, int $windowSecs): array
{
    $limit = max(1, $limit);
    $windowSecs = max(1, $windowSecs);
    $bucketKey = substr($action, 0, 40) . ':' . hash('sha256', $action . '|' . $identifier);

    try {
        $pdo = getPDO();

        // Atomic: increment inside the window, or start a fresh window.
        $stmt = $pdo->prepare("
            INSERT INTO rate_limit_buckets (bucket_key, action_key, hit_count, window_started_at)
            VALUES (:k, :a, 1, NOW())
            ON DUPLICATE KEY UPDATE
                hit_count = IF(window_started_at < (NOW() - INTERVAL :w1 SECOND), 1, hit_count + 1),
                window_started_at = IF(window_started_at < (NOW() - INTERVAL :w2 SECOND), NOW(), window_started_at)
        ");
        $stmt->bindValue(':k', $bucketKey);
        $stmt->bindValue(':a', substr($action, 0, 60));
        $stmt->bindValue(':w1', $windowSecs, PDO::PARAM_INT);
        $stmt->bindValue(':w2', $windowSecs, PDO::PARAM_INT);
        $stmt->execute();

        $read = $pdo->prepare("
            SELECT hit_count,
                   GREATEST(0, :w3 - TIMESTAMPDIFF(SECOND, window_started_at, NOW())) AS retry_after
            FROM rate_limit_buckets
            WHERE bucket_key = :k
            LIMIT 1
        ");
        $read->bindValue(':k', $bucketKey);
        $read->bindValue(':w3', $windowSecs, PDO::PARAM_INT);
        $read->execute();
        $row = $read->fetch();

        $hits = (int)($row['hit_count'] ?? 1);
        $retryAfter = (int)($row['retry_after'] ?? $windowSecs);

        return [
            'allowed' => $hits <= $limit,
            'hits' => $hits,
            'limit' => $limit,
            'retry_after' => $retryAfter,
        ];
    } catch (Throwable $e) {
        Logger::critical(Logger::CH_SECURITY, 'Rate limiter unavailable — request allowed without limiting', [
            'action' => $action,
        ], $e);
        return ['allowed' => true, 'hits' => 0, 'limit' => $limit, 'retry_after' => 0];
    }
}

/**
 * Read the current count without consuming a hit — used to check a
 * limit before doing expensive work, when the hit is recorded later.
 */
function rateLimitPeek(string $action, string $identifier, int $windowSecs): int
{
    $bucketKey = substr($action, 0, 40) . ':' . hash('sha256', $action . '|' . $identifier);
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare("
            SELECT hit_count FROM rate_limit_buckets
            WHERE bucket_key = :k AND window_started_at >= (NOW() - INTERVAL :w SECOND)
            LIMIT 1
        ");
        $stmt->bindValue(':k', $bucketKey);
        $stmt->bindValue(':w', max(1, $windowSecs), PDO::PARAM_INT);
        $stmt->execute();
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Clear a bucket — call after a successful login so a legitimate user
 * who mistyped a few times is not punished for the rest of the window.
 */
function rateLimitReset(string $action, string $identifier): void
{
    $bucketKey = substr($action, 0, 40) . ':' . hash('sha256', $action . '|' . $identifier);
    try {
        getPDO()->prepare('DELETE FROM rate_limit_buckets WHERE bucket_key = :k')
            ->execute([':k' => $bucketKey]);
    } catch (Throwable) {
        // Non-critical: the window will expire on its own.
    }
}

/** Client IP for limiting purposes. REMOTE_ADDR only — never trust XFF. */
function rateLimitClientIp(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}
