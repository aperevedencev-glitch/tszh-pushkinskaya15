<?php
declare(strict_types=1);
/**
 * Проверки ядра без браузера и без Telegram.
 * Запуск:  php tests/core_test.php            (SQLite в памяти)
 *          TEST_DB=mysql php tests/core_test.php   (MySQL: TEST_DB_NAME/USER/PASS)
 */

putenv('TSZH_CONFIG=/nonexistent');
require __DIR__ . '/../private/bootstrap.php';

$cfg = require __DIR__ . '/../private/config.example.php';
$cfg['app_secret'] = str_repeat('a', 64);
$cfg['site_url'] = 'https://example.test';
$cfg['_configured'] = true;
App::init($cfg);

if (getenv('TEST_DB') === 'mysql') {
    $db = Db::connect(['driver' => 'mysql', 'host' => 'localhost', 'name' => getenv('TEST_DB_NAME') ?: 'tszh_test',
        'user' => getenv('TEST_DB_USER') ?: 'tszh', 'pass' => getenv('TEST_DB_PASS') ?: '']);
    $db->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($db->all('SHOW TABLES') as $t) {
        $db->pdo->exec('DROP TABLE `' . array_values($t)[0] . '`');
    }
    $db->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
} else {
    $db = Db::fromPdo(new PDO('sqlite::memory:'));
    $db->pdo->exec('PRAGMA foreign_keys = ON');
}
App::setDb($db);
Schema::create($db);
echo "База: {$db->driver}\n";

$fails = 0;
function ok(bool $cond, string $msg): void
{
    global $fails;
    echo ($cond ? '✓ ' : '✗ ') . $msg . "\n";
    if (!$cond) {
        $fails++;
    }
}
function at(string $s): void
{
    App::setNow(new DateTimeImmutable($s, new DateTimeZone('Europe/Moscow')));
}
function lead(array $over = []): array
{
    return Leads::capture($over + [
        'channel' => 'site', 'name' => 'Ирина', 'phone' => '+79161234567', 'text' => 'Не работает домофон',
        'consent' => ['ip' => '10.0.0.1'], 'source' => ['channel' => 'site_form', 'utm_source' => 'qr', 'utm_campaign' => 'lift_2'],
    ]);
}

// --- Нормализация ---
ok(Phone::normalize('8 (916) 123-45-67') === '+79161234567', 'телефон 8 (916)… → +79161234567');
ok(Phone::normalize('9161234567') === '+79161234567', 'телефон из 10 цифр');
ok(Phone::normalize('+1 202 555 0100') === null, 'иностранный номер отклоняется');
ok(Phone::telegramUsername('https://t.me/Ivan_Petrov') === 'ivan_petrov', 'ник из ссылки t.me');
ok(Phone::telegramUsername('@1abc') === null, 'некорректный ник отклоняется');

// --- Ссылки в бот ---
$start = Deeplink::forDeal('L-000123');
ok(Deeplink::parse($start) === ['deal' => 'L-000123'], 'подписанная метка заявки распознаётся');
ok(isset(Deeplink::parse('L000124_' . substr($start, -10))['invalid']), 'подделанный номер заявки отклоняется');
ok(Deeplink::campaignTag('Лифт 2 / осень', 'qr') === 'site' || Deeplink::campaignTag('lift 2', null) === 'site_lift-2', 'метка кампании очищается');
ok(strlen(Deeplink::campaignTag(str_repeat('x', 200))) <= 64, 'метка кампании не длиннее 64 символов');

// --- Рабочие часы ---
$wh = new WorkHours(['days' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '18:00'], ['2026-11-04'], []);
$fri = new DateTimeImmutable('2026-10-09 23:00');
ok(!$wh->isWorkingTime($fri), 'пятница 23:00 — нерабочее время');
ok($wh->nextStart($fri)->format('Y-m-d H:i') === '2026-10-12 09:00', 'следующее начало после пятницы 23:00 — понедельник 09:00');
ok($wh->nextStart(new DateTimeImmutable('2026-11-03 19:00'))->format('Y-m-d H:i') === '2026-11-05 09:00', 'праздник 4 ноября пропускается');
ok($wh->workingMinutes(new DateTimeImmutable('2026-10-09 23:00'), new DateTimeImmutable('2026-10-12 09:30')) === 30, 'пятница 23:00 → понедельник 9:30 = 30 рабочих минут');

// --- Менеджеры для распределения ---
$now = Db::ts();
$m1 = $db->insert('users', ['login' => 'm1', 'name' => 'Менеджер 1', 'role' => 'manager', 'password_hash' => 'x', 'telegram_id' => 111, 'created_at' => $now]);
$m2 = $db->insert('users', ['login' => 'm2', 'name' => 'Менеджер 2', 'role' => 'manager', 'password_hash' => 'x', 'telegram_id' => 222, 'created_at' => $now]);
Settings::set('holidays', []);

