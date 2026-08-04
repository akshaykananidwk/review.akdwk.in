<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/settings_helper.php';
require_once __DIR__ . '/WhatsAppService.php';

final class ReviewInviteService
{
    /**
     * @return array{ok:bool, message:string, link?:string}
     */
    public static function sendInvite(PDO $pdo, int $clientId, string $customerName, string $customerMobile): array
    {
        $customerName = trim($customerName);
        $customerMobile = trim($customerMobile);
        if ($customerName === '' || $customerMobile === '') {
            return ['ok' => false, 'message' => 'Name and mobile are required.'];
        }

        $stmt = $pdo->prepare('
            SELECT public_review_url FROM client_qr_codes
            WHERE client_id = :id AND is_active = 1 ORDER BY id DESC LIMIT 1
        ');
        $stmt->execute([':id' => $clientId]);
        $row = $stmt->fetch();
        $link = trim((string)($row['public_review_url'] ?? ''));
        if ($link === '') {
            return ['ok' => false, 'message' => 'No active review link for your business.'];
        }

        $tpl = getSystemSetting(
            $pdo,
            'review_invite_message_template',
            'Hi {name}, thank you for visiting us. Please rate your experience: {link}'
        );
        $body = str_replace(['{name}', '{link}'], [$customerName, $link], $tpl);

        $wa = new WhatsAppService($pdo);
        if (!$wa->isConfigured()) {
            return ['ok' => false, 'message' => 'WhatsApp is not configured on this platform.'];
        }
        $resp = $wa->sendText($customerMobile, $body);
        if (!$resp['ok']) {
            return ['ok' => false, 'message' => 'WhatsApp send failed: ' . ($resp['error'] ?? 'unknown')];
        }
        return ['ok' => true, 'message' => 'Invite sent successfully.', 'link' => $link];
    }
}
