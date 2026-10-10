<?php
declare(strict_types=1);

/** Журнал событий без персональных данных: только идентификаторы и коды. */
final class Log
{
    public static function event(string $event, array $ctx = []): void
    {
        self::write('info', $event, $ctx);
    }

    public static function error(string $event, array $ctx = []): void
    {
        self::write('error', $event, $ctx);
        try {
            if (class_exists('Settings')) {
                Settings::set('last_error', ['at' => Db::ts(), 'event' => $event, 'ctx' => self::clean($ctx)]);
            }
        } catch (Throwable) {
            // база недоступна — запись в файл уже сделана
        }
    }

    private static function write(string $level, string $event, array $ctx): void
    {
        $dir = APP_PRIVATE . '/logs';
        if (!is_dir($dir) || !is_writable($dir)) {
            error_log("[$level] $event " . json_encode(self::clean($ctx), JSON_UNESCAPED_UNICODE));
            return;
        }
        $line = json_encode(['t' => date('c'), 'level' => $level, 'event' => $event] + self::clean($ctx), JSON_UNESCAPED_UNICODE);
        @file_put_contents($dir . '/app-' . date('Y-m') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /** Убирает из текста секреты из config.php и всё, похожее на токен бота. */
    public static function scrubSecrets(string $s): string
    {
        foreach ([App::cfg('telegram.bot_token'), App::cfg('telegram.webhook_secret'), App::cfg('app_secret'), App::cfg('db.pass'), App::cfg('install_key')] as $secret) {
            if (is_string($secret) && strlen($secret) >= 4) {
                $s = str_replace($secret, '***', $s);
            }
        }
        return (string)preg_replace('/\d{6,}:[A-Za-z0-9_-]{30,}/', '***', $s);
    }

    /** Вырезает всё, что похоже на телефон, почту или имя. */
    private static function clean(array $ctx): array
    {
        $deny = ['name', 'phone', 'email', 'text', 'comment', 'username', 'telegram_username', 'ip'];
        $out = [];
        foreach ($ctx as $k => $v) {
            if (in_array($k, $deny, true)) {
                continue;
            }
            if (is_string($v)) {
                $v = self::scrubSecrets($v);
                $v = preg_replace('/\+?\d[\d\s\-()]{8,}\d/', '[phone]', $v);
                $v = preg_replace('/[\w.+-]+@[\w-]+\.[\w.]+/', '[email]', $v);
                $v = mb_substr($v, 0, 300);
            }
            $out[$k] = $v;
        }
        return $out;
    }
}
