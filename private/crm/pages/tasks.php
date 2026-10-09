<?php
declare(strict_types=1);
/** Задачи: просроченные подсвечены, напоминания приходят в Telegram (cron). */

$db = App::db();
$e = 'CrmApp::e';
$uid = CrmApp::uid();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ok = CrmDeals::completeTask((int)($_POST['task_id'] ?? 0), $uid);
    CrmApp::flash($ok ? 'Задача выполнена.' : 'Эту задачу закрыть нельзя.', $ok ? 'ok' : 'err');
    CrmApp::redirect('/crm/tasks.php' . (!empty($_POST['who']) ? '?who=' . rawurlencode((string)$_POST['who']) : ''));
}

$who = (string)($_GET['who'] ?? 'me');
$params = [];
$cond = 't.user_id = ?';
$params[] = $uid;
if (CrmApp::isAdmin()) {
    if ($who === 'all') {
        $cond = '1=1';
        $params = [];
    } elseif (ctype_digit($who)) {
        $params = [(int)$who];
    }
}
$now = Db::ts();
$open = $db->all("SELECT t.*, d.number, u.name AS user_name FROM tasks t LEFT JOIN deals d ON d.id = t.deal_id LEFT JOIN users u ON u.id = t.user_id
    WHERE $cond AND t.done_at IS NULL ORDER BY t.due_at", $params);
$done = $db->all("SELECT t.*, d.number, u.name AS user_name FROM tasks t LEFT JOIN deals d ON d.id = t.deal_id LEFT JOIN users u ON u.id = t.user_id
    WHERE $cond AND t.done_at >= ? ORDER BY t.done_at DESC LIMIT 50", array_merge($params, [Db::ts(App::now()->modify('-7 days'))]));
$kinds = ['callback' => 'Связаться', 'delete_request' => 'Удаление данных', 'manual' => 'Задача', 'question' => 'Вопрос'];

$row = static function (array $t, bool $isDone) use ($e, $now, $kinds, $who): string {
    $late = !$isDone && $t['due_at'] < $now;
    $link = $t['deal_id'] ? '<a class="num" href="/crm/deal.php?id=' . (int)$t['deal_id'] . '">' . $e($t['number']) . '</a>' : '';
    if ($t['kind'] === 'delete_request' && CrmApp::isAdmin() && preg_match('/#(\d+)\)/', $t['title'], $m)) {
        $link .= ' <a href="/crm/contact.php?id=' . (int)$m[1] . '">Открыть запрос</a>';
    }
    $h = '<tr class="' . ($late ? 'late' : '') . '"><td><span class="tag' . ($t['kind'] === 'delete_request' ? ' late' : '') . '">' . $e($kinds[$t['kind']] ?? $t['kind']) . '</span></td><td>' . $e($t['title']) . '</td><td>' . $link . '</td>'
        . '<td>' . CrmApp::dt($isDone ? $t['done_at'] : $t['due_at']) . ($late ? ' <b class="late-t">просрочена</b>' : '') . '</td><td>' . $e($t['user_name'] ?? '—') . '</td><td>';
    if (!$isDone) {
        $h .= '<form method="post">' . CrmApp::csrfField() . '<input type="hidden" name="task_id" value="' . (int)$t['id'] . '"><input type="hidden" name="who" value="' . $e($who) . '"><button class="btn mini" type="submit">Выполнена</button></form>';
    }
    return $h . '</td></tr>';
};

$html = '<div class="page-head"><h1>Задачи</h1>';
if (CrmApp::isAdmin()) {
    $html .= '<form class="filters" method="get"><label for="w">Чьи</label><select class="inp" id="w" name="who"><option value="me"' . ($who === 'me' ? ' selected' : '') . '>Мои</option><option value="all"' . ($who === 'all' ? ' selected' : '') . '>Все</option>';
    foreach ($db->all('SELECT id, name FROM users WHERE active = 1 ORDER BY name') as $u) {
        $html .= '<option value="' . (int)$u['id'] . '"' . ($who === (string)$u['id'] ? ' selected' : '') . '>' . $e($u['name']) . '</option>';
    }
    $html .= '</select><button class="btn ghost" type="submit">Показать</button></form>';
}
$html .= '</div><p class="muted small">Напоминание о задаче приходит в Telegram, когда наступает срок (если Telegram привязан в профиле).</p>';
$head = '<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Тип</th><th>Задача</th><th>Заявка</th><th>Срок</th><th>Кому</th><th><span class="sr">Действие</span></th></tr></thead><tbody>';
$html .= '<h2>Открытые (' . count($open) . ')</h2>' . $head;
foreach ($open as $t) {
    $html .= $row($t, false);
}
if (!$open) {
    $html .= '<tr><td colspan="6" class="muted">Открытых задач нет.</td></tr>';
}
$html .= '</tbody></table></div><h2>Выполнены за неделю</h2>' . str_replace('<th>Срок</th>', '<th>Выполнена</th>', $head);
foreach ($done as $t) {
    $html .= $row($t, true);
}
if (!$done) {
    $html .= '<tr><td colspan="6" class="muted">Пока ничего.</td></tr>';
}
$html .= '</tbody></table></div>';
echo CrmApp::layout('Задачи', $html, 'tasks');
