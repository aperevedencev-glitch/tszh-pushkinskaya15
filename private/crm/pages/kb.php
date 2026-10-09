<?php
declare(strict_types=1);
/** Ответы бота (база знаний): менеджеры и руководитель правят тексты без программиста. */

$db = App::db();
$e = 'CrmApp::e';
$uid = CrmApp::uid();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $answer = trim((string)($_POST['answer'] ?? ''));
    if ($title === '' || $answer === '') {
        CrmApp::flash('Заполните вопрос и ответ.', 'err');
        CrmApp::redirect('/crm/kb.php' . ($id ? '?edit=' . $id : '?edit=new'));
    }
    // в Telegram допустимы только простые теги; остальное экранируем
    $allowed = strip_tags($answer, '<b><i><u><s><a><code>');
    $row = [
        'title'        => mb_substr($title, 0, 150),
        'button_label' => mb_substr(trim((string)($_POST['button_label'] ?? '')), 0, 40) ?: null,
        'keywords'     => mb_substr(trim((string)($_POST['keywords'] ?? '')), 0, 1000),
        'answer'       => mb_substr($allowed, 0, 3500),
        'sort'         => (int)($_POST['sort'] ?? 100),
        'active'       => !empty($_POST['active']) ? 1 : 0,
        'updated_by'   => $uid,
        'updated_at'   => Db::ts(),
    ];
    if ($id) {
        $db->update('kb_articles', $row, 'id = ?', [$id]);
    } else {
        $id = $db->insert('kb_articles', $row);
    }
    Audit::log($uid, 'kb', $id, 'save');
    CrmApp::flash('Ответ сохранён — бот уже отвечает новым текстом.');
    CrmApp::redirect('/crm/kb.php');
}

$edit = $_GET['edit'] ?? null;
$html = '<div class="page-head"><h1>Ответы бота</h1><a class="btn" href="?edit=new">Добавить ответ</a></div>
<p class="muted small">Бот показывает эти ответы в разделе «Частые вопросы» и подбирает их по ключевым словам, когда жилец пишет своими словами. Достаточно начала слова: «оплат» найдёт «оплатить» и «оплата». Подстановки: {work_hours}, {phone_emergency}, {phone_office}, {email}, {site_url}.</p>';

if ($edit !== null) {
    $a = $edit === 'new' ? ['id' => 0, 'title' => '', 'button_label' => '', 'keywords' => '', 'answer' => '', 'sort' => 100, 'active' => 1] : $db->one('SELECT * FROM kb_articles WHERE id = ?', [(int)$edit]);
    if ($a) {
        $html .= '<section class="card"><h2>' . ($a['id'] ? 'Изменить ответ' : 'Новый ответ') . '</h2><form method="post" class="form">' . CrmApp::csrfField() . '
          <input type="hidden" name="id" value="' . (int)$a['id'] . '">
          <label for="k-title">Вопрос</label><input class="inp" id="k-title" name="title" maxlength="150" required value="' . $e($a['title']) . '">
          <label for="k-btn">Надпись на кнопке (до 40 символов)</label><input class="inp" id="k-btn" name="button_label" maxlength="40" value="' . $e((string)$a['button_label']) . '">
          <label for="k-kw">Ключевые слова через запятую</label><input class="inp" id="k-kw" name="keywords" maxlength="1000" value="' . $e($a['keywords']) . '">
          <label for="k-ans">Ответ (можно &lt;b&gt;жирный&lt;/b&gt; и &lt;i&gt;курсив&lt;/i&gt;)</label><textarea class="inp tall" id="k-ans" name="answer" maxlength="3500" required>' . $e($a['answer']) . '</textarea>
          <div class="row"><label for="k-sort">Порядок</label><input class="inp narrow" id="k-sort" name="sort" type="number" value="' . (int)$a['sort'] . '">
          <label class="chk"><input type="checkbox" name="active" value="1"' . ((int)$a['active'] ? ' checked' : '') . '> Показывать в боте</label></div>
          <div class="row"><button class="btn" type="submit">Сохранить</button><a class="btn ghost" href="/crm/kb.php">Отмена</a></div></form></section>';
    }
}

$html .= '<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Вопрос</th><th>Кнопка</th><th>Ответ</th><th>В боте</th><th><span class="sr">Действие</span></th></tr></thead><tbody>';
foreach ($db->all('SELECT * FROM kb_articles ORDER BY sort, id') as $a) {
    $ph = str_contains($a['answer'], 'Нужно от заказчика') ? ' <span class="tag late">есть плейсхолдер</span>' : '';
    $html .= '<tr><td>' . $e($a['title']) . $ph . '</td><td>' . $e((string)$a['button_label']) . '</td><td class="small">' . $e(mb_strimwidth(strip_tags($a['answer']), 0, 140, '…')) . '</td><td>' . ((int)$a['active'] ? 'да' : 'нет') . '</td><td><a href="?edit=' . (int)$a['id'] . '">Изменить</a></td></tr>';
}
$html .= '</tbody></table></div>';
echo CrmApp::layout('Ответы бота', $html, 'kb');
