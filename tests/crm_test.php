<?php
declare(strict_types=1);
/**
 * CRM без браузера: отчёты (источник первого касания, ночные заявки, воронка, первый ответ),
 * обезличивание, автоудаление по сроку, напоминания о задачах, ответ при недоступном Telegram.
 * Запуск: php tests/crm_test.php   (или TEST_DB=mysql …)
 */

putenv('TSZH_CONFIG=/nonexistent');
require __DIR__ . '/../private/bootstrap.php';
$cfg = require __DIR__ . '/../private/config.example.php';
$cfg['app_secret'] = str_repeat('c', 64);
$cfg['site_url'] = 'https://example.test';
$cfg['telegram']['bot_token'] = 'TEST';
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
Kb::seed();
Settings::set('holidays', []);
echo "База: {$db->driver}\n";

$sent = [];
$down = false;
Telegram::$transport = function (string $m, array $p) use (&$sent, &$down) {
    if ($down) {
        return ['ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway', '_http' => 502];
    }
    $sent[] = [$m, $p];
    return ['ok' => true, 'result' => ['message_id' => count($sent)]];
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

$now = Db::ts();
$boss = $db->insert('users', ['login' => 'boss', 'name' => 'Руководитель', 'role' => 'admin', 'password_hash' => 'x', 'telegram_id' => 900, 'created_at' => $now]);
$anna = $db->insert('users', ['login' => 'anna', 'name' => 'Анна', 'role' => 'manager', 'password_hash' => 'x', 'telegram_id' => 901, 'created_at' => $now]);

// Сценарий 3 + 6: бот через неделю → сайт с тем же телефоном, оплата → выручка в источнике первого касания
at('2026-10-01 10:00');
$b = Leads::capture(['channel' => 'bot', 'name' => 'Вера', 'telegram_id' => 7001, 'phone' => '+79001112233', 'text' => 'Ключ от домофона',
    'consent' => ['telegram_id' => 7001], 'source' => ['channel' => 'bot', 'bot_start_tag' => 'qr_lift_2']]);
CrmDeals::move(Deals::find($b['deal_id']), 'lost', 'Купили сами', $anna);
at('2026-10-08 11:00');
$s = Leads::capture(['channel' => 'site', 'name' => 'Вера', 'phone' => '8 900 111-22-33', 'text' => 'Нужен мастер', 'consent' => ['ip' => '1.1.1.1'],
    'source' => ['channel' => 'site_form', 'utm_source' => 'yandex', 'utm_campaign' => 'autumn']]);
ok($s['contact_id'] === $b['contact_id'] && $s['created'], 'бот, потом сайт с тем же телефоном: один контакт, вторая сделка (первая закрыта)');
CrmDeals::update(Deals::find($s['deal_id']), ['amount' => '1 800,50'], $anna);
CrmDeals::move(Deals::find($s['deal_id']), 'paid', null, $anna);
$src = (new Reports('2026-10-01', '2026-10-09'))->sources();
$row = array_values(array_filter($src, fn($r) => $r['paid'] > 0))[0] ?? null;
ok($row && $row['channel'] === 'Telegram-бот' && abs($row['revenue'] - 1800.50) < 0.01, 'выручка 1 800,50 ₽ отнесена к первому касанию — Telegram-бот (а не к последней кампании сайта)');

// Сценарий 5: заявка в 23:00 учтена как ночная
at('2026-10-09 23:00');
$n = Leads::capture(['channel' => 'site', 'name' => 'Ночной', 'phone' => '+79005556677', 'text' => 'Ночью', 'consent' => ['ip' => '1.1.1.2']]);
$h = (new Reports('2026-10-09', '2026-10-09'))->hours();
ok($h['hours'][23] === 1 && $h['off'] === 1, 'ночная заявка — в отчёте по часам (23:00) и в счётчике нерабочего времени');
$task = $db->one("SELECT * FROM tasks WHERE deal_id = ? AND kind = 'callback'", [$n['deal_id']]);
ok($task && $task['due_at'] === '2026-10-12 09:00:00', 'у ответственного задача на понедельник 09:00');

// Напоминание о задаче приходит в Telegram, когда наступает срок
at('2026-10-12 09:01');
$cnt = CrmDeals::remindTasks();
$rem = $db->one("SELECT * FROM outbox WHERE kind = 'task_reminder' ORDER BY id DESC LIMIT 1");
ok($cnt >= 1 && $rem && str_contains($rem['payload'], $n['number']), 'cron: напоминание о задаче поставлено в отправку');
ok(CrmDeals::remindTasks() === 0, 'повторно то же напоминание не отправляется');

// Первый ответ считается в рабочих минутах
at('2026-10-12 09:30');
$deal = Deals::find($n['deal_id']);
ok(CrmDeals::reply($deal, 'Здравствуйте!', (int)$deal['manager_id']) !== null, 'у клиента нет Telegram — CRM не даёт «ответить в Telegram», просит позвонить');
CrmDeals::move($deal, 'contacted', null, (int)$deal['manager_id']);   // позвонил по телефону
$fr = (new Reports('2026-10-09', '2026-10-12'))->firstResponse();
ok($fr['total']['n'] === 1 && $fr['total']['median'] === 30, 'время до первого ответа: пятница 23:00 → понедельник 9:30 = 30 рабочих минут');

// Ответ клиенту, когда Telegram недоступен: в очереди, потом доставлен (сценарий 4 и 8)
$v = Deals::find($s['deal_id']);
$db->update('deals', ['stage' => 'contacted', 'closed_at' => null], 'id = ?', [$v['id']]);
$down = true;
$err = CrmDeals::reply(Deals::find($v['id']), 'Мастер придёт в среду', $anna);
$m = $db->one("SELECT * FROM messages WHERE deal_id = ? AND direction = 'out' ORDER BY id DESC LIMIT 1", [$v['id']]);
ok($err === null && $m['delivery'] === 'queued', 'Telegram недоступен: ответ сохранён, статус «в очереди»');
$down = false;
at('2026-10-12 10:00');
Telegram::flushOutbox(50);
ok($db->value('SELECT delivery FROM messages WHERE id = ?', [$m['id']]) === 'sent', 'связь вернулась: ответ доставлен, статус обновлён');
$last = end($sent);
ok($last[1]['chat_id'] === 7001 && str_contains($last[1]['text'], 'Мастер придёт в среду'), 'ответ ушёл клиенту в его Telegram');

// Воронка
$f = (new Reports('2026-10-01', '2026-10-12'))->funnel();
ok($f['total'] === 3 && $f['rows'][3]['count'] === 1 && $f['lost'] === 1, 'воронка: 3 заявки, 1 оплачена, 1 отказ');

// Сценарий 7: «Удалить мои данные» → задача руководителю → обезличивание
$db->insert('tasks', ['deal_id' => $v['id'], 'user_id' => $boss, 'kind' => 'delete_request', 'title' => 'Запрос на удаление персональных данных (контакт #' . $v['contact_id'] . ')', 'due_at' => Db::ts(), 'created_at' => Db::ts()]);
Privacy::anonymize((int)$v['contact_id'], $boss, 'запрос субъекта');
$c = Contacts::find((int)$v['contact_id']);
ok($c['phone'] === null && $c['telegram_id'] === null && $c['name'] === null && $c['anonymized_at'], 'обезличено: телефон, Telegram, имя удалены');
ok((int)$db->value("SELECT COUNT(*) FROM messages m JOIN deals d ON d.id = m.deal_id WHERE d.contact_id = ? AND m.text <> ? AND m.author <> 'system'", [$c['id'], Privacy::REDACTED]) === 0, 'тексты переписки удалены');
ok((int)$db->value("SELECT COUNT(*) FROM tasks WHERE kind = 'delete_request' AND done_at IS NULL") === 0, 'задача на удаление закрыта');
ok((int)$db->value("SELECT COUNT(*) FROM outbox WHERE chat_id = 7001") === 0, 'в очереди не осталось Telegram ID клиента');
ok((float)$db->value("SELECT amount FROM deals WHERE id = ?", [$s['deal_id']]) === 1800.5, 'сумма и этап для отчётов сохранились');
ok((int)$db->value("SELECT COUNT(*) FROM audit_log WHERE entity = 'contact' AND action = 'anonymize'") === 1, 'обезличивание записано в audit_log');

// Автоудаление по сроку хранения
at('2027-10-20 03:00');
$n2 = Privacy::retention();
ok($n2 === 1 && Contacts::find($n['contact_id'])['anonymized_at'], 'через 12 месяцев без активности — автоматическое обезличивание');

App::setNow(null);
echo "\nИтого ошибок: $fails\n";
exit($fails ? 1 : 0);
