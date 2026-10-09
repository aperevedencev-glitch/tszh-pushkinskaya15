<?php
declare(strict_types=1);
/**
 * Тестовые данные для локальной проверки CRM. НЕ для боевого сервера.
 * php tests/seed_demo.php /путь/private
 * Создаёт: руководитель boss / менеджеры anna, oleg (пароль Test-Passw0rd!), заявки из сайта и бота.
 */
$priv = $argv[1] ?? __DIR__ . '/../private';
require $priv . '/bootstrap.php';
$db = App::db();
Schema::create($db);
Kb::seed();
Settings::set('holidays', []);
$pass = password_hash('Test-Passw0rd!', PASSWORD_DEFAULT);
$now = Db::ts();
$ids = [];
foreach ([['boss', 'Руководитель Ирина', 'admin', 555000], ['anna', 'Анна (менеджер)', 'manager', 555001], ['oleg', 'Олег (менеджер)', 'manager', 555002]] as [$l, $n, $r, $tg]) {
    $ids[$l] = (int)($db->value('SELECT id FROM users WHERE login = ?', [$l]) ?: $db->insert('users', ['login' => $l, 'name' => $n, 'role' => $r, 'password_hash' => $pass, 'telegram_id' => $tg, 'created_at' => $now]));
}
$leads = [
    ['site', 'Мария Петрова', '+79035550011', null, 'Течёт кран на кухне, нужен сантехник', ['channel' => 'site_form', 'utm_source' => 'qr', 'utm_medium' => 'print', 'utm_campaign' => 'lift_2']],
    ['bot', 'Сергей', null, 700100, 'Не закрывается дверь 3-го подъезда', ['channel' => 'bot', 'bot_start_tag' => 'site_chat_doma']],
    ['site', 'Елена', '+79165550022', null, 'Нужна справка об отсутствии задолженности', ['channel' => 'site_form', 'utm_source' => 'chat', 'utm_campaign' => 'chat_doma']],
    ['site', 'Глеб', '+79265550033', null, 'Вывоз строительного мусора после ремонта', ['channel' => 'site_form']],
    ['bot', 'Ольга', null, 700101, 'Вопрос по начислениям за сентябрь', ['channel' => 'bot']],
];
foreach ($leads as [$ch, $name, $phone, $tg, $text, $src]) {
    Leads::capture(['channel' => $ch, 'name' => $name, 'phone' => $phone, 'telegram_id' => $tg, 'text' => $text,
        'consent' => $ch === 'bot' ? ['telegram_id' => $tg] : ['ip' => '10.0.0.1'], 'source' => $src]);
}
echo "users: " . json_encode($ids) . ", deals: " . $db->value('SELECT COUNT(*) FROM deals') . "\n";
