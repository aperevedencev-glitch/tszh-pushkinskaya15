<?php
declare(strict_types=1);
/**
 * Этап 5 без браузера: бэкап и восстановление, хранение 14 дней, мастер установки,
 * автозаполнение секретов, подключение вебхука, проверки /crm/health.
 * Запуск: php tests/deploy_test.php   (или TEST_DB=mysql TEST_DB_PASS=… php tests/deploy_test.php)
 */

putenv('TSZH_CONFIG=/nonexistent');
require __DIR__ . '/../private/bootstrap.php';
$tmp = sys_get_temp_dir() . '/tszh_deploy_' . getmypid();
@mkdir($tmp . '/backups', 0700, true);
$cfg = require __DIR__ . '/../private/config.example.php';
$cfg['app_secret'] = str_repeat('d', 64);
$cfg['site_url'] = 'http://example.test';
$cfg['telegram']['bot_token'] = '123456:AAHsecretTOKENsecretTOKENsecretTOKEN12';
$cfg['telegram']['webhook_secret'] = 'whsec_ABCDEFGHIJKLMNOP_123';
$cfg['install_key'] = 'ключ-установки-1';
$cfg['install_lock'] = $tmp . '/install.lock';
$cfg['backup'] = ['dir' => $tmp . '/backups', 'keep_days' => 14];
$cfg['_configured'] = true;
App::init($cfg);

$mysql = getenv('TEST_DB') === 'mysql';
$connect = static function () use ($mysql): Db {
    if ($mysql) {
        return Db::connect(['driver' => 'mysql', 'host' => 'localhost', 'name' => getenv('TEST_DB_NAME') ?: 'tszh_test',
            'user' => getenv('TEST_DB_USER') ?: 'tszh', 'pass' => getenv('TEST_DB_PASS') ?: '']);
    }
    return Db::connect(['driver' => 'sqlite', 'path' => $GLOBALS['tmp'] . '/crm.sqlite']);
};
$db = $connect();
if ($mysql) {
    $db->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($db->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $db->pdo->exec("DROP TABLE `$t`");
    }
    $db->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}
App::setDb($db);
echo "База: {$db->driver}\n";

$sent = [];
$hook = ['url' => ''];
Telegram::$transport = function (string $m, array $p) use (&$sent, &$hook) {
    $sent[] = [$m, $p];
    if ($m === 'setWebhook') {
        $hook = ['url' => $p['url'], 'pending_update_count' => 0, 'allowed_updates' => $p['allowed_updates']];
    }
    if ($m === 'getWebhookInfo') {
        return ['ok' => true, 'result' => $hook];
    }
    return ['ok' => true, 'result' => true];
};
$fails = 0;
function ok(bool $c, string $m): void
{
    global $fails;
    echo ($c ? '✓ ' : '✗ ') . $m . "\n";
    $fails += $c ? 0 : 1;
}
function at(string $s): void
{
    App::setNow(new DateTimeImmutable($s, new DateTimeZone('Europe/Moscow')));
}
function snapshot(Db $db): array
{
    $out = [];
    foreach (Backup::tables() as $t) {
        $rows = $db->all("SELECT * FROM $t ORDER BY 1");
        $out[$t] = md5(json_encode(array_map(static fn($r) => array_map(static fn($v) => $v === null ? null : (string)$v, $r), $rows), JSON_UNESCAPED_UNICODE));
    }
    return $out;
}

