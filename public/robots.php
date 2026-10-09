<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';
header('Content-Type: text/plain; charset=utf-8');
echo "User-agent: *\nDisallow: /api/\nDisallow: /bot/\nDisallow: /crm/\n\nSitemap: " . App::siteUrl() . "/sitemap.xml\n";
