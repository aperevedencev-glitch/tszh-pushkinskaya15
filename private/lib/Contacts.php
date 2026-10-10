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
     *  - телефон, отправленный кнопкой «Поделиться номером» (contact.user_id = автор), — подтверждён Telegram;
     *  - телефон и ник, набранные руками (сайт, бот), — только заявление человека.
     * Набранный руками телефон НЕ привязывает Telegram к чужому контакту и не склеивает контакты:
     * иначе любой, кто знает номер жильца, получал бы ответы менеджера на его заявки.
     * Такой случай помечается как возможный дубль (conflict) — руководитель проверяет и объединяет в CRM.
     *
     * @return array{id:int, created:bool, conflict:?int}
     */
    public static function resolveDetailed(?string $name, ?string $phone, ?int $tgId, ?string $tgUsername, ?string $email = null,
        string $channel = 'site', bool $phoneVerified = false): array
    {
        $db = App::db();
        $now = Db::ts();
        $conflict = null;
        $byPhone = $phone ? $db->one('SELECT * FROM contacts WHERE phone = ?', [$phone]) : null;
        $byTg = $tgId ? $db->one('SELECT * FROM contacts WHERE telegram_id = ?', [$tgId]) : null;

        if ($byPhone && $tgId && (int)$byPhone['id'] !== (int)($byTg['id'] ?? 0)) {
            // телефон принадлежит другому контакту, а пишет человек из Telegram
            $ownedByOtherTg = $byPhone['telegram_id'] && (int)$byPhone['telegram_id'] !== $tgId;
            if (!$phoneVerified || $ownedByOtherTg) {
                $conflict = (int)$byPhone['id'];
                $byPhone = null;
                $phone = null;     // номер занят другим контактом — в этот не записываем
            }
        }
        // ник, набранный на сайте, ищем только для заявок без Telegram: он ничего не открывает
        $byUser = (!$byPhone && !$byTg && !$tgId && $tgUsername)
            ? $db->one('SELECT * FROM contacts WHERE telegram_username = ? AND anonymized_at IS NULL ORDER BY id LIMIT 1', [$tgUsername])
            : null;

        if ($byPhone && $byTg && (int)$byPhone['id'] !== (int)$byTg['id']) {
            // сюда попадаем только с подтверждённым телефоном
            self::merge((int)$byTg['id'], (int)$byPhone['id']);
            $byTg = null;
        }
        $c = $byPhone ?: $byTg ?: $byUser;

        if (!$c) {
            $id = $db->insert('contacts', [
                'name'              => $name,
                'phone'             => $phone,
                'telegram_id'       => $tgId,
                'telegram_username' => $tgUsername,
                'email'             => $email,
                'created_at'        => $now,
                'updated_at'        => $now,
                'last_activity_at'  => $now,
            ]);
            return ['id' => $id, 'created' => true, 'conflict' => $conflict];
        }

        $id = (int)$c['id'];
        $upd = ['last_activity_at' => $now, 'updated_at' => $now];
        if ($name && !$c['name']) {
            $upd['name'] = $name;
        }
        if ($phone && !$c['phone']) {
            $upd['phone'] = $phone;
        }
        if ($tgId && !$c['telegram_id']) {
            $upd['telegram_id'] = $tgId;
        }
        if ($tgUsername && $tgUsername !== $c['telegram_username']) {
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
        return ['id' => $id, 'created' => false, 'conflict' => $conflict];
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
        foreach (['consents', 'deals', 'sources'] as $t) {
            $db->run("UPDATE $t SET contact_id = ? WHERE contact_id = ?", [$into, $from]);
        }
        $fill = [];
        foreach (['name', 'phone', 'telegram_id', 'telegram_username', 'email'] as $f) {
            if (!$dst[$f] && $src[$f]) {
                $fill[$f] = $src[$f];
            }
        }
        // уникальные поля освобождаем у старого контакта до переноса
        $db->update('contacts', ['phone' => null, 'telegram_id' => null], 'id = ?', [$from]);
        if ($fill) {
            $fill['updated_at'] = Db::ts();
            $db->update('contacts', $fill, 'id = ?', [$into]);
        }
        $db->run('DELETE FROM contacts WHERE id = ?', [$from]);
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
