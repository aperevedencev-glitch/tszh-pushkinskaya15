<?php
declare(strict_types=1);

/** База знаний бота: ответы на частые вопросы. Тексты правят менеджеры в CRM. */
final class Kb
{
    public static function seed(?int $userId = null): int
    {
        $db = App::db();
        if ((int)$db->value('SELECT COUNT(*) FROM kb_articles') > 0) {
            return 0;
        }
        $n = 0;
        foreach (require APP_PRIVATE . '/sql/kb_seed.php' as $i => $a) {
            $db->insert('kb_articles', $a + ['sort' => ($i + 1) * 10, 'active' => 1, 'updated_by' => $userId, 'updated_at' => Db::ts()]);
            $n++;
        }
        return $n;
    }

    public static function active(): array
    {
        return App::db()->all('SELECT * FROM kb_articles WHERE active = 1 ORDER BY sort, id');
    }

    public static function find(int $id): ?array
    {
        return App::db()->one('SELECT * FROM kb_articles WHERE id = ? AND active = 1', [$id]);
    }

    /** Лучшее совпадение по ключевым словам или null. */
    public static function search(string $text): ?array
    {
        $norm = self::normalize($text);
        if ($norm === '') {
            return null;
        }
        $tokens = explode(' ', $norm);
        $best = null;
        $bestScore = 0;
        foreach (self::active() as $a) {
            $score = 0;
            foreach (array_filter(array_map('trim', explode(',', (string)$a['keywords']))) as $kw) {
                $kw = self::normalize($kw);
                if ($kw === '') {
                    continue;
                }
                if (str_contains($kw, ' ')) {
                    $score += str_contains($norm, $kw) ? 2 : 0;
                    continue;
                }
                $stem = mb_substr($kw, 0, 6);
                foreach ($tokens as $t) {
                    if (str_starts_with($t, $stem)) {
                        $score++;
                        break;
                    }
                }
            }
            if ($score > $bestScore) {
                [$best, $bestScore] = [$a, $score];
            }
        }
        return $best;
    }

    /** Подставить данные ТСЖ; пустые — заметный плейсхолдер. */
    public static function render(string $answer): string
    {
        $c = static function (string $key, string $label): string {
            $v = trim((string)App::cfg('company.' . $key, ''));
            return $v !== '' ? htmlspecialchars($v, ENT_QUOTES) : '[Нужно от заказчика: ' . $label . ']';
        };
        return strtr($answer, [
            '{work_hours}'      => (new WorkHours(null, [], []))->label(),
            '{phone_emergency}' => $c('phone_emergency', 'телефон аварийной службы'),
            '{phone_office}'    => $c('phone_office', 'телефон правления'),
            '{email}'           => $c('email', 'e-mail'),
            '{site_url}'        => App::siteUrl(),
            '{bot}'             => '@' . App::botUsername(),
        ]);
    }

    private static function normalize(string $s): string
    {
        $s = mb_strtolower(str_replace(['ё', 'Ё'], 'е', $s));
        preg_match_all('/[a-zа-я0-9]+/u', $s, $m);
        return implode(' ', $m[0]);
    }
}
