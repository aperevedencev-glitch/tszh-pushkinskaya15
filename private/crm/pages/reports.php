<?php
declare(strict_types=1);
/** Отчёты руководителя: источники и кампании, часы суток, воронка, время до первого ответа, выручка. Выгрузка CSV. */

CrmApp::requireAdmin();
$e = 'CrmApp::e';
$r = Reports::fromRequest($_GET);
$src = $r->sources();
$hours = $r->hours();
$funnel = $r->funnel();
$resp = $r->firstResponse();
$period = $r->from() . '_' . $r->to();

switch ((string)($_GET['export'] ?? '')) {
    case 'sources':
        Reports::csv("istochniki_$period.csv", ['Канал', 'utm_source', 'utm_campaign', 'Заявок', 'Из них в нерабочее время', 'Оплачено', 'Выручка, ₽'],
            array_map(static fn($x) => [$x['channel'], $x['utm_source'], $x['utm_campaign'], $x['leads'], $x['off_hours'], $x['paid'], (float)$x['revenue']], $src));
    case 'hours':
        Reports::csv("chasy_$period.csv", ['Час', 'Заявок'], array_map(static fn($h, $n) => [sprintf('%02d:00', $h), $n], array_keys($hours['hours']), $hours['hours']));
    case 'funnel':
        Reports::csv("voronka_$period.csv", ['Этап', 'Сделок', '% от предыдущего', '% от всех'],
            array_map(static fn($x) => [$x['stage'], $x['count'], $x['from_prev'] ?? '', $x['from_total'] ?? ''], $funnel['rows']));
    case 'response':
        Reports::csv("pervyj_otvet_$period.csv", ['Сотрудник', 'Ответили', 'Среднее, мин', 'Медиана, мин', 'Без ответа'],
            array_map(static fn($x) => [$x['name'], $x['n'], $x['avg'] ?? '', $x['median'] ?? '', $x['without']], $resp['by']));
}

$exp = static fn(string $k) => '<a class="btn mini ghost" href="?' . $e(http_build_query(['from' => $r->from(), 'to' => $r->to(), 'export' => $k])) . '">Скачать CSV</a>';
$totLeads = array_sum(array_column($src, 'leads'));
$totRev = array_sum(array_column($src, 'revenue'));

$html = '<div class="page-head"><h1>Отчёты</h1>
<form class="filters" method="get"><label for="r-from">С</label><input class="inp" type="date" id="r-from" name="from" value="' . $e($r->from()) . '">
<label for="r-to">По</label><input class="inp" type="date" id="r-to" name="to" value="' . $e($r->to()) . '"><button class="btn ghost" type="submit">Показать</button></form></div>
<div class="kpis">
  <div class="kpi"><span>Заявок</span><b>' . $funnel['total'] . '</b></div>
  <div class="kpi"><span>В нерабочее время</span><b>' . $hours['off'] . '</b><small>' . ($hours['total'] ? round($hours['off'] / $hours['total'] * 100) : 0) . '% от всех</small></div>
  <div class="kpi"><span>Первый ответ, медиана</span><b>' . Reports::minutes($resp['total']['median']) . '</b><small>рабочего времени</small></div>
  <div class="kpi"><span>Выручка (оплачено в периоде)</span><b>' . CrmApp::money((string)$totRev) . '</b></div>
</div>

