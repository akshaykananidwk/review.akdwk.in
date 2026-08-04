<?php
declare(strict_types=1);

final class QrService
{
    public static function createToken(int $length = 32): string
    {
        return bin2hex(random_bytes(max(16, (int)($length / 2))));
    }

    public static function generate(string $text, string $outputFile): void
    {
        $dir = dirname($outputFile);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('QR directory could not be created: ' . $dir);
        }

        if (!is_writable($dir)) {
            throw new RuntimeException('QR directory is not writable: ' . $dir);
        }

        // Ensure output extension is png even if caller sends something else.
        $normalizedOutput = preg_replace('/\.[a-zA-Z0-9]+$/', '.png', $outputFile) ?: ($outputFile . '.png');

        if (self::generateWithLocalPhpQrCode($text, $normalizedOutput)) {
            return;
        }

        if (self::generateWithQrServerApi($text, $normalizedOutput)) {
            return;
        }

        throw new RuntimeException('QR generation failed for both local library and external fallback API.');
    }

    /**
     * Generate a print-ready A4 portrait standee with the QR code,
     * business name (auto-wrapped) and lead-gen branding.
     *
     * Layout is built top-down with a vertical "cursor" so blocks can
     * never overlap regardless of how long the business name is.
     */
    public static function generateStandee(string $businessName, string $qrImagePath, string $outputFile, string $systemName = 'Krishna Review System'): void
    {
        $dir = dirname($outputFile);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Standee directory could not be created: ' . $dir);
        }

        if (!function_exists('imagecreatetruecolor') || !function_exists('imagecreatefrompng')) {
            throw new RuntimeException('GD extension is required for standee generation.');
        }

        if (!is_file($qrImagePath)) {
            throw new RuntimeException('QR image not found for standee generation.');
        }

        // ---------- Canvas (A4 portrait @ 150 DPI) ----------
        $W = 1240;
        $H = 1754;
        $canvas = imagecreatetruecolor($W, $H);
        if ($canvas === false) {
            throw new RuntimeException('Failed to create standee canvas.');
        }
        if (function_exists('imageantialias')) {
            imageantialias($canvas, true);
        }

        // ---------- Colours ----------
        $peacock     = imagecolorallocate($canvas, 0, 95, 143);
        $peacockDark = imagecolorallocate($canvas, 0, 67, 103);
        $deepYellow  = imagecolorallocate($canvas, 244, 180, 0);
        $brightGold  = imagecolorallocate($canvas, 255, 215, 0);   // #FFD700 — readable on dark blue
        $gold        = imagecolorallocate($canvas, 212, 175, 55);
        $white       = imagecolorallocate($canvas, 255, 255, 255);
        $dark        = imagecolorallocate($canvas, 22, 31, 62);
        $cream       = imagecolorallocate($canvas, 255, 251, 235);
        $softShadow  = imagecolorallocate($canvas, 230, 220, 200);

        // ---------- Layout constants (single source of truth) ----------
        $borderInset    = 30;   // gold border rectangle inset
        $headerHeight   = 290;  // dark band at the top
        $headerStripe   = 30;   // yellow stripe under the header
        $footerHeight   = 240;  // dark band at the bottom
        $footerStripe   = 30;   // yellow stripe above the footer
        $sidePadding    = 110;  // horizontal padding for text content
        $contentTopPad  = 70;   // padding inside the cream zone (top)
        $contentBotPad  = 60;   // padding inside the cream zone (bottom)
        $gapAfterBiz    = 60;   // gap between business name block and "scan here"
        $gapWithinScan  = 14;   // gap between "SCAN HERE FOR" and "5-STAR REVIEW"
        $gapAfterScan   = 60;   // gap between scan block and QR card
        $qrFramePad     = 36;   // padding inside the white QR card

        // ---------- Background bands ----------
        imagefilledrectangle($canvas, 0, 0, $W, $H, $cream);
        imagefilledrectangle($canvas, 0, 0, $W, $headerHeight, $peacock);
        imagefilledrectangle($canvas, 0, $headerHeight, $W, $headerHeight + $headerStripe, $deepYellow);
        imagefilledrectangle($canvas, 0, $H - $footerHeight - $footerStripe, $W, $H - $footerHeight, $deepYellow);
        imagefilledrectangle($canvas, 0, $H - $footerHeight, $W, $H, $peacockDark);

        // Decorative outer border.
        imagesetthickness($canvas, 8);
        imagerectangle($canvas, $borderInset, $borderInset, $W - $borderInset, $H - $borderInset, $gold);
        imagesetthickness($canvas, 1);

        // ---------- Sanitise inputs ----------
        $safeBusiness = trim($businessName) !== '' ? trim($businessName) : 'Our Business';
        $safeSystem   = trim($systemName)   !== '' ? trim($systemName)   : 'Krishna Review System';

        // ---------- HEADER BAND (dark blue) ----------
        // System name + "PREMIUM CUSTOMER REVIEW" badge stacked vertically.
        $headerSysSize  = 50;   // pt
        $headerBadgeSize = 26;  // pt
        $headerInnerTop = 60;   // padding inside header

        $sysBlockY = $headerInnerTop;
        self::drawCenteredTtfLine(
            $canvas, $white, $headerSysSize, $sysBlockY,
            $safeSystem, $W, $sidePadding, true
        );

        // Compute height used by system name to position the badge below it.
        $sysHeight = self::measureTtfLineHeight($headerSysSize);

        $badgeY = $sysBlockY + $sysHeight + 30;
        // FIX: previously rendered as dark blue on dark blue (invisible).
        // Now uses BRIGHT GOLD #FFD700 so it pops on the peacock background.
        self::drawCenteredTtfLine(
            $canvas, $brightGold, $headerBadgeSize, $badgeY,
            'PREMIUM CUSTOMER REVIEW', $W, $sidePadding, true
        );

        // ---------- CONTENT ZONE (cream) — dynamic vertical flow ----------
        $contentTop    = $headerHeight + $headerStripe + $contentTopPad;
        $contentBottom = $H - $footerHeight - $footerStripe - $contentBotPad;
        $maxTextWidth  = $W - 2 * $sidePadding;

        // ----- Business name block (auto-wrap up to 3 lines, auto-shrink font) -----
        $bizMaxFontPt = 100;  // big & bold (focal point)
        $bizMinFontPt = 40;
        $bizFit = self::fitWrappedTtfText(
            $safeBusiness, $bizMaxFontPt, $bizMinFontPt, $maxTextWidth, 3
        );
        $bizFontPt    = $bizFit['size'];
        $bizLines     = $bizFit['lines'];
        $bizLineH     = (int)round($bizFontPt * 1.45); // generous line spacing
        $bizBlockH    = count($bizLines) * $bizLineH;

        // ----- "SCAN HERE FOR" + "5-STAR REVIEW" block -----
        $scanTitleSize = 40; // pt
        $scanMainSize  = 76; // pt
        $scanTitleH    = self::measureTtfLineHeight($scanTitleSize);
        $scanMainH     = self::measureTtfLineHeight($scanMainSize);
        $scanBlockH    = $scanTitleH + $gapWithinScan + $scanMainH;

        // ----- QR card sizing (adaptive to remaining space) -----
        $availableForQr = ($contentBottom - $contentTop)
            - $bizBlockH
            - $gapAfterBiz
            - $scanBlockH
            - $gapAfterScan
            - 2 * $qrFramePad;
        $qrSize = max(420, min(620, $availableForQr));
        // Pull QR slightly down/up so the whole stack is vertically centred.
        $stackHeight = $bizBlockH + $gapAfterBiz + $scanBlockH + $gapAfterScan + $qrSize + 2 * $qrFramePad;
        $startY = $contentTop + max(0, (int)(($contentBottom - $contentTop - $stackHeight) / 2));

        // Paint a subtle inner card behind the cream zone for "page" feel.
        // (Soft shadow rectangle pushed down/right, then a clean white-ish card.)
        $cardInset = 60;
        $cardTop   = $headerHeight + $headerStripe + 30;
        $cardBot   = $H - $footerHeight - $footerStripe - 30;
        imagefilledrectangle($canvas, $cardInset + 6, $cardTop + 6, $W - $cardInset + 6, $cardBot + 6, $softShadow);
        imagefilledrectangle($canvas, $cardInset, $cardTop, $W - $cardInset, $cardBot, $white);
        imagesetthickness($canvas, 3);
        imagerectangle($canvas, $cardInset, $cardTop, $W - $cardInset, $cardBot, $gold);
        imagesetthickness($canvas, 1);

        // ----- Draw business name lines (top-down) -----
        $cursorY = $startY;
        foreach ($bizLines as $line) {
            self::drawCenteredTtfLine(
                $canvas, $peacock, $bizFontPt, $cursorY,
                $line, $W, $sidePadding, true, true /* extraBold */
            );
            $cursorY += $bizLineH;
        }

        // small underline accent under business name
        $underlineY = $cursorY + 8;
        $underlineW = (int)min(360, $W * 0.28);
        $ux1 = (int)(($W - $underlineW) / 2);
        $ux2 = $ux1 + $underlineW;
        imagesetthickness($canvas, 6);
        imageline($canvas, $ux1, $underlineY, $ux2, $underlineY, $deepYellow);
        imagesetthickness($canvas, 1);

        $cursorY += $gapAfterBiz;

        // ----- Draw "SCAN HERE FOR" -----
        self::drawCenteredTtfLine(
            $canvas, $dark, $scanTitleSize, $cursorY,
            'SCAN HERE FOR', $W, $sidePadding, true
        );
        $cursorY += $scanTitleH + $gapWithinScan;

        // ----- Draw "5-STAR REVIEW" (accent gold colour) -----
        self::drawCenteredTtfLine(
            $canvas, $peacock, $scanMainSize, $cursorY,
            '5-STAR REVIEW', $W, $sidePadding, true, true
        );
        $cursorY += $scanMainH + $gapAfterScan;

        // ----- QR CARD (centered horizontally, framed) -----
        $qr = imagecreatefrompng($qrImagePath);
        if ($qr === false) {
            imagedestroy($canvas);
            throw new RuntimeException('Failed to read QR image.');
        }

        $qrX = (int)(($W - $qrSize) / 2);
        $qrY = $cursorY + $qrFramePad;
        $frameLeft   = $qrX - $qrFramePad;
        $frameTop    = $qrY - $qrFramePad;
        $frameRight  = $qrX + $qrSize + $qrFramePad;
        $frameBottom = $qrY + $qrSize + $qrFramePad;

        // White card with gold border behind QR.
        imagefilledrectangle($canvas, $frameLeft + 6, $frameTop + 6, $frameRight + 6, $frameBottom + 6, $softShadow);
        imagefilledrectangle($canvas, $frameLeft, $frameTop, $frameRight, $frameBottom, $white);
        imagesetthickness($canvas, 6);
        imagerectangle($canvas, $frameLeft, $frameTop, $frameRight, $frameBottom, $gold);
        imagesetthickness($canvas, 1);

        imagecopyresampled($canvas, $qr, $qrX, $qrY, 0, 0, $qrSize, $qrSize, imagesx($qr), imagesy($qr));
        imagedestroy($qr);

        // ---------- FOOTER BAND ----------
        $footerInnerTop = $H - $footerHeight + 50;
        self::drawCenteredTtfLine(
            $canvas, $white, 38, $footerInnerTop,
            'Thank you for visiting!', $W, $sidePadding, true
        );
        $thankH = self::measureTtfLineHeight(38);

        self::drawCenteredTtfLine(
            $canvas, $brightGold, 26, $footerInnerTop + $thankH + 24,
            'Powered by ' . $safeSystem, $W, $sidePadding, true
        );

        imagepng($canvas, $outputFile, 2);
        imagedestroy($canvas);
    }

    /**
     * Compose a standee by pasting the client's QR onto an admin-uploaded
     * background template. NO text, NO overlays, NO frames — the template
     * is treated as the finished design.
     *
     * The QR rectangle (`$qrX`, `$qrY`, `$qrW`, `$qrH`) is in the template's
     * NATIVE pixel coordinates, exactly as configured by the admin in the
     * drag-and-drop editor. Any (W,H) is supported (square or otherwise),
     * since `imagecopyresampled()` resizes the QR to fit precisely.
     *
     * If the rectangle is invalid (zero/negative width or height) the QR
     * falls back to a centred square sized at 40% of the template's
     * shorter edge, so the output is never broken.
     *
     * @param string $templateAbsolutePath Absolute filesystem path to PNG/JPG/WebP template
     * @param string $qrImagePath          Absolute path to client's QR PNG
     * @param string $outputFile           Absolute filesystem path for composed PNG
     * @param int    $qrX                  QR top-left X (native px)
     * @param int    $qrY                  QR top-left Y (native px)
     * @param int    $qrW                  QR width  (native px)
     * @param int    $qrH                  QR height (native px)
     * @param array<string,mixed>|null $businessNameOverlay Optional keys:
     *        enabled (bool), text (string), x, y, box_w, box_h, font_pt, color_hex (#RRGGBB)
     */
    public static function generateStandeeFromTemplate(
        string $templateAbsolutePath,
        string $qrImagePath,
        string $outputFile,
        int $qrX,
        int $qrY,
        int $qrW,
        int $qrH,
        ?array $businessNameOverlay = null
    ): void {
        $dir = dirname($outputFile);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Standee output directory could not be created.');
        }
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('GD extension is required for standee generation.');
        }
        if (!is_file($templateAbsolutePath)) {
            throw new RuntimeException('Template image not found.');
        }
        if (!is_file($qrImagePath)) {
            throw new RuntimeException('QR image not found for standee generation.');
        }

        $bg = self::loadRasterImage($templateAbsolutePath);
        if ($bg === false) {
            throw new RuntimeException('Could not load template image.');
        }
        $bg = self::ensureTrueColor($bg);
        $W = imagesx($bg);
        $H = imagesy($bg);
        if ($W < 50 || $H < 50) {
            imagedestroy($bg);
            throw new RuntimeException('Template image is too small.');
        }

        imagealphablending($bg, true);
        imagesavealpha($bg, true);

        // Sensible safety fallback when the admin hasn't configured a box yet.
        if ($qrW < 20 || $qrH < 20) {
            $fallback = max(80, (int)round(min($W, $H) * 0.40));
            $qrW = $qrH = $fallback;
            $qrX = (int)round(($W - $qrW) / 2);
            $qrY = (int)round(($H - $qrH) / 2);
        }

        // Clamp inside canvas just in case the box was saved at slightly off
        // dimensions or the template was re-uploaded at a smaller size.
        $qrW = min($qrW, $W);
        $qrH = min($qrH, $H);
        $qrX = max(0, min($qrX, $W - $qrW));
        $qrY = max(0, min($qrY, $H - $qrH));

        $qrSrc = imagecreatefrompng($qrImagePath);
        if ($qrSrc === false) {
            imagedestroy($bg);
            throw new RuntimeException('Failed to read QR image.');
        }
        imagealphablending($qrSrc, true);
        imagesavealpha($qrSrc, true);

        // High-quality resample — keeps the QR crisp at any target size.
        // No frames, no padding, no text.
        imagecopyresampled(
            $bg, $qrSrc,
            $qrX, $qrY, 0, 0,
            $qrW, $qrH,
            imagesx($qrSrc), imagesy($qrSrc)
        );
        imagedestroy($qrSrc);

        if (
            $businessNameOverlay !== null
            && !empty($businessNameOverlay['enabled'])
            && trim((string)($businessNameOverlay['text'] ?? '')) !== ''
        ) {
            self::drawBusinessNameOverlayOnCanvas(
                $bg,
                trim((string)$businessNameOverlay['text']),
                (int)($businessNameOverlay['x'] ?? 0),
                (int)($businessNameOverlay['y'] ?? 0),
                max(40, (int)($businessNameOverlay['box_w'] ?? 400)),
                max(30, (int)($businessNameOverlay['box_h'] ?? 120)),
                max(10, (int)($businessNameOverlay['font_pt'] ?? 36)),
                (string)($businessNameOverlay['color_hex'] ?? '#0f172a')
            );
        }

        // PNG compression level 0 = maximum fidelity (no zlib penalty).
        imagepng($bg, $outputFile, 0);
        imagedestroy($bg);
    }

    /**
     * Single-line business name: auto-scale (font_pt = max size) to fit inside the box,
     * then center horizontally and vertically using imagettfbbox() metrics.
     *
     * @param resource|GdImage $bg
     */
    private static function drawBusinessNameOverlayOnCanvas(
        $bg,
        string $text,
        int $boxX,
        int $boxY,
        int $boxW,
        int $boxH,
        int $startFontPt,
        string $colorHex
    ): void {
        $text = trim($text);
        if ($text === '' || !function_exists('imagettftext') || !function_exists('imagettfbbox')) {
            return;
        }

        $canvasW = imagesx($bg);
        $canvasH = imagesy($bg);
        $boxX = max(0, min($boxX, max(0, $canvasW - 1)));
        $boxY = max(0, min($boxY, max(0, $canvasH - 1)));
        $boxW = max(20, min($boxW, $canvasW - $boxX));
        $boxH = max(20, min($boxH, $canvasH - $boxY));

        $rgb = self::hexStringToRgb($colorHex) ?? [15, 95, 143];
        $ink = imagecolorallocate($bg, $rgb[0], $rgb[1], $rgb[2]);
        if ($ink === false) {
            return;
        }

        $fontFile = self::findTtfFont(true);
        if ($fontFile === '') {
            return;
        }

        $maxPt = max(1, min(200, $startFontPt));
        // Upper bound: admin max, and nothing larger than can ever fit vertically.
        $hi = min($maxPt, max(1, (int)floor($boxH * 0.98)));

        $chosen = self::fitSingleLineTtfSize($text, $fontFile, $hi, $boxW, $boxH);
        $chosen = max(1, $chosen);

        $bbox = imagettfbbox($chosen, 0, $fontFile, $text);
        if ($bbox === false) {
            return;
        }

        $minX = (int)min($bbox[0], $bbox[2], $bbox[4], $bbox[6]);
        $maxX = (int)max($bbox[0], $bbox[2], $bbox[4], $bbox[6]);
        $minY = (int)min($bbox[1], $bbox[3], $bbox[5], $bbox[7]);
        $maxY = (int)max($bbox[1], $bbox[3], $bbox[5], $bbox[7]);
        $textW = max(1, $maxX - $minX);
        $textH = max(1, $maxY - $minY);

        // Top-left of tight glyph box inside the green rectangle (visual centering).
        $left = (int)round($boxX + ($boxW - $textW) / 2);
        $top = (int)round($boxY + ($boxH - $textH) / 2);

        // imagettftext() uses (x,y) as reference for the first character's baseline;
        // bbox corners are relative to that reference point.
        $px = $left - $minX;
        $py = $top - $minY;

        imagettftext($bg, $chosen, 0, $px, $py, $ink, $fontFile, $text);
    }

    /**
     * Largest font size in [1, $maxPt] whose single-line tight bbox fits in $boxW × $boxH.
     */
    private static function fitSingleLineTtfSize(string $text, string $fontFile, int $maxPt, int $boxW, int $boxH): int
    {
        $maxPt = max(1, min(200, $maxPt));
        $lo = 1;
        $hi = $maxPt;
        $best = 0;

        while ($lo <= $hi) {
            $mid = (int)(($lo + $hi) / 2);
            $m = self::ttfTightBBox($fontFile, $text, $mid);
            if ($m === null) {
                $hi = $mid - 1;
                continue;
            }
            if ($m['w'] <= $boxW && $m['h'] <= $boxH) {
                $best = $mid;
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return $best > 0 ? $best : 1;
    }

    /**
     * @return array{w:int,h:int,minX:int,minY:int,maxX:int,maxY:int}|null
     */
    private static function ttfTightBBox(string $fontFile, string $text, int $size): ?array
    {
        $bbox = imagettfbbox($size, 0, $fontFile, $text);
        if ($bbox === false) {
            return null;
        }
        $minX = (int)min($bbox[0], $bbox[2], $bbox[4], $bbox[6]);
        $maxX = (int)max($bbox[0], $bbox[2], $bbox[4], $bbox[6]);
        $minY = (int)min($bbox[1], $bbox[3], $bbox[5], $bbox[7]);
        $maxY = (int)max($bbox[1], $bbox[3], $bbox[5], $bbox[7]);
        $w = $maxX - $minX;
        $h = $maxY - $minY;
        if ($w <= 0 || $h <= 0) {
            return null;
        }

        return ['w' => $w, 'h' => $h, 'minX' => $minX, 'minY' => $minY, 'maxX' => $maxX, 'maxY' => $maxY];
    }

    /**
     * @return array{0:int,1:int,2:int}|null
     */
    private static function hexStringToRgb(string $hex): ?array
    {
        $hex = trim($hex);
        if ($hex === '') {
            return null;
        }
        if ($hex[0] === '#') {
            $hex = substr($hex, 1);
        }
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return null;
        }
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * @return resource|GdImage|false
     */
    private static function loadRasterImage(string $absolutePath)
    {
        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        return match ($ext) {
            'png' => imagecreatefrompng($absolutePath),
            'jpg', 'jpeg' => imagecreatefromjpeg($absolutePath),
            'gif' => imagecreatefromgif($absolutePath),
            'webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($absolutePath) : false,
            default => false,
        };
    }

    /**
     * @param resource|GdImage $im
     * @return resource|GdImage
     */
    private static function ensureTrueColor($im)
    {
        if (imageistruecolor($im)) {
            return $im;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        $tc = imagecreatetruecolor($w, $h);
        if ($tc === false) {
            return $im;
        }
        imagealphablending($tc, false);
        imagesavealpha($tc, true);
        $transparent = imagecolorallocatealpha($tc, 0, 0, 0, 127);
        imagefilledrectangle($tc, 0, 0, $w, $h, $transparent);
        imagealphablending($tc, true);
        imagecopy($tc, $im, 0, 0, 0, 0, $w, $h);
        imagedestroy($im);
        return $tc;
    }

    // =====================================================================
    //  TEXT-RENDERING HELPERS (TTF first, bitmap fallback)
    // =====================================================================

    /**
     * Render a single horizontally-centered text line.
     * Uses TrueType when a font is available; degrades to scaled bitmap
     * otherwise. Auto-shrinks the font if the text is too wide.
     *
     * @param int  $y            Top Y of the line block.
     * @param int  $canvasWidth  Total canvas width.
     * @param int  $sidePadding  Horizontal safe-area padding.
     */
    private static function drawCenteredTtfLine(
        $image,
        int $color,
        int $fontPt,
        int $y,
        string $text,
        int $canvasWidth,
        int $sidePadding,
        bool $bold = false,
        bool $extraBold = false
    ): void {
        $maxTextWidth = $canvasWidth - 2 * $sidePadding;
        $fontFile = self::findTtfFont($bold);

        if ($fontFile !== '' && function_exists('imagettftext')) {
            $size = $fontPt;
            for ($i = 0; $i < 12; $i++) {
                $bbox = imagettfbbox($size, 0, $fontFile, $text);
                if ($bbox === false) {
                    break;
                }
                $textW = abs($bbox[2] - $bbox[0]);
                $textH = abs($bbox[7] - $bbox[1]);
                if ($textW <= $maxTextWidth) {
                    $x = (int)(($canvasWidth - $textW) / 2);
                    // baseline = top + (block + text) / 2 in a single-line setup,
                    // we treat $y as TOP of an em-box of height ~1.25 * size.
                    $emBox    = (int)round($size * 1.25);
                    $baseline = $y + (int)(($emBox + $textH) / 2);

                    imagettftext($image, $size, 0, $x, $baseline, $color, $fontFile, $text);
                    if ($bold) {
                        imagettftext($image, $size, 0, $x + 1, $baseline,     $color, $fontFile, $text);
                        imagettftext($image, $size, 0, $x,     $baseline + 1, $color, $fontFile, $text);
                    }
                    if ($extraBold) {
                        imagettftext($image, $size, 0, $x + 2, $baseline,     $color, $fontFile, $text);
                        imagettftext($image, $size, 0, $x,     $baseline + 2, $color, $fontFile, $text);
                        imagettftext($image, $size, 0, $x + 2, $baseline + 1, $color, $fontFile, $text);
                    }
                    return;
                }
                $size = max(10, (int)floor($size * 0.92));
            }
        }

        self::drawScaledBitmapTextCentered($image, $color, $fontPt, $y, $text);
    }

    /** Fixed-height estimate for a TTF size (em-box ~ 1.25 * pt). */
    private static function measureTtfLineHeight(int $fontPt): int
    {
        return (int)round($fontPt * 1.25);
    }

    /**
     * Word-wrap `$text` into at most `$maxLines` lines fitting `$maxWidth`,
     * automatically shrinking the font size from `$startSize` down to
     * `$minSize` until it fits. Returns ['size' => int, 'lines' => string[]].
     *
     * Falls back gracefully when no TTF font is available (returns the whole
     * string as a single line so the bitmap renderer can scale it).
     *
     * @return array{size:int,lines:array<int,string>}
     */
    private static function fitWrappedTtfText(string $text, int $startSize, int $minSize, int $maxWidth, int $maxLines): array
    {
        $fontFile = self::findTtfFont(true);
        $text = trim($text);
        if ($text === '') {
            return ['size' => $startSize, 'lines' => []];
        }
        if ($fontFile === '' || !function_exists('imagettfbbox')) {
            return ['size' => $startSize, 'lines' => [$text]];
        }

        for ($size = $startSize; $size >= $minSize; $size -= 4) {
            $lines = self::wrapTtfText($text, $fontFile, $size, $maxWidth);
            if (count($lines) <= $maxLines && self::allLinesFit($lines, $fontFile, $size, $maxWidth)) {
                return ['size' => $size, 'lines' => $lines];
            }
        }

        // Couldn't fit gracefully. Hard wrap at min size.
        $lines = self::wrapTtfText($text, $fontFile, $minSize, $maxWidth);
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
        }
        return ['size' => $minSize, 'lines' => $lines];
    }

    /**
     * Greedy word-wrap a string into lines bounded by pixel width.
     *
     * @return array<int,string>
     */
    private static function wrapTtfText(string $text, string $fontFile, int $size, int $maxWidth): array
    {
        $words = preg_split('/\s+/', $text) ?: [];
        $words = array_values(array_filter($words, static fn(string $w): bool => $w !== ''));
        if (empty($words)) {
            return [];
        }

        $lines  = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : ($current . ' ' . $word);
            $bbox = imagettfbbox($size, 0, $fontFile, $candidate);
            $width = $bbox === false ? PHP_INT_MAX : abs($bbox[2] - $bbox[0]);

            if ($width <= $maxWidth) {
                $current = $candidate;
                continue;
            }

            if ($current === '') {
                // Single word longer than the box — keep it; the outer loop
                // will shrink the font size and retry the whole wrap.
                $lines[] = $word;
                $current = '';
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        return $lines;
    }

    /**
     * @param array<int,string> $lines
     */
    private static function allLinesFit(array $lines, string $fontFile, int $size, int $maxWidth): bool
    {
        foreach ($lines as $line) {
            $bbox = imagettfbbox($size, 0, $fontFile, $line);
            if ($bbox === false) {
                return false;
            }
            if (abs($bbox[2] - $bbox[0]) > $maxWidth) {
                return false;
            }
        }
        return true;
    }

    private static function drawScaledBitmapTextCentered($image, int $color, int $fontPt, int $y, string $text): void
    {
        // Approximate the requested pt size as cap-height in pixels.
        $targetPx = (int)round($fontPt * 1.4);
        self::drawScaledBitmapText($image, $color, $targetPx, $y, $targetPx, $text);
    }

    /**
     * Bitmap text fallback that scales GD's built-in font 5 up to the
     * requested pixel height. Result is blocky but still readable.
     *
     * @param resource|GdImage $image
     */
    private static function drawScaledBitmapText($image, int $color, int $fontPx, int $y, int $blockHeight, string $text): void
    {
        $font = 5;
        $charW = imagefontwidth($font);   // 9
        $charH = imagefontheight($font);  // 15
        $textPx = $charW * strlen($text);
        if ($textPx <= 0) {
            return;
        }

        $scaleY = max(1.0, $fontPx / $charH);
        $scaleX = $scaleY; // keep aspect

        $imageWidth = imagesx($image);
        $maxWidth = $imageWidth - 120;
        $scaledWidth = (int)round($textPx * $scaleX);
        if ($scaledWidth > $maxWidth) {
            $scaleX = $maxWidth / $textPx;
            $scaleY = $scaleX;
            $scaledWidth = $maxWidth;
        }
        $scaledHeight = (int)round($charH * $scaleY);

        $tmp = imagecreatetruecolor(max(1, $textPx), max(1, $charH));
        if ($tmp === false) {
            return;
        }
        $bg = imagecolorallocate($tmp, 0, 0, 0);
        imagecolortransparent($tmp, $bg);
        imagefilledrectangle($tmp, 0, 0, $textPx, $charH, $bg);
        $tmpColor = imagecolorallocate($tmp, ($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF);
        imagestring($tmp, $font, 0, 0, $text, $tmpColor);

        $x = (int)(($imageWidth - $scaledWidth) / 2);
        $yy = $y + (int)(($blockHeight - $scaledHeight) / 2);
        imagecopyresampled($image, $tmp, $x, $yy, 0, 0, $scaledWidth, $scaledHeight, $textPx, $charH);
        imagedestroy($tmp);
    }

    private static function findTtfFont(bool $preferBold): string
    {
        static $cache = [];
        $cacheKey = $preferBold ? 'bold' : 'regular';
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $candidates = $preferBold
            ? [
                __DIR__ . '/../../public/lib/fonts/DejaVuSans-Bold.ttf',
                __DIR__ . '/../../public/lib/fonts/Roboto-Bold.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
                '/usr/share/fonts/liberation/LiberationSans-Bold.ttf',
                '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
                'C:/Windows/Fonts/arialbd.ttf',
                'C:/Windows/Fonts/calibrib.ttf',
                'C:/Windows/Fonts/segoeuib.ttf',
                'C:/Windows/Fonts/Arial.ttf',
            ]
            : [
                __DIR__ . '/../../public/lib/fonts/DejaVuSans.ttf',
                __DIR__ . '/../../public/lib/fonts/Roboto-Regular.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
                '/usr/share/fonts/liberation/LiberationSans-Regular.ttf',
                '/usr/share/fonts/TTF/DejaVuSans.ttf',
                'C:/Windows/Fonts/Arial.ttf',
                'C:/Windows/Fonts/calibri.ttf',
                'C:/Windows/Fonts/segoeui.ttf',
            ];

        foreach ($candidates as $path) {
            if ($path !== '' && is_file($path) && is_readable($path)) {
                $cache[$cacheKey] = $path;
                return $path;
            }
        }

        $cache[$cacheKey] = '';
        return '';
    }

    private static function generateWithLocalPhpQrCode(string $text, string $outputFile): bool
    {
        $qrlibPath = __DIR__ . '/../../public/lib/phpqrcode/qrlib.php';
        if (!is_file($qrlibPath)) {
            return false;
        }

        require_once $qrlibPath;
        if (!class_exists('QRcode')) {
            return false;
        }

        try {
            QRcode::png($text, $outputFile, 'M', 8, 2);
            return is_file($outputFile) && filesize($outputFile) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private static function generateWithQrServerApi(string $text, string $outputFile): bool
    {
        $url = 'https://api.qrserver.com/v1/create-qr-code/?size=600x600&format=png&data=' . rawurlencode($text);

        $content = self::fetchBinary($url);
        if ($content === null || $content === '') {
            return false;
        }

        $written = @file_put_contents($outputFile, $content);
        return $written !== false && $written > 0 && is_file($outputFile);
    }

    private static function fetchBinary(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            $body = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!is_string($body) || $httpCode < 200 || $httpCode >= 300) {
                return null;
            }

            return $body;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 20,
            ],
        ]);

        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) ? $body : null;
    }
}
