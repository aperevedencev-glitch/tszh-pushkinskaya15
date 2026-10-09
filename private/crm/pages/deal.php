<?php
declare(strict_types=1);
/** Карточка сделки: контакт, источник и UTM, вся переписка, ответ в Telegram, заметки, задачи, история. */

$db = App::db();
$e = 'CrmApp::e';
$id = (int)($_GET['id'] ?? 0);
$deal = Deals::find($id);
if (!$deal) {
    http_response_code(404);
    echo CrmApp::layout('Не найдено', '<div class="empty"><h1>Заявка не найдена</h1><p><a class="btn" href="/crm/">На канбан</a></p></div>');
    return;
}
if (!CrmApp::canView($deal)) {
    Log::event('crm_deal_forbidden', ['deal' => $id, 'user' => CrmApp::uid()]);
    CrmApp::deny('Эта заявка закреплена за другим менеджером.');
}
$uid = CrmApp::uid();
$json = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

// ---------------------------------------------------------------- действия
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $err = null;
    if ($action === 'take') {
        if (!CrmApp::isAdmin() && !($deal['stage'] === 'new' || !$deal['manager_id'])) {
            CrmApp::deny();
        }
        $err = CrmDeals::assign($deal, $uid, $uid);
        $msg = 'Заявка закреплена за вами.';
    } elseif (!CrmApp::canEdit($deal) && !($action === 'assign' && CrmApp::isAdmin())) {
        if ($json) {
            CrmApp::json(['ok' => false, 'error' => 'Это не ваша заявка. Сначала нажмите «Взять себе».'], 403);
        }
        CrmApp::deny('Изменять заявку может только ответственный менеджер или руководитель.');
    } else {
        switch ($action) {
            case 'move':
                $err = CrmDeals::move($deal, (string)($_POST['stage'] ?? ''), (string)($_POST['reason'] ?? ''), $uid);
                $msg = 'Этап изменён.';
                break;
            case 'reply':
                $err = CrmDeals::reply($deal, (string)($_POST['text'] ?? ''), $uid);
                $msg = 'Ответ отправлен в Telegram.';
                break;
            case 'note':
                $err = CrmDeals::note($deal, (string)($_POST['text'] ?? ''), $uid);
                $msg = 'Заметка добавлена.';
                break;
            case 'update':
                $err = CrmDeals::update($deal, ['amount' => $_POST['amount'] ?? '', 'subject' => $_POST['subject'] ?? ''], $uid);
                $msg = 'Сохранено.';
                break;
            case 'assign':
                if (!CrmApp::isAdmin()) {
                    CrmApp::deny();
                }
                $err = CrmDeals::assign($deal, ($_POST['manager_id'] ?? '') === '' ? null : (int)$_POST['manager_id'], $uid);
                $msg = 'Ответственный изменён.';
                break;
            case 'task_add':
                $for = CrmApp::isAdmin() && !empty($_POST['user_id']) ? (int)$_POST['user_id'] : $uid;
                $err = CrmDeals::addTask($deal, $for, (string)($_POST['title'] ?? ''), (string)($_POST['due'] ?? ''), $uid);
                $msg = 'Задача добавлена.';
                break;
            case 'task_done':
                $err = CrmDeals::completeTask((int)($_POST['task_id'] ?? 0), $uid) ? null : 'Задачу нельзя закрыть.';
                $msg = 'Задача выполнена.';
                break;
            default:
                $err = 'Неизвестное действие.';
        }
    }
    if ($json) {
        $fresh = Deals::find($id);
        CrmApp::json($err ? ['ok' => false, 'error' => $err] : ['ok' => true, 'stage' => $fresh['stage']], $err ? 422 : 200);
    }
    CrmApp::flash($err ?? $msg, $err ? 'err' : 'ok');
    CrmApp::redirect('/crm/deal.php?id=' . $id . ($action === 'reply' || $action === 'note' ? '#feed' : ''));
}

