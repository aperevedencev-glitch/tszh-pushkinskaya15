<?php
declare(strict_types=1);
/**
 * Сценарии Telegram-бота без сети: Telegram API подменён, время зафиксировано.
 * Запуск: php tests/bot_test.php   (или TEST_DB=mysql …)
 */

putenv('TSZH_CONFIG=/nonexistent');
require __DIR__ . '/../private/bootstrap.php';

$cfg = require __DIR__ . '/../private/config.example.php';
$cfg['app_secret'] = str_repeat('b', 64);
$cfg['site_url'] = 'https://example.test';
$cfg['telegram']['bot_token'] = 'TEST';
$cfg['telegram']['rate_limit'] = ['max' => 30, 'window_sec' => 60];
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

// --- подменённый Telegram: записываем вызовы, можем «уронить» сеть ---
$calls = [];
$tgMode = 'ok';
$msgId = 500;
Telegram::$transport = function (string $method, array $params) use (&$calls, &$tgMode, &$msgId) {
    $calls[] = [$method, $params];
    if ($tgMode === 'down') {
        return ['ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway', '_http' => 502];
    }
    if ($tgMode === 'blocked') {
        return ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user', '_http' => 403];
    }
    return ['ok' => true, 'result' => ['message_id' => ++$msgId]];
};

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
$upd = 1000;
function user(int $id, string $first = 'Анна', ?string $username = 'anna_k'): array
{
    return ['id' => $id, 'is_bot' => false, 'first_name' => $first, 'username' => $username];
}
function send(array $from, string $text = '', array $extra = [], string $chatType = 'private'): void
{
    global $upd;
    Bot::handle(['update_id' => ++$upd, 'message' => ['message_id' => $upd, 'from' => $from,
        'chat' => ['id' => $from['id'], 'type' => $chatType], 'date' => time()] + ($text !== '' ? ['text' => $text] : []) + $extra]);
}
function press(array $from, string $data): void
{
    global $upd;
    Bot::handle(['update_id' => ++$upd, 'callback_query' => ['id' => 'cq' . $upd, 'from' => $from, 'data' => $data,
        'message' => ['message_id' => 77, 'chat' => ['id' => $from['id'], 'type' => 'private']]]]);
}
function lastText(): string
{
    global $calls;
    for ($i = count($calls) - 1; $i >= 0; $i--) {
        if ($calls[$i][0] === 'sendMessage') {
            return (string)$calls[$i][1]['text'];
        }
    }
    return '';
}
function lastMarkup(): array
{
    global $calls;
    for ($i = count($calls) - 1; $i >= 0; $i--) {
        if ($calls[$i][0] === 'sendMessage') {
            return $calls[$i][1]['reply_markup'] ?? [];
        }
    }
    return [];
}

$now = Db::ts();
$m1 = $db->insert('users', ['login' => 'm1', 'name' => 'Менеджер 1', 'role' => 'manager', 'password_hash' => 'x', 'created_at' => $now]);
$admin = $db->insert('users', ['login' => 'boss', 'name' => 'Руководитель', 'role' => 'admin', 'password_hash' => 'x', 'created_at' => $now]);

// 1. /start с меткой рекламы
at('2026-10-12 10:00');   // понедельник, рабочее время
$anna = user(70001);
send($anna, '/start site_lift_2');
ok(str_contains(lastText(), 'бот ТСЖ') && str_contains(lastText(), 'не человек'), '/start: бот здоровается и говорит, что он бот');
$kb = json_encode(lastMarkup(), JSON_UNESCAPED_UNICODE);
ok(str_contains($kb, 'Оставить заявку') && str_contains($kb, 'Цены и сроки') && str_contains($kb, 'Частые вопросы') && str_contains($kb, 'Связаться с менеджером'), 'меню из четырёх кнопок + «Удалить мои данные»');
ok((int)$db->value('SELECT COUNT(*) FROM contacts') === 0, 'до согласия в CRM ничего не сохранено');

