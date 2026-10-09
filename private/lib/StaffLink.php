<?php
declare(strict_types=1);

/** Привязка Telegram сотрудника к учётной записи CRM: код из профиля → /link КОД в боте. */
final class StaffLink
{
    public static function create(int $userId): string
    {
        $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        Settings::set('staff_link:' . $code, ['user_id' => $userId, 'exp' => App::now()->modify('+15 minutes')->getTimestamp()]);
        return $code;
    }

    /** Возвращает имя сотрудника или null, если код неверный или истёк. */
    public static function redeem(string $code, int $tgId): ?string
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        if ($code === '') {
            return null;
        }
        $key = 'staff_link:' . $code;
        $v = Settings::get($key);
        App::db()->run('DELETE FROM settings WHERE skey = ?', [$key]);   // код одноразовый
        if (!is_array($v) || ($v['exp'] ?? 0) < App::now()->getTimestamp()) {
            return null;
        }
        $db = App::db();
        $db->run('UPDATE users SET telegram_id = NULL WHERE telegram_id = ?', [$tgId]);
        $db->update('users', ['telegram_id' => $tgId], 'id = ?', [(int)$v['user_id']]);
        Audit::log((int)$v['user_id'], 'user', (int)$v['user_id'], 'telegram_linked');
        return (string)$db->value('SELECT name FROM users WHERE id = ?', [(int)$v['user_id']]);
    }
}
