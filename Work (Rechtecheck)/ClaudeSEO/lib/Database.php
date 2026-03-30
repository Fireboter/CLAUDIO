<?php

class Database {
    private static ?Database $instance = null;
    private PDO $pdo;

    private function __construct() {
        $config = require __DIR__ . '/../config/database.php';
        $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
        $this->pdo = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getPdo(): PDO {
        return $this->pdo;
    }

    private function reconnect(): void {
        $config = require __DIR__ . '/../config/database.php';
        $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
        $this->pdo = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public function query(string $sql, array $params = []): PDOStatement {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            // MySQL server has gone away (2006) or lost connection (2013) — reconnect once
            if (in_array($e->errorInfo[1] ?? 0, [2006, 2013])) {
                $this->reconnect();
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            }
            throw $e;
        }
    }

    public function fetchAll(string $sql, array $params = []): array {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array {
        $result = $this->query($sql, $params)->fetch();
        return $result ?: null;
    }

    public function insert(string $table, array $data): int {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $this->query("INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})", array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int {
        $set = implode(', ', array_map(fn($col) => "{$col} = ?", array_keys($data)));
        $params = array_merge(array_values($data), $whereParams);
        return $this->query("UPDATE {$table} SET {$set} WHERE {$where}", $params)->rowCount();
    }
}
