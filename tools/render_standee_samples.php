<?php
declare(strict_types=1);

/**
 * One-shot CLI helper that renders a few standee samples (short,
 * medium, very long business names) so the design can be verified
 * visually without going through the full DB/registration flow.
 *
 * Usage: php tools/render_standee_samples.php
 */

require_once __DIR__ . '/../app/services/QrService.php';

$outputDir = __DIR__ . '/standee_samples';
if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Cannot create output dir: {$outputDir}\n");
    exit(1);
}

$qrPath = $outputDir . '/_sample_qr.png';
try {
    QrService::generate('https://review.akdwk.in/review.php?t=demo_token', $qrPath);
    echo "QR generated via library: {$qrPath}\n";
} catch (Throwable $e) {
    // Sandbox / offline fallback: paint a fake QR-style checkerboard so we
    // can still inspect the standee layout. Real deployments use the proper QR.
    fwrite(STDERR, "Real QR unavailable ({$e->getMessage()}); using placeholder.\n");
    $size = 600;
    $img = imagecreatetruecolor($size, $size);
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    imagefilledrectangle($img, 0, 0, $size, $size, $white);
    $cell = 20;
    for ($y = 0; $y < $size; $y += $cell) {
        for ($x = 0; $x < $size; $x += $cell) {
            // pseudo-random pattern from coords
            if ((($x * 31 + $y * 17 + 11) % 7) < 3) {
                imagefilledrectangle($img, $x, $y, $x + $cell - 1, $y + $cell - 1, $black);
            }
        }
    }
    // Three "finder" squares like a real QR has.
    foreach ([[0,0],[$size-140,0],[0,$size-140]] as [$fx, $fy]) {
        imagefilledrectangle($img, $fx,        $fy,        $fx+140, $fy+140, $black);
        imagefilledrectangle($img, $fx+20,     $fy+20,     $fx+120, $fy+120, $white);
        imagefilledrectangle($img, $fx+40,     $fy+40,     $fx+100, $fy+100, $black);
    }
    imagepng($img, $qrPath);
    imagedestroy($img);
    echo "Placeholder QR written: {$qrPath}\n";
}

$systemName = 'Krishna Review System';

$cases = [
    'short'   => 'Cafe Mocha',
    'medium'  => 'Sharma Sweets & Snacks',
    'long'    => 'Raghuvanshi Mobile Zone (The Branch Of Rasik Pan)',
    'longer'  => 'Dr. Ananya Computer & Electronic Repair Hospital — Sector 22 Branch',
];

foreach ($cases as $key => $businessName) {
    $out = $outputDir . "/standee_{$key}.png";
    QrService::generateStandee($businessName, $qrPath, $out, $systemName);
    echo "Generated [{$key}]: {$out}  (" . filesize($out) . " bytes)\n";
}

echo "\nDone. Open the .png files in {$outputDir} to inspect.\n";
