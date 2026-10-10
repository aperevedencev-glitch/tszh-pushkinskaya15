<?php
declare(strict_types=1);
/** Профиль: смена пароля и привязка Telegram для уведомлений. */

$db = App::db();
$e = 'CrmApp::e';
$u = CrmApp::user();
$code = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'password') {
        $row = $db->one('SELECT password_hash, login FROM users WHERE id = ?', [$u['id']]);
        $new = (string)($_POST['new'] ?? '');
        $err = null;
        if (!password_verify((string)($_POST['current'] ?? ''), $row['password_hash'])) {
            $err = 'Текущий пароль указан неверно.';
        } elseif ($new !== (string)($_POST['repeat'] ?? '')) {
            $err = 'Новые пароли не совпадают.';
        } else {
            $err = Passwords::weak($new, $row['login']);
        }
        if (!$err) {
            $db->update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = ?', [$u['id']]);
            Audit::log((int)$u['id'], 'user', (int)$u['id'], 'password_change');
            CrmApp::bumpSessions((int)$u['id']);   // на других устройствах придётся войти заново
            session_regenerate_id(true);
        }
        CrmApp::flash($err ?? 'Пароль изменён.', $err ? 'err' : 'ok');
        CrmApp::redirect('/crm/profile.php');
    }
    if ($action === 'link') {
        $code = StaffLink::create((int)$u['id']);
    }
    if ($action === 'unlink') {
        $db->update('users', ['telegram_id' => null], 'id = ?', [$u['id']]);
        Audit::log((int)$u['id'], 'user', (int)$u['id'], 'telegram_unlinked');
        CrmApp::flash('Telegram отвязан: уведомления больше не приходят.');
        CrmApp::redirect('/crm/profile.php');
    }
}

$bot = App::botUsername();
$html = '<div class="page-head"><h1>Профиль</h1></div><div class="deal-grid"><div class="main-col">
<section class="card"><h2>Уведомления в Telegram</h2>';
if ($code) {
    $html .= '<div class="notice"><p>Откройте бота <a href="https://t.me/' . $e($bot) . '" target="_blank" rel="noopener">@' . $e($bot) . '</a> и отправьте ему сообщение:</p><p><code class="num big">/link ' . $e($code) . '</code></p><p class="small">Код действует 15 минут и подходит один раз.</p></div>';
} elseif ($u['telegram_id']) {
    $html .= '<p>✓ Telegram привязан. Сюда приходят новые заявки, сообщения клиентов и напоминания о задачах.</p><form method="post">' . CrmApp::csrfField() . '<input type="hidden" name="action" value="unlink"><button class="btn ghost" type="submit">Отвязать</button></form>';
} else {
    $html .= '<p>Привяжите Telegram, чтобы получать новые заявки за секунды — с кнопкой «Открыть в CRM». В уведомлениях нет имён и телефонов клиентов.</p>';
}
if (!$code) {
    $html .= '<form method="post">' . CrmApp::csrfField() . '<input type="hidden" name="action" value="link"><button class="btn" type="submit">' . ($u['telegram_id'] ? 'Привязать другой аккаунт' : 'Получить код привязки') . '</button></form>';
}
$html .= '</section></div><aside class="side-col"><section class="card"><h2>Сменить пароль</h2><form method="post" class="form">' . CrmApp::csrfField() . '<input type="hidden" name="action" value="password">
  <label for="p-cur">Текущий пароль</label><input class="inp" id="p-cur" name="current" type="password" autocomplete="current-password" required>
  <label for="p-new">Новый пароль</label><input class="inp" id="p-new" name="new" type="password" autocomplete="new-password" minlength="10" required aria-describedby="p-hint">
  <p class="small muted" id="p-hint">Не короче 10 символов, без логина.</p>
  <label for="p-rep">Повторите</label><input class="inp" id="p-rep" name="repeat" type="password" autocomplete="new-password" minlength="10" required>
  <button class="btn" type="submit">Сменить пароль</button></form></section></aside></div>';
echo CrmApp::layout('Профиль', $html, 'profile');
