<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Database.php';
$pdo = Database::getInstance()->getPdo();

$pdo->exec("
    CREATE TABLE IF NOT EXISTS settings (
        `key`        VARCHAR(64)  NOT NULL PRIMARY KEY,
        `value`      TEXT         NOT NULL,
        `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Seed default active model
$pdo->exec("
    INSERT IGNORE INTO settings (`key`, `value`)
    VALUES ('active_model', 'claude-3-5-haiku-20241022')
");

echo "Settings table created. Default model: claude-3-5-haiku-20241022\n";