<section class="card"><div class="sec-h"><h2>Источники и кампании</h2>' . $exp('sources') . '</div>
<p class="muted small">Источник — первое касание жильца: откуда он пришёл впервые. Выручка — по сделкам, оплаченным в выбранном периоде.</p>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Канал</th><th>utm_source</th><th>Кампания</th><th class="r">Заявок</th><th class="r">Ночью</th><th class="r">Оплачено</th><th class="r">Выручка</th></tr></thead><tbody>';
foreach ($src as $x) {
    $html .= '<tr><td>' . $e($x['channel']) . '</td><td>' . $e($x['utm_source']) . '</td><td>' . $e($x['utm_campaign']) . '</td><td class="r">' . $x['leads'] . '</td><td class="r">' . $x['off_hours'] . '</td><td class="r">' . $x['paid'] . '</td><td class="r">' . CrmApp::money((string)$x['revenue']) . '</td></tr>';
}
if (!$src) {
    $html .= '<tr><td colspan="7" class="muted">За период заявок нет.</td></tr>';
}
$html .= '</tbody><tfoot><tr><th colspan="3">Итого</th><th class="r">' . $totLeads . '</th><th class="r">' . $hours['off'] . '</th><th class="r">' . array_sum(array_column($src, 'paid')) . '</th><th class="r">' . CrmApp::money((string)$totRev) . '</th></tr></tfoot></table></div></section>';

// Часы суток — столбцы
$max = max(1, max($hours['hours']));
$wh = (array)App::cfg('work_hours', []);
$startH = (int)substr($wh['start'] ?? '09:00', 0, 2);
$endH = (int)substr($wh['end'] ?? '18:00', 0, 2);
$html .= '<section class="card"><div class="sec-h"><h2>Заявки по часам суток</h2>' . $exp('hours') . '</div>
<p class="muted small">Оранжевым — часы вне рабочего времени. За период: ' . $hours['off'] . ' из ' . $hours['total'] . ' заявок пришли в нерабочее время (включая выходные).</p>
<div class="hours" role="img" aria-label="Распределение заявок по часам суток">';
foreach ($hours['hours'] as $h => $n) {
    $off = $h < $startH || $h >= $endH;
    $html .= '<div class="h' . ($off ? ' off' : '') . '"><span class="v">' . ($n ?: '') . '</span><i data-h="' . round($n / $max * 100) . '"></i><span class="l">' . $h . '</span></div>';
}
$html .= '</div></section>';

// Воронка
$html .= '<section class="card"><div class="sec-h"><h2>Конверсия по этапам</h2>' . $exp('funnel') . '</div>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Этап</th><th class="r">Сделок дошло</th><th class="r">От предыдущего</th><th class="r">От всех</th></tr></thead><tbody>';
foreach ($funnel['rows'] as $x) {
    $html .= '<tr><td>' . $e($x['stage']) . '</td><td class="r">' . $x['count'] . '</td><td class="r">' . ($x['from_prev'] !== null ? $x['from_prev'] . '%' : '—') . '</td><td class="r">' . ($x['from_total'] !== null ? $x['from_total'] . '%' : '—') . '</td></tr>';
}
$html .= '<tr><td>Отказ</td><td class="r">' . $funnel['lost'] . '</td><td class="r">—</td><td class="r">' . ($funnel['total'] ? round($funnel['lost'] / $funnel['total'] * 100) . '%' : '—') . '</td></tr></tbody></table></div></section>';

// Первый ответ
$html .= '<section class="card"><div class="sec-h"><h2>Время до первого ответа</h2>' . $exp('response') . '</div>
<p class="muted small">Считается в рабочих часах: от создания заявки до первого ответа клиенту или перевода на этап «Связались».</p>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Сотрудник</th><th class="r">Ответили</th><th class="r">Среднее</th><th class="r">Медиана</th><th class="r">Ещё без ответа</th></tr></thead><tbody>';
foreach ($resp['by'] as $x) {
    $html .= '<tr><td>' . $e($x['name']) . '</td><td class="r">' . $x['n'] . '</td><td class="r">' . Reports::minutes($x['avg']) . '</td><td class="r">' . Reports::minutes($x['median']) . '</td><td class="r">' . $x['without'] . '</td></tr>';
}
$t = $resp['total'];
$html .= '</tbody><tfoot><tr><th>Все</th><th class="r">' . $t['n'] . '</th><th class="r">' . Reports::minutes($t['avg']) . '</th><th class="r">' . Reports::minutes($t['median']) . '</th><th class="r">' . $t['without'] . '</th></tr></tfoot></table></div></section>';

echo CrmApp::layout('Отчёты', $html, 'reports');
