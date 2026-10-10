<?php
declare(strict_types=1);

/** Действия менеджера со сделкой. Каждое изменение пишется в audit_log (без персональных данных). */
final class CrmDeals
{
    /** Перевод на этап. Для «Отказ» причина обязательна. Возвращает null или текст ошибки. */
    public static function move(array $deal, string $to, ?string $reason, ?int $userId): ?string
    {
        if (!isset(Deals::STAGES[$to])) {
            return 'Неизвестный этап.';
        }
        if ($deal['stage'] === $to) {
            return null;
        }
        $reason = trim((string)$reason);
        if ($to === 'lost' && mb_strlen($reason) < 3) {
            return 'Укажите причину отказа.';
        }
        $db = App::db();
        $db->tx(function () use ($deal, $to, $reason, $userId) {
            $now = Db::ts();
            $upd = ['stage' => $to, 'stage_changed_at' => $now, 'updated_at' => $now];
            $upd['closed_at'] = in_array($to, ['paid', 'lost'], true) ? $now : null;
            $upd['lost_reason'] = $to === 'lost' ? mb_substr($reason, 0, 255) : null;
            if ($to !== 'new' && !$deal['first_response_at']) {
                $upd['first_response_at'] = $now;
            }
            App::db()->update('deals', $upd, 'id = ?', [$deal['id']]);
            App::db()->insert('deal_stage_log', ['deal_id' => $deal['id'], 'from_stage' => $deal['stage'], 'to_stage' => $to, 'user_id' => $userId, 'created_at' => $now]);
            Audit::log($userId, 'deal', (int)$deal['id'], 'stage', ['from' => $deal['stage'], 'to' => $to]);
        });
        return null;
    }