// --- Заявка в рабочее время ---
at('2026-10-09 14:00');
$r1 = lead();
ok($r1['created'] && $r1['number'] === 'L-000001', 'первая заявка создаёт сделку L-000001');
$d1 = Deals::find($r1['deal_id']);
ok($d1['stage'] === 'new' && (int)$d1['manager_id'] === $m1, 'этап «Новая», ответственный — первый менеджер');
ok((int)$db->value('SELECT COUNT(*) FROM consents WHERE contact_id = ?', [$r1['contact_id']]) === 1, 'согласие записано');
ok($db->value('SELECT utm_campaign FROM sources WHERE deal_id = ?', [$r1['deal_id']]) === 'lift_2', 'UTM сохранены в источнике');
$ob = $db->one('SELECT * FROM outbox ORDER BY id DESC LIMIT 1');
ok($ob && (int)$ob['chat_id'] === 111 && str_contains($ob['payload'], 'L-000001'), 'уведомление менеджеру поставлено в очередь');
ok(!str_contains($ob['payload'], 'Ирина') && !str_contains($ob['payload'], '916'), 'в уведомлении нет имени и телефона');
ok(str_contains($ob['payload'], 'example.test/crm/deal.php'), 'в уведомлении кнопка «Открыть в CRM»');
ok((int)$db->value("SELECT COUNT(*) FROM tasks WHERE deal_id = ?", [$r1['deal_id']]) === 0, 'днём задача «перезвонить» не создаётся');

// --- Повтор той же заявки (двойной клик) ---
$r2 = lead();
ok($r2['duplicate'] && $r2['number'] === 'L-000001', 'повтор за 2 минуты не создаёт дубль');
ok((int)$db->value('SELECT COUNT(*) FROM messages WHERE deal_id = ?', [$r1['deal_id']]) === 1, 'сообщение не задвоено');

// --- Новое сообщение при открытой сделке ---
at('2026-10-09 15:00');
$r3 = lead(['text' => 'И ещё свет в лифте', 'phone' => '8 916 123 45 67']);
ok(!$r3['created'] && $r3['number'] === 'L-000001', 'при открытой сделке сообщение дописывается в неё');

// --- Сделка закрыта → новая сделка, контакт тот же ---
$db->update('deals', ['stage' => 'paid', 'closed_at' => Db::ts()], 'id = ?', [$r1['deal_id']]);
$r4 = lead(['text' => 'Новый вопрос']);
ok($r4['created'] && $r4['contact_id'] === $r1['contact_id'] && $r4['number'] === 'L-000002', 'после закрытия — новая сделка у того же контакта');
ok((int)Deals::find($r4['deal_id'])['manager_id'] === $m2, 'распределение по очереди: второй менеджер');

// --- Ночная заявка ---
at('2026-10-09 23:00');
$r5 = lead(['phone' => '+79990001122', 'name' => 'Ночной', 'text' => 'Ночью']);
$d5 = Deals::find($r5['deal_id']);
$task = $db->one('SELECT * FROM tasks WHERE deal_id = ?', [$r5['deal_id']]);
ok((int)$d5['is_off_hours'] === 1, 'ночная заявка помечена как нерабочее время');
ok($task && $task['kind'] === 'callback' && $task['due_at'] === '2026-10-12 09:00:00' && (int)$task['user_id'] === (int)$d5['manager_id'], 'задача ответственному на понедельник 09:00');

// --- Без согласия ничего не сохраняем ---
$before = (int)$db->value('SELECT COUNT(*) FROM contacts');
try {
    Leads::capture(['channel' => 'site', 'name' => 'Без согласия', 'phone' => '+79990009999', 'text' => 'x']);
    ok(false, 'без согласия должна быть ошибка');
} catch (InvalidArgumentException) {
    ok((int)$db->value('SELECT COUNT(*) FROM contacts') === $before, 'без согласия контакт не создан');
}

