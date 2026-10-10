<?php
declare(strict_types=1);
/** Список заявок: поиск по имени, телефону, номеру; фильтры по этапу, источнику, менеджеру, дате. */

$db = App::db();
$e = 'CrmApp::e';
[$scope, $params] = CrmApp::dealScope();
$where = [$scope];
$g = static fn(string $k) => trim((string)($_GET[$k] ?? ''));

$q = $g('q');
if ($q !== '') {
    $digits = preg_replace('/\D/', '', $q);
    $w = ['d.number LIKE ?', 'c.name LIKE ?', 'd.subject LIKE ?', 'c.telegram_username LIKE ?'];
    $p = array_fill(0, 4, '%' . ltrim($q, '@') . '%');
    if (preg_match('/^L?-?(\d{1,6})$/i', $q, $m)) {
        $w[] = 'd.number = ?';
        $p[] = Deals::number((int)$m[1]);
    }
    if (strlen($digits) >= 4) {
        $w[] = 'c.phone LIKE ?';
        $p[] = '%' . (strlen($digits) === 11 ? substr($digits, 1) : $digits) . '%';
    }
    $where[] = '(' . implode(' OR ', $w) . ')';
    array_push($params, ...$p);
}
$stage = $g('stage');
if (isset(Deals::STAGES[$stage])) {
    $where[] = 'd.stage = ?';
    $params[] = $stage;
} elseif ($stage === 'open') {
    $where[] = "d.stage IN ('new','contacted','invoiced')";
}
$chan = $g('channel');
if (in_array($chan, ['site_form', 'bot', 'site_bot'], true)) {
    $where[] = '(SELECT s.channel FROM sources s WHERE s.contact_id = d.contact_id ORDER BY s.id LIMIT 1) = ?';
    $params[] = $chan;
}
$camp = $g('campaign');
if ($camp !== '') {
    $where[] = 'EXISTS (SELECT 1 FROM sources s WHERE s.contact_id = d.contact_id AND (s.utm_campaign = ? OR s.bot_start_tag = ?))';
    array_push($params, $camp, $camp);
}
$mgr = $g('manager');
if (CrmApp::isAdmin() && $mgr !== '') {
    $where[] = $mgr === 'none' ? 'd.manager_id IS NULL' : 'd.manager_id = ?';
    if ($mgr !== 'none') {
        $params[] = (int)$mgr;
    }
}
foreach (['from' => '>=', 'to' => '<'] as $k => $op) {
    $v = $g($k);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        $where[] = "d.created_at $op ?";
        $params[] = $k === 'to' ? date('Y-m-d', strtotime($v . ' +1 day')) . ' 00:00:00' : $v . ' 00:00:00';
    }
}
$off = $g('off_hours');
if ($off === '1') {
    $where[] = 'd.is_off_hours = 1';
}

$page = min(10000, max(1, (int)($_GET['page'] ?? 1)));
$per = 50;
$sqlWhere = implode(' AND ', $where);
$total = (int)$db->value("SELECT COUNT(*) FROM deals d JOIN contacts c ON c.id = d.contact_id WHERE $sqlWhere", $params);
$rows = $db->all(
    "SELECT d.*, c.name AS contact_name, c.phone, c.anonymized_at, u.name AS manager_name,
        (SELECT s.channel FROM sources s WHERE s.contact_id = d.contact_id ORDER BY s.id LIMIT 1) AS channel,
        (SELECT s.utm_campaign FROM sources s WHERE s.contact_id = d.contact_id ORDER BY s.id LIMIT 1) AS campaign
     FROM deals d JOIN contacts c ON c.id = d.contact_id LEFT JOIN users u ON u.id = d.manager_id
     WHERE $sqlWhere ORDER BY d.id DESC LIMIT $per OFFSET " . (($page - 1) * $per),
    $params
);
$users = $db->all('SELECT id, name FROM users WHERE active = 1 ORDER BY name');
$chanLabel = ['site_form' => 'Сайт', 'bot' => 'Бот', 'site_bot' => 'Сайт → бот'];

$opt = static function (array $opts, string $cur) use ($e): string {
    $o = '';
    foreach ($opts as $k => $v) {
        $o .= '<option value="' . $e((string)$k) . '"' . ((string)$k === $cur ? ' selected' : '') . '>' . $e($v) . '</option>';
    }
    return $o;
};

