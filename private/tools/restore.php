<?php
declare(strict_types=1);
/**
 * Восстановление базы из бэкапа (SSH на Reg.ru). НЕОБРАТИМО: текущие данные заменяются данными из файла.
 *   php restore.php                         — список бэкапов
 *   php restore.php db-2026-10-09_030001.mysql.sql.gz
 * Перед восстановлением автоматически делается свежий бэкап текущего состояния.
 * MySQL-бэкап можно восстановить и без SSH: phpMyAdmin → база → «Импорт» → файл .sql.gz.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/../bootstrap.php';

$name = $argv[1] ?? '';
if ($name === '') {
    foreach (Backup::list() as $b) {
        printf("%s  %s  %s\n", $b['at'], str_pad(Health::bytes($b['size']), 10), $b['name']);
    }
    echo "Укажите имя файла: php restore.php ИМЯ\n";
    exit(0);
}
$file = Backup::dir() . '/' . basename($name);
if (!is_file($file)) {
    fwrite(STDERR, "Нет файла $file\n");
    exit(1);
}
if (!str_contains(basename($file), '.' . App::db()->driver . '.')) {
    fwrite(STDERR, 'Бэкап снят с другой базы (' . basename($file) . '), а сейчас используется ' . App::db()->driver . ".\n");
    exit(1);
}
echo 'Все текущие данные CRM будут заменены данными из ' . basename($file) . ".\nДля подтверждения введите ВОССТАНОВИТЬ: ";
if (trim((string)fgets(STDIN)) !== 'ВОССТАНОВИТЬ') {
    echo "Отменено.\n";
    exit(1);
}
try {
    $safety = Backup::create();
    echo 'Текущее состояние сохранено в ', basename($safety), PHP_EOL;
    $n = Backup::restore($file);
    Audit::log(null, 'system', null, 'restore', ['file' => basename($file), 'statements' => $n]);
    echo "Готово: выполнено $n команд.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . Health::scrub($e->getMessage()) . PHP_EOL);
    exit(1);
}
