<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/session_helper.php';
require_once __DIR__ . '/../helpers/settings_helper.php';
require_once __DIR__ . '/../helpers/csrf_helper.php';
require_once __DIR__ . '/../services/QrService.php';

final class StandeeGalleryController
{
    public function index(): void
    {
        requireClientPanelAccess();
        $pdo = getPDO();
        $clientId = (int)$_SESSION['client_id'];

        $clientStmt = $pdo->prepare('
            SELECT id, business_name, email, wallet_balance
            FROM clients WHERE id = :id LIMIT 1
        ');
        $clientStmt->execute([':id' => $clientId]);
        $client = $clientStmt->fetch();
        if (!$client) {
            header('Location: ' . APP_URL . '/logout.php');
            exit;
        }

        try {
            $templates = $pdo->query('
                SELECT id, title, image_path, sort_order
                FROM standee_templates
                WHERE is_active = 1
                ORDER BY sort_order ASC, id DESC
            ')->fetchAll();
        } catch (Throwable) {
            $templates = [];
        }

        $flash = '';
        $error = '';
        if (isset($_GET['msg'])) {
            $flash = (string)$_GET['msg'];
        }
        if (isset($_GET['err'])) {
            $error = (string)$_GET['err'];
        }
        $csrfToken = csrfGenerateToken();

        require __DIR__ . '/../views/client/standee_gallery.php';
    }

    public function generate(): void
    {
        requireClientPanelAccess();
        $pdo = getPDO();
        $clientId = (int)$_SESSION['client_id'];

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . APP_URL . '/standee_gallery.php');
            exit;
        }

        if (!csrfValidateToken((string)($_POST['csrf_token'] ?? ''))) {
            header('Location: ' . APP_URL . '/standee_gallery.php?err=' . rawurlencode('Invalid session token. Please try again.'));
            exit;
        }

        $templateId = (int)($_POST['template_id'] ?? 0);
        if ($templateId <= 0) {
            header('Location: ' . APP_URL . '/standee_gallery.php?err=' . rawurlencode('Please select a template.'));
            exit;
        }

        $tpl = null;
        try {
            $tplStmt = $pdo->prepare('SELECT * FROM standee_templates WHERE id = :id AND is_active = 1 LIMIT 1');
            $tplStmt->execute([':id' => $templateId]);
            $tpl = $tplStmt->fetch();
        } catch (Throwable) {
            $tplStmt = $pdo->prepare('SELECT id, image_path FROM standee_templates WHERE id = :id AND is_active = 1 LIMIT 1');
            $tplStmt->execute([':id' => $templateId]);
            $tpl = $tplStmt->fetch();
        }
        if (!$tpl) {
            header('Location: ' . APP_URL . '/standee_gallery.php?err=' . rawurlencode('Template not found.'));
            exit;
        }

        $qrX = (int)($tpl['qr_pos_x'] ?? 0);
        $qrY = (int)($tpl['qr_pos_y'] ?? 0);
        $qrW = (int)($tpl['qr_width'] ?? 0);
        $qrH = (int)($tpl['qr_height'] ?? 0);

        $qrStmt = $pdo->prepare('
            SELECT qr_image_path FROM client_qr_codes
            WHERE client_id = :cid AND is_active = 1 ORDER BY id DESC LIMIT 1
        ');
        $qrStmt->execute([':cid' => $clientId]);
        $qrRow = $qrStmt->fetch();
        if (!$qrRow || empty($qrRow['qr_image_path'])) {
            header('Location: ' . APP_URL . '/standee_gallery.php?err=' . rawurlencode('No active QR code on file. Contact support if this is unexpected.'));
            exit;
        }

        $qrAbs = __DIR__ . '/../../public/' . ltrim((string)$qrRow['qr_image_path'], '/');
        if (!is_file($qrAbs)) {
            header('Location: ' . APP_URL . '/standee_gallery.php?err=' . rawurlencode('QR image file missing on server.'));
            exit;
        }

        $tplAbs = __DIR__ . '/../../public/' . ltrim((string)$tpl['image_path'], '/');
        if (!is_file($tplAbs)) {
            header('Location: ' . APP_URL . '/standee_gallery.php?err=' . rawurlencode('Template file missing. Ask admin to re-upload.'));
            exit;
        }

        $outDir = __DIR__ . '/../../public/uploads/standee/generated';
        if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
            header('Location: ' . APP_URL . '/standee_gallery.php?err=' . rawurlencode('Could not prepare download folder.'));
            exit;
        }

        $fileName = 'c' . $clientId . '_t' . $templateId . '_' . bin2hex(random_bytes(4)) . '.png';
        $relativeOut = 'uploads/standee/generated/' . $fileName;
        $absoluteOut = $outDir . '/' . $fileName;

        $bizStmt = $pdo->prepare('SELECT business_name FROM clients WHERE id = :id LIMIT 1');
        $bizStmt->execute([':id' => $clientId]);
        $bizRow = $bizStmt->fetch();
        $businessName = trim((string)($bizRow['business_name'] ?? ''));
        $overlay = $this->buildBusinessNameOverlayForTemplate($tpl, $businessName);

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
        } catch (Throwable $e) {
            header('Location: ' . APP_URL . '/standee_gallery.php?err=' . rawurlencode('Could not compose standee: ' . $e->getMessage()));
            exit;
        }

        csrfEnsureSession();
        $_SESSION['standee_dl_path'] = $relativeOut;
        $_SESSION['standee_dl_ts'] = time();

        header('Location: ' . APP_URL . '/standee_download.php');
        exit;
    }

    /**
     * @param array<string,mixed> $tpl
     */
    private function buildBusinessNameOverlayForTemplate(array $tpl, string $businessName): ?array
    {
        if ($businessName === '' || !array_key_exists('business_name_enabled', $tpl)) {
            return null;
        }
        if ((int)$tpl['business_name_enabled'] !== 1) {
            return null;
        }

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

        return [
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
}
