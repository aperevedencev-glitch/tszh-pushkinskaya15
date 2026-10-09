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
file_put_contents("$dir/calls.jsonl", json_encode(['method' => $m[2], 'params' => $params, 'mode' => $mode], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
if ($mode === 'down') {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway']);
    exit;
}
static $id = 0;
echo json_encode(['ok' => true, 'result' => ['message_id' => random_int(1000, 999999), 'chat' => ['id' => $params['chat_id'] ?? 0]]]);