// ---------------------------------------------------------------- мастер установки
echo "\nМастер установки\n";
ok(!Installer::locked(), 'до установки мастер открыт');
ok(!Installer::keyMatches('') && !Installer::keyMatches('неверный') && Installer::keyMatches('ключ-установки-1'), 'ключ установки проверяется точно');
$errs = [];
foreach ([['Boss!', 'Ирина', 'Test-Passw0rd!'], ['boss', 'Ирина', 'short'], ['boss', 'Ирина', 'boss-12345678'], ['boss', '', 'Test-Passw0rd!']] as [$l, $n, $p]) {
    try {
        Installer::install($l, $n, $p);
        $errs[] = 'принято';
    } catch (InvalidArgumentException $e) {
        $errs[] = $e->getMessage();
    }
}
ok(!in_array('принято', $errs, true), 'неверный логин, короткий пароль, пароль с логином, пустое имя — отклонены (' . count($errs) . ')');
ok(!is_file($cfg['install_lock']), 'после отказа замок не создан');
$bossId = Installer::install('boss', 'Руководитель Ирина', 'Test-Passw0rd!');
$u = $db->one('SELECT * FROM users WHERE id = ?', [$bossId]);
ok($u['role'] === 'admin' && password_verify('Test-Passw0rd!', $u['password_hash']) && !str_contains($u['password_hash'], 'Test'), 'первый руководитель создан, пароль — только хеш');
ok((int)$db->value('SELECT COUNT(*) FROM kb_articles') >= 10, 'стартовые ответы бота загружены');
ok(count(array_diff(Backup::tables(), $db->driver === 'mysql' ? $db->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) : $db->pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN))) === 0, 'все ' . count(Backup::tables()) . ' таблиц созданы');
ok(is_file($cfg['install_lock']) && Installer::locked(), 'создан замок — мастер заблокирован');
try {
    Installer::install('boss2', 'Второй', 'Another-Passw0rd!');
    ok(false, 'повторная установка отклонена');
} catch (RuntimeException $e) {
    ok((int)$db->value('SELECT COUNT(*) FROM users') === 1, 'повторная установка отклонена, второго руководителя нет');
}
$att = [];
for ($i = 0; $i < 6; $i++) {
    $att[] = Installer::attempt('203.0.113.7');
}
ok($att === [true, true, true, true, true, false] && Installer::attempt('203.0.113.8'), 'мастер: 5 попыток за 15 минут с одного адреса, другие адреса не страдают');
@unlink(dirname($cfg['install_lock']) . '/install_attempts.json');
unlink($cfg['install_lock']);
ok(Installer::locked(), 'даже без файла-замка мастер закрыт, если в базе есть сотрудники');
ok((int)$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'install'") === 1, 'установка записана в audit_log');

// обновление: база от прошлой версии без новых столбцов
$db->pdo->exec('ALTER TABLE contacts DROP COLUMN phone_verified');
$db->run('DELETE FROM settings WHERE skey = ?', ['schema_version']);
ok(Schema::migrate($db) === 1 && Schema::migrate($db) === 0, 'обновление на ' . $db->driver . ': недостающий столбец добавлен, повторно ничего не меняется');

// ---------------------------------------------------------------- секреты в config.php
echo "\nСекреты в config.php\n";
$cfgFile = $tmp . '/config.php';
$example = file_get_contents(__DIR__ . '/../private/config.example.php');
file_put_contents($cfgFile, str_replace("'name'    => '',", "'name'    => 'u1234567_tszh',", $example));
ok(Installer::canFill('app_secret', $cfgFile) && Installer::canFill('webhook_secret', $cfgFile), 'пустые app_secret и webhook_secret в config.php распознаны');
$done = Installer::fillSecrets($cfgFile);
$filled = require $cfgFile;
ok($done === ['app_secret', 'webhook_secret'], 'мастер вписал оба секрета');
ok(preg_match('/^[0-9a-f]{64}$/', $filled['app_secret']) === 1, 'app_secret — 64 случайных hex-символа');
ok(Webhook::secretValid($filled['telegram']['webhook_secret']), 'webhook_secret подходит Telegram (A-Z a-z 0-9 _ -)');
ok($filled['db']['name'] === 'u1234567_tszh' && $filled['telegram']['bot_token'] === '', 'остальные настройки не тронуты, токен бота мастер не трогает');
ok((fileperms($cfgFile) & 0777) === 0600, 'config.php после записи — права 600');
$before = file_get_contents($cfgFile);
ok(Installer::fillSecrets($cfgFile) === [] && file_get_contents($cfgFile) === $before, 'заполненные секреты повторно не меняются');
App::init($cfg);   // вернуть тестовые настройки

// ---------------------------------------------------------------- бэкап и восстановление
echo "\nБэкап и восстановление\n";
at('2026-10-09 12:00');
$tricky = "Строка 1\nСтрока 2\r\nкавычки ' \" обратный слэш \\ и ; точка с запятой -- не комментарий 😀";
$r = Leads::capture(['channel' => 'site', 'name' => "О'Брайен", 'phone' => '+7 903 555-00-11', 'text' => $tricky,
    'consent' => ['ip' => '10.0.0.1'], 'source' => ['channel' => 'site_form', 'utm_source' => 'test']]);
Leads::capture(['channel' => 'bot', 'name' => 'Сергей', 'telegram_id' => 700100, 'text' => 'Вторая заявка', 'consent' => ['telegram_id' => 700100]]);
$snap = snapshot($db);
$file = Backup::create();
ok(is_file($file) && str_ends_with($file, '.' . $db->driver . '.sql.gz'), 'бэкап создан: ' . basename($file));
ok((fileperms($file) & 0777) === 0600, 'файл бэкапа — права 600 (только владелец)');
$sqlText = implode('', gzfile($file));
ok(str_contains($sqlText, '-- конец бэкапа') && !str_contains($sqlText, 'Test-Passw0rd'), 'бэкап целый, паролей в открытом виде нет');
ok(!str_contains($sqlText, $cfg['telegram']['bot_token']) && !str_contains($sqlText, $cfg['app_secret']), 'секретов из config.php в бэкапе нет');
$db->pdo->exec('DELETE FROM messages');
$db->pdo->exec("UPDATE contacts SET name = 'испорчено'");
ok(snapshot($db) !== $snap, 'данные изменены после бэкапа');
$n = Backup::restore($file);
ok(snapshot($db) === $snap, "восстановление вернуло все таблицы байт в байт ($n команд)");
ok($db->value('SELECT text FROM messages WHERE deal_id = ? ORDER BY id LIMIT 1', [$r['deal_id']]) === $tricky, 'переводы строк, кавычки, слэши и эмодзи восстановлены точно');
$id = Leads::capture(['channel' => 'site', 'name' => 'Новый', 'phone' => '+79035550099', 'text' => 'после восстановления', 'consent' => ['ip' => '10.0.0.2']]);
ok($id['number'] === 'L-000003', 'после восстановления нумерация заявок продолжается (' . $id['number'] . ')');
if (!$mysql) {
    // восстановление в пустую базу — как на новом сервере
    $fresh = Db::connect(['driver' => 'sqlite', 'path' => $tmp . '/fresh.sqlite']);
    Backup::restore($file, $fresh);
    ok(snapshot($fresh) === $snap, 'бэкап разворачивается в пустую базу');
}
// два бэкапа в одну секунду не затирают друг друга (страховочный перед восстановлением)
$f1 = Backup::create();
$f2 = Backup::create();
ok($f1 !== $f2 && is_file($f1) && is_file($f2), 'два бэкапа в одну секунду — два разных файла');
unlink($f1);
unlink($f2);
// обрезанный файл
$cut = $tmp . '/backups/db-2026-10-09_120001.' . $db->driver . '.sql.gz';
$gz = gzopen($cut, 'wb');
gzwrite($gz, substr($sqlText, 0, (int)(strlen($sqlText) * 0.6)));
gzclose($gz);
try {
    Backup::restore($cut, $mysql ? $db : Db::connect(['driver' => 'sqlite', 'path' => $tmp . '/cut.sqlite']));
    ok(false, 'обрезанный бэкап распознан');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), 'обрезан'), 'обрезанный бэкап распознан и не выдаётся за целый');
}
unlink($cut);
if ($mysql) {
    Backup::restore($file);
}

