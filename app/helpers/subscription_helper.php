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
    } catch (Throwable $e) {
        // FAIL CLOSED. This is a paywall: a database error must not hand
        // every expired client free access. The failure is logged as
        // critical because it silently blocks paying customers too.
        require_once __DIR__ . '/../services/Logger.php';
        Logger::critical(Logger::CH_APP, 'Subscription check failed — denying access (fail closed)', [
            'client_id' => $clientId,
        ], $e);
        return false;
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

/**
 * Turn a configured number of free days into the phrase the public site
 * shows, in Gujarati and English.
 *
 * The landing copy used to hard-code its durations, which meant the
 * advertised offer and the validity actually granted could drift apart —
 * and did: the page promised a three-year Dwarka offer and a three-month
 * trial while the settings said otherwise. Deriving the words from the
 * same setting that grants the days keeps the promise and the product in
 * step, and changing the offer becomes one number in Global Settings.
 *
 * @return array{gu:string, en:string}
 */
function humanValidityPeriod(int $days): array
{
    $days = max(0, $days);
    if ($days === 0) {
        return ['gu' => 'કોઈ ફ્રી સમય નહીં', 'en' => 'no free period'];
    }
    if ($days % 365 === 0) {
        $years = intdiv($days, 365);
        return [
            'gu' => $years . ' વર્ષ',
            'en' => $years . ' year' . ($years === 1 ? '' : 's'),
        ];
    }
    if ($days % 30 === 0) {
        $months = intdiv($days, 30);
        return [
            'gu' => $months . ' મહિન' . ($months === 1 ? 'ો' : 'ા'),
            'en' => $months . ' month' . ($months === 1 ? '' : 's'),
        ];
    }
    return [
        'gu' => $days . ' દિવસ',
        'en' => $days . ' day' . ($days === 1 ? '' : 's'),
    ];
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
 * Everything the client panel needs to tell a client when their access
 * ends, in one place.
 *
 * A client could previously only find their expiry date on the recharge
 * page, written as a bare 2026-12-15 with no sense of how close it was.
 * This returns a human date plus the days remaining, so the panel can
 * warn before access is cut off instead of after.
 *
 * `state` is one of:
 *   ok       comfortably in date
 *   warning  inside WARN_DAYS of expiry
 *   critical inside CRITICAL_DAYS of expiry, or expires today
 *   expired  the date has passed
 *   none     no expiry date is set on the account
 *   unknown  the lookup failed — say nothing rather than guess
 *
 * days_left counts whole days from today and is 0 on the last valid day,
 * matching clientHasActiveSubscription(), which treats today as active.
 *
 * @return array{
 *   valid_until:?string, valid_until_human:?string, days_left:?int,
 *   state:string, is_active:bool, headline:string, detail:string
 * }
 */
function clientValidityStatus(PDO $pdo, int $clientId): array
{
    static $cache = [];
    if (array_key_exists($clientId, $cache)) {
        return $cache[$clientId];
    }

    $warnDays = 10;
    $criticalDays = 3;

    $base = [
        'valid_until' => null,
        'valid_until_human' => null,
        'days_left' => null,
        'state' => 'unknown',
        'is_active' => false,
        'headline' => '',
        'detail' => '',
    ];

    try {
        $st = $pdo->prepare('SELECT subscription_valid_until FROM clients WHERE id = :id LIMIT 1');
        $st->execute([':id' => $clientId]);
        $row = $st->fetch();
    } catch (Throwable $e) {
        require_once __DIR__ . '/../services/Logger.php';
        Logger::warning(Logger::CH_APP, 'Could not read subscription validity for the client panel', [
            'client_id' => $clientId,
        ], $e);
        return $cache[$clientId] = $base;
    }

    if (!$row) {
        return $cache[$clientId] = $base;
    }

    $until = trim((string)($row['subscription_valid_until'] ?? ''));
    if ($until === '') {
        // No validity at all. Since the platform is paid from day one,
        // this is the normal state of a business that has registered but
        // not yet recharged — so it points at the fix, not at support.
        $base['state'] = 'none';
        $base['headline'] = 'No active plan';
        $base['detail'] = 'Recharge your wallet to activate your QR standee and start collecting reviews.';
        return $cache[$clientId] = $base;
    }

    $untilDt = DateTimeImmutable::createFromFormat('!Y-m-d', $until);
    if ($untilDt === false) {
        return $cache[$clientId] = $base;   // unknown: do not guess at a malformed date
    }

    $today = new DateTimeImmutable('today');
    $daysLeft = (int)$today->diff($untilDt)->format('%r%a');

    $base['valid_until'] = $untilDt->format('Y-m-d');
    $base['valid_until_human'] = $untilDt->format('d M Y');
    $base['days_left'] = $daysLeft;
    $base['is_active'] = $daysLeft >= 0;

    if ($daysLeft < 0) {
        $base['state'] = 'expired';
        $base['headline'] = 'Your plan expired on ' . $base['valid_until_human'];
        $base['detail'] = abs($daysLeft) === 1
            ? 'It expired 1 day ago. Recharge to restore your QR reviews and dashboard.'
            : 'It expired ' . abs($daysLeft) . ' days ago. Recharge to restore your QR reviews and dashboard.';
    } elseif ($daysLeft === 0) {
        $base['state'] = 'critical';
        $base['headline'] = 'Your plan expires today';
        $base['detail'] = 'Recharge today to avoid any break in your QR reviews.';
    } elseif ($daysLeft <= $criticalDays) {
        $base['state'] = 'critical';
        $base['headline'] = 'Your plan expires in ' . $daysLeft . ($daysLeft === 1 ? ' day' : ' days');
        $base['detail'] = 'Valid until ' . $base['valid_until_human'] . '. Recharge now to stay active.';
    } elseif ($daysLeft <= $warnDays) {
        $base['state'] = 'warning';
        $base['headline'] = 'Your plan expires in ' . $daysLeft . ' days';
        $base['detail'] = 'Valid until ' . $base['valid_until_human'] . '. Recharge any time to extend it.';
    } else {
        $base['state'] = 'ok';
        $base['headline'] = 'Plan active until ' . $base['valid_until_human'];
        $base['detail'] = $daysLeft . ' days remaining.';
    }

    return $cache[$clientId] = $base;
}

/** Short chip text for the panel header: "12 Dec 2026 · 27 days left". */
function validityChipText(array $status): string
{
    if ($status['valid_until_human'] === null) {
        return $status['state'] === 'none' ? 'No expiry set' : '';
    }
    $days = (int)$status['days_left'];
    if ($days < 0) {
        return $status['valid_until_human'] . ' · expired';
    }
    if ($days === 0) {
        return $status['valid_until_human'] . ' · last day';
    }
    return $status['valid_until_human'] . ' · ' . $days . ($days === 1 ? ' day left' : ' days left');
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
