<?php
declare(strict_types=1);
/** Вход в CRM. */

$back = (string)($_GET['back'] ?? $_POST['back'] ?? '/crm/');
if (!preg_match('~^/crm/[\w./?=&%-]*$~', $back)) {
    $back = '/crm/';   // только внутренние адреса CRM — без открытых редиректов
}
if (CrmApp::user()) {
    CrmApp::redirect($back);
}
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = CrmApp::login((string)($_POST['login'] ?? ''), (string)($_POST['password'] ?? ''));
    if ($error === null) {
        CrmApp::redirect($back);
    }
    http_response_code(401);
}
$e = 'CrmApp::e';
echo CrmApp::layout('Вход', '
<section class="login">
  <h1>Вход в CRM</h1>
  <p class="muted">ТСЖ «Пушкинская 15». Доступ — только для сотрудников.</p>
  ' . ($error ? '<p class="flash err" role="alert">' . $e($error) . '</p>' : '') . '
  <form method="post" class="card form">
    ' . CrmApp::csrfField() . '
    <input type="hidden" name="back" value="' . $e($back) . '">
    <label for="l-login">Логин</label>
    <input class="inp" id="l-login" name="login" autocomplete="username" required autofocus value="' . $e((string)($_POST['login'] ?? '')) . '">
    <label for="l-pass">Пароль</label>
    <input class="inp" id="l-pass" name="password" type="password" autocomplete="current-password" required>
    <button class="btn" type="submit">Войти</button>
  </form>
</section>');
