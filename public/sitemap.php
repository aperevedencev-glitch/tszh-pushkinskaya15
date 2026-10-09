<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';
header('Content-Type: application/xml; charset=utf-8');
$site = htmlspecialchars(App::siteUrl(), ENT_XML1);
$tpl = APP_PRIVATE . '/templates/';
$pages = ['/' => 'landing.html', '/politika/' => 'policy.html'];
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n", '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
foreach ($pages as $path => $file) {
    printf("  <url><loc>%s%s</loc><lastmod>%s</lastmod></url>\n", $site, $path, date('Y-m-d', filemtime($tpl . $file)));
}
echo "</urlset>\n";
