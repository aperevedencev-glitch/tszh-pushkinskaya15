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

    public static function create(Db $db): void
    {
        foreach (self::statements($db->driver) as $st) {
            $db->pdo->exec($st);
        }
    }
}