// ---------------------------------------------------------------- хранение 14 дней и расписание
echo "\nРасписание и хранение\n";
foreach (glob($tmp . '/backups/*') as $f) {
    unlink($f);
}
at('2026-10-09 02:30');
ok(Backup::daily() === 'skip', 'до 03:00 бэкап не делается');
at('2026-10-09 03:01');
$d = Backup::daily();
ok(is_array($d) && count(Backup::list()) === 1, 'после 03:00 — бэкап за сутки');
at('2026-10-09 15:00');
ok(Backup::daily() === 'skip' && count(Backup::list()) === 1, 'второй раз в тот же день — не повторяется');
at('2026-10-10 03:02');
foreach ([20, 15, 14, 13, 1] as $ago) {
    $day = App::now()->modify("-$ago days")->format('Y-m-d');
    touch($tmp . "/backups/db-{$day}_030000.{$db->driver}.sql.gz");
}
$d = Backup::daily();
$dates = array_column(Backup::list(), 'date');
ok($d['pruned'] === 2 && !in_array('2026-09-20', $dates, true) && !in_array('2026-09-25', $dates, true), 'старше 14 дней удалены (' . $d['pruned'] . ' шт.)');
ok(array_values(array_unique($dates)) === ['2026-10-10', '2026-10-09', '2026-09-27', '2026-09-26'], 'бэкапы за последние 14 дней на месте: ' . implode(', ', array_unique($dates)));
App::setNow(null);

