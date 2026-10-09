<?php
declare(strict_types=1);

/** Сделки (обращения жильцов) и их этапы. */
final class Deals
{
    public const STAGES = [
        'new'       => 'Новая',
        'contacted' => 'Связались',
        'invoiced'  => 'Выставили квитанцию/счёт',
        'paid'      => 'Оплачено',
        'lost'      => 'Отказ',
    ];
    public const OPEN = ['new', 'contacted', 'invoiced'];

    public static function number(int $id): string
    {
        return sprintf('L-%06d', $id);
    }

    public static function find(int $id): ?array
    {
        return App::db()->one('SELECT * FROM deals WHERE id = ?', [$id]);
    }

    public static function byNumber(string $number): ?array
    {
        return App::db()->one('SELECT * FROM deals WHERE number = ?', [$number]);
    }

    public static function openFor(int $contactId): ?array
    {
        return App::db()->one(
            "SELECT * FROM deals WHERE contact_id = ? AND stage IN ('new','contacted','invoiced') ORDER BY id DESC LIMIT 1",
            [$contactId]
        );
    }

    public static function create(int $contactId, ?string $subject, bool $offHours): array
    {
        $db = App::db();
        $now = Db::ts();
        $managerId = Assign::next();
        $id = $db->insert('deals', [
            'contact_id'       => $contactId,
            'stage'            => 'new',
            'manager_id'       => $managerId,
            'subject'          => $subject ? mb_substr($subject, 0, 200) : null,
            'is_off_hours'     => $offHours ? 1 : 0,
            'created_at'       => $now,
            'updated_at'       => $now,
            'stage_changed_at' => $now,
        ]);
        $number = self::number($id);
        $db->update('deals', ['number' => $number], 'id = ?', [$id]);
        $db->insert('deal_stage_log', ['deal_id' => $id, 'from_stage' => null, 'to_stage' => 'new', 'user_id' => null, 'created_at' => $now]);
        Audit::log(null, 'deal', $id, 'create', ['manager_id' => $managerId, 'off_hours' => $offHours]);
        return self::find($id);
    }

    public static function addMessage(int $dealId, string $direction, string $channel, string $author, string $text, ?int $userId = null, string $delivery = 'none'): int
    {
        $db = App::db();
        $id = $db->insert('messages', [
            'deal_id'    => $dealId,
            'direction'  => $direction,
            'channel'    => $channel,
            'author'     => $author,
            'user_id'    => $userId,
            'text'       => mb_substr($text, 0, 4000),
            'delivery'   => $delivery,
            'created_at' => Db::ts(),
        ]);
        $db->update('deals', ['updated_at' => Db::ts()], 'id = ?', [$dealId]);
        return $id;
    }

    public static function addSource(int $contactId, ?int $dealId, array $s): void
    {
        $cut = static fn($v, $n) => ($v === null || $v === '') ? null : mb_substr((string)$v, 0, $n);
        App::db()->insert('sources', [
            'contact_id'    => $contactId,
            'deal_id'       => $dealId,
            'channel'       => $s['channel'] ?? 'site_form',
            'utm_source'    => $cut($s['utm_source'] ?? null, 100),
            'utm_medium'    => $cut($s['utm_medium'] ?? null, 100),
            'utm_campaign'  => $cut($s['utm_campaign'] ?? null, 100),
            'utm_content'   => $cut($s['utm_content'] ?? null, 100),
            'utm_term'      => $cut($s['utm_term'] ?? null, 100),
            'referrer'      => $cut($s['referrer'] ?? null, 255),
            'landing_page'  => $cut($s['landing_page'] ?? null, 255),
            'bot_start_tag' => $cut($s['bot_start_tag'] ?? null, 64),
            'created_at'    => Db::ts(),
        ]);
    }

    public static function addTask(?int $dealId, ?int $userId, string $kind, string $title, DateTimeImmutable $due): int
    {
        return App::db()->insert('tasks', [
            'deal_id'    => $dealId,
            'user_id'    => $userId,
            'kind'       => $kind,
            'title'      => mb_substr($title, 0, 255),
            'due_at'     => Db::ts($due),
            'created_at' => Db::ts(),
        ]);
    }
}
