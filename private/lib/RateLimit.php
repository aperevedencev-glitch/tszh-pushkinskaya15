<?php
declare(strict_types=1);

/** Ограничение частоты: окно фиксированной длины, ключ — хеш (без IP и телефонов в базе). */
final class RateLimit
{
    /** true — можно; false — лимит исчерпан. */
    public static function hit(string $bucket, string $subject, int $max, int $windowSec): bool
    {
        $db = App::db();
        $key = $bucket . ':' . substr(hash_hmac('sha256', $subject, App::secret()), 0, 40);
        $window = intdiv(time(), $windowSec) * $windowSec;
        $hits = $db->value('SELECT hits FROM rate_limits WHERE rl_key = ? AND window_start = ?', [$key, $window]);
        if ($hits === null) {
            try {
                $db->run('INSERT INTO rate_limits (rl_key, window_start, hits) VALUES (?, ?, 1)', [$key, $window]);
                return true;
            } catch (PDOException) {
                // одновременная вставка — посчитаем как обычный хит ниже
            }
        }
        $db->run('UPDATE rate_limits SET hits = hits + 1 WHERE rl_key = ? AND window_start = ?', [$key, $window]);
        return (int)$hits + 1 <= $max;
    }

    public static function cleanup(): void
    {
        App::db()->run('DELETE FROM rate_limits WHERE window_start < ?', [time() - 86400]);
    }
}
