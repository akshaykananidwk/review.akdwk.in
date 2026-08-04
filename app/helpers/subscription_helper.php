<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function clientHasActiveSubscription(PDO $pdo, int $clientId): bool
{
    try {
        $st = $pdo->prepare('SELECT subscription_valid_until FROM clients WHERE id = :id LIMIT 1');
        $st->execute([':id' => $clientId]);
        $row = $st->fetch();
        if (!$row) {
            return false;
        }
        $until = $row['subscription_valid_until'] ?? null;
        if ($until === null || $until === '') {
            return false;
        }
        $today = date('Y-m-d');
        return strcmp((string)$until, $today) >= 0;
    } catch (Throwable) {
        // Column missing before migration — do not block production traffic.
        return true;
    }
}

/**
 * Extend platform access (valid_until) from the later of today or current valid_until.
 * Does not touch subscription_plan_id — used for wallet/recharge plans.
 *
 * @return string|null New subscription_valid_until (Y-m-d) or null on failure
 */
function extendClientValidityByDays(PDO $pdo, int $clientId, int $durationDays): ?string
{
    $durationDays = max(1, $durationDays);
    $pdo->prepare('SELECT id FROM clients WHERE id = :id FOR UPDATE')->execute([':id' => $clientId]);

    $st = $pdo->prepare('SELECT subscription_valid_until FROM clients WHERE id = :id LIMIT 1');
    $st->execute([':id' => $clientId]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    $cur = $row['subscription_valid_until'] ?? null;
    $today = new DateTimeImmutable('today');
    $base = $today;
    if ($cur !== null && $cur !== '') {
        $curDt = DateTimeImmutable::createFromFormat('Y-m-d', (string)$cur) ?: $today;
        if ($curDt > $today) {
            $base = $curDt;
        }
    }
    $newEnd = $base->modify('+' . $durationDays . ' days')->format('Y-m-d');
    $u = $pdo->prepare('
        UPDATE clients
        SET subscription_valid_until = :until,
            updated_at = NOW()
        WHERE id = :id
    ');
    $u->execute([
        ':until' => $newEnd,
        ':id' => $clientId,
    ]);
    return $newEnd;
}

/**
 * Legacy subscription_orders path: extend validity and link subscription_plans.id when valid.
 *
 * @return string|null New subscription_valid_until (Y-m-d) or null on failure
 */
function extendClientSubscriptionByDays(PDO $pdo, int $clientId, int $planId, int $durationDays): ?string
{
    $newEnd = extendClientValidityByDays($pdo, $clientId, $durationDays);
    if ($newEnd === null || $planId <= 0) {
        return $newEnd;
    }
    try {
        $chk = $pdo->prepare('SELECT id FROM subscription_plans WHERE id = :id LIMIT 1');
        $chk->execute([':id' => $planId]);
        if ($chk->fetch()) {
            $pdo->prepare('UPDATE clients SET subscription_plan_id = :pid, updated_at = NOW() WHERE id = :id')
                ->execute([':pid' => $planId, ':id' => $clientId]);
        }
    } catch (Throwable) {
        // subscription_plans table may be unused; validity already extended.
    }
    return $newEnd;
}

function paymentPlansHaveDurationDays(PDO $pdo): bool
{
    try {
        return (bool)$pdo->query("SHOW COLUMNS FROM payment_plans LIKE 'duration_days'")->fetch();
    } catch (Throwable) {
        return false;
    }
}

function formatValidityDaysLabel(int $days): string
{
    if ($days === 30) {
        return '30 days (1 month)';
    }
    if ($days === 365) {
        return '365 days (1 year)';
    }
    return $days . ' days';
}

/**
 * Set exact platform expiry date (admin edit or manual recharge).
 *
 * @return string|null Normalized Y-m-d or null on failure
 */
function setClientValidityUntil(PDO $pdo, int $clientId, string $dateYmd): ?string
{
    $dateYmd = trim($dateYmd);
    if ($dateYmd === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $dateYmd);
    if ($dt === false) {
        return null;
    }
    $until = $dt->format('Y-m-d');

    $pdo->prepare('SELECT id FROM clients WHERE id = :id FOR UPDATE')->execute([':id' => $clientId]);
    $pdo->prepare('
        UPDATE clients
        SET subscription_valid_until = :until,
            updated_at = NOW()
        WHERE id = :id
    ')->execute([':until' => $until, ':id' => $clientId]);

    return $until;
}

/**
 * Admin top-up: extend by N days or set a fixed expiry date.
 *
 * @return string|null New valid_until (Y-m-d)
 */
function applyAdminTopupValidity(PDO $pdo, int $clientId, string $mode, int $validityDays, string $validityUntilDate): ?string
{
    if ($mode === 'set_date' && trim($validityUntilDate) !== '') {
        return setClientValidityUntil($pdo, $clientId, $validityUntilDate);
    }
    if ($validityDays > 0) {
        return extendClientValidityByDays($pdo, $clientId, $validityDays);
    }
    return null;
}

/**
 * @return array{label:string, class:string}
 */
function validityStatusBadge(?string $validUntil): array
{
    if ($validUntil === null || trim($validUntil) === '') {
        return ['label' => 'No date', 'class' => 'suspended'];
    }
    $today = date('Y-m-d');
    if (strcmp(trim($validUntil), $today) >= 0) {
        return ['label' => 'Active', 'class' => 'active'];
    }
    return ['label' => 'Expired', 'class' => 'suspended'];
}
