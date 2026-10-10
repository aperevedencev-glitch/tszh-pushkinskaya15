<?php
declare(strict_types=1);

/** Контакты: один человек — одна запись. Склейка по телефону и telegram_id. */
final class Contacts
{
    public static function find(int $id): ?array
    {
        return App::db()->one('SELECT * FROM contacts WHERE id = ?', [$id]);
    }

    /** Совместимая обёртка: только id контакта. */
    public static function resolve(?string $name, ?string $phone, ?int $tgId, ?string $tgUsername, ?string $email = null,
        string $channel = 'site', bool $phoneVerified = false): int
    {
        return self::resolveDetailed($name, $phone, $tgId, $tgUsername, $email, $channel, $phoneVerified)['id'];
    }

    /**
     * Найти или создать контакт и дописать недостающие поля.
     *
     * Доверие к данным разное, и от этого зависит склейка:
     *  - Telegram ID приходит от самого Telegram — ему верим;
     *  - телефон, отправленный кнопкой «Поделиться номером» (contact.user_id = автор), подтверждён Telegram;
     *  - телефон и ник, набранные руками (сайт, бот), — только заявление человека.
     *
     * Главное правило: ответы менеджера уходят в Telegram контакта, поэтому к контакту с Telegram
     * нельзя ни присоединиться, ни «застолбить» за ним чужой номер по неподтверждённым данным.
     *  1. Набранный в боте телефон не записывается в контакт (только в текст заявки) и ни с чем не склеивает.
     *  2. Заявка с сайта присоединяется к контакту с Telegram, только если его номер подтверждён Telegram.
     *  3. Ник с сайта ищем только среди контактов без Telegram и не перезаписываем им сохранённый.
     * Всё, что не склеено из-за этих правил, помечается как возможный дубль (conflict) — руководитель проверяет
     * и объединяет в карточке контакта. Отброшенный телефон возвращается в phone_note, чтобы менеджер его видел.
     *
     * @return array{id:int, created:bool, conflict:?int, phone_note:?string}
     */
    public static function resolveDetailed(?string $name, ?string $phone, ?int $tgId, ?string $tgUsername, ?string $email = null,
        string $channel = 'site', bool $phoneVerified = false): array
    {
        $db = App::db();
        $now = Db::ts();
        $conflict = null;
        $phoneNote = null;
        $byTg = $tgId ? $db->one('SELECT * FROM contacts WHERE telegram_id = ?', [$tgId]) : null;
        $byPhone = $phone ? $db->one('SELECT * FROM contacts WHERE phone = ?', [$phone]) : null;
        $verified = $phone !== null && $phoneVerified && $tgId !== null;

        if ($phone !== null && $tgId !== null && !$verified) {
            // правило 1: в боте номер набран руками — в контакт не пишем, ни с кем не склеиваем
            if ($byPhone && (int)$byPhone['id'] !== (int)($byTg['id'] ?? 0)) {
                $conflict = (int)$byPhone['id'];
            }
            $phoneNote = $phone;
            $phone = null;
            $byPhone = null;
        } elseif ($byPhone && $tgId !== null && (int)$byPhone['id'] !== (int)($byTg['id'] ?? 0)) {
            // номер подтверждён Telegram, но записан у контакта другого Telegram-аккаунта — решает руководитель
            if ($byPhone['telegram_id'] && (int)$byPhone['telegram_id'] !== $tgId) {
                $conflict = (int)$byPhone['id'];
                $phoneNote = $phone;
                $phone = null;
                $byPhone = null;
            }
        } elseif ($byPhone && $tgId === null && $byPhone['telegram_id'] && !(int)$byPhone['phone_verified']) {
            // правило 2: сайт, номер совпал с контактом, у которого Telegram, а номер им не подтверждён
            $conflict = (int)$byPhone['id'];
            $phoneNote = $phone;
            $phone = null;
            $byPhone = null;
        }
        // правило 3
        $byUser = (!$byPhone && !$byTg && !$tgId && $tgUsername)
            ? $db->one('SELECT * FROM contacts WHERE telegram_username = ? AND telegram_id IS NULL AND anonymized_at IS NULL ORDER BY id LIMIT 1', [$tgUsername])
            : null;

        if ($byPhone && $byTg && (int)$byPhone['id'] !== (int)$byTg['id']) {
            // сюда попадаем только с номером, подтверждённым этим же Telegram-аккаунтом
            self::merge((int)$byTg['id'], (int)$byPhone['id']);
            $byTg = null;
        }
        $c = $byPhone ?: $byTg ?: $byUser;

        // номер с сайта ранее набрали руками в боте (другой человек?) — подсказка руководителю, без склейки
        if ($conflict === null && $tgId === null && $phone !== null) {
            $claimedBy = $db->value('SELECT id FROM contacts WHERE phone_claimed = ? AND id <> ? ORDER BY id LIMIT 1', [$phone, (int)($c['id'] ?? 0)]);
            $conflict = $claimedBy ? (int)$claimedBy : null;
        }
        $claimed = ($phoneNote !== null && $tgId !== null) ? $phoneNote : null;

        if (!$c) {
            $id = $db->insert('contacts', [
                'name'              => $name,
                'phone'             => $phone,
                'phone_verified'    => $verified && $phone !== null ? 1 : 0,
                'phone_claimed'     => $claimed,
                'telegram_id'       => $tgId,
                'telegram_username' => $tgUsername,
                'email'             => $email,
                'created_at'        => $now,
                'updated_at'        => $now,
                'last_activity_at'  => $now,
            ]);
            return ['id' => $id, 'created' => true, 'conflict' => $conflict, 'phone_note' => $phoneNote];
        }

        $id = (int)$c['id'];
        $upd = ['last_activity_at' => $now, 'updated_at' => $now];
        if ($name && !$c['name']) {
            $upd['name'] = $name;
        }
        if ($phone && !$c['phone']) {
            $upd['phone'] = $phone;
        }
        if ($verified && $phone !== null && ($c['phone'] === null || $c['phone'] === $phone)) {
            $upd['phone_verified'] = 1;
        }
        if ($tgId && !$c['telegram_id']) {
            $upd['telegram_id'] = $tgId;
        }
        if ($claimed !== null && $claimed !== $c['phone']) {
            $upd['phone_claimed'] = $claimed;
        }
        // ник от Telegram (бот) обновляем; набранный на сайте — только если поле пустое
        if ($tgUsername && $tgUsername !== $c['telegram_username'] && ($tgId !== null || !$c['telegram_username'])) {
            $upd['telegram_username'] = $tgUsername;
        }
        if ($email && !$c['email']) {
            $upd['email'] = $email;
        }
        if ($c['anonymized_at']) {
            // человек вернулся после обезличивания — это новый набор данных
            $upd['anonymized_at'] = null;
            $upd['name'] = $name;
        }
        $db->update('contacts', $upd, 'id = ?', [$id]);
        return ['id' => $id, 'created' => false, 'conflict' => $conflict, 'phone_note' => $phoneNote];
    }