// --- Склейка: сначала бот (telegram_id), потом сайт с тем же телефоном ---
at('2026-10-10 10:00');
$b = Leads::capture(['channel' => 'bot', 'name' => 'Олег', 'telegram_id' => 555001, 'text' => 'Из бота', 'consent' => ['telegram_id' => 555001], 'source' => ['channel' => 'bot', 'bot_start_tag' => 'qr_lift']]);
$s = Leads::capture(['channel' => 'site', 'name' => 'Олег', 'phone' => '+79995550001', 'text' => 'С сайта', 'consent' => ['ip' => '10.0.0.2']]);
ok($b['contact_id'] !== $s['contact_id'], 'бот без телефона и сайт с телефоном — пока два контакта');
// набранный руками телефон — только заявление: не склеиваем, а помечаем возможный дубль для руководителя
$m = Leads::capture(['channel' => 'bot', 'telegram_id' => 555001, 'phone' => '+79995550001', 'text' => 'Мой телефон', 'consent' => ['telegram_id' => 555001]]);
ok($m['contact_id'] === $b['contact_id'] && $m['possible_duplicate'] === $s['contact_id'], 'телефон набран в боте руками — контакты НЕ склеены, отмечен возможный дубль');
ok(Contacts::find($s['contact_id'])['telegram_id'] === null, 'Telegram не привязан к контакту, найденному по набранному телефону');
ok((int)$db->value("SELECT COUNT(*) FROM tasks WHERE kind = 'duplicate' AND done_at IS NULL") === 1, 'руководителю задача «проверить дубль»');
// телефон отправлен кнопкой «Поделиться номером» — его подтвердил Telegram, склеиваем
$m = Leads::capture(['channel' => 'bot', 'telegram_id' => 555001, 'phone' => '+79995550001', 'phone_verified' => true, 'text' => 'Мой телефон', 'consent' => ['telegram_id' => 555001]]);
$left = (int)$db->value('SELECT COUNT(*) FROM contacts WHERE telegram_id = 555001 OR phone = ?', ['+79995550001']);
ok($left === 1, 'телефон подтверждён кнопкой Telegram — контакты склеены в один');
ok((int)$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'merge'") === 1, 'склейка записана в журнал');

// --- Чужой номер не открывает доступ к переписке жильца ---
$victim = Leads::capture(['channel' => 'site', 'name' => 'Иванова Мария', 'phone' => '+79161230077', 'text' => 'Долг по кв. 42', 'consent' => ['ip' => '10.0.0.5']]);
ok($victim['contact_created'] === true, 'первая заявка жильца — новый контакт (ему можно дать ссылку «Продолжить в Telegram»)');
$evil = Leads::capture(['channel' => 'site', 'name' => 'Злоумышленник', 'phone' => '8 (916) 123-00-77', 'text' => 'хочу ответы', 'consent' => ['ip' => '6.6.6.6']]);
ok($evil['contact_created'] === false, 'тот же телефон с сайта — контакт уже есть, ссылку с привязкой к заявке не выдаём');
$evilBot = Leads::capture(['channel' => 'bot', 'telegram_id' => 666001, 'phone' => '+79161230077', 'text' => 'это я', 'consent' => ['telegram_id' => 666001]]);
ok($evilBot['contact_id'] !== $victim['contact_id'] && Contacts::find($victim['contact_id'])['telegram_id'] === null, 'в боте набран чужой телефон — Telegram злоумышленника к жильцу не привязан');
ok((int)$db->value('SELECT COUNT(*) FROM deals WHERE contact_id = ?', [$evilBot['contact_id']]) === 1 && $evilBot['deal_id'] !== $victim['deal_id'], 'его сообщение — в отдельной заявке, не в заявке жильца');
try {
    Leads::capture(['channel' => 'bot', 'telegram_id' => 666002, 'phone' => '+79161230077', 'text' => 'без согласия']);
    ok(false, 'чужое согласие по набранному телефону не «наследуется»');
} catch (InvalidArgumentException) {
    ok(true, 'чужое согласие по набранному телефону не «наследуется»');
}
Leads::capture(['channel' => 'site', 'name' => 'Злоумышленник', 'phone' => '+79001112233', 'telegram_username' => '@victim_nick', 'text' => 'x', 'consent' => ['ip' => '6.6.6.6']]);
$realVictim = Leads::capture(['channel' => 'bot', 'telegram_id' => 777001, 'telegram_username' => 'victim_nick', 'text' => 'мой вопрос', 'consent' => ['telegram_id' => 777001]]);
ok($realVictim['contact_created'] === true, 'ник, набранный на сайте чужим человеком, не склеивает его контакт с настоящим владельцем ника');

// --- «Застолбить» номер жильца заранее нельзя ---
// через бот: злоумышленник первым набирает номер жильца руками
$pre = Leads::capture(['channel' => 'bot', 'telegram_id' => 666101, 'phone' => '+79161230101', 'text' => 'занимаю номер', 'consent' => ['telegram_id' => 666101]]);
ok(Contacts::find($pre['contact_id'])['phone'] === null, 'набранный в боте номер не записывается в контакт (только в текст заявки)');
ok((bool)$db->value("SELECT 1 FROM messages WHERE deal_id = ? AND text LIKE '%+79161230101%'", [$pre['deal_id']]), 'менеджер видит этот номер в заявке');
$vic = Leads::capture(['channel' => 'site', 'name' => 'Жилец', 'phone' => '+79161230101', 'text' => 'мой вопрос', 'consent' => ['ip' => '10.0.0.11']]);
ok($vic['contact_id'] !== $pre['contact_id'] && $vic['contact_created'], 'заявка жильца с сайта — в его собственный контакт, не к тому, кто набрал номер раньше');
// через сайт + ссылку: злоумышленник оставляет заявку с номером жильца и привязывает свой Telegram
$pre2 = Leads::capture(['channel' => 'site', 'name' => 'Злоумышленник', 'phone' => '+79161230202', 'text' => 'x', 'consent' => ['ip' => '6.6.6.6']]);
$db->update('contacts', ['telegram_id' => 666102], 'id = ?', [$pre2['contact_id']]);   // так делает переход по ссылке «Продолжить в Telegram»
$vic2 = Leads::capture(['channel' => 'site', 'name' => 'Жилец', 'phone' => '8 916 123-02-02', 'text' => 'Долг по кв. 42?', 'consent' => ['ip' => '10.0.0.12']]);
ok($vic2['contact_id'] !== $pre2['contact_id'] && $vic2['possible_duplicate'] === $pre2['contact_id'], 'номер совпал с контактом, у которого Telegram без подтверждённого номера, — отдельный контакт и пометка «дубль»');
ok((int)$db->value('SELECT COUNT(*) FROM deals WHERE contact_id = ?', [$pre2['contact_id']]) === 1, 'заявка жильца не попала к контакту злоумышленника — ответы туда не уйдут');
// подтверждённый номер: сайт присоединяется к контакту, а ник с сайта не перезаписывает сохранённый
$ok3 = Leads::capture(['channel' => 'bot', 'telegram_id' => 777201, 'telegram_username' => 'real_owner', 'phone' => '+79161230303', 'phone_verified' => true, 'text' => 'x', 'consent' => ['telegram_id' => 777201]]);
$s3 = Leads::capture(['channel' => 'site', 'name' => 'Кто-то', 'phone' => '+79161230303', 'telegram_username' => '@attacker_nick', 'text' => 'y', 'consent' => ['ip' => '6.6.6.6']]);
ok($s3['contact_id'] === $ok3['contact_id'] && Contacts::find($ok3['contact_id'])['phone_verified'] == 1, 'номер подтверждён Telegram — заявка с сайта в тот же контакт (сценарий 3)');
ok(Contacts::find($ok3['contact_id'])['telegram_username'] === 'real_owner', 'ник, набранный на сайте, не перезаписывает ник из Telegram');

// --- объединение не отменяет отзыв согласия ---
$x = Leads::capture(['channel' => 'site', 'name' => 'Икс', 'phone' => '+79161230404', 'text' => 'x', 'consent' => ['ip' => '1.1.1.1']]);
Contacts::addConsent($x['contact_id'], 'site', null, null, 'revoked');
$y = Leads::capture(['channel' => 'site', 'name' => 'Игрек', 'phone' => '+79161230505', 'text' => 'y', 'consent' => ['ip' => '1.1.1.2']]);
Contacts::merge($x['contact_id'], $y['contact_id']);
ok(!Contacts::hasConsent($y['contact_id']), 'после объединения отозванное согласие остаётся отозванным');

// --- обновление уже установленной базы: недостающие столбцы добавляются сами ---
$old = Db::fromPdo(new PDO('sqlite::memory:'));
foreach (Schema::statements('sqlite') as $st) {
    $old->pdo->exec($st);
}
$old->pdo->exec('ALTER TABLE users DROP COLUMN session_epoch');
$old->pdo->exec('ALTER TABLE contacts DROP COLUMN phone_verified');
$added = Schema::migrate($old);
ok($added === 2 && Schema::migrate($old) === 0, 'миграция добавила 2 столбца в старую базу, повторный запуск ничего не меняет');
$old->pdo->query('SELECT session_epoch FROM users LIMIT 0');
ok(true, 'после миграции вход в CRM работает со старой базой (столбец session_epoch есть)');

// --- Журнал без персональных данных ---
$audit = json_encode($db->all('SELECT details FROM audit_log'), JSON_UNESCAPED_UNICODE);
ok(!preg_match('/\+79|Ирина|Олег/u', $audit), 'в audit_log нет телефонов и имён');

App::setNow(null);
echo "\nИтого ошибок: $fails\n";
exit($fails ? 1 : 0);
