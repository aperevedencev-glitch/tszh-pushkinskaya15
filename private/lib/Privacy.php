<?php
declare(strict_types=1);

/** 152-ФЗ: обезличивание контакта по запросу и по сроку хранения. */
final class Privacy
{
    public const REDACTED = '[удалено по 152-ФЗ]';

    public static function anonymize(int $contactId, ?int $userId, string $reason): bool
    {
        $db = App::db();
        $c = Contacts::find($contactId);
        if (!$c || $c['anonymized_at']) {
            return false;
        }
        $db->tx(function () use ($db, $c, $contactId, $userId, $reason) {
            $now = Db::ts();
            $tg = $c['telegram_id'];
            $db->update('contacts', [
                'name' => null, 'phone' => null, 'phone_verified' => 0, 'phone_claimed' => null, 'telegram_id' => null, 'telegram_username' => null, 'email' => null,
                'anonymized_at' => $now, 'updated_at' => $now,
            ], 'id = ?', [$contactId]);
            $dealIds = array_map('intval', array_column($db->all('SELECT id FROM deals WHERE contact_id = ?', [$contactId]), 'id'));
            foreach ($dealIds as $dealId) {
                // в переписке могут быть адрес, имена, телефоны — удаляем тексты целиком
                $db->run("UPDATE messages SET text = ? WHERE deal_id = ? AND author IN ('client', 'manager', 'bot')", [self::REDACTED, $dealId]);
                $db->run('UPDATE deals SET subject = NULL, updated_at = ? WHERE id = ?', [$now, $dealId]);
                $deal = Deals::find($dealId);
                if (in_array($deal['stage'], Deals::OPEN, true)) {
                    CrmDeals::move($deal, 'lost', 'Персональные данные удалены по запросу', $userId);
                }
                $db->run("UPDATE outbox SET failed_at = ?, last_error = 'данные обезличены' WHERE deal_id = ? AND sent_at IS NULL AND failed_at IS NULL", [$now, $dealId]);
                $db->run("UPDATE outbox SET payload = '{}' WHERE deal_id = ?", [$dealId]);
            }
            $db->run('UPDATE consents SET ip = NULL, telegram_id = NULL WHERE contact_id = ?', [$contactId]);
            $db->run('UPDATE sources SET referrer = NULL WHERE contact_id = ?', [$contactId]);
            if ($tg) {
                $db->run('DELETE FROM bot_sessions WHERE telegram_id = ?', [$tg]);
                $db->run("UPDATE outbox SET payload = '{}', chat_id = 0, failed_at = COALESCE(sent_at, failed_at, ?) WHERE chat_id = ?", [$now, $tg]);
            }
            $db->run("UPDATE tasks SET done_at = ?, done_by = ? WHERE kind = 'delete_request' AND done_at IS NULL AND (title LIKE ? OR deal_id IN (SELECT id FROM deals WHERE contact_id = ?))",
                [$now, $userId, '%#' . $contactId . ')%', $contactId]);
            Audit::log($userId, 'contact', $contactId, 'anonymize', ['reason' => $reason, 'deals' => count($dealIds)]);
        });
        return true;
    }

    /** Тексты уже отправленных сообщений в очереди не храним дольше недели — история остаётся в карточке. */
    public static function purgeOutbox(): int
    {
        return App::db()->run("UPDATE outbox SET payload = '{}' WHERE payload <> '{}' AND (sent_at < ? OR failed_at < ?)",
            [Db::ts(App::now()->modify('-7 days')), Db::ts(App::now()->modify('-7 days'))])->rowCount();
    }

    /** Обезличить контакты без активности дольше срока хранения. Запускается cron раз в сутки. */
    public static function retention(): int
    {
        $months = max(1, (int)App::cfg('retention_months', 12));
        $before = Db::ts(App::now()->modify("-$months months"));
        $ids = array_column(App::db()->all('SELECT id FROM contacts WHERE anonymized_at IS NULL AND last_activity_at < ? LIMIT 500', [$before]), 'id');
        foreach ($ids as $id) {
            self::anonymize((int)$id, null, "срок хранения $months мес.");
        }
        return count($ids);
    }
}
