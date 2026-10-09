<?php
declare(strict_types=1);

/**
 * Очередь исходящих сообщений в Telegram.
 * Запись в очередь всегда происходит в той же транзакции, что и заявка,
 * поэтому сбой Telegram не теряет ни заявку, ни уведомление.
 * Отправка — Telegram-клиентом сразу после ответа пользователю и cron-задачей повторно.
 */
final class Outbox
{
    public static function enqueue(string $kind, int $chatId, array $payload, ?int $messageId = null, ?int $dealId = null): int
    {
        return App::db()->insert('outbox', [
            'kind'        => $kind,
            'chat_id'     => $chatId,
            'payload'     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'message_id'  => $messageId,
            'deal_id'     => $dealId,
            'attempts'    => 0,
            'next_try_at' => Db::ts(),
            'created_at'  => Db::ts(),
        ]);
    }

    /** Уведомить ответственного о новой заявке или новом сообщении. Без имён и телефонов. */
    public static function notifyManager(array $deal, string $event, string $channelLabel): void
    {
        if (!$deal['manager_id']) {
            return;
        }
        $tg = App::db()->value('SELECT telegram_id FROM users WHERE id = ? AND active = 1', [$deal['manager_id']]);
        if (!$tg) {
            return; // уведомление увидят в CRM
        }
        // о новых сообщениях в одной сделке напоминаем не чаще раза в 10 минут
        if ($event === 'message' && App::db()->value(
            "SELECT 1 FROM outbox WHERE deal_id = ? AND kind = 'notify_message' AND created_at >= ? LIMIT 1",
            [$deal['id'], Db::ts(App::now()->modify('-10 minutes'))]
        )) {
            return;
        }
        $title = $event === 'new' ? '🆕 Новая заявка' : '💬 Новое сообщение';
        $text = sprintf("%s %s\nКанал: %s%s", $title, $deal['number'], $channelLabel, $deal['is_off_hours'] ? "\n🌙 Пришла в нерабочее время" : '');
        $payload = ['text' => $text];
        $url = App::siteUrl() . '/crm/deal.php?id=' . (int)$deal['id'];
        if (str_starts_with($url, 'https://')) {
            $payload['reply_markup'] = ['inline_keyboard' => [[['text' => 'Открыть в CRM', 'url' => $url]]]];
        }
        self::enqueue('notify_' . $event, (int)$tg, $payload, null, (int)$deal['id']);
    }
}
