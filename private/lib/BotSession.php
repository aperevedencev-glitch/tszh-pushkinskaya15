<?php
declare(strict_types=1);

/**
 * Состояние диалога с ботом: шаг и черновик.
 * Черновик заявки до согласия — техническая необходимость диалога: в CRM он не попадает,
 * удаляется при отмене, после согласия и автоматически через 24 часа (cron).
 */
final class BotSession
{
    public function __construct(public readonly int $tgId, public string $state = '', public array $data = [])
    {
    }

    public static function load(int $tgId): self
    {
        $row = App::db()->one('SELECT state, data FROM bot_sessions WHERE telegram_id = ?', [$tgId]);
        return $row ? new self($tgId, (string)$row['state'], json_decode((string)$row['data'], true) ?: []) : new self($tgId);
    }

    public function save(): void
    {
        $db = App::db();
        $json = json_encode($this->data, JSON_UNESCAPED_UNICODE);
        if ($db->value('SELECT 1 FROM bot_sessions WHERE telegram_id = ?', [$this->tgId])) {
            $db->update('bot_sessions', ['state' => $this->state, 'data' => $json, 'updated_at' => Db::ts()], 'telegram_id = ?', [$this->tgId]);
        } else {
            $db->insert('bot_sessions', ['telegram_id' => $this->tgId, 'state' => $this->state, 'data' => $json, 'updated_at' => Db::ts()]);
        }
    }

    /** Сбросить шаг и черновик с личными данными. Метку кампании и привязанную заявку оставляем — в них нет ПДн. */
    public function reset(): void
    {
        $keep = array_intersect_key($this->data, array_flip(['start_tag', 'deal_number']));
        $this->state = '';
        $this->data = $keep;
    }

    /** Удалить брошенные черновики старше 24 часов. */
    public static function purgeDrafts(): int
    {
        $db = App::db();
        $n = 0;
        foreach ($db->all("SELECT telegram_id, data FROM bot_sessions WHERE state <> '' AND updated_at < ?", [Db::ts(App::now()->modify('-24 hours'))]) as $row) {
            $s = new self((int)$row['telegram_id'], 'x', json_decode((string)$row['data'], true) ?: []);
            $s->reset();
            $s->save();
            $n++;
        }
        return $n;
    }
}
