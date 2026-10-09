<?php
// Локальный запуск без Apache: php -S 127.0.0.1:8080 -t public dev/router.php
// Повторяет правила public/.htaccess и заголовки безопасности.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pub = realpath(__DIR__ . '/../public');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
$map = ['/robots.txt' => '/robots.php', '/sitemap.xml' => '/sitemap.php', '/politika' => '/policy.php', '/politika/' => '/policy.php', '/' => '/index.php'];
if (preg_match('~(^|/)\.|^/_boot\.php$~', $path)) { http_response_code(403); exit; }
if (isset($map[$path])) { $path = $map[$path]; }
$file = $pub . $path;
if (is_file($file)) {
    if (str_ends_with($file, '.php')) { chdir(dirname($file)); require $file; return true; }
    if (preg_match('/\.(woff2|webp|jpg|png|ico)$/', $file)) header('Cache-Control: public, max-age=2592000, immutable');
    if (preg_match('/\.(css|js)$/', $file)) header('Cache-Control: public, max-age=604800');
    return false;
}
http_response_code(404);
readfile($pub . '/404.html');
return true;
