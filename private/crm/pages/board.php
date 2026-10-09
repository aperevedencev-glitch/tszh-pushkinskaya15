<?php
declare(strict_types=1);
/** Канбан: открытые сделки + закрытые за 30 дней. Перетаскивание и выпадающий список этапа. */

$db = App::db();
$e = 'CrmApp::e';
[$scope, $params] = CrmApp::dealScope();
$where = ["$scope", "(d.stage IN ('new','contacted','invoiced') OR d.closed_at >= ?)"];
$params[] = Db::ts(App::now()->modify('-30 days'));

$q = trim((string)($_GET['q'] ?? ''));
$mgr = (string)($_GET['manager'] ?? '');
$chan = (string)($_GET['channel'] ?? '');
if ($q !== '') {
    $digits = preg_replace('/\D/', '', $q);
    $w = ['d.number LIKE ?', 'c.name LIKE ?', 'd.subject LIKE ?'];
    $params2 = ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%'];
    if (strlen($digits) >= 4) {
        $w[] = 'c.phone LIKE ?';
        $params2[] = '%' . $digits . '%';
    }
    $where[] = '(' . implode(' OR ', $w) . ')';
    array_push($params, ...$params2);
}
if (CrmApp::isAdmin() && $mgr !== '') {
    if ($mgr === 'none') {
        $where[] = 'd.manager_id IS NULL';
    } else {
        $where[] = 'd.manager_id = ?';
        $params[] = (int)$mgr;
    }
}
if (in_array($chan, ['site_form', 'bot', 'site_bot'], true)) {
    $where[] = '(SELECT s.channel FROM sources s WHERE s.contact_id = d.contact_id ORDER BY s.id LIMIT 1) = ?';
    $params[] = $chan;
}

