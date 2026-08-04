<?php
declare(strict_types=1);

require_once __DIR__ . '/QrService.php';
require_once __DIR__ . '/../helpers/settings_helper.php';

/**
 * Builds a template-based standee PNG for a client (QR + optional business name overlay).
 */
final class StandeeComposerService
{
    /**
     * @return array{relative:string,absolute:string}|null
     */
    public static function composeTemplateStandeeForClient(PDO $pdo, int $clientId, ?int $templateId = null): ?array
    {
        if ($templateId !== null && $templateId > 0) {
            $tplStmt = $pdo->prepare('SELECT * FROM standee_templates WHERE id = :id AND is_active = 1 LIMIT 1');
            $tplStmt->execute([':id' => $templateId]);
            $tpl = $tplStmt->fetch();
        } else {
            $configured = (int)getSystemSetting($pdo, 'default_welcome_standee_template_id', '0');
            if ($configured > 0) {
                $tplStmt = $pdo->prepare('SELECT * FROM standee_templates WHERE id = :id AND is_active = 1 LIMIT 1');
                $tplStmt->execute([':id' => $configured]);
                $tpl = $tplStmt->fetch();
            } else {
                $tpl = false;
            }
            if (!$tpl) {
                $tpl = $pdo->query('
                    SELECT * FROM standee_templates
                    WHERE is_active = 1
                    ORDER BY sort_order ASC, id ASC
                    LIMIT 1
                ')->fetch();
            }
        }
        if (!$tpl) {
            return null;
        }

        $cStmt = $pdo->prepare('SELECT business_name FROM clients WHERE id = :id LIMIT 1');
        $cStmt->execute([':id' => $clientId]);
        $crow = $cStmt->fetch();
        if (!$crow) {
            return null;
        }
        $businessName = trim((string)($crow['business_name'] ?? ''));

        $qrStmt = $pdo->prepare('
            SELECT qr_image_path FROM client_qr_codes
            WHERE client_id = :cid AND is_active = 1 ORDER BY id DESC LIMIT 1
        ');
        $qrStmt->execute([':cid' => $clientId]);
        $qrRow = $qrStmt->fetch();
        if (!$qrRow || empty($qrRow['qr_image_path'])) {
            return null;
        }

        $qrAbs = __DIR__ . '/../../public/' . ltrim((string)$qrRow['qr_image_path'], '/');
        if (!is_file($qrAbs)) {
            return null;
        }

        $tplAbs = __DIR__ . '/../../public/' . ltrim((string)$tpl['image_path'], '/');
        if (!is_file($tplAbs)) {
            return null;
        }

        $qrX = (int)($tpl['qr_pos_x'] ?? 0);
        $qrY = (int)($tpl['qr_pos_y'] ?? 0);
        $qrW = (int)($tpl['qr_width'] ?? 0);
        $qrH = (int)($tpl['qr_height'] ?? 0);

        $overlay = null;
        if (
            isset($tpl['business_name_enabled'])
            && (int)$tpl['business_name_enabled'] === 1
            && $businessName !== ''
        ) {
            $nw = (int)($tpl['native_width'] ?? 0);
            $nh = (int)($tpl['native_height'] ?? 0);
            $bw = (int)($tpl['business_name_box_w'] ?? 0);
            $bh = (int)($tpl['business_name_box_h'] ?? 0);
            if ($bw < 40 && $nw > 0) {
                $bw = max(200, $nw - 80);
            }
            if ($bh < 30 && $nh > 0) {
                $bh = min(200, max(80, (int)round($nh * 0.12)));
            }
            $overlay = [
                'enabled'   => true,
                'text'      => $businessName,
                'x'         => (int)($tpl['business_name_pos_x'] ?? 0),
                'y'         => (int)($tpl['business_name_pos_y'] ?? 0),
                'box_w'     => max(40, $bw),
                'box_h'     => max(30, $bh),
                'font_pt'   => max(10, (int)($tpl['business_name_font_pt'] ?? 36)),
                'color_hex' => (string)($tpl['business_name_color'] ?? '#0f172a'),
            ];
        }

        $outDir = __DIR__ . '/../../public/uploads/standee/generated';
        if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
            return null;
        }

        $fileName = 'auto_c' . $clientId . '_t' . (int)$tpl['id'] . '_' . bin2hex(random_bytes(4)) . '.png';
        $relativeOut = 'uploads/standee/generated/' . $fileName;
        $absoluteOut = $outDir . '/' . $fileName;

        try {
            QrService::generateStandeeFromTemplate(
                $tplAbs,
                $qrAbs,
                $absoluteOut,
                $qrX,
                $qrY,
                $qrW,
                $qrH,
                $overlay
            );
        } catch (Throwable) {
            return null;
        }

        return ['relative' => $relativeOut, 'absolute' => $absoluteOut];
    }
}
