<?php
declare(strict_types=1);
/** Мастер установки: /install.php. Работает только пока установка не выполнена. */

$e = 'CrmApp::e';
if (Installer::locked()) {
    http_response_code(404);
    echo CrmApp::layout('Установка выполнена', '<div class="empty"><h1>Установка уже выполнена</h1>'
        . '<p class="muted">Мастер заблокирован. Если файл install.php ещё лежит на сайте — удалите его.</p>'
        . '<p><a class="btn" href="/crm/">Вход в CRM</a></p></div>');
    return;
}

$error = null;
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Installer::ready()) {
            throw new InvalidArgumentException('Сначала исправьте пункты, отмеченные красным.');
        }
        // до проверки ключа ничего не пишем ни в базу, ни в config.php; лимит попыток — в файле
        if (!Installer::attempt($ip)) {
            throw new InvalidArgumentException('Слишком много попыток. Подождите 15 минут.');
        }
        if (!Installer::keyMatches((string)($_POST['install_key'] ?? ''))) {
            Log::event('install_bad_key', []);
            usleep(700000);
            throw new InvalidArgumentException('Неверный ключ установки. Он записан в private/config.php в строке install_key.');
        }
        if ((string)($_POST['password'] ?? '') !== (string)($_POST['password2'] ?? '')) {
            throw new InvalidArgumentException('Пароли не совпадают.');
        }
        $uid = Installer::install((string)($_POST['login'] ?? ''), (string)($_POST['name'] ?? ''), (string)($_POST['password'] ?? ''));
        $deleted = defined('INSTALL_SCRIPT') && @unlink(INSTALL_SCRIPT);
        session_regenerate_id(true);
        $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
        $php = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        echo CrmApp::layout('Установка завершена', '
<section class="install">
  <h1>Установка завершена</h1>
  <p class="flash" role="status">Таблицы созданы, руководитель добавлен, стартовые ответы бота загружены. Мастер заблокирован'
            . ($deleted ? ', файл install.php удалён.' : '.') . '</p>
  ' . ($deleted ? '' : '<p class="flash err" role="alert">Не удалось удалить install.php — удалите его вручную в файловом менеджере. Повторно он всё равно не откроется.</p>') . '
  <div class="card">
    <h2>Что дальше</h2>
    <ol class="steps">
      <li>Добавьте задание cron в панели Reg.ru — <b>каждую минуту</b>:<br><code>/opt/php/' . $e($php) . '/bin/php -f ' . $e(realpath(APP_PRIVATE) . '/cron/run.php') . '</code></li>
      <li>Войдите в CRM и откройте <b>Состояние системы</b>: там видно базу, cron, бэкапы и бота.</li>
      <li>Когда сайт откроется по https:// — подключите бота кнопкой на той же странице.</li>
      <li>Добавьте менеджеров в разделе «Сотрудники», праздники — в «Настройках».</li>
    </ol>
    <p><a class="btn" href="/crm/login.php?back=%2Fcrm%2Fhealth.php">Войти в CRM</a></p>
  </div>
</section>');
        return;
    } catch (InvalidArgumentException $ex) {
        $error = $ex->getMessage();
    } catch (Throwable $ex) {
        Log::error('install_failed', ['err' => $ex->getMessage()]);
        $error = 'Установка не выполнена: ' . Health::scrub($ex->getMessage());
    }
    http_response_code(422);
}

$rows = '';
foreach (Installer::checks() as [$title, $ok, $detail, $blocking]) {
    $st = $ok ? 'ok' : ($blocking ? 'fail' : 'warn');
    $rows .= '<li class="check ' . $st . '"><span class="dot" aria-hidden="true"></span><div><b>' . $e($title) . '</b>'
        . '<span class="sr"> — ' . ($ok ? 'в порядке' : ($blocking ? 'нужно исправить' : 'можно позже')) . '</span>'
        . ($ok ? '' : '<p class="small muted">' . $e($detail) . '</p>') . '</div></li>';
}
$ready = Installer::ready();
echo CrmApp::layout('Установка', '
<section class="install">
  <h1>Установка CRM ТСЖ «Пушкинская 15»</h1>
  <p class="muted">Мастер создаст таблицы в базе и первого пользователя — руководителя. После установки он заблокируется.</p>
  ' . ($error ? '<p class="flash err" role="alert">' . $e($error) . '</p>' : '') . '
  <div class="card"><h2>Проверка сервера</h2><ul class="checks">' . $rows . '</ul>
  ' . ($ready ? '' : '<p class="small">Исправьте красные пункты и обновите страницу.</p>') . '</div>
  <form method="post" class="card form" autocomplete="off">
    <h2>Руководитель</h2>
    ' . CrmApp::csrfField() . '
    <label for="i-key">Ключ установки (install_key из config.php)</label>
    <input class="inp" id="i-key" name="install_key" type="password" required autocomplete="off">
    <label for="i-name">Имя</label>
    <input class="inp" id="i-name" name="name" required maxlength="100" value="' . $e((string)($_POST['name'] ?? '')) . '">
    <label for="i-login">Логин</label>
    <input class="inp" id="i-login" name="login" required pattern="[a-zA-Z0-9._\-]{3,32}" autocomplete="username" value="' . $e((string)($_POST['login'] ?? '')) . '">
    <p class="small muted">Латиница, цифры, точка, дефис — от 3 до 32 символов.</p>
    <label for="i-pass">Пароль</label>
    <input class="inp" id="i-pass" name="password" type="password" required minlength="10" autocomplete="new-password">
    <label for="i-pass2">Пароль ещё раз</label>
    <input class="inp" id="i-pass2" name="password2" type="password" required minlength="10" autocomplete="new-password">
    <p class="small muted">Не короче 10 символов и без логина внутри.</p>
    <button class="btn" type="submit"' . ($ready ? '' : ' disabled') . '>Установить</button>
  </form>
</section>');
