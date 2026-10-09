<?php
declare(strict_types=1);

/** Ключ-значение в таблице settings: праздники, очередь распределения, дежурный. */
final class Settings
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $v = App::db()->value('SELECT value FROM settings WHERE skey = ?', [$key]);
        if ($v === null) {
            return $default;
        }
        $decoded = json_decode((string)$v, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $v;
    }

    public static function set(string $key, mixed $value): void
    {
        $db = App::db();
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        if ($db->value('SELECT 1 FROM settings WHERE skey = ?', [$key])) {
            $db->run('UPDATE settings SET value = ? WHERE skey = ?', [$json, $key]);
        } else {
            $db->run('INSERT INTO settings (skey, value) VALUES (?, ?)', [$key, $json]);
        }
    }
}