// ---------------------------------------------------------------- данные
$c = Contacts::find((int)$deal['contact_id']);
$consent = $db->one('SELECT action, channel, created_at FROM consents WHERE contact_id = ? ORDER BY id DESC LIMIT 1', [$c['id']]);
$canEdit = CrmApp::canEdit($deal);
$manager = $deal['manager_id'] ? $db->one('SELECT name FROM users WHERE id = ?', [$deal['manager_id']]) : null;
$users = $db->all('SELECT id, name, role FROM users WHERE active = 1 ORDER BY name');
$names = array_column($db->all('SELECT id, name FROM users'), 'name', 'id');
$sources = $db->all('SELECT * FROM sources WHERE deal_id = ? ORDER BY id', [$id]);
$first = $db->one('SELECT * FROM sources WHERE contact_id = ? ORDER BY id LIMIT 1', [$c['id']]);
$tasks = $db->all('SELECT * FROM tasks WHERE deal_id = ? ORDER BY done_at IS NOT NULL, due_at', [$id]);
$other = $db->all('SELECT id, number, stage, created_at FROM deals WHERE contact_id = ? AND id <> ? ORDER BY id DESC', [$c['id'], $id]);

$feed = [];
foreach ($db->all('SELECT * FROM messages WHERE deal_id = ? ORDER BY id', [$id]) as $m) {
    $feed[] = ['t' => $m['created_at'], 'k' => 'm', 'm' => $m];
}
foreach ($db->all('SELECT * FROM deal_stage_log WHERE deal_id = ? ORDER BY id', [$id]) as $l) {
    $feed[] = ['t' => $l['created_at'], 'k' => 's', 'l' => $l];
}
usort($feed, static fn($a, $b) => [$a['t'], $a['k'] === 's' ? 0 : 1] <=> [$b['t'], $b['k'] === 's' ? 0 : 1]);
$audit = $db->all("SELECT * FROM audit_log WHERE entity = 'deal' AND entity_id = ? ORDER BY id DESC LIMIT 50", [$id]);

$srcLabel = ['site_form' => 'Сайт, форма', 'bot' => 'Telegram-бот', 'site_bot' => 'Сайт → бот'];
$src = static function (?array $s) use ($e, $srcLabel): string {
    if (!$s) {
        return '—';
    }
    $parts = [$srcLabel[$s['channel']] ?? $s['channel']];
    foreach (['utm_source' => 'source', 'utm_medium' => 'medium', 'utm_campaign' => 'campaign', 'utm_content' => 'content', 'utm_term' => 'term'] as $k => $l) {
        if ($s[$k]) {
            $parts[] = $l . ': ' . $e($s[$k]);
        }
    }
    if ($s['bot_start_tag']) {
        $parts[] = 'метка бота: ' . $e($s['bot_start_tag']);
    }
    if ($s['referrer']) {
        $parts[] = 'откуда: ' . $e($s['referrer']);
    }
    if ($s['landing_page']) {
        $parts[] = 'страница: ' . $e($s['landing_page']);
    }
    return implode(' · ', $parts);
};

// ---------------------------------------------------------------- вывод
$anon = (bool)$c['anonymized_at'];
$html = '<p class="crumbs"><a href="/crm/">Канбан</a> / ' . $e($deal['number']) . '</p>
<div class="page-head"><h1><span class="num">' . $e($deal['number']) . '</span> · ' . $e($anon ? 'Обезличено' : ($c['name'] ?: 'Без имени')) . '</h1>
<span class="stage s-' . $e($deal['stage']) . '">' . CrmApp::stageLabel($deal['stage']) . '</span></div>';
if (!$canEdit) {
    $html .= '<div class="notice">Заявка у менеджера ' . $e($manager['name'] ?? '—') . '. Вы видите её, потому что она во входящих. '
        . '<form method="post" class="inline">' . CrmApp::csrfField() . '<input type="hidden" name="action" value="take"><button class="btn" type="submit">Взять себе</button></form></div>';
}

$html .= '<div class="deal-grid"><div class="main-col">';