// 2. Заявка по шагам
send($anna, Bot::BTN_LEAD);
ok(str_contains(lastText(), '1/4'), 'заявка: шаг 1 — имя');
send($anna, 'Анна Ковалёва');
ok(str_contains(json_encode(lastMarkup()), 'request_contact'), 'шаг 2 — кнопка «Поделиться номером»');
send($anna, '', ['contact' => ['phone_number' => '79161112233', 'user_id' => 99999, 'first_name' => 'Чужой']]);
ok(str_contains(lastText(), 'другого человека'), 'чужой контакт не принимается');
send($anna, '', ['contact' => ['phone_number' => '79161112233', 'user_id' => 70001, 'first_name' => 'Анна']]);
ok(str_contains(lastText(), '3/4'), 'свой номер принят, шаг 3 — суть');
send($anna, 'Не работает домофон во 2 подъезде');
ok(str_contains(lastText(), '4/4') && str_contains(lastText(), '/politika/') && str_contains(json_encode(lastMarkup(), JSON_UNESCAPED_UNICODE), 'Согласен'), 'шаг 4 — согласие кнопкой со ссылкой на политику');
ok((int)$db->value('SELECT COUNT(*) FROM contacts') === 0, 'до нажатия «Согласен» контакт не создан');
press($anna, 'consent:lead');
ok((bool)preg_match('/Заявка <b>L-000001<\/b> принята/u', lastText()), 'после согласия — номер L-000001');
$c = $db->one('SELECT * FROM contacts WHERE telegram_id = 70001');
ok($c && $c['phone'] === '+79161112233' && $c['name'] === 'Анна Ковалёва' && $c['telegram_username'] === 'anna_k', 'контакт: имя, телефон, Telegram');
ok($db->value("SELECT channel FROM consents WHERE contact_id = ? AND telegram_id = 70001", [$c['id']]) === 'bot', 'согласие записано с каналом bot и telegram_id');
$src = $db->one('SELECT * FROM sources WHERE contact_id = ?', [$c['id']]);
ok($src && $src['channel'] === 'bot' && $src['bot_start_tag'] === 'site_lift_2', 'метка из /start сохранена в источнике');
ok($db->value("SELECT data FROM bot_sessions WHERE telegram_id = 70001") !== null && !str_contains((string)$db->value("SELECT data FROM bot_sessions WHERE telegram_id = 70001"), 'Ковал'), 'черновик с личными данными очищен');

// 3. Свободное сообщение — в ту же сделку
send($anna, 'И ещё: в лифте не горит свет');
ok(str_contains(lastText(), 'L-000001'), 'свободное сообщение добавлено в открытую заявку');
ok((int)$db->value("SELECT COUNT(*) FROM messages WHERE deal_id = 1 AND direction = 'in'") === 2, 'в переписке сделки два входящих');
ok((int)$db->value('SELECT COUNT(*) FROM deals') === 1, 'новая сделка не создана');

// 4. Частые вопросы из базы знаний
send($anna, Bot::BTN_FAQ);
$rows = lastMarkup()['inline_keyboard'] ?? [];
ok(count($rows) >= 8, 'частые вопросы — список кнопок из базы знаний (' . count($rows) . ')');
press($anna, $rows[5][0]['callback_data']);
ok(str_contains(lastText(), 'ПП РФ № 491'), 'ответ из базы знаний по кнопке');

// 5. Вопрос без ответа от нового человека → согласие → менеджеру
$oleg = user(70002, 'Олег', null);
send($oleg, 'Можно ли поставить шлагбаум во дворе?');
ok(str_contains(lastText(), 'Готового ответа у меня нет'), 'нет ответа — бот честно говорит');
press($oleg, 'ask');
ok(str_contains(json_encode(lastMarkup(), JSON_UNESCAPED_UNICODE), 'consent:ask'), 'перед передачей — согласие');
press($oleg, 'consent:ask');
ok(str_contains(lastText(), 'L-000002'), 'вопрос передан менеджеру новой заявкой L-000002');
ok($db->value("SELECT text FROM messages WHERE deal_id = 2") === 'Можно ли поставить шлагбаум во дворе?', 'текст вопроса в переписке сделки');

// 6. Вопрос с ответом в базе
$ira = user(70003, 'Ира');
send($ira, 'как передать показания счетчика воды?');
ok(str_contains(lastText(), 'показаний') && str_contains(lastText(), 'Нужно от заказчика'), 'вопрос про показания — ответ из базы (с плейсхолдером)');