$html = '<div class="page-head"><h1>Заявки</h1><p class="muted">Найдено: ' . $total . '</p></div>
<form class="filters wrap-f" method="get" role="search">
  <label for="f-q">Поиск</label><input class="inp" id="f-q" name="q" value="' . $e($q) . '" placeholder="Имя, телефон, L-000123, @ник">
  <label for="f-st">Этап</label><select class="inp" id="f-st" name="stage">' . $opt(['' => 'Все', 'open' => 'Открытые'] + Deals::STAGES, $stage) . '</select>
  <label for="f-ch">Источник</label><select class="inp" id="f-ch" name="channel">' . $opt(['' => 'Все', 'site_form' => 'Сайт', 'bot' => 'Telegram-бот', 'site_bot' => 'Сайт → бот'], $chan) . '</select>
  <label for="f-cmp">Кампания</label><input class="inp" id="f-cmp" name="campaign" value="' . $e($camp) . '" placeholder="utm_campaign или метка">';
if (CrmApp::isAdmin()) {
    $mo = ['' => 'Все', 'none' => 'Не назначен'];
    foreach ($users as $u) {
        $mo[$u['id']] = $u['name'];
    }
    $html .= '<label for="f-m">Менеджер</label><select class="inp" id="f-m" name="manager">' . $opt($mo, $mgr) . '</select>';
}
$html .= '<label for="f-from">С</label><input class="inp" id="f-from" type="date" name="from" value="' . $e($g('from')) . '">
  <label for="f-to">По</label><input class="inp" id="f-to" type="date" name="to" value="' . $e($g('to')) . '">
  <label class="chk"><input type="checkbox" name="off_hours" value="1"' . ($off === '1' ? ' checked' : '') . '> Только нерабочее время</label>
  <button class="btn" type="submit">Найти</button> <a class="btn ghost" href="/crm/deals.php">Сбросить</a>
</form>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Номер</th><th>Контакт</th><th>Тема</th><th>Этап</th><th>Источник</th><th>Менеджер</th><th class="r">Сумма</th><th>Создана</th></tr></thead><tbody>';
foreach ($rows as $r) {
    $html .= '<tr><td><a class="num" href="/crm/deal.php?id=' . (int)$r['id'] . '">' . $e($r['number']) . '</a></td>'
        . '<td>' . $e($r['anonymized_at'] ? 'Обезличено' : ($r['contact_name'] ?: '—')) . ($r['phone'] && !$r['anonymized_at'] ? '<br><small class="muted">' . $e($r['phone']) . '</small>' : '') . '</td>'
        . '<td>' . $e(mb_strimwidth((string)$r['subject'], 0, 70, '…')) . '</td>'
        . '<td><span class="stage s-' . $e($r['stage']) . '">' . CrmApp::stageLabel($r['stage']) . '</span></td>'
        . '<td>' . $e($chanLabel[$r['channel']] ?? '—') . ($r['campaign'] ? '<br><small class="muted">' . $e($r['campaign']) . '</small>' : '') . '</td>'
        . '<td>' . $e($r['manager_name'] ?: '—') . '</td>'
        . '<td class="r">' . CrmApp::money($r['amount']) . '</td>'
        . '<td>' . CrmApp::dt($r['created_at']) . ((int)$r['is_off_hours'] ? ' <span class="tag night">ночь</span>' : '') . '</td></tr>';
}
if (!$rows) {
    $html .= '<tr><td colspan="8" class="muted">Ничего не найдено. Измените условия поиска или сбросьте фильтры.</td></tr>';
}
$html .= '</tbody></table></div>';
$pages = (int)ceil($total / $per);
if ($pages > 1) {
    $qs = $_GET;
    $html .= '<nav class="pager" aria-label="Страницы">';
    for ($i = 1; $i <= $pages; $i++) {
        $qs['page'] = $i;
        $html .= $i === $page ? '<span aria-current="page">' . $i . '</span>' : '<a href="?' . $e(http_build_query($qs)) . '">' . $i . '</a>';
    }
    $html .= '</nav>';
}
echo CrmApp::layout('Заявки', $html, 'list');
