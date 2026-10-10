<?php
declare(strict_types=1);

/**
 * Приём обращения из любого канала (сайт, бот) — единая точка.
 * Контакт → согласие → касание (источник) → сделка L-… → сообщение → задача → уведомление.
 */
final class Leads
{
    /**
     * @param array $in [
     *   'channel' => 'site'|'bot', 'name', 'phone', 'telegram_id', 'telegram_username', 'email', 'text',
     *   'consent' => ['ip' => ..., 'telegram_id' => ...]  — новое согласие. Можно не передавать,
     *               если у контакта уже есть действующее согласие (например, сообщение в боте после заявки).
     *   'source' => ['channel' => 'site_form'|'bot'|'site_bot', utm_*, referrer, landing_page, bot_start_tag]
     *               — касание. Для сообщений в существующую сделку можно не передавать.
     *   'deal_number' => 'L-000123' — дописать в конкретную сделку (только если она принадлежит этому контакту)
     * ]
     * @return array ['deal_id', 'number', 'contact_id', 'created' => bool, 'duplicate' => bool, 'off_hours' => bool]
     */
    public static function capture(array $in): array
    {
        $channel = $in['channel'] === 'bot' ? 'bot' : 'site';
        // телефон и ник приводим к единому виду в любом канале — иначе склейка контактов не сработает
        $in['phone'] = !empty($in['phone']) ? Phone::normalize((string)$in['phone']) : null;
        $in['telegram_username'] = !empty($in['telegram_username']) ? Phone::telegramUsername((string)$in['telegram_username']) : null;
        $text = trim((string)($in['text'] ?? ''));
        $now = App::now();
        $wh = new WorkHours();
        $offHours = !$wh->isWorkingTime($now);

        if (empty($in['consent']) && !self::existingConsent($in)) {
            throw new InvalidArgumentException('Нет согласия на обработку персональных данных');
        }

        return App::db()->tx(function () use ($in, $channel, $text, $now, $wh, $offHours) {
            $who = Contacts::resolveDetailed(
                $in['name'] ?? null,
                $in['phone'] ?? null,
                isset($in['telegram_id']) ? (int)$in['telegram_id'] : null,
                $in['telegram_username'] ?? null,
                $in['email'] ?? null,
                $channel,
                !empty($in['phone_verified'])
            );
            $contactId = $who['id'];
            if (!empty($in['consent'])) {
                Contacts::addConsent($contactId, $channel, $in['consent']['ip'] ?? null, $in['consent']['telegram_id'] ?? null);
            } elseif (!Contacts::hasConsent($contactId)) {
                throw new InvalidArgumentException('Согласие отозвано или не давалось');
            }

            $deal = null;
            if (!empty($in['deal_number'])) {
                $d = Deals::byNumber($in['deal_number']);
                if ($d && (int)$d['contact_id'] === $contactId && in_array($d['stage'], Deals::OPEN, true)) {
                    $deal = $d;
                }
            }
            $deal ??= Deals::openFor($contactId);
            $msgText = $text === '' ? self::emptyText($channel) : $text;

            // защита от двойного нажатия на сайте: тот же текст в ту же сделку за 2 минуты.
            // В боте повторы отсекаются по update_id Telegram.
            if ($deal && $channel === 'site') {
                $recent = App::db()->value(
                    "SELECT id FROM messages WHERE deal_id = ? AND direction = 'in' AND text = ? AND created_at >= ? LIMIT 1",
                    [$deal['id'], $msgText, Db::ts($now->modify('-2 minutes'))]
                );
                if ($recent) {
                    return ['deal_id' => (int)$deal['id'], 'number' => $deal['number'], 'contact_id' => $contactId,
                        'created' => false, 'duplicate' => true, 'off_hours' => $offHours,
                        'contact_created' => false, 'possible_duplicate' => $who['conflict']];
                }
            }

            $created = false;
            if (!$deal) {
                $subject = $text !== '' ? mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 120) : null;
                $deal = Deals::create($contactId, $subject, $offHours);
                $created = true;
            }
            $dealId = (int)$deal['id'];

            if (!empty($in['source']) || $created) {
                Deals::addSource($contactId, $dealId, $in['source'] ?? ['channel' => $channel === 'bot' ? 'bot' : 'site_form']);
            }
            Deals::addMessage($dealId, 'in', $channel, 'client', $msgText);
            App::db()->update('contacts', ['last_activity_at' => Db::ts()], 'id = ?', [$contactId]);

            // в нерабочее время — задача ответственному на начало следующего рабочего дня (одна на сделку)
            if ($offHours) {
                $hasTask = App::db()->value("SELECT 1 FROM tasks WHERE deal_id = ? AND kind = 'callback' AND done_at IS NULL LIMIT 1", [$dealId]);
                if (!$hasTask) {
                    Deals::addTask($dealId, $deal['manager_id'] ? (int)$deal['manager_id'] : null, 'callback',
                        ($created ? 'Связаться с клиентом по заявке ' : 'Ответить на сообщение по заявке ') . $deal['number'] . ' (пришло в нерабочее время)',
                        $wh->nextStart($now));
                }
            }
            if ($who['conflict']) {
                self::flagPossibleDuplicate($dealId, $deal['number'], $who['conflict'], (string)$in['phone']);
            }
            Outbox::notifyManager($deal, $created ? 'new' : 'message', $channel === 'bot' ? 'Telegram-бот' : 'сайт');

            return ['deal_id' => $dealId, 'number' => $deal['number'], 'contact_id' => $contactId,
                'created' => $created, 'duplicate' => false, 'off_hours' => $offHours,
                'contact_created' => $who['created'], 'possible_duplicate' => $who['conflict']];
        });
    }

    /** Есть ли действующее согласие у контакта, найденного по telegram_id или телефону. */
    private static function existingConsent(array $in): bool
    {
        $db = App::db();
        $id = null;
        if (!empty($in['telegram_id'])) {
            $id = $db->value('SELECT id FROM contacts WHERE telegram_id = ?', [(int)$in['telegram_id']]);
        }
        // в боте телефон набран руками — по нему чужое согласие не «наследуем»
        if (!$id && !empty($in['phone']) && ($in['channel'] ?? '') !== 'bot') {
            $id = $db->value('SELECT id FROM contacts WHERE phone = ?', [$in['phone']]);
        }
        return $id ? Contacts::hasConsent((int)$id) : false;
    }

    /**
     * Человек в Telegram указал телефон, который уже есть у другого контакта, но не подтвердил его кнопкой.
     * Контакты не склеиваем: пусть руководитель проверит (например, звонком) и объединит в карточке контакта.
     */
    private static function flagPossibleDuplicate(int $dealId, string $number, int $otherContactId, string $phone): void
    {
        Deals::addMessage($dealId, 'note', 'bot', 'system',
            'Жилец указал телефон ' . Phone::mask($phone) . ', который уже есть у контакта №' . $otherContactId
            . '. Номер не подтверждён через Telegram, поэтому контакты не объединены. Проверьте и при необходимости объедините в карточке контакта.');
        $admin = App::db()->value("SELECT id FROM users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1");
        $open = App::db()->value("SELECT 1 FROM tasks WHERE deal_id = ? AND kind = 'duplicate' AND done_at IS NULL", [$dealId]);
        if (!$open) {
            Deals::addTask($dealId, $admin ? (int)$admin : null, 'duplicate', "Проверить возможный дубль контакта по заявке $number (контакт №$otherContactId)", App::now());
        }
        Audit::log(null, 'contact', $otherContactId, 'possible_duplicate', ['deal' => $number]);
    }

    private static function emptyText(string $channel): string
    {
        return $channel === 'bot' ? 'Заявка из Telegram без описания' : 'Заявка с сайта без комментария';
    }
}
