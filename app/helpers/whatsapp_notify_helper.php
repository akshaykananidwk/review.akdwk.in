<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/WhatsAppService.php';

/**
 * @return list<string> E.164-ish digits as expected by the gateway (e.g. 9198…)
 */
function whatsapp_collect_notify_numbers(PDO $pdo, string $primaryMobile, ?string $extraCommaSeparated): array
{
    $wa = new WhatsAppService($pdo);
    $seen = [];
    $add = static function (string $raw) use ($wa, &$seen): void {
        $raw = trim($raw);
        if ($raw === '') {
            return;
        }
        $n = $wa->normalizeMobile($raw);
        if ($n !== '') {
            $seen[$n] = true;
        }
    };

    $add($primaryMobile);
    $extra = trim((string)$extraCommaSeparated);
    if ($extra !== '') {
        foreach (preg_split('/[\s,;|]+/', $extra) ?: [] as $part) {
            $add((string)$part);
        }
    }

    return array_keys($seen);
}

/**
 * @return array{business_name:string,mobile:string,extra_whatsapp_numbers:?string}|null
 */
function whatsapp_fetch_client_row_for_notify(PDO $pdo, int $clientId): ?array
{
    try {
        $s = $pdo->prepare("
            SELECT business_name, mobile, extra_whatsapp_numbers
            FROM clients WHERE id = :id LIMIT 1
        ");
        $s->execute([':id' => $clientId]);
        $row = $s->fetch();
        return $row ?: null;
    } catch (Throwable) {
        $s = $pdo->prepare("SELECT business_name, mobile FROM clients WHERE id = :id LIMIT 1");
        $s->execute([':id' => $clientId]);
        $row = $s->fetch();
        return $row ?: null;
    }
}