    /** Перенести всё из контакта $from в $into и удалить $from. */
    public static function merge(int $from, int $into, ?int $userId = null): void
    {
        $db = App::db();
        $src = self::find($from);
        $dst = self::find($into);
        if (!$src || !$dst || $from === $into) {
            return;
        }
        // если кто-то из двоих отозвал согласие — после объединения согласие тоже считается отозванным
        $revoked = !self::hasConsent($from) && $db->value('SELECT 1 FROM consents WHERE contact_id = ?', [$from])
            || !self::hasConsent($into) && $db->value('SELECT 1 FROM consents WHERE contact_id = ?', [$into]);
        foreach (['consents', 'deals', 'sources'] as $t) {
            $db->run("UPDATE $t SET contact_id = ? WHERE contact_id = ?", [$into, $from]);
        }
        // запросы на удаление ссылаются на номер контакта в заголовке задачи
        $db->run("UPDATE tasks SET title = REPLACE(title, ?, ?) WHERE kind = 'delete_request' AND done_at IS NULL", ['#' . $from . ')', '#' . $into . ')']);
        $fill = [];
        foreach (['name', 'phone', 'telegram_id', 'telegram_username', 'email'] as $f) {
            if (!$dst[$f] && $src[$f]) {
                $fill[$f] = $src[$f];
            }
        }
        if (isset($fill['phone'])) {
            $fill['phone_verified'] = (int)$src['phone_verified'];
        }
        // уникальные поля освобождаем у старого контакта до переноса
        $db->update('contacts', ['phone' => null, 'telegram_id' => null], 'id = ?', [$from]);
        if ($fill) {
            $fill['updated_at'] = Db::ts();
            $db->update('contacts', $fill, 'id = ?', [$into]);
        }
        $db->run('DELETE FROM contacts WHERE id = ?', [$from]);
        if ($revoked && self::hasConsent($into)) {
            self::addConsent($into, 'crm', null, null, 'revoked');
        }
        Audit::log($userId, 'contact', $into, 'merge', ['merged_contact_id' => $from]);
    }

    public static function addConsent(int $contactId, string $channel, ?string $ip, ?int $tgId, string $action = 'given'): void
    {
        App::db()->insert('consents', [
            'contact_id'     => $contactId,
            'channel'        => $channel,
            'action'         => $action,
            'policy_version' => (string)App::cfg('policy_version', ''),
            'ip'             => $ip,
            'telegram_id'    => $tgId,
            'created_at'     => Db::ts(),
        ]);
    }

    public static function hasConsent(int $contactId): bool
    {
        $last = App::db()->value('SELECT action FROM consents WHERE contact_id = ? ORDER BY id DESC LIMIT 1', [$contactId]);
        return $last === 'given';
    }
}