// 7. Ночью: бот отвечает сразу, менеджеру задача на утро
at('2026-10-09 23:00');   // пятница
$night = user(70004, 'Пётр', 'petr_n');
send($night, Bot::BTN_MANAGER);
send($night, 'Прошу перезвонить по поводу счёта');
press($night, 'consent:ask');
ok(str_contains(lastText(), 'нерабочее время') && str_contains(lastText(), 'Пн–Пт, 09:00–18:00'), 'ночью: «менеджер свяжется в рабочее время»');
$deal = $db->one("SELECT * FROM deals WHERE number = 'L-000003'");
$task = $db->one('SELECT * FROM tasks WHERE deal_id = ?', [$deal['id']]);
ok((int)$deal['is_off_hours'] === 1 && $task && $task['due_at'] === '2026-10-12 09:00:00' && $task['kind'] === 'callback', 'сделка помечена ночной, задача на понедельник 09:00');

// 8. Авария
at('2026-10-12 11:00');
send($ira, 'Нас заливает сверху!');
$texts = array_map(fn($c) => $c[1]['text'] ?? '', array_slice($calls, -3));
ok((bool)array_filter($texts, fn($t) => str_contains($t, '112')), 'авария: сразу аварийный ответ с 112');

// 9. Группы и каналы игнорируются
$before = count($calls);
send(user(70005), 'привет', [], 'group');
ok(count($calls) === $before, 'сообщения из групп игнорируются');

// 10. Переход с сайта: та же сделка, без дубля
$site = Leads::capture(['channel' => 'site', 'name' => 'Мария', 'phone' => '+79035550011', 'text' => 'С сайта: течёт кран', 'consent' => ['ip' => '10.1.1.1'], 'source' => ['channel' => 'site_form', 'utm_source' => 'qr']]);
$maria = user(70006, 'Мария', 'maria_s');
send($maria, '/start ' . Deeplink::forDeal($site['number']));
ok(str_contains(lastText(), $site['number']) && str_contains(lastText(), 'у нас'), 'переход с сайта: бот узнаёт заявку ' . $site['number']);
ok((int)$db->value('SELECT telegram_id FROM contacts WHERE id = ?', [$site['contact_id']]) === 70006, 'telegram_id привязан к контакту с сайта');
send($maria, 'Добавлю фото позже, кран на кухне');
$cnt = (int)$db->value("SELECT COUNT(*) FROM messages WHERE deal_id = ? AND direction = 'in'", [$site['deal_id']]);
ok($cnt === 2 && (int)$db->value("SELECT COUNT(*) FROM deals WHERE contact_id = ?", [$site['contact_id']]) === 1, 'сообщение из бота — в ту же сделку, новой нет');
ok($db->value("SELECT channel FROM sources WHERE deal_id = ? ORDER BY id DESC LIMIT 1", [$site['deal_id']]) === 'site_bot', 'источник site→bot записан');

// 11. Чужая и поддельная ссылка
send(user(70007, 'Кто-то'), '/start ' . Deeplink::forDeal($site['number']));
ok(str_contains(lastText(), 'другим аккаунтом'), 'чужой человек по той же ссылке не получает доступ к заявке');
send(user(70008), '/start L000001_0000000000');
ok(str_contains(lastText(), 'не распознана'), 'поддельная ссылка отклоняется');

// 12. Удаление данных
send($anna, '/stop');
ok(str_contains(json_encode(lastMarkup()), 'del:yes'), '/stop — подтверждение');
press($anna, 'del:yes');
$t = $db->one("SELECT * FROM tasks WHERE kind = 'delete_request'");
ok($t && (int)$t['user_id'] === $admin, 'задача на удаление — руководителю');
ok($db->value("SELECT action FROM consents WHERE contact_id = ? ORDER BY id DESC LIMIT 1", [$c['id']]) === 'revoked', 'согласие отозвано');
$n = (int)$db->value("SELECT COUNT(*) FROM messages WHERE deal_id = 1");
send($anna, 'ещё одно сообщение');
ok((int)$db->value("SELECT COUNT(*) FROM messages WHERE deal_id = 1") === $n, 'после отзыва согласия сообщения не сохраняются');

// 13. Лимит сообщений
App::init(array_replace_recursive($cfg, ['telegram' => ['rate_limit' => ['max' => 3, 'window_sec' => 60]]]));
$spam = user(70009);
$before = count($calls);
for ($i = 0; $i < 6; $i++) {
    send($spam, 'спам ' . $i);
}
ok(count(array_filter(array_slice($calls, $before), fn($c) => $c[0] === 'sendMessage')) <= 3, 'после лимита бот перестаёт отвечать');
App::init($cfg);

