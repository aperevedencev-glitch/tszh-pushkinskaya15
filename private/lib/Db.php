<?php
declare(strict_types=1);

/** Тонкая обёртка над PDO: только подготовленные запросы, MySQL или SQLite. */
final class Db
{
    private function __construct(public readonly PDO $pdo, public readonly string $driver)
    {
    }

    public static function connect(array $c): self
    {
        $driver = $c['driver'] ?? 'mysql';
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        if ($driver === 'sqlite') {
            $pdo = new PDO('sqlite:' . $c['path'], null, null, $opts);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            return new self($pdo, 'sqlite');
        }
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $c['host'] ?? 'localhost', $c['name'] ?? '', $c['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, $c['user'] ?? '', $c['pass'] ?? '', $opts);
        $pdo->exec("SET time_zone = '+03:00'");
        return new self($pdo, 'mysql');
    }

    public static function fromPdo(PDO $pdo): self
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return new self($pdo, $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** INSERT по массиву «колонка => значение». Имена колонок — только из кода, не из ввода. */
    public function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $cols), implode(', ', array_fill(0, count($cols), '?')));
        $this->run($sql, array_values($row));
        return (int)$this->pdo->lastInsertId();
    }

    public function update(string $table, array $row, string $where, array $whereParams): int
    {
        $set = implode(', ', array_map(static fn($c) => "$c = ?", array_keys($row)));
        return $this->run("UPDATE $table SET $set WHERE $where", [...array_values($row), ...$whereParams])->rowCount();
    }

    public function tx(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn($this);   // вложенный вызов — работаем внутри внешней транзакции
        }
        $this->pdo->beginTransaction();
        try {
            $r = $fn($this);
            $this->pdo->commit();
            return $r;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function ts(?DateTimeInterface $d = null): string
    {
        return ($d ?? App::now())->format('Y-m-d H:i:s');
    }
}
