<?php
declare(strict_types=1);
/** Сотрудники (только руководитель): добавить, сбросить пароль, отключить, дежурный. */

CrmApp::requireAdmin();
$db = App::db();
$e = 'CrmApp::e';
$uid = CrmApp::uid();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $target = $id ? $db->one('SELECT * FROM users WHERE id = ?', [$id]) : null;
    $admins = (int)$db->value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1");
    $err = null;
    $msg = 'Сохранено.';
    if ($action === 'create') {
        $login = mb_strtolower(trim((string)($_POST['login'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'manager';
        if (!preg_match('/^[a-z0-9._-]{3,32}$/', $login)) {
            $err = 'Логин — латиница, цифры, точка, дефис, от 3 до 32 символов.';
        } elseif ($name === '') {
            $err = 'Укажите имя.';
        } elseif ($db->value('SELECT 1 FROM users WHERE login = ?', [$login])) {
            $err = 'Такой логин уже есть.';
        } else {
            $pass = Passwords::generate();
            $nid = $db->insert('users', ['login' => $login, 'name' => mb_substr($name, 0, 100), 'role' => $role, 'password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'created_at' => Db::ts()]);
            Audit::log($uid, 'user', $nid, 'create', ['role' => $role]);
            $_SESSION['new_password'] = ['login' => $login, 'pass' => $pass];
            $msg = 'Сотрудник добавлен. Передайте ему временный пароль лично — он показан один раз.';
        }
    } elseif (!$target) {
        $err = 'Сотрудник не найден.';
    } elseif ($action === 'reset') {
        $pass = Passwords::generate();
        $db->update('users', ['password_hash' => password_hash($pass, PASSWORD_DEFAULT)], 'id = ?', [$id]);
        Audit::log($uid, 'user', $id, 'password_reset');
        $_SESSION['new_password'] = ['login' => $target['login'], 'pass' => $pass];
        $msg = 'Пароль сброшен. Новый пароль показан один раз.';
    } elseif ($action === 'toggle') {
        if ((int)$target['active'] && $target['role'] === 'admin' && $admins <= 1) {
            $err = 'Нельзя отключить последнего руководителя.';
        } elseif ($id === $uid) {
            $err = 'Нельзя отключить себя.';
        } else {
            $db->update('users', ['active' => (int)$target['active'] ? 0 : 1, 'is_on_duty' => 0], 'id = ?', [$id]);
            Audit::log($uid, 'user', $id, (int)$target['active'] ? 'disable' : 'enable');
        }
    } elseif ($action === 'role') {
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'manager';
        if ($target['role'] === 'admin' && $role !== 'admin' && $admins <= 1) {
            $err = 'Нельзя убрать роль у последнего руководителя.';
        } else {
            $db->update('users', ['role' => $role], 'id = ?', [$id]);
            Audit::log($uid, 'user', $id, 'role', ['to' => $role]);
        }
    } elseif ($action === 'duty') {
        $db->run('UPDATE users SET is_on_duty = 0');
        if (!(int)$target['is_on_duty']) {
            $db->update('users', ['is_on_duty' => 1], 'id = ?', [$id]);
        }
        Audit::log($uid, 'settings', null, 'duty', ['user_id' => (int)$target['is_on_duty'] ? null : $id]);
        $msg = (int)$target['is_on_duty'] ? 'Дежурство снято: заявки распределяются по очереди.' : 'Все новые заявки идут дежурному: ' . $target['name'] . '.';
    }
    CrmApp::flash($err ?? $msg, $err ? 'err' : 'ok');
    CrmApp::redirect('/crm/users.php');
}

$users = $db->all('SELECT * FROM users ORDER BY active DESC, role, name');
$html = '<div class="page-head"><h1>Сотрудники</h1></div>';
if (!empty($_SESSION['new_password'])) {
    $np = $_SESSION['new_password'];
    unset($_SESSION['new_password']);
    $html .= '<div class="notice"><b>Временный пароль для ' . $e($np['login']) . ':</b> <code class="num" id="np">' . $e($np['pass']) . '</code><br><span class="small">Показан один раз. Сотрудник сменит его в профиле после входа.</span></div>';
}
$html .= '<p class="muted small">Новые заявки распределяются по очереди между активными менеджерами. Если назначить дежурного, все новые заявки пойдут ему.</p>
<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Имя</th><th>Логин</th><th>Роль</th><th>Telegram</th><th>Статус</th><th>Последний вход</th><th>Действия</th></tr></thead><tbody>';
foreach ($users as $u) {
    $f = static fn(string $action, string $label, string $extra = '') => '<form method="post" class="inline">' . CrmApp::csrfField() . '<input type="hidden" name="id" value="' . (int)$u['id'] . '"><input type="hidden" name="action" value="' . $action . '">' . $extra . '<button class="link" type="submit">' . $label . '</button></form>';
    $html .= '<tr' . ((int)$u['active'] ? '' : ' class="muted"') . '><td>' . $e($u['name']) . ((int)$u['is_on_duty'] ? ' <span class="tag okk">дежурный</span>' : '') . '</td><td>' . $e($u['login']) . '</td>'
        . '<td>' . ($u['role'] === 'admin' ? 'руководитель' : 'менеджер') . '</td><td>' . ($u['telegram_id'] ? '✓ привязан' : '—') . '</td><td>' . ((int)$u['active'] ? 'активен' : 'отключён') . '</td><td>' . CrmApp::dt($u['last_login_at']) . '</td><td class="acts">'
        . $f('reset', 'Сбросить пароль')
        . $f('role', $u['role'] === 'admin' ? 'Сделать менеджером' : 'Сделать руководителем', '<input type="hidden" name="role" value="' . ($u['role'] === 'admin' ? 'manager' : 'admin') . '">')
        . ((int)$u['active'] && $u['role'] === 'manager' ? $f('duty', (int)$u['is_on_duty'] ? 'Снять дежурство' : 'Назначить дежурным') : '')
        . ((int)$u['id'] !== $uid ? $f('toggle', (int)$u['active'] ? 'Отключить' : 'Включить') : '')
        . '</td></tr>';
}
$html .= '</tbody></table></div>
<section class="card"><h2>Добавить сотрудника</h2><form method="post" class="form grid2">' . CrmApp::csrfField() . '<input type="hidden" name="action" value="create">
  <div><label for="u-name">Имя</label><input class="inp" id="u-name" name="name" maxlength="100" required></div>
  <div><label for="u-login">Логин</label><input class="inp" id="u-login" name="login" maxlength="32" required pattern="[a-z0-9._-]{3,32}" autocapitalize="off"></div>
  <div><label for="u-role">Роль</label><select class="inp" id="u-role" name="role"><option value="manager">Менеджер</option><option value="admin">Руководитель</option></select></div>
  <div class="end"><button class="btn" type="submit">Добавить и показать временный пароль</button></div></form></section>';
echo CrmApp::layout('Сотрудники', $html, 'users');