// 14. Telegram недоступен: ответ уходит в очередь, потом доставляется
$tgMode = 'down';
send(user(70010, 'Вера'), '/start');
$q = $db->one("SELECT * FROM outbox WHERE kind = 'bot_reply' AND sent_at IS NULL ORDER BY id DESC LIMIT 1");
ok($q && (int)$q['chat_id'] === 70010, 'Telegram недоступен — ответ лёг в очередь');
$tgMode = 'ok';
at('2026-10-12 11:30');
$st = Telegram::flushOutbox(50);
ok($db->value('SELECT sent_at FROM outbox WHERE id = ?', [$q['id']]) !== null, 'связь вернулась — очередь отправлена (' . $st[0] . ' шт.)');

// 15. Бот заблокирован клиентом — сообщение помечается «не доставлено», без бесконечных повторов
$mid = Deals::addMessage((int)$site['deal_id'], 'out', 'crm', 'manager', 'Ответ менеджера', $m1, 'queued');
Outbox::enqueue('client_reply', 70006, ['text' => 'Ответ менеджера'], $mid, (int)$site['deal_id']);
$tgMode = 'blocked';
Telegram::flushOutbox(50);
ok($db->value('SELECT delivery FROM messages WHERE id = ?', [$mid]) === 'failed', 'бот заблокирован — статус «не доставлено», повторов нет');
$tgMode = 'ok';

// 16. Привязка сотрудника
$code = StaffLink::create($m1);
send(user(80001, 'Менеджер'), '/link ' . $code);
ok((int)$db->value('SELECT telegram_id FROM users WHERE id = ?', [$m1]) === 80001 && str_contains(lastText(), 'Уведомления CRM'), '/link КОД привязывает Telegram менеджера');
send(user(80002), '/link ' . $code);
ok(str_contains(lastText(), 'не подошёл'), 'код одноразовый');

// 17. Уведомление менеджеру о новой заявке — без ПДн
$n = $db->one("SELECT * FROM outbox WHERE kind = 'notify_new' ORDER BY id DESC LIMIT 1");
$site2 = Leads::capture(['channel' => 'site', 'name' => 'Глеб', 'phone' => '+79035550099', 'text' => 'x', 'consent' => ['ip' => '1.1.1.1']]);
$n = $db->one("SELECT * FROM outbox WHERE kind = 'notify_new' ORDER BY id DESC LIMIT 1");
ok($n && (int)$n['chat_id'] === 80001 && !str_contains($n['payload'], 'Глеб') && !str_contains($n['payload'], '5550099'), 'уведомление менеджеру без имени и телефона');

// 18. Безопасность: чужой номер, ссылка с сайта, черновики, сбой базы на вебхуке
echo "\nБезопасность\n";
at('2026-10-13 10:00');
$res = Leads::capture(['channel' => 'site', 'name' => 'Иванова Мария', 'phone' => '+79161112200', 'text' => 'Вопрос по долгу', 'consent' => ['ip' => '10.0.0.9']]);
$owner = user(71001, 'Мария', 'maria_i');
send($owner, '/start ' . Deeplink::forDeal($res['number']));
ok(str_contains(lastText(), $res['number']) && !str_contains(lastText(), 'Иванова'), 'по ссылке с сайта бот узнаёт заявку, но не называет имя из CRM');
ok((int)Contacts::find($res['contact_id'])['telegram_id'] === 71001, 'Telegram привязан к контакту того, кто оставил заявку');
send(user(71002, 'Чужой', 'evil'), '/start ' . Deeplink::forDeal($res['number']));
ok(str_contains(lastText(), 'уже связана с другим аккаунтом') && (int)Contacts::find($res['contact_id'])['telegram_id'] === 71001, 'повторно та же ссылка у другого человека не срабатывает');

