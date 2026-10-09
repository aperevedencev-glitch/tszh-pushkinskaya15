<?php
declare(strict_types=1);
/**
 * Вебхук бота из консоли (SSH на Reg.ru).
 *   php webhook.php info                     — что сейчас знает Telegram (getWebhookInfo)
 *   php webhook.php set                      — подключить на site_url/bot/webhook.php; спросит подтверждение
 *   php webhook.php delete                   — отключить вебхук; спросит подтверждение
 * Для скриптов без вопросов: --confirm=ДОМЕН (например --confirm=pushkinskaya15.ru).
 * Токен и секрет берутся из config.php и на экран не выводятся.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/../bootstrap.php';

$cmd = $argv[1] ?? 'info';
$host = (string)parse_url(App::siteUrl(), PHP_URL_HOST);
$confirmed = static function (string $what) use ($argv, $host): bool {
    foreach ($argv as $a) {
        if (str_starts_with($a, '--confirm=')) {
            return substr($a, 10) === $host;
        }
    }
    echo "$what\nДля подтверждения введите домен ($host): ";
    return trim((string)fgets(STDIN)) === $host;
};
$print = static function (array $info): void {
    [$status, $text] = Webhook::assess($info);
    echo strtoupper($status), ': ', $text, PHP_EOL;
    foreach (['url', 'pending_update_count', 'max_connections', 'ip_address', 'last_error_date', 'last_error_message', 'allowed_updates'] as $k) {
        if (isset($info[$k])) {
            $v = $k === 'last_error_date' ? date('d.m.Y H:i:s', (int)$info[$k]) : (is_array($info[$k]) ? implode(', ', $info[$k]) : $info[$k]);
            echo "  $k: $v", PHP_EOL;
        }
    }
};

try {
    switch ($cmd) {
        case 'info':
            $print(Webhook::info());
            exit(0);
        case 'set':
            if ($p = Webhook::problems()) {
                fwrite(STDERR, implode(PHP_EOL, $p) . PHP_EOL);
                exit(1);
            }
            if (!$confirmed('Telegram начнёт присылать сообщения бота на ' . Webhook::url())) {
                echo "Отменено.\n";
                exit(1);
            }
            $print(Webhook::set());
            exit(0);
        case 'delete':
            if (!$confirmed('Бот перестанет получать сообщения жильцов.')) {
                echo "Отменено.\n";
                exit(1);
            }
            Webhook::delete();
            echo "Вебхук отключён.\n";
            exit(0);
        default:
            fwrite(STDERR, "Команды: info | set | delete\n");
            exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . Health::scrub($e->getMessage()) . PHP_EOL);
    exit(1);
}
