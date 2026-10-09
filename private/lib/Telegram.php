<?php
declare(strict_types=1);

/**
 * Клиент Telegram Bot API и отправка очереди outbox.
 * Сбой Telegram не ломает сайт и CRM: всё, что не ушло сразу, остаётся в очереди и уходит повторно.
 */
final class Telegram
{
    /** Подмена транспорта в тестах: fn(string $method, array $params): array */
    public static $transport = null;

    public static function configured(): bool
    {
        return (string)App::cfg('telegram.bot_token', '') !== '';
    }

    /** Вызов метода API. Возвращает result или бросает TelegramError. */
    public static function api(string $method, array $params = [], int $timeout = 10): mixed
    {
        if (self::$transport) {
            $resp = (self::$transport)($method, $params);
            return self::unwrap($resp, (int)($resp['_http'] ?? 200));
        }
        $token = (string)App::cfg('telegram.bot_token', '');
        if ($token === '') {
            throw new TelegramError('Токен бота не задан в config.php', 0);
        }
        $base = rtrim((string)App::cfg('telegram.api_base', 'https://api.telegram.org'), '/');
        $ch = curl_init("$base/bot$token/$method");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new TelegramError('Нет связи с Telegram: ' . $err, 0);   // токен в текст ошибки не попадает
        }
        $resp = json_decode((string)$raw, true);
        if (!is_array($resp)) {
            throw new TelegramError('Telegram вернул не JSON (HTTP ' . $http . ')', $http ?: 502);
        }
        return self::unwrap($resp, $http);
    }

    private static function unwrap(array $resp, int $http): mixed
    {
        if (!empty($resp['ok'])) {
            return $resp['result'] ?? true;
        }
        $code = (int)($resp['error_code'] ?? ($http ?: 500));
        $retry = (int)($resp['parameters']['retry_after'] ?? 0);
        throw new TelegramError((string)($resp['description'] ?? 'Ошибка Telegram'), $code, $retry);
    }

    /** Отправить сразу; при 429 или сбое сети — одна быстрая повторная попытка. */
    public static function send(int $chatId, string $text, array $extra = []): array
    {
        $params = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true] + $extra;
        try {
            return (array)self::api('sendMessage', $params);
        } catch (TelegramError $e) {
            if ($e->isPermanent() || $e->retryAfter > 3) {
                throw $e;
            }
            usleep(max(1, $e->retryAfter) * 300000);
            return (array)self::api('sendMessage', $params);
        }
    }

    /** Ответ пользователю из вебхука: если Telegram не принял — кладём в очередь, повтор по cron. */
    public static function reply(int $chatId, string $text, array $extra = []): void
    {
        try {
            self::send($chatId, $text, $extra);
        } catch (TelegramError $e) {
            if ($e->isPermanent()) {
                Log::event('tg_reply_rejected', ['code' => $e->httpCode]);
                return;
            }
            Outbox::enqueue('bot_reply', $chatId, ['text' => $text, 'parse_mode' => 'HTML'] + $extra);
        }
    }

    /** Отправить сообщения из очереди. Возвращает [отправлено, отложено, отказ]. */
    public static function flushOutbox(int $limit = 20): array
    {
        $db = App::db();
        $stats = [0, 0, 0];
        if (!self::configured() && !self::$transport) {
            return $stats;
        }
        $rows = $db->all(
            'SELECT * FROM outbox WHERE sent_at IS NULL AND failed_at IS NULL AND next_try_at <= ? ORDER BY id LIMIT ' . (int)$limit,
            [Db::ts()]
        );
        foreach ($rows as $row) {
            // «бронируем» строку, чтобы вебхук и cron не отправили одно и то же дважды
            $claimed = $db->run('UPDATE outbox SET next_try_at = ? WHERE id = ? AND next_try_at = ? AND sent_at IS NULL',
                [Db::ts(App::now()->modify('+2 minutes')), $row['id'], $row['next_try_at']])->rowCount();
            if (!$claimed) {
                continue;
            }
            $payload = json_decode($row['payload'], true) ?: [];
            $text = (string)($payload['text'] ?? '');
            unset($payload['text']);
            try {
                $res = self::api('sendMessage', ['chat_id' => (int)$row['chat_id'], 'text' => $text, 'disable_web_page_preview' => true] + $payload);
                $db->update('outbox', ['sent_at' => Db::ts(), 'attempts' => (int)$row['attempts'] + 1, 'last_error' => null], 'id = ?', [$row['id']]);
                if ($row['message_id']) {
                    $db->update('messages', ['delivery' => 'sent', 'tg_message_id' => $res['message_id'] ?? null], 'id = ?', [$row['message_id']]);
                }
                $stats[0]++;
            } catch (TelegramError $e) {
                $attempts = (int)$row['attempts'] + 1;
                $giveUp = $e->isPermanent() || App::now() > new DateTimeImmutable($row['created_at'] . ' +2 days');
                $upd = ['attempts' => $attempts, 'last_error' => mb_substr($e->getMessage(), 0, 250)];
                if ($giveUp) {
                    $upd['failed_at'] = Db::ts();
                    if ($row['message_id']) {
                        $db->update('messages', ['delivery' => 'failed'], 'id = ?', [$row['message_id']]);
                    }
                    $stats[2]++;
                } else {
                    $delay = max($e->retryAfter, [30, 60, 120, 300, 600, 1800][min($attempts - 1, 5)]);
                    $upd['next_try_at'] = Db::ts(App::now()->modify("+$delay seconds"));
                    $stats[1]++;
                }
                $db->update('outbox', $upd, 'id = ?', [$row['id']]);
                Log::error('outbox_send_failed', ['outbox_id' => (int)$row['id'], 'code' => $e->httpCode, 'final' => $giveUp]);
            }
        }
        return $stats;
    }
}