// Лента
$html .= '<section class="card" id="feed" aria-labelledby="h-feed"><h2 id="h-feed">Переписка и события</h2><ol class="feed">';
foreach ($feed as $it) {
    if ($it['k'] === 's') {
        $l = $it['l'];
        $html .= '<li class="ev"><time>' . CrmApp::dt($l['created_at']) . '</time> Этап: ' . ($l['from_stage'] ? CrmApp::stageLabel($l['from_stage']) . ' → ' : '') . '<b>' . CrmApp::stageLabel($l['to_stage']) . '</b>'
            . ($l['user_id'] ? ' · ' . $e($names[$l['user_id']] ?? '') : '') . '</li>';
        continue;
    }
    $m = $it['m'];
    $who = match ($m['author']) {
        'client' => 'Клиент · ' . ($m['channel'] === 'bot' ? 'Telegram' : 'сайт'),
        'manager' => ($names[$m['user_id']] ?? 'Менеджер') . ($m['direction'] === 'note' ? ' · заметка' : ' · ответ в Telegram'),
        'bot' => 'Бот',
        default => 'Система',
    };
    $status = '';
    if ($m['direction'] === 'out') {
        $status = ['queued' => '<span class="tag">в очереди</span>', 'sent' => '<span class="tag okk">доставлено в Telegram</span>', 'failed' => '<span class="tag late">не доставлено</span>'][$m['delivery']] ?? '';
    }
    $html .= '<li class="msg ' . $e($m['direction']) . '"><p class="who">' . $e($who) . ' <time>' . CrmApp::dt($m['created_at']) . '</time> ' . $status . '</p><div class="text">' . nl2br($e($m['text'])) . '</div></li>';
}
$html .= '</ol>';

if ($canEdit) {
    $why = null;
    if ($anon) {
        $why = 'Данные обезличены.';
    } elseif (!$c['telegram_id']) {
        $why = 'У клиента нет Telegram — свяжитесь по телефону и отметьте результат заметкой.';
    } elseif (($consent['action'] ?? '') !== 'given') {
        $why = 'Клиент отозвал согласие — писать ему нельзя.';
    }
    $html .= '<form method="post" class="form reply">' . CrmApp::csrfField() . '<input type="hidden" name="action" value="reply">
      <label for="r-text">Ответить в Telegram</label>
      <textarea class="inp" id="r-text" name="text" maxlength="3500" ' . ($why ? 'disabled aria-describedby="r-why"' : 'required') . ' placeholder="Сообщение уйдёт клиенту от имени бота"></textarea>'
      . ($why ? '<p class="muted small" id="r-why">' . $e($why) . '</p>' : '') . '
      <button class="btn" type="submit"' . ($why ? ' disabled' : '') . '>Отправить клиенту</button></form>
    <form method="post" class="form">' . CrmApp::csrfField() . '<input type="hidden" name="action" value="note">
      <label for="n-text">Заметка (видят только сотрудники)</label>
      <textarea class="inp" id="n-text" name="text" maxlength="4000" required placeholder="Например: позвонил, договорились на четверг"></textarea>
      <button class="btn ghost" type="submit">Добавить заметку</button></form>';
}
$html .= '</section>';

// История изменений
$html .= '<section class="card" aria-labelledby="h-audit"><h2 id="h-audit">История изменений</h2>';
if ($audit) {
    $html .= '<ul class="audit">';
    $acts = ['create' => 'создана', 'stage' => 'смена этапа', 'reply' => 'ответ клиенту', 'note' => 'заметка', 'update' => 'изменены поля', 'assign' => 'смена ответственного', 'task_add' => 'добавлена задача', 'task_done' => 'задача выполнена'];
    foreach ($audit as $a) {
        $d = json_decode((string)$a['details'], true) ?: [];
        $det = '';
        if ($a['action'] === 'stage') {
            $det = ': ' . CrmApp::stageLabel($d['from'] ?? '') . ' → ' . CrmApp::stageLabel($d['to'] ?? '');
        } elseif ($a['action'] === 'assign') {
            $det = ': ' . $e($names[$d['from'] ?? 0] ?? 'не назначен') . ' → ' . $e($names[$d['to'] ?? 0] ?? 'не назначен');
        } elseif ($a['action'] === 'update' && isset($d['amount'])) {
            $det = ': сумма ' . CrmApp::money($d['amount']['from'] ?? null) . ' → ' . CrmApp::money($d['amount']['to'] ?? null);
        }
        $html .= '<li><time>' . CrmApp::dt($a['created_at']) . '</time> ' . $e($a['user_id'] ? ($names[$a['user_id']] ?? '—') : 'система') . ' — ' . $e($acts[$a['action']] ?? $a['action']) . $det . '</li>';
    }
    $html .= '</ul>';
} else {
    $html .= '<p class="muted">Пока без изменений.</p>';
}
$html .= '</section></div><aside class="side-col">';

