<?php
declare(strict_types=1);

/** Пароли сотрудников: генерация и проверка сложности. */
final class Passwords
{
    /** Временный пароль: 14 символов без похожих букв (0/O, 1/l). */
    public static function generate(): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $p = '';
        for ($i = 0; $i < 14; $i++) {
            $p .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $p;
    }

    /** null — пароль подходит, иначе — что не так. */
    public static function weak(string $p, string $login = ''): ?string
    {
        if (mb_strlen($p) < 10) {
            return 'Пароль — не короче 10 символов.';
        }
        if ($login !== '' && mb_stripos($p, $login) !== false) {
            return 'Пароль не должен содержать логин.';
        }
        if (preg_match('/^(.)\1+$/', $p) || in_array(mb_strtolower($p), ['1234567890', 'qwertyuiop', 'password12', 'пушкинская15'], true)) {
            return 'Слишком простой пароль.';
        }
        return null;
    }
}
