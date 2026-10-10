<?php
declare(strict_types=1);

/**
 * Создание таблиц. Источник правды — sql/schema.mysql.sql;
 * для SQLite схема получается из него автоматически.
 */
final class Schema
{
    public static function statements(string $driver): array
    {
        $sql = file_get_contents(APP_PRIVATE . '/sql/schema.mysql.sql');
        $sql = preg_replace('/--[^\n]*/', '', $sql);
        $stmts = array_filter(array_map('trim', explode(';', $sql)));
        if ($driver !== 'sqlite') {
            return array_values($stmts);
        }
        $out = [];
        foreach ($stmts as $st) {
            if (!preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $st, $m)) {
                continue;
            }
            $table = $m[1];
            $indexes = [];
            $lines = [];
            $body = substr($st, strpos($st, '(') + 1, strrpos($st, ')') - strpos($st, '(') - 1);
            foreach (preg_split('/,\s*\n/', $body) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (preg_match('/^KEY (\w+) \(([^)]+)\)$/', $line, $k)) {
                    $indexes[] = "CREATE INDEX IF NOT EXISTS {$k[1]} ON $table ({$k[2]})";
                    continue;
                }
                $line = str_replace('INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $line);
                $line = preg_replace('/\bINT UNSIGNED\b/', 'INTEGER', $line);
                $line = preg_replace('/^CONSTRAINT \w+ /', '', $line);
                $lines[] = $line;
            }
            $out[] = "CREATE TABLE IF NOT EXISTS $table (\n  " . implode(",\n  ", $lines) . "\n)";
            array_push($out, ...$indexes);
        }
        return $out;
    }

    /** Версия схемы: увеличивается при каждом добавлении столбца в MIGRATIONS. */
    public const VERSION = 3;

    /** Столбцы, добавленные после первой версии: [таблица, столбец, определение]. Для уже установленных баз. */
    private const MIGRATIONS = [
        ['users', 'session_epoch', 'INT NOT NULL DEFAULT 0'],
        ['contacts', 'phone_verified', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['contacts', 'phone_claimed', 'VARCHAR(16) NULL'],
    ];

    public static function create(Db $db): void
    {
        foreach (self::statements($db->driver) as $st) {
            $db->pdo->exec($st);
        }
        self::migrate($db);
    }

    /** Добавить недостающие столбцы. Повторный запуск ничего не меняет. */
    public static function migrate(Db $db): int
    {
        $n = 0;
        foreach (self::MIGRATIONS as [$table, $column, $def]) {
            try {
                $db->pdo->query("SELECT $column FROM $table LIMIT 0");
                continue;   // столбец уже есть
            } catch (PDOException) {
            }
            $db->pdo->exec("ALTER TABLE $table ADD COLUMN $column $def");
            $n++;
        }
        $db->run('DELETE FROM settings WHERE skey = ?', ['schema_version']);
        $db->run('INSERT INTO settings (skey, value) VALUES (?, ?)', ['schema_version', (string)self::VERSION]);
        return $n;
    }

    /**
     * После обновления файлов на хостинге: при первом же запросе дописать новые столбцы.
     * Стоит один лёгкий запрос; до установки (таблиц нет) ничего не делает.
     */
    public static function ensureCurrent(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $db = App::db();
            if ((int)$db->value('SELECT value FROM settings WHERE skey = ?', ['schema_version']) < self::VERSION) {
                self::migrate($db);
                Log::event('schema_migrated', ['version' => self::VERSION]);
            }
        } catch (Throwable) {
            // таблиц ещё нет или база недоступна — этим занимаются мастер установки и /crm/health
        }
    }
}