// бот: телефон набран руками — к чужому контакту не привязываемся
$site3 = Leads::capture(['channel' => 'site', 'name' => 'Пётр', 'phone' => '+79161113300', 'text' => 'Сайт', 'consent' => ['ip' => '10.0.0.8']]);
$evil = user(71003, 'Злоумышленник', 'evil2');
send($evil, Bot::BTN_LEAD);
send($evil, 'Пётр');
send($evil, '+7 916 111-33-00');
send($evil, 'Пришлите ответы мне');
press($evil, 'consent:lead');
ok(Contacts::find($site3['contact_id'])['telegram_id'] === null, 'в боте набран чужой телефон — Telegram к контакту жильца не привязан');
$evilContact = $db->one('SELECT * FROM contacts WHERE telegram_id = 71003');
ok($evilContact && (int)$evilContact['id'] !== $site3['contact_id'] && $evilContact['phone'] === null, 'для пишущего — свой контакт, чужой номер в него не записан');
ok((int)$db->value("SELECT COUNT(*) FROM tasks WHERE kind = 'duplicate' AND done_at IS NULL") >= 1, 'руководителю — задача проверить возможный дубль');
// тот же сценарий, но номер отправлен кнопкой «Поделиться номером» — это подтверждение от Telegram
$site4 = Leads::capture(['channel' => 'site', 'name' => 'Ольга', 'phone' => '+79161114400', 'text' => 'Сайт', 'consent' => ['ip' => '10.0.0.7']]);
$olga = user(71004, 'Ольга', 'olga');
send($olga, Bot::BTN_LEAD);
send($olga, 'Ольга');
send($olga, '', ['contact' => ['phone_number' => '79161114400', 'user_id' => 71004, 'first_name' => 'Ольга']]);
send($olga, 'Дополнение из бота');
press($olga, 'consent:lead');
ok((int)Contacts::find($site4['contact_id'])['telegram_id'] === 71004, 'номер отправлен кнопкой Telegram — бот и сайт склеены в один контакт');

// анонимизированный контакт по старой ссылке не «оживает»
Privacy::anonymize($res['contact_id'], $admin, 'запрос субъекта');
send(user(71005, 'Кто-то', null), '/start ' . Deeplink::forDeal($res['number']));
$c = Contacts::find($res['contact_id']);
ok($c['telegram_id'] === null && $c['anonymized_at'] !== null && str_contains(lastText(), 'закрыта'), 'старая ссылка на обезличенную заявку не привязывает Telegram');

// черновик с текстом до согласия удаляется через 24 часа, даже вне диалога
$nc = user(71006, 'Новенький', null);
send($nc, 'Я Петров, кв 7, тел 89161112233, течёт кран');
$raw = (string)$db->value('SELECT data FROM bot_sessions WHERE telegram_id = 71006');
ok(str_contains($raw, 'Петров'), 'текст до согласия временно в черновике');
at('2026-10-14 11:00');
BotSession::purgeDrafts();
ok(!str_contains((string)$db->value('SELECT data FROM bot_sessions WHERE telegram_id = 71006'), 'Петров'), 'через 24 часа черновик без согласия удалён');

// вебхук при сбое базы — 503, чтобы Telegram повторил (а не «duplicate» и потеря сообщения)
$cfgW = $cfg;
$cfgW['telegram']['webhook_secret'] = 'test_secret_1234567890';
App::init($cfgW);
$_SERVER['REQUEST_METHOD'] = 'POST';
$body = json_encode(['update_id' => 999001, 'message' => ['message_id' => 1, 'from' => user(71007), 'chat' => ['id' => 71007, 'type' => 'private'], 'date' => time(), 'text' => '/start']]);
ob_start(); BotWebhook::handle($body, 'test_secret_1234567890'); $o1 = ob_get_clean();
ob_start(); BotWebhook::handle($body, 'test_secret_1234567890'); $o2 = ob_get_clean();
ok($o1 === 'ok' && $o2 === 'duplicate', 'повторная доставка того же update_id — «duplicate»');
$db->pdo->exec($db->driver === 'mysql' ? 'RENAME TABLE tg_updates TO tg_updates_off' : 'ALTER TABLE tg_updates RENAME TO tg_updates_off');
ob_start(); BotWebhook::handle(str_replace('999001', '999002', $body), 'test_secret_1234567890'); $o3 = ob_get_clean();
$db->pdo->exec($db->driver === 'mysql' ? 'RENAME TABLE tg_updates_off TO tg_updates' : 'ALTER TABLE tg_updates_off RENAME TO tg_updates');
// в консоли код ответа не виден — проверяем текст ответа и журнал (по HTTP код 503 проверяет webhook_http_test)
ok($o3 === 'retry later' && Settings::get('last_error')['event'] === 'webhook_db_failed', 'база недоступна — ответ «повторите позже» (503), ошибка в журнале, сообщение не потеряно');
App::init($cfg);

App::setNow(null);
echo "\nИтого ошибок: $fails\n";
exit($fails ? 1 : 0);