// Параметры сделки
$html .= '<section class="card" aria-labelledby="h-deal"><h2 id="h-deal">Заявка</h2>';
if ($canEdit) {
    $html .= '<form method="post" class="form" data-move-form>' . CrmApp::csrfField() . '<input type="hidden" name="action" value="move">
      <label for="d-stage">Этап</label><select class="inp" id="d-stage" name="stage">';
    foreach (Deals::STAGES as $k => $v) {
        $html .= '<option value="' . $k . '"' . ($k === $deal['stage'] ? ' selected' : '') . '>' . $v . '</option>';
    }
    $html .= '</select><div data-reason' . ($deal['stage'] === 'lost' ? '' : ' hidden') . '><label for="d-reason">Причина отказа</label><input class="inp" id="d-reason" name="reason" maxlength="255" value="' . $e((string)$deal['lost_reason']) . '"></div>
      <button class="btn ghost" type="submit">Сменить этап</button></form>
    <form method="post" class="form">' . CrmApp::csrfField() . '<input type="hidden" name="action" value="update">
      <label for="d-subj">Тема</label><input class="inp" id="d-subj" name="subject" maxlength="200" value="' . $e((string)$deal['subject']) . '">
      <label for="d-amount">Сумма, ₽</label><input class="inp" id="d-amount" name="amount" inputmode="decimal" value="' . $e($deal['amount'] !== null ? str_replace('.', ',', rtrim(rtrim(number_format((float)$deal['amount'], 2, '.', ''), '0'), '.')) : '') . '" placeholder="0">
      <button class="btn ghost" type="submit">Сохранить</button></form>';
} else {
    $html .= '<p>Сумма: ' . CrmApp::money($deal['amount']) . '</p>';
}
if ($deal['lost_reason']) {
    $html .= '<p class="muted small">Причина отказа: ' . $e($deal['lost_reason']) . '</p>';
}
$html .= '<dl class="kv"><dt>Создана</dt><dd>' . CrmApp::dt($deal['created_at']) . ((int)$deal['is_off_hours'] ? ' <span class="tag night">нерабочее время</span>' : '') . '</dd>'
    . '<dt>Первый ответ</dt><dd>' . ($deal['first_response_at'] ? CrmApp::dt($deal['first_response_at']) . ' (' . Reports::minutes((new WorkHours())->workingMinutes(new DateTimeImmutable($deal['created_at']), new DateTimeImmutable($deal['first_response_at']))) . ' рабочего времени)' : '—') . '</dd>'
    . '<dt>Ответственный</dt><dd>' . $e($manager['name'] ?? 'не назначен') . '</dd></dl>';
if (CrmApp::isAdmin()) {
    $html .= '<form method="post" class="form">' . CrmApp::csrfField() . '<input type="hidden" name="action" value="assign"><label for="d-mgr">Передать</label><select class="inp" id="d-mgr" name="manager_id"><option value="">— не назначен —</option>';
    foreach ($users as $u) {
        $html .= '<option value="' . (int)$u['id'] . '"' . ((int)$deal['manager_id'] === (int)$u['id'] ? ' selected' : '') . '>' . $e($u['name']) . '</option>';
    }
    $html .= '</select><button class="btn ghost" type="submit">Назначить</button></form>';
}
$html .= '</section>';

