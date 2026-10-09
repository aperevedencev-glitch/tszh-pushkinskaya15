<?php
declare(strict_types=1);

/** Нормализация контактов. */
final class Phone
{
    /** «8 (916) 123-45-67», «+7 916…», «9161234567» → «+79161234567». Иначе null. */
    public static function normalize(?string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', (string)$raw);
        if (strlen($d) === 10 && $d[0] === '9') {
            $d = '7' . $d;
        }
        if (strlen($d) === 11 && ($d[0] === '7' || $d[0] === '8')) {
            return '+7' . substr($d, 1);
        }
        return null;
    }

    /** «@ivan_petrov», «t.me/ivan_petrov», «ivan_petrov» → «ivan_petrov». Иначе null. */
    public static function telegramUsername(?string $raw): ?string
    {
        $s = trim((string)$raw);
        $s = preg_replace('~^(https?://)?(t\.me|telegram\.me)/~i', '', $s);
        $s = ltrim($s, '@');
        return preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', $s) ? strtolower($s) : null;
    }

    /** Для журналов и уведомлений: +7 916 ***-**-67. */
    public static function mask(?string $phone): string
    {
        if (!$phone) {
            return '—';
        }
        return substr($phone, 0, 2) . ' ' . substr($phone, 2, 3) . ' ***-**-' . substr($phone, -2);
    }
}
