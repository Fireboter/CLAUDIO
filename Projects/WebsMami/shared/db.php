<?php
// PDO MySQL helper with retry logic
// Usage: $pdo = db_connect(); then $stmt = $pdo->prepare(...); $stmt->execute([...]);

function db_connect(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $host = DB_HOST;
    $dbname = DB_NAME;
    $user = DB_USER;
    $pass = DB_PASS;
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $retries = 0;
    $maxRetries = 5;

    while (true) {
        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
            return $pdo;
        } catch (PDOException $e) {
            // Retry on too many connections (MySQL error 1040, 1226)
            if ($retries < $maxRetries && (
                strpos($e->getMessage(), '1040') !== false ||
                strpos($e->getMessage(), '1226') !== false
            )) {
                $retries++;
                $delay = (int)(pow(2, $retries) * 100 + rand(0, 200));
                usleep($delay * 1000);
                continue;
            }
            throw $e;
        }
    }
}

function db_query(string $sql, array $params = []): array {
    $pdo = db_connect();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_execute(string $sql, array $params = []): int {
    $pdo = db_connect();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$pdo->lastInsertId();
}

function db_run(string $sql, array $params = []): int {
    $pdo = db_connect();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}
