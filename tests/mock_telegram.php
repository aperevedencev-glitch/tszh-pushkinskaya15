<?php
// Имитация Telegram Bot API для тестов: php -S 127.0.0.1:8091 tests/mock_telegram.php
// Записывает каждый вызов в $MOCK_DIR/calls.jsonl. Режим — файл $MOCK_DIR/mode: ok | down
$dir = getenv('MOCK_DIR') ?: sys_get_temp_dir();
$mode = trim((string)@file_get_contents("$dir/mode")) ?: 'ok';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!preg_match('~^/bot([^/]+)/(\w+)$~', $path, $m)) {
    http_response_code(404);
    exit;
}
$params = json_decode(file_get_contents('php://input'), true) ?: $_POST;
file_put_contents("$dir/calls.jsonl", json_encode(['method' => $m[2], 'params' => $params, 'mode' => $mode, 't' => microtime(true)], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
if ($mode === 'down') {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway']);
    exit;
}
// вебхук: setWebhook запоминает адрес, getWebhookInfo его возвращает (секрет не сохраняем)
if ($m[2] === 'setWebhook' || $m[2] === 'deleteWebhook') {
    file_put_contents("$dir/webhook.json", json_encode(['url' => $m[2] === 'setWebhook' ? (string)($params['url'] ?? '') : '', 'pending_update_count' => 0, 'max_connections' => (int)($params['max_connections'] ?? 40), 'allowed_updates' => $params['allowed_updates'] ?? []]));
    echo json_encode(['ok' => true, 'result' => true]);
    exit;
}
if ($m[2] === 'getWebhookInfo') {
    $info = json_decode((string)@file_get_contents("$dir/webhook.json"), true) ?: ['url' => '', 'pending_update_count' => 0];
    echo json_encode(['ok' => true, 'result' => $info]);
    exit;
}
echo json_encode(['ok' => true, 'result' => ['message_id' => random_int(1000, 999999), 'chat' => ['id' => $params['chat_id'] ?? 0]]]);
