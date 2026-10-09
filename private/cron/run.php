<?php
declare(strict_types=1);
/**
 * Фоновые задачи. На хостинге Reg.ru запускать каждую минуту:
 *   /opt/php/8.3/bin/php -f /var/www/u1234567/data/private/cron/run.php
 * Каждая часть работает независимо: ошибка одной не останавливает остальные.
 * Ручной запуск одной задачи: php run.php backup -v
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
    // напоминания о задачах сотрудникам в Telegram
    'task_reminders' => static fn() => CrmDeals::remindTasks(),
    // тексты отправленных сообщений в очереди — не дольше недели
    'outbox_purge' => static fn() => Privacy::purgeOutbox(),
    // раз в сутки после 03:00 — бэкап базы в private/backups, старше 14 дней удаляются
    'backup' => static fn() => Backup::daily(),
    // раз в сутки: обезличивание по сроку хранения
    'daily' => static function () {
        if (Settings::get('daily_last_run') === date('Y-m-d')) {
            return 'skip';
        }
        Settings::set('daily_last_run', date('Y-m-d'));
        return ['retention' => Privacy::retention()];
    },
    'tg_updates' => static fn() => App::db()->run('DELETE FROM tg_updates WHERE received_at < ?', [Db::ts(App::now()->modify('-7 days'))])->rowCount(),
];

if (!Installer::locked()) {
    exit(0);   // установка ещё не выполнена — таблиц нет
}
$only = array_values(array_filter(array_slice($argv ?? [], 1), static fn($a) => $a[0] !== '-'));
if ($only) {
    $unknown = array_diff($only, array_keys($jobs));
    if ($unknown) {
        fwrite(STDERR, 'Нет задач: ' . implode(', ', $unknown) . '. Есть: ' . implode(', ', array_keys($jobs)) . PHP_EOL);
        exit(2);
    }
    if (in_array('backup', $only, true)) {
        $jobs['backup'] = static fn() => ['file' => basename(Backup::create()), 'pruned' => Backup::prune()];   // вручную — без ожидания 03:00
    }
    $jobs = array_intersect_key($jobs, array_flip($only));
}
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
