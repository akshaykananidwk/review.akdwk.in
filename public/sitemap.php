<?php
declare(strict_types=1);

/**
 * XML sitemap for search engines.
 * Referenced from robots.txt (Sitemap: .../sitemap.php) and can also be
 * submitted directly in Google Search Console. Only public marketing
 * pages are listed — customer/admin pages are intentionally excluded.
 */

require_once __DIR__ . '/../app/config/config.php';

header('Content-Type: application/xml; charset=utf-8');

$pages = [
    ['loc' => APP_URL . '/', 'priority' => '1.0', 'changefreq' => 'daily'],
    ['loc' => APP_URL . '/register.php', 'priority' => '0.9', 'changefreq' => 'weekly'],
    ['loc' => APP_URL . '/demo.php', 'priority' => '0.7', 'changefreq' => 'monthly'],
    ['loc' => APP_URL . '/login.php', 'priority' => '0.3', 'changefreq' => 'monthly'],
];

$lastMod = date('Y-m-d');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($pages as $page) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($page['loc'], ENT_XML1) . "</loc>\n";
    echo '    <lastmod>' . $lastMod . "</lastmod>\n";
    echo '    <changefreq>' . $page['changefreq'] . "</changefreq>\n";
    echo '    <priority>' . $page['priority'] . "</priority>\n";
    echo "  </url>\n";
}
echo '</urlset>' . "\n";
