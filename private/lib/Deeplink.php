<?php
declare(strict_types=1);

/**
 * Ссылки в Telegram-бота.
 * С сайта:          ?start=site_<кампания>
 * После заявки:     ?start=L000123_<подпись> — подпись не даёт подобрать чужой номер.
 */
final class Deeplink
{
    public static function botUrl(string $start = ''): string
    {
        $u = 'https://t.me/' . App::botUsername();
        return $start === '' ? $u : $u . '?start=' . rawurlencode($start);
    }

    /** Метка кампании для /start: только A-Z a-z 0-9 _ -, до 64 символов. */
    public static function campaignTag(?string $campaign, ?string $source = null): string
    {
        $raw = $campaign ?: $source ?: '';
        $tag = preg_replace('/[^A-Za-z0-9_-]+/', '-', $raw);
        $tag = trim(substr($tag, 0, 58), '-');
        return $tag === '' ? 'site' : 'site_' . $tag;
    }

    public static function forDeal(string $number): string
    {
        $num = str_replace('-', '', $number);               // L-000123 → L000123
        return $num . '_' . self::sign($number);
    }

    /** Разбор ?start=: ['deal' => 'L-000123'] | ['campaign' => 'site_xxx'] | ['tag' => ...] | []. */
    public static function parse(string $start): array
    {
        if (preg_match('/^L(\d{6,})_([a-f0-9]{10})$/', $start, $m)) {
            $number = 'L-' . $m[1];
            return hash_equals(self::sign($number), $m[2]) ? ['deal' => $number] : ['invalid' => true];
        }
        if (preg_match('/^site(_[A-Za-z0-9_-]{1,58})?$/', $start)) {
            return ['campaign' => $start];
        }
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $start)) {
            return ['tag' => $start];
        }
        return [];
    }

    private static function sign(string $number): string
    {
        return substr(hash_hmac('sha256', 'deal:' . $number, App::secret()), 0, 10);
    }
}
