<?php
declare(strict_types=1);
/**
 * Фоновые задачи. На хостинге запускать каждую минуту:
 *   /usr/bin/php /путь/private/cron/run.php
 * Каждая часть работает независимо: ошибка одной не останавливает остальные.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/../bootstrap.php';

$jobs = [
    // повторная отправка в Telegram всего, что не ушло сразу
    'outbox' => static fn() => Telegram::flushOutbox(50),
    // брошенные черновики диалога с личными данными — через 24 часа
    'bot_drafts' => static fn() => BotSession::purgeDrafts(),
    'rate_limits' => static fn() => RateLimit::cleanup(),
    'tg_updates' => static fn() => App::db()->run('DELETE FROM tg_updates WHERE received_at < ?', [Db::ts(App::now()->modify('-7 days'))])->rowCount(),
];

$lock = fopen(APP_PRIVATE . '/data/cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);   // предыдущий запуск ещё идёт
}
foreach ($jobs as $name => $job) {
    try {
        $r = $job();
        if (PHP_SAPI === 'cli' && in_array('-v', $argv ?? [], true)) {
            echo $name, ': ', json_encode($r), PHP_EOL;
        }
    } catch (Throwable $e) {
        Log::error('cron_' . $name . '_failed', ['err' => $e->getMessage()]);
    }
}
Settings::set('cron_last_run', Db::ts());
flock($lock, LOCK_UN);
