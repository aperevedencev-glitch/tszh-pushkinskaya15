<?php
declare(strict_types=1);
/** Настройки (руководитель): праздники и перенесённые рабочие дни, срок хранения. */

CrmApp::requireAdmin();
$e = 'CrmApp::e';
$parse = static function (string $raw): array {
    $out = [];
    foreach (preg_split('/[\s,;]+/', $raw) as $d) {
        $d = trim($d);
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $d, $m)) {
            $d = "$m[3]-$m[2]-$m[1]";
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d)) {
            $out[] = $d;
        }
    }
    $out = array_values(array_unique($out));
    sort($out);
    return $out;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $h = $parse((string)($_POST['holidays'] ?? ''));
    $w = $parse((string)($_POST['workdays'] ?? ''));
    Settings::set('holidays', $h);
    Settings::set('workdays', $w);
    Audit::log(CrmApp::uid(), 'settings', null, 'calendar', ['holidays' => count($h), 'workdays' => count($w)]);
    CrmApp::flash('Календарь сохранён: праздников ' . count($h) . ', рабочих выходных ' . count($w) . '.');
    CrmApp::redirect('/crm/settings.php');
}

$fmt = static fn(array $ds) => implode("\n", array_map(static fn($d) => date('d.m.Y', strtotime($d)), $ds));
$wh = new WorkHours();
$html = '<div class="page-head"><h1>Настройки</h1></div>
<section class="card"><h2>Рабочее время</h2>
<p>Менеджеры: <b>' . $e($wh->label()) . '</b>. Дни и часы меняются в <code>private/config.php</code> (раздел work_hours).</p>
<p class="muted small">Рабочее время влияет на ответ бота ночью, на задачи «связаться утром», на пометку «нерабочее время» и на отчёт о времени первого ответа.</p>
<form method="post" class="form grid2">' . CrmApp::csrfField() . '
  <div><label for="s-h">Праздники (нерабочие дни)</label><textarea class="inp tall" id="s-h" name="holidays" placeholder="01.01.2027&#10;07.01.2027">' . $e($fmt((array)Settings::get('holidays', []))) . '</textarea><p class="small muted">По одной дате в строке: ДД.ММ.ГГГГ.</p></div>
  <div><label for="s-w">Перенесённые рабочие дни (суббота или воскресенье, когда работаем)</label><textarea class="inp tall" id="s-w" name="workdays">' . $e($fmt((array)Settings::get('workdays', []))) . '</textarea></div>
  <div class="end"><button class="btn" type="submit">Сохранить календарь</button></div>
</form></section>
<section class="card"><h2>Персональные данные</h2>
<p>Срок хранения: <b>' . (int)App::cfg('retention_months', 12) . ' мес.</b> с последнего обращения. После этого данные контакта обезличиваются автоматически (раз в сутки).</p>
<p>Редакция политики в согласиях: <b>' . $e((string)App::cfg('policy_version', '')) . '</b>.</p></section>';
echo CrmApp::layout('Настройки', $html, 'settings');