    /** Ответ клиенту в Telegram от имени бота. */
    public static function reply(array $deal, string $text, int $userId): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return 'Напишите текст ответа.';
        }
        if (mb_strlen($text) > 3500) {
            return 'Слишком длинный ответ: Telegram принимает до 4096 символов.';
        }
        $c = Contacts::find((int)$deal['contact_id']);
        if (!$c || !$c['telegram_id']) {
            return 'У клиента нет Telegram — свяжитесь по телефону.';
        }
        if (!Contacts::hasConsent((int)$c['id'])) {
            return 'Клиент отозвал согласие на обработку данных — писать ему нельзя.';
        }
        $db = App::db();
        [$mid, $oid] = $db->tx(function () use ($deal, $text, $userId, $c) {
            $mid = Deals::addMessage((int)$deal['id'], 'out', 'crm', 'manager', $text, $userId, 'queued');
            $msg = "💬 <b>Ответ ТСЖ «Пушкинская 15»</b> по заявке {$deal['number']}:\n\n" . htmlspecialchars($text, ENT_QUOTES | ENT_HTML5) . "\n\n<i>Чтобы ответить, просто напишите сюда.</i>";
            $oid = Outbox::enqueue('client_reply', (int)$c['telegram_id'], ['text' => $msg, 'parse_mode' => 'HTML'], $mid, (int)$deal['id']);
            $upd = ['updated_at' => Db::ts()];
            if (!$deal['first_response_at']) {
                $upd['first_response_at'] = Db::ts();
            }
            App::db()->update('deals', $upd, 'id = ?', [$deal['id']]);
            Audit::log($userId, 'deal', (int)$deal['id'], 'reply', ['message_id' => $mid]);
            return [$mid, $oid];
        });
        if ($deal['stage'] === 'new') {
            self::move(self::fresh($deal), 'contacted', null, $userId);
        }
        try {
            Telegram::flushOutbox(1, [$oid]);   // отправляем сразу именно этот ответ; если Telegram недоступен — уйдёт по cron
        } catch (Throwable $e) {
            Log::error('reply_flush_failed', ['err' => $e->getMessage()]);
        }
        return null;
    }

    public static function note(array $deal, string $text, int $userId): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return 'Пустая заметка.';
        }
        Deals::addMessage((int)$deal['id'], 'note', 'crm', 'manager', $text, $userId);
        Audit::log($userId, 'deal', (int)$deal['id'], 'note');
        return null;
    }

    public static function update(array $deal, array $in, int $userId): ?string
    {
        $upd = [];
        if (array_key_exists('amount', $in)) {
            $raw = str_replace([' ', ','], ['', '.'], trim((string)$in['amount']));
            if ($raw !== '' && (!is_numeric($raw) || (float)$raw < 0 || (float)$raw > 99999999)) {
                return 'Сумма — число от 0, например 1500 или 1500,50.';
            }
            $upd['amount'] = $raw === '' ? null : number_format((float)$raw, 2, '.', '');
        }
        if (array_key_exists('subject', $in)) {
            $upd['subject'] = mb_substr(trim((string)$in['subject']), 0, 200) ?: null;
        }
        if (!$upd) {
            return null;
        }
        $changed = [];
        foreach ($upd as $k => $v) {
            if ((string)$deal[$k] !== (string)$v) {
                $changed[$k] = $k === 'amount' ? ['from' => $deal[$k], 'to' => $v] : true;
            }
        }
        if (!$changed) {
            return null;
        }
        $upd['updated_at'] = Db::ts();
        App::db()->update('deals', $upd, 'id = ?', [$deal['id']]);
        Audit::log($userId, 'deal', (int)$deal['id'], 'update', $changed);
        return null;
    }

    public static function assign(array $deal, ?int $managerId, int $byUserId): ?string
    {
        if ($managerId && !App::db()->value('SELECT 1 FROM users WHERE id = ? AND active = 1', [$managerId])) {
            return 'Сотрудник не найден или отключён.';
        }
        if ((int)$deal['manager_id'] === (int)$managerId) {
            return null;
        }
        App::db()->tx(function () use ($deal, $managerId, $byUserId) {
            App::db()->update('deals', ['manager_id' => $managerId, 'updated_at' => Db::ts()], 'id = ?', [$deal['id']]);
            // открытые задачи прежнего ответственного переходят новому (задачи руководителя — удаление данных, дубли — остаются у него)
            App::db()->run("UPDATE tasks SET user_id = ? WHERE deal_id = ? AND done_at IS NULL AND kind IN ('callback', 'manual', 'question') AND (user_id = ? OR user_id IS NULL)",
                [$managerId, $deal['id'], $deal['manager_id']]);
            Audit::log($byUserId, 'deal', (int)$deal['id'], 'assign', ['from' => $deal['manager_id'], 'to' => $managerId]);
            if ($managerId && $managerId !== $byUserId) {
                Outbox::notifyManager(self::fresh($deal), 'new', 'передана вам в CRM');
            }
        });
        return null;
    }

    public static function addTask(array $deal, int $forUser, string $title, string $due, int $byUser): ?string
    {
        $title = trim($title);
        if ($title === '') {
            return 'Опишите задачу.';
        }
        try {
            $d = new DateTimeImmutable($due);
        } catch (Exception) {
            return 'Укажите срок задачи.';
        }
        Deals::addTask((int)$deal['id'], $forUser, 'manual', $title, $d);
        Audit::log($byUser, 'deal', (int)$deal['id'], 'task_add');
        return null;
    }

    public static function completeTask(int $taskId, int $userId, ?int $dealId = null): bool
    {
        $t = App::db()->one('SELECT * FROM tasks WHERE id = ?', [$taskId]);
        if (!$t || $t['done_at'] || ($dealId !== null && (int)$t['deal_id'] !== $dealId)) {
            return false;
        }
        if (!CrmApp::isAdmin() && (int)$t['user_id'] !== $userId) {
            return false;
        }
        App::db()->update('tasks', ['done_at' => Db::ts(), 'done_by' => $userId], 'id = ?', [$taskId]);
        Audit::log($userId, 'deal', $t['deal_id'] ? (int)$t['deal_id'] : null, 'task_done', ['task_id' => $taskId]);
        return true;
    }

    public static function fresh(array $deal): array
    {
        return Deals::find((int)$deal['id']);
    }

    /** Напоминания о задачах в Telegram сотруднику (cron). */
    public static function remindTasks(): int
    {
        $db = App::db();
        $rows = $db->all(
            'SELECT t.*, u.telegram_id, d.number FROM tasks t JOIN users u ON u.id = t.user_id LEFT JOIN deals d ON d.id = t.deal_id
             WHERE t.done_at IS NULL AND t.notified_at IS NULL AND t.due_at <= ? AND u.active = 1 AND u.telegram_id IS NOT NULL LIMIT 100',
            [Db::ts()]
        );
        foreach ($rows as $t) {
            $payload = ['text' => '⏰ Задача' . ($t['number'] ? ' по заявке ' . $t['number'] : '') . ":\n" . $t['title']];
            $url = App::siteUrl() . ($t['deal_id'] ? '/crm/deal.php?id=' . (int)$t['deal_id'] : '/crm/tasks.php');
            if (str_starts_with($url, 'https://')) {
                $payload['reply_markup'] = ['inline_keyboard' => [[['text' => 'Открыть в CRM', 'url' => $url]]]];
            }
            Outbox::enqueue('task_reminder', (int)$t['telegram_id'], $payload, null, $t['deal_id'] ? (int)$t['deal_id'] : null);
            $db->update('tasks', ['notified_at' => Db::ts()], 'id = ?', [$t['id']]);
        }
        return count($rows);
    }
}
