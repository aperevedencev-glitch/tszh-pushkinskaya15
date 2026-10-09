<?php
declare(strict_types=1);

/** Настройки и общие сервисы приложения. */
final class App
{
    private static array $config = [];
    private static ?Db $db = null;
    private static ?DateTimeImmutable $fixedNow = null;

    public static function init(array $config): void
    {
        self::$config = $config;
        date_default_timezone_set($config['timezone'] ?? 'Europe/Moscow');
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        $logs = APP_PRIVATE . '/logs';
        if (is_dir($logs) && is_writable($logs)) {
            ini_set('error_log', $logs . '/php-error.log');
        }
    }

    /** Значение настройки по пути через точку: App::cfg('telegram.bot_username'). */
    public static function cfg(string $path, mixed $default = null): mixed
    {
        $v = self::$config;
        foreach (explode('.', $path) as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) {
                return $default;
            }
            $v = $v[$k];
        }
        return $v;
    }

    public static function configured(): bool
    {
        return (bool)(self::$config['_configured'] ?? false);
    }

    public static function db(): Db
    {
        return self::$db ??= Db::connect(self::$config['db'] ?? []);
    }

    /** Для тестов: подменить базу. */
    public static function setDb(?Db $db): void
    {
        self::$db = $db;
    }

    public static function secret(): string
    {
        $s = (string)self::cfg('app_secret', '');
        if (strlen($s) < 32) {
            throw new RuntimeException('app_secret не задан в private/config.php (нужно 32+ символа)');
        }
        return $s;
    }

    public static function now(): DateTimeImmutable
    {
        return self::$fixedNow ?? new DateTimeImmutable('now');
    }

    /** Для тестов: зафиксировать «сейчас». null — вернуть реальное время. */
    public static function setNow(?DateTimeImmutable $now): void
    {
        self::$fixedNow = $now;
    }

    public static function siteUrl(): string
    {
        return rtrim((string)self::cfg('site_url', ''), '/');
    }

    public static function botUsername(): string
    {
        return ltrim((string)self::cfg('telegram.bot_username', ''), '@');
    }
}