// Контакт
$html .= '<section class="card" aria-labelledby="h-contact"><h2 id="h-contact">Контакт</h2>';
if ($anon) {
    $html .= '<p class="muted">Персональные данные обезличены ' . CrmApp::dt($c['anonymized_at'], false) . '.</p>';
} else {
    $html .= '<dl class="kv"><dt>Имя</dt><dd>' . $e($c['name'] ?: '—') . '</dd>'
        . '<dt>Телефон</dt><dd>' . ($c['phone'] ? '<a href="tel:' . $e($c['phone']) . '">' . $e($c['phone']) . '</a>' : '—') . '</dd>'
        . '<dt>Telegram</dt><dd>' . ($c['telegram_username'] ? '<a href="https://t.me/' . $e($c['telegram_username']) . '" rel="noopener noreferrer" target="_blank">@' . $e($c['telegram_username']) . '</a>' : ($c['telegram_id'] ? 'есть (без ника)' : '—')) . '</dd>'
        . '<dt>Согласие</dt><dd>' . ($consent ? ($consent['action'] === 'given' ? '✓ дано' : '✗ отозвано') . ', ' . ($consent['channel'] === 'bot' ? 'бот' : 'сайт') . ', ' . CrmApp::dt($consent['created_at'], false) : 'нет') . '</dd></dl>';
}
if (CrmApp::isAdmin()) {
    $html .= '<p><a href="/crm/contact.php?id=' . (int)$c['id'] . '">Согласия и удаление данных</a></p>';
}
if ($other) {
    $html .= '<p class="small muted">Другие заявки: ';
    foreach ($other as $o) {
        $html .= '<a href="/crm/deal.php?id=' . (int)$o['id'] . '">' . $e($o['number']) . '</a> (' . CrmApp::stageLabel($o['stage']) . ') ';
    }
    $html .= '</p>';
}
$html .= '</section>';

// Источник
$html .= '<section class="card" aria-labelledby="h-src"><h2 id="h-src">Источник</h2><dl class="kv"><dt>Первое касание</dt><dd>' . $src($first) . ($first ? ' · ' . CrmApp::dt($first['created_at'], false) : '') . '</dd>';
if ($sources) {
    $last = end($sources);
    $html .= '<dt>Последнее касание</dt><dd>' . $src($last) . ' · ' . CrmApp::dt($last['created_at'], false) . '</dd>';
}
$html .= '</dl></section>';

// Задачи
$html .= '<section class="card" aria-labelledby="h-tasks"><h2 id="h-tasks">Задачи</h2><ul class="tasks">';
foreach ($tasks as $t) {
    $late = !$t['done_at'] && $t['due_at'] < Db::ts();
    $html .= '<li class="' . ($t['done_at'] ? 'done' : ($late ? 'late' : '')) . '"><span>' . $e($t['title']) . '</span><small>' . ($t['done_at'] ? 'выполнена ' . CrmApp::dt($t['done_at']) : 'срок ' . CrmApp::dt($t['due_at']) . ($late ? ' · просрочена' : '')) . ' · ' . $e($names[$t['user_id']] ?? '—') . '</small>';
    if (!$t['done_at'] && $canEdit) {
        $html .= '<form method="post">' . CrmApp::csrfField() . '<input type="hidden" name="action" value="task_done"><input type="hidden" name="task_id" value="' . (int)$t['id'] . '"><button class="link" type="submit">Выполнена</button></form>';
    }
    $html .= '</li>';
}
if (!$tasks) {
    $html .= '<li class="muted">Задач нет.</li>';
}
$html .= '</ul>';
if ($canEdit) {
    $html .= '<form method="post" class="form">' . CrmApp::csrfField() . '<input type="hidden" name="action" value="task_add">
      <label for="t-title">Новая задача</label><input class="inp" id="t-title" name="title" maxlength="255" required placeholder="Перезвонить клиенту">
      <label for="t-due">Срок</label><input class="inp" id="t-due" name="due" type="datetime-local" required value="' . App::now()->modify('+1 day')->format('Y-m-d\T10:00') . '">';
    if (CrmApp::isAdmin()) {
        $html .= '<label for="t-user">Кому</label><select class="inp" id="t-user" name="user_id">';
        foreach ($users as $u) {
            $html .= '<option value="' . (int)$u['id'] . '"' . ((int)$u['id'] === (int)($deal['manager_id'] ?: $uid) ? ' selected' : '') . '>' . $e($u['name']) . '</option>';
        }
        $html .= '</select>';
    }
    $html .= '<button class="btn ghost" type="submit">Добавить задачу</button></form>';
}
$html .= '</section></aside></div>';

echo CrmApp::layout($deal['number'], $html, 'board');