// ---------------------------------------------------------------- вебхук
echo "\nВебхук\n";
ok(in_array('В site_url должен быть адрес с https:// — Telegram принимает вебхук только по HTTPS.', Webhook::problems(), true), 'по http:// подключать вебхук нельзя');
try {
    Webhook::set($bossId);
    ok(false, 'set() без HTTPS отклонён');
} catch (RuntimeException) {
    ok(!in_array('setWebhook', array_column($sent, 0), true), 'set() без HTTPS отклонён, Telegram не вызывался');
}
$c2 = $cfg;
$c2['site_url'] = 'https://ВАШ-ДОМЕН.ru';
App::init($c2);
ok(Webhook::problems() !== [], 'с доменом-заглушкой подключать нельзя');
$c2['site_url'] = 'https://tszh-p15.ru';
$c2['telegram']['webhook_secret'] = 'short';
App::init($c2);
ok(Webhook::problems() !== [], 'со слишком коротким секретом подключать нельзя');
$c2['telegram']['webhook_secret'] = $cfg['telegram']['webhook_secret'];
App::init($c2);
ok(Webhook::problems() === [], 'боевой https-домен и секрет — можно подключать');
$info = Webhook::set($bossId);
$call = array_values(array_filter($sent, static fn($s) => $s[0] === 'setWebhook'))[0][1] ?? [];
ok(($call['url'] ?? '') === 'https://tszh-p15.ru/bot/webhook.php', 'setWebhook на https://…/bot/webhook.php');
ok(($call['secret_token'] ?? '') === $cfg['telegram']['webhook_secret'], 'setWebhook передаёт secret_token');
ok(($call['allowed_updates'] ?? []) === ['message', 'callback_query'] && ($call['drop_pending_updates'] ?? null) === false, 'только нужные типы обновлений, очередь Telegram не сбрасывается');
ok(end($sent)[0] === 'getWebhookInfo' && Webhook::assess($info)[0] === 'ok', 'сразу после подключения — проверка getWebhookInfo: ок');
ok((int)$db->value("SELECT COUNT(*) FROM audit_log WHERE entity = 'webhook' AND action = 'set'") === 1, 'подключение записано в audit_log');
ok(Webhook::assess(['url' => ''])[0] === 'fail', 'getWebhookInfo без адреса — ошибка «не подключён»');
ok(Webhook::assess(['url' => 'https://evil.example/hook'])[0] === 'fail', 'вебхук на чужой адрес — ошибка');
ok(Webhook::assess(['url' => Webhook::url(), 'last_error_date' => time() - 60, 'last_error_message' => 'Wrong response from the webhook: 403 Forbidden'])[0] === 'fail', 'свежая ошибка доставки — ошибка');
ok(Webhook::assess(['url' => Webhook::url(), 'last_error_date' => time() - 86400 * 2, 'pending_update_count' => 0])[0] === 'ok', 'старая ошибка доставки — уже не тревога');

