<?php
declare(strict_types=1);

/**
 * Подключение вебхука бота: setWebhook с secret_token и проверка getWebhookInfo.
 * Вызывается только по явному подтверждению человека: из /crm/health (кнопка с кодовым словом)
 * или из консоли private/tools/webhook.php.
 */
final class Webhook
{
    public const ALLOWED_UPDATES = ['message', 'callback_query'];

    public static function url(): string
    {
        return App::siteUrl() . '/bot/webhook.php';
    }

    /** Что мешает подключить вебхук. Пустой массив — можно подключать. */
    public static function problems(): array
    {
        $p = [];
        $site = App::siteUrl();
        if (!str_starts_with($site, 'https://')) {
            $p[] = 'В site_url должен быть адрес с https:// — Telegram принимает вебхук только по HTTPS.';
        }
        if ($site === '' || str_contains($site, 'ВАШ-ДОМЕН') || !filter_var(self::asciiHost($site), FILTER_VALIDATE_URL)) {
            $p[] = 'В site_url не указан настоящий домен.';
        }
        if (!Telegram::configured()) {
            $p[] = 'В config.php не вписан токен бота (telegram.bot_token).';
        }
        if (!self::secretValid((string)App::cfg('telegram.webhook_secret', ''))) {
            $p[] = 'Секрет вебхука (telegram.webhook_secret) пустой или содержит недопустимые символы: нужно 16–256 символов A-Z a-z 0-9 _ -.';
        }
        return $p;
    }

    public static function secretValid(string $s): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9_-]{16,256}$/', $s);
    }

    /** Подключить вебхук и сразу проверить. Возвращает getWebhookInfo. */
    public static function set(?int $userId = null): array
    {
        if ($p = self::problems()) {
            throw new RuntimeException(implode(' ', $p));
        }
        Telegram::api('setWebhook', [
            'url'                  => self::url(),
            'secret_token'         => (string)App::cfg('telegram.webhook_secret'),
            'allowed_updates'      => self::ALLOWED_UPDATES,
            'max_connections'      => 10,
            'drop_pending_updates' => false,
        ], 15);
        $info = self::info();
        if (($info['url'] ?? '') !== self::url()) {
            throw new RuntimeException('Telegram принял команду, но getWebhookInfo показывает другой адрес.');
        }
        Settings::set('webhook_set', ['at' => Db::ts(), 'url' => self::url(), 'by' => $userId]);
        Audit::log($userId, 'webhook', null, 'set', ['url' => self::url()]);
        Log::event('webhook_set', []);
        return $info;
    }

    public static function delete(?int $userId = null): void
    {
        Telegram::api('deleteWebhook', ['drop_pending_updates' => false], 15);
        Audit::log($userId, 'webhook', null, 'delete', []);
    }

    public static function info(): array
    {
        return (array)Telegram::api('getWebhookInfo', [], 10);
    }

    /** Оценка состояния по getWebhookInfo: [status ok|warn|fail, текст]. */
    public static function assess(array $info): array
    {
        $url = (string)($info['url'] ?? '');
        if ($url === '') {
            return ['fail', 'Вебхук не подключён: бот не получает сообщения.'];
        }
        if ($url !== self::url()) {
            return ['fail', 'Вебхук ведёт на другой адрес: ' . $url];
        }
        $pending = (int)($info['pending_update_count'] ?? 0);
        $errAt = (int)($info['last_error_date'] ?? 0);
        if ($errAt && $errAt > time() - 3600) {
            return ['fail', 'Telegram не может доставить сообщения: «' . (string)($info['last_error_message'] ?? '') . '» (' . date('d.m.Y H:i', $errAt) . '). В очереди: ' . $pending . '.'];
        }
        if ($pending > 20) {
            return ['warn', 'В очереди Telegram ' . $pending . ' необработанных сообщений.'];
        }
        $txt = 'Подключён: ' . $url . '. В очереди: ' . $pending . '.';
        if ($errAt) {
            $txt .= ' Последняя ошибка доставки была ' . date('d.m.Y H:i', $errAt) . ' — сейчас всё в порядке.';
        }
        return ['ok', $txt];
    }

    private static function asciiHost(string $url): string
    {
        return preg_replace_callback('/[^\x20-\x7e]+/u', static fn() => 'x', $url);
    }
}
