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
     *   'consent' => ['ip' => ..., 'telegram_id' => ...] (обязательно — без согласия не сохраняем),
     *   'source' => ['channel' => 'site_form'|'bot'|'site_bot', utm_*, referrer, landing_page, bot_start_tag],
     *   'deal_number' => 'L-000123' (необязательно: дописать в конкретную сделку)
     * ]
     * @return array ['deal_id', 'number', 'contact_id', 'created' => bool, 'duplicate' => bool]
     */
    public static function capture(array $in): array
    {
        if (empty($in['consent'])) {
            throw new InvalidArgumentException('Нет согласия на обработку персональных данных');
        }
        $channel = $in['channel'] === 'bot' ? 'bot' : 'site';
        // телефон и ник приводим к единому виду в любом канале — иначе склейка контактов не сработает
        $in['phone'] = !empty($in['phone']) ? Phone::normalize((string)$in['phone']) : null;
        $in['telegram_username'] = !empty($in['telegram_username']) ? Phone::telegramUsername((string)$in['telegram_username']) : null;
        $text = trim((string)($in['text'] ?? ''));
        $now = App::now();
        $wh = new WorkHours();
        $offHours = !$wh->isWorkingTime($now);

        return App::db()->tx(function () use ($in, $channel, $text, $now, $wh, $offHours) {
            $contactId = Contacts::resolve(
                $in['name'] ?? null,
                $in['phone'] ?? null,
                isset($in['telegram_id']) ? (int)$in['telegram_id'] : null,
                $in['telegram_username'] ?? null,
                $in['email'] ?? null
            );
            Contacts::addConsent($contactId, $channel, $in['consent']['ip'] ?? null, $in['consent']['telegram_id'] ?? null);

            $deal = null;
            if (!empty($in['deal_number'])) {
                $d = Deals::byNumber($in['deal_number']);
                if ($d && (int)$d['contact_id'] === $contactId) {
                    $deal = $d;
                }
            }
            $deal ??= Deals::openFor($contactId);

            // защита от двойного нажатия: тот же текст в ту же сделку за 2 минуты
            if ($deal) {
                $recent = App::db()->value(
                    "SELECT id FROM messages WHERE deal_id = ? AND direction = 'in' AND text = ? AND created_at >= ? LIMIT 1",
                    [$deal['id'], $text === '' ? self::emptyText($channel) : $text, Db::ts($now->modify('-2 minutes'))]
                );
                if ($recent) {
                    return ['deal_id' => (int)$deal['id'], 'number' => $deal['number'], 'contact_id' => $contactId, 'created' => false, 'duplicate' => true];
                }
            }

            $created = false;
            if (!$deal) {
                $subject = $text !== '' ? mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 120) : null;
                $deal = Deals::create($contactId, $subject, $offHours);
                $created = true;
            }
            $dealId = (int)$deal['id'];

            Deals::addSource($contactId, $dealId, $in['source'] ?? ['channel' => $channel === 'bot' ? 'bot' : 'site_form']);
            Deals::addMessage($dealId, 'in', $channel, 'client', $text === '' ? self::emptyText($channel) : $text);

            if ($created && $offHours) {
                Deals::addTask($dealId, $deal['manager_id'] ? (int)$deal['manager_id'] : null, 'callback',
                    'Связаться с клиентом по заявке ' . $deal['number'] . ' (пришла в нерабочее время)', $wh->nextStart($now));
            }
            Outbox::notifyManager($deal, $created ? 'new' : 'message', $channel === 'bot' ? 'Telegram-бот' : 'сайт');

            return ['deal_id' => $dealId, 'number' => $deal['number'], 'contact_id' => $contactId, 'created' => $created, 'duplicate' => false];
        });
    }

    private static function emptyText(string $channel): string
    {
        return $channel === 'bot' ? 'Заявка из Telegram без описания' : 'Заявка с сайта без комментария';
    }
}