$rows = $db->all(
    'SELECT d.*, c.name AS contact_name, c.anonymized_at, u.name AS manager_name,
        (SELECT m.direction FROM messages m WHERE m.deal_id = d.id AND m.direction <> \'note\' ORDER BY m.id DESC LIMIT 1) AS last_dir,
        (SELECT COUNT(*) FROM tasks t WHERE t.deal_id = d.id AND t.done_at IS NULL AND t.due_at < ?) AS overdue
     FROM deals d JOIN contacts c ON c.id = d.contact_id LEFT JOIN users u ON u.id = d.manager_id
     WHERE ' . implode(' AND ', $where) . ' ORDER BY d.updated_at DESC LIMIT 600',
    array_merge([Db::ts()], $params)
);

$cols = array_fill_keys(array_keys(Deals::STAGES), []);
foreach ($rows as $r) {
    $cols[$r['stage']][] = $r;
}
$users = $db->all("SELECT id, name FROM users WHERE active = 1 ORDER BY name");

$ago = static function (string $ts): string {
    $s = App::now()->getTimestamp() - strtotime($ts);
    return match (true) {
        $s < 3600 => max(1, intdiv($s, 60)) . ' мин',
        $s < 86400 => intdiv($s, 3600) . ' ч',
        default => intdiv($s, 86400) . ' дн',
    };
};

$html = '<div class="page-head"><h1>Канбан</h1>
<form class="filters" method="get" role="search">
  <label class="sr" for="b-q">Поиск</label><input class="inp" id="b-q" name="q" value="' . $e($q) . '" placeholder="Имя, телефон, L-номер">';
if (CrmApp::isAdmin()) {
    $html .= '<label class="sr" for="b-m">Менеджер</label><select class="inp" id="b-m" name="manager"><option value="">Все менеджеры</option><option value="none"' . ($mgr === 'none' ? ' selected' : '') . '>Не назначен</option>';
    foreach ($users as $u) {
        $html .= '<option value="' . (int)$u['id'] . '"' . ($mgr === (string)$u['id'] ? ' selected' : '') . '>' . $e($u['name']) . '</option>';
    }
    $html .= '</select>';
}
$html .= '<label class="sr" for="b-c">Источник</label><select class="inp" id="b-c" name="channel"><option value="">Все источники</option>';
foreach (['site_form' => 'Сайт', 'bot' => 'Telegram-бот', 'site_bot' => 'Сайт → бот'] as $k => $v) {
    $html .= '<option value="' . $k . '"' . ($chan === $k ? ' selected' : '') . '>' . $v . '</option>';
}
$html .= '</select><button class="btn ghost" type="submit">Показать</button></form></div>
<p class="muted small">Перетащите карточку в другую колонку или выберите этап в карточке. Закрытые сделки показаны за 30 дней — остальные в разделе «Заявки».</p>
<div class="board" id="board">';

foreach ($cols as $stage => $deals) {
    $sum = array_sum(array_map(static fn($d) => (float)$d['amount'], $deals));
    $html .= '<section class="col" data-stage="' . $stage . '" aria-labelledby="col-' . $stage . '">
      <header><h2 id="col-' . $stage . '">' . CrmApp::stageLabel($stage) . '</h2>
      <p class="col-meta"><span class="count" data-count>' . count($deals) . '</span> · <span data-sum>' . ($sum ? CrmApp::money((string)$sum) : '0 ₽') . '</span></p></header>
      <div class="cards">';
    foreach ($deals as $d) {
        $mine = CrmApp::canEdit($d);
        $flags = '';
        if ((int)$d['is_off_hours']) {
            $flags .= '<span class="tag night" title="Пришла в нерабочее время">ночь</span>';
        }
        if ($d['last_dir'] === 'in' && in_array($d['stage'], Deals::OPEN, true)) {
            $flags .= '<span class="tag new" title="Последнее сообщение — от клиента">ждёт ответа</span>';
        }
        if ((int)$d['overdue']) {
            $flags .= '<span class="tag late">просрочена задача</span>';
        }
        $html .= '<article class="card deal' . ($mine ? '' : ' foreign') . '" data-id="' . (int)$d['id'] . '" data-amount="' . (float)$d['amount'] . '"' . ($mine ? ' draggable="true"' : '') . '>
          <a class="deal-link" href="/crm/deal.php?id=' . (int)$d['id'] . '"><b class="num">' . $e($d['number']) . '</b> ' . $e($d['anonymized_at'] ? 'Обезличено' : ($d['contact_name'] ?: 'Без имени')) . '</a>
          <p class="subj">' . $e(mb_strimwidth((string)$d['subject'], 0, 90, '…')) . '</p>
          <p class="meta">' . $flags . '<span>' . $ago($d['created_at']) . '</span>' . ($d['amount'] !== null ? '<span>' . CrmApp::money($d['amount']) . '</span>' : '') . '<span>' . $e($d['manager_name'] ?: 'не назначен') . '</span></p>';
        if ($mine) {
            $html .= '<label class="sr" for="mv-' . (int)$d['id'] . '">Этап заявки ' . $e($d['number']) . '</label><select class="inp mini move" id="mv-' . (int)$d['id'] . '" data-id="' . (int)$d['id'] . '">';
            foreach (Deals::STAGES as $k => $v) {
                $html .= '<option value="' . $k . '"' . ($k === $d['stage'] ? ' selected' : '') . '>' . $v . '</option>';
            }
            $html .= '</select>';
        } else {
            $html .= '<form method="post" action="/crm/deal.php?id=' . (int)$d['id'] . '">' . CrmApp::csrfField() . '<input type="hidden" name="action" value="take"><button class="link" type="submit">Взять себе</button></form>';
        }
        $html .= '</article>';
    }
    $html .= '</div></section>';
}
$html .= '</div>
<dialog id="lost-dialog" aria-labelledby="lost-title">
  <form method="dialog" class="form" id="lost-form">
    <h2 id="lost-title">Причина отказа</h2>
    <p class="muted small">Обязательно для этапа «Отказ». Попадёт в отчёты.</p>
    <label class="sr" for="lost-reason">Причина</label>
    <textarea class="inp" id="lost-reason" required minlength="3" maxlength="255" placeholder="Например: вопрос решён без участия ТСЖ"></textarea>
    <div class="row"><button class="btn" value="ok">Сохранить</button><button class="btn ghost" value="cancel" formnovalidate>Отмена</button></div>
  </form>
</dialog>';

echo CrmApp::layout('Канбан', $html, 'board');
