<?php
declare(strict_types=1);
/** Контакт (только руководитель): согласия, заявки, обезличивание по 152-ФЗ. */

CrmApp::requireAdmin();
$db = App::db();
$e = 'CrmApp::e';
$id = (int)($_GET['id'] ?? 0);
$c = Contacts::find($id);
if (!$c) {
    http_response_code(404);
    echo CrmApp::layout('Не найдено', '<div class="empty"><h1>Контакт не найден</h1></div>');
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'anonymize') {
    if (trim((string)($_POST['confirm'] ?? '')) !== 'УДАЛИТЬ') {
        CrmApp::flash('Для подтверждения введите слово УДАЛИТЬ.', 'err');
    } else {
        Privacy::anonymize($id, CrmApp::uid(), (string)($_POST['reason'] ?? 'запрос субъекта'));
        CrmApp::flash('Персональные данные обезличены, запись в журнале сохранена.');
    }
    CrmApp::redirect('/crm/contact.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'merge') {
    // объединение возможного дубля: этот контакт переносится в указанный, все заявки и согласия — туда же
    $into = (int)($_POST['into'] ?? 0);
    $target = Contacts::find($into);
    if (trim((string)($_POST['confirm'] ?? '')) !== 'ОБЪЕДИНИТЬ') {
        CrmApp::flash('Для подтверждения введите слово ОБЪЕДИНИТЬ.', 'err');
    } elseif (!$target || $into === $id || $target['anonymized_at'] || $c['anonymized_at']) {
        CrmApp::flash('Объединить можно только два разных необезличенных контакта.', 'err');
    } elseif ($c['telegram_id'] && $target['telegram_id'] && (int)$c['telegram_id'] !== (int)$target['telegram_id']) {
        CrmApp::flash('У обоих контактов свой Telegram — это разные люди или разные аккаунты. Объединение отменено.', 'err');
    } else {
        $db->tx(static function () use ($db, $id, $into) {
            Contacts::merge($id, $into, CrmApp::uid());
            $db->run("UPDATE tasks SET done_at = ?, done_by = ? WHERE kind = 'duplicate' AND done_at IS NULL AND deal_id IN (SELECT id FROM deals WHERE contact_id = ?)",
                [Db::ts(), CrmApp::uid(), $into]);
        });
        CrmApp::flash('Контакты объединены: заявки, согласия и источники перенесены.');
        CrmApp::redirect('/crm/contact.php?id=' . $into);
    }
    CrmApp::redirect('/crm/contact.php?id=' . $id);
}

$consents = $db->all('SELECT * FROM consents WHERE contact_id = ? ORDER BY id DESC', [$id]);
$deals = $db->all('SELECT id, number, stage, created_at FROM deals WHERE contact_id = ? ORDER BY id DESC', [$id]);
$audit = $db->all("SELECT a.*, u.name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.entity = 'contact' AND a.entity_id = ? ORDER BY a.id DESC", [$id]);
$req = $db->one("SELECT * FROM tasks WHERE kind = 'delete_request' AND title LIKE ? ORDER BY id DESC LIMIT 1", ['%#' . $id . ')%']);

$html = '<p class="crumbs"><a href="/crm/deals.php">Заявки</a> / Контакт #' . $id . '</p><div class="page-head"><h1>' . $e($c['anonymized_at'] ? 'Обезличенный контакт' : ($c['name'] ?: 'Без имени')) . '</h1></div>
<div class="deal-grid"><div class="main-col">
<section class="card"><h2>Согласия на обработку данных</h2><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Дата</th><th>Действие</th><th>Канал</th><th>Редакция политики</th><th>Подтверждение</th></tr></thead><tbody>';
foreach ($consents as $k) {
    $html .= '<tr><td>' . CrmApp::dt($k['created_at']) . '</td><td>' . ($k['action'] === 'given' ? '✓ дано' : '✗ отозвано') . '</td><td>' . ($k['channel'] === 'bot' ? 'Telegram-бот' : 'сайт') . '</td><td>' . $e($k['policy_version']) . '</td><td>'
        . $e($k['ip'] ? 'IP ' . $k['ip'] : ($k['telegram_id'] ? 'Telegram ID ' . $k['telegram_id'] : ($c['anonymized_at'] ? 'обезличено' : '—'))) . '</td></tr>';
}
if (!$consents) {
    $html .= '<tr><td colspan="5" class="muted">Согласий нет.</td></tr>';
}
$html .= '</tbody></table></div></section>
<section class="card"><h2>Журнал по контакту</h2><ul class="audit">';
foreach ($audit as $a) {
    $html .= '<li><time>' . CrmApp::dt($a['created_at']) . '</time> ' . $e($a['name'] ?? 'система') . ' — ' . $e($a['action']) . ' ' . $e((string)$a['details']) . '</li>';
}
if (!$audit) {
    $html .= '<li class="muted">Записей нет.</li>';
}
$html .= '</ul></section></div><aside class="side-col"><section class="card"><h2>Заявки</h2><ul>';
foreach ($deals as $d) {
    $html .= '<li><a class="num" href="/crm/deal.php?id=' . (int)$d['id'] . '">' . $e($d['number']) . '</a> — ' . CrmApp::stageLabel($d['stage']) . ', ' . CrmApp::dt($d['created_at'], false) . '</li>';
}
$dup = $db->one("SELECT t.id FROM tasks t JOIN deals d ON d.id = t.deal_id WHERE t.kind = 'duplicate' AND t.done_at IS NULL AND d.contact_id = ? LIMIT 1", [$id]);
$dupTarget = null;
if ($dup && preg_match('/контакт №(\d+)/u', (string)$db->value('SELECT title FROM tasks WHERE id = ?', [$dup['id']]), $m)) {
    $dupTarget = (int)$m[1];
}
$html .= '</ul></section>';
if (!$c['anonymized_at']) {
    $html .= '<section class="card"><h2>Объединить с другим контактом</h2>'
        . ($dupTarget ? '<p class="notice">Возможный дубль: телефон из Telegram совпадает с контактом <a href="/crm/contact.php?id=' . $dupTarget . '">№' . $dupTarget . '</a>. Объединяйте, только если убедились, что это один человек (например, позвонили).</p>' : '')
        . '<form method="post" class="form">' . CrmApp::csrfField() . '<input type="hidden" name="action" value="merge">'
        . '<label for="m-into">Номер контакта, в который перенести этот</label><input class="inp narrow-wide" id="m-into" name="into" inputmode="numeric" required value="' . ($dupTarget ?: '') . '">'
        . '<label for="m-confirm">Для подтверждения впишите ОБЪЕДИНИТЬ</label><input class="inp narrow-wide" id="m-confirm" name="confirm" autocomplete="off" required>'
        . '<button class="btn ghost" type="submit">Объединить</button></form></section>';
}
$html .= '<section class="card danger"><h2>Удалить персональные данные</h2>';
if ($c['anonymized_at']) {
    $html .= '<p>Данные обезличены ' . CrmApp::dt($c['anonymized_at']) . '.</p>';
} else {
    $html .= ($req ? '<p class="notice">Есть запрос клиента на удаление от ' . CrmApp::dt($req['created_at']) . '.</p>' : '')
        . '<p class="small">Будут удалены имя, телефон, Telegram, почта и тексты переписки. Открытые заявки закроются с причиной «данные удалены». Цифры для отчётов (этапы, суммы, источники) останутся. Отменить нельзя.</p>
      <form method="post" class="form">' . CrmApp::csrfField() . '<input type="hidden" name="action" value="anonymize">
        <label for="a-reason">Основание</label><select class="inp" id="a-reason" name="reason"><option value="запрос субъекта">Запрос клиента</option><option value="отзыв согласия">Отзыв согласия</option><option value="другое">Другое</option></select>
        <label for="a-confirm">Введите слово УДАЛИТЬ</label><input class="inp" id="a-confirm" name="confirm" autocomplete="off" required>
        <button class="btn danger" type="submit">Обезличить данные</button></form>';
}
$html .= '</section></aside></div>';
echo CrmApp::layout('Контакт', $html, 'list');
