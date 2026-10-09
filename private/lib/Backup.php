<?php
declare(strict_types=1);

/**
 * Ежедневный бэкап базы в private/backups (вне публичной папки), хранение 14 дней.
 * Формат — обычный SQL в gzip: MySQL-бэкап импортируется через phpMyAdmin на Reg.ru,
 * любой бэкап восстанавливается командой private/tools/restore.php.
 * Каждая инструкция — ровно одна строка, поэтому восстановление идёт построчно без разбора SQL.
 */
final class Backup
{
    public const PREFIX = 'db-';

    public static function dir(): string
    {
        return (string)App::cfg('backup.dir', APP_PRIVATE . '/backups');
    }

    public static function keepDays(): int
    {
        return max(1, (int)App::cfg('backup.keep_days', 14));
    }

    /** Таблицы приложения в порядке схемы. */
    public static function tables(): array
    {
        preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+)/', (string)file_get_contents(APP_PRIVATE . '/sql/schema.mysql.sql'), $m);
        return $m[1];
    }

    /** Снять бэкап. Возвращает путь к файлу. */
    public static function create(?Db $db = null, ?string $dir = null): string
    {
        $db ??= App::db();
        $dir ??= self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            throw new RuntimeException('Не удалось создать папку бэкапов');
        }
        $name = self::PREFIX . App::now()->format('Y-m-d_His') . '.' . $db->driver . '.sql.gz';
        $tmp = $dir . '/.' . $name . '.part';
        $gz = gzopen($tmp, 'wb6');
        if (!$gz) {
            throw new RuntimeException('Не удалось открыть файл бэкапа на запись');
        }
        @chmod($tmp, 0600);
        $w = static function (string $line) use ($gz): void {
            if (gzwrite($gz, $line . "\n") === false) {
                throw new RuntimeException('Ошибка записи бэкапа (нет места на диске?)');
            }
        };
        try {
            $w('-- Бэкап базы ТСЖ «Пушкинская 15». Создан ' . App::now()->format('d.m.Y H:i:s') . ', драйвер ' . $db->driver);
            $w('-- Содержит персональные данные. Хранить только на сервере в России, не пересылать.');
            if ($db->driver === 'mysql') {
                $w('SET NAMES utf8mb4;');
                $w('SET FOREIGN_KEY_CHECKS = 0;');
            } else {
                $w('PRAGMA foreign_keys = OFF;');
            }
            $existing = self::existingTables($db);
            foreach (self::tables() as $t) {
                if (!in_array($t, $existing, true)) {
                    continue;
                }
                $w('DROP TABLE IF EXISTS ' . self::qi($db, $t) . ';');
                foreach (self::ddl($db, $t) as $ddl) {
                    $w(preg_replace('/\s*\n\s*/', ' ', trim($ddl)) . ';');
                }
                $st = $db->pdo->query('SELECT * FROM ' . self::qi($db, $t));
                $cols = null;
                $batch = [];
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $cols ??= '(' . implode(', ', array_map(static fn($c) => self::qi($db, $c), array_keys($row))) . ')';
                    $batch[] = '(' . implode(', ', array_map(static fn($v) => self::lit($db, $v), array_values($row))) . ')';
                    if (count($batch) >= 200) {
                        $w('INSERT INTO ' . self::qi($db, $t) . ' ' . $cols . ' VALUES ' . implode(', ', $batch) . ';');
                        $batch = [];
                    }
                }
                if ($batch) {
                    $w('INSERT INTO ' . self::qi($db, $t) . ' ' . $cols . ' VALUES ' . implode(', ', $batch) . ';');
                }
            }
            $w($db->driver === 'mysql' ? 'SET FOREIGN_KEY_CHECKS = 1;' : 'PRAGMA foreign_keys = ON;');
            $w('-- конец бэкапа');
        } catch (Throwable $e) {
            gzclose($gz);
            @unlink($tmp);
            throw $e;
        }
        gzclose($gz);
        $file = $dir . '/' . $name;
        rename($tmp, $file);
        return $file;
    }

    /** Восстановить базу из файла бэкапа. Необратимо: таблицы приложения пересоздаются. */
    public static function restore(string $file, ?Db $db = null): int
    {
        $db ??= App::db();
        if (!self::complete($file)) {
            // проверяем до первой команды, чтобы обрезанный файл не испортил базу наполовину
            throw new RuntimeException('Файл бэкапа обрезан или повреждён: нет отметки о конце. База не изменена.');
        }
        $gz = gzopen($file, 'rb');
        if (!$gz) {
            throw new RuntimeException('Не удалось открыть файл бэкапа');
        }
        $complete = false;
        $n = 0;
        $buf = '';
        while (($line = gzgets($gz, 64 * 1024 * 1024)) !== false) {
            $buf .= $line;
            if (!str_ends_with($buf, "\n") && !gzeof($gz)) {
                continue;   // очень длинная строка — дочитываем
            }
            $sql = trim($buf);
            $buf = '';
            if ($sql === '' || str_starts_with($sql, '--')) {
                $complete = $complete || $sql === '-- конец бэкапа';
                continue;
            }
            $db->pdo->exec($sql);
            $n++;
        }
        gzclose($gz);
        if (!$complete) {
            throw new RuntimeException('Файл бэкапа обрезан: нет отметки о конце. Восстановление могло пройти частично.');
        }
        return $n;
    }

    /** Файл бэкапа дочитывается до конца и заканчивается отметкой «конец бэкапа». */
    public static function complete(string $file): bool
    {
        $gz = @gzopen($file, 'rb');
        if (!$gz) {
            return false;
        }
        $last = '';
        while (($line = gzgets($gz, 1 << 20)) !== false) {
            if (trim($line) !== '') {
                $last = trim($line);
            }
        }
        gzclose($gz);
        return $last === '-- конец бэкапа';
    }

    /** Удалить бэкапы старше срока хранения. Возвращает число удалённых. */
    public static function prune(?string $dir = null, ?int $keepDays = null): int
    {
        $dir ??= self::dir();
        $limit = App::now()->modify('-' . ($keepDays ?? self::keepDays()) . ' days')->format('Y-m-d');
        $n = 0;
        foreach (self::list($dir) as $b) {
            if ($b['date'] < $limit && @unlink($b['path'])) {
                $n++;
            }
        }
        foreach (glob($dir . '/.' . self::PREFIX . '*.part') ?: [] as $part) {
            if (filemtime($part) < time() - 86400) {
                @unlink($part);   // недописанный бэкап после сбоя
            }
        }
        return $n;
    }

    /** Список бэкапов, новые сверху. */
    public static function list(?string $dir = null): array
    {
        $out = [];
        foreach (glob(($dir ?? self::dir()) . '/' . self::PREFIX . '*.sql.gz') ?: [] as $f) {
            if (preg_match('/db-(\d{4}-\d\d-\d\d)_(\d\d)(\d\d)(\d\d)/', basename($f), $m)) {
                $out[] = ['path' => $f, 'name' => basename($f), 'date' => $m[1], 'at' => "$m[1] $m[2]:$m[3]:$m[4]", 'size' => (int)filesize($f)];
            }
        }
        usort($out, static fn($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    /** Для cron: раз в сутки после 03:00 — бэкап и чистка старых. */
    public static function daily(): array|string
    {
        $now = App::now();
        if ((int)$now->format('G') < 3 || Settings::get('backup_last_date') === $now->format('Y-m-d')) {
            return 'skip';
        }
        Settings::set('backup_last_date', $now->format('Y-m-d'));
        $file = self::create();
        Settings::set('backup_last', ['at' => Db::ts(), 'file' => basename($file), 'size' => filesize($file)]);
        return ['file' => basename($file), 'pruned' => self::prune()];
    }

    // ------------------------------------------------------------ служебное

    private static function existingTables(Db $db): array
    {
        $sql = $db->driver === 'mysql' ? 'SHOW TABLES' : "SELECT name FROM sqlite_master WHERE type = 'table'";
        return $db->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function ddl(Db $db, string $t): array
    {
        if ($db->driver === 'mysql') {
            $row = $db->pdo->query('SHOW CREATE TABLE ' . self::qi($db, $t))->fetch(PDO::FETCH_NUM);
            return [(string)$row[1]];
        }
        $st = $db->pdo->prepare("SELECT sql FROM sqlite_master WHERE tbl_name = ? AND sql IS NOT NULL ORDER BY type = 'table' DESC, name");
        $st->execute([$t]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function qi(Db $db, string $name): string
    {
        return $db->driver === 'mysql' ? '`' . str_replace('`', '``', $name) . '`' : '"' . str_replace('"', '""', $name) . '"';
    }

    /** Значение для INSERT без переводов строк внутри (иначе построчное восстановление сломается). */
    private static function lit(Db $db, mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_int($v) || is_float($v)) {
            return (string)$v;
        }
        $v = (string)$v;
        if ($db->driver === 'mysql') {
            return $db->pdo->quote($v);   // MySQL экранирует \n и \r сам
        }
        if (strpbrk($v, "\r\n\0") !== false) {
            return "CAST(X'" . bin2hex($v) . "' AS TEXT)";
        }
        return $db->pdo->quote($v);
    }
}