// ---------------------------------------------------------------- /crm/health
echo "\nСостояние системы\n";
$_SERVER['DOCUMENT_ROOT'] = $tmp . '/public';
@mkdir($tmp . '/public');
file_put_contents($cfg['install_lock'], 'x');
$byTitle = static fn(array $checks) => array_column($checks, null, 'title');
Settings::set('cron_last_run', null);
$h = $byTitle(Health::run());
ok($h['Cron']['status'] === 'fail', 'cron ни разу не запускался — красный');
Settings::set('cron_last_run', Db::ts());
Settings::set('holidays', ['2026-11-04', '2026-12-31']);
Backup::create();
$checks = Health::run();
$h = $byTitle($checks);
ok($h['Cron']['status'] === 'ok' && $h['Бэкап базы']['status'] === 'ok', 'cron и бэкап за сутки — зелёные');
ok($h['Вебхук бота']['status'] === 'ok', 'вебхук подключён — зелёный');
ok($h['Таблицы']['status'] === 'ok' && $h['Подключение']['status'] === 'ok', 'база и таблицы — зелёные');
ok($h['Закрытая папка вне сайта']['status'] === 'ok', 'private/ вне публичной папки — зелёный');
ok($h['Мастер установки']['status'] === 'ok', 'установка завершена, install.php нет — зелёный');
touch($tmp . '/public/install.php');
ok($byTitle(Health::run(false))['Мастер установки']['status'] === 'warn', 'install.php остался на сайте — предупреждение');
$_SERVER['DOCUMENT_ROOT'] = dirname(APP_PRIVATE);
ok($byTitle(Health::run(false))['Закрытая папка вне сайта']['status'] === 'fail', 'private/ внутри публичной папки — красный');
$_SERVER['DOCUMENT_ROOT'] = $tmp . '/public';
Log::error('test_failure', ['err' => 'Ошибка при отправке токеном ' . $cfg['telegram']['bot_token'], 'phone' => '+79035550011']);
$h = $byTitle(Health::run(false));
ok($h['Последняя ошибка']['status'] === 'warn' && str_contains($h['Последняя ошибка']['detail'], 'test_failure'), 'последняя ошибка видна');
$json = json_encode(Health::run(), JSON_UNESCAPED_UNICODE);
$leak = array_filter([$cfg['telegram']['bot_token'], $cfg['telegram']['webhook_secret'], $cfg['app_secret'], $cfg['install_key'], '79035550011'], static fn($s) => str_contains($json, $s));
ok(!$leak, 'в проверках нет токена, секретов, ключа установки и телефонов');
$hookDown = $hook;
Telegram::$transport = static fn() => ['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized', '_http' => 401];
$h = $byTitle(Health::run());
ok($h['Вебхук бота']['status'] === 'fail' && str_contains($h['Вебхук бота']['detail'], 'Unauthorized'), 'неверный токен — красный с причиной');
ok(Health::overall(Health::run(false)) !== 'ok' && Health::overall([['status' => 'ok']]) === 'ok', 'общий итог считается по худшей проверке');

// уборка
foreach (array_merge(glob($tmp . '/backups/{,.}*', GLOB_BRACE) ?: [], glob($tmp . '/public/*') ?: [], glob($tmp . '/*') ?: []) as $f) {
    is_file($f) && @unlink($f);
}
@rmdir($tmp . '/backups');
@rmdir($tmp . '/public');
@rmdir($tmp);
echo "\nИтого ошибок: $fails\n";
exit($fails ? 1 : 0);
