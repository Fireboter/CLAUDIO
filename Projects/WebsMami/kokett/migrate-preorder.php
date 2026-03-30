<?php
/**
 * Database migration script — add isPreorder columns.
 * Run once, then DELETE this file.
 * Access: https://kokett.ad/migrate-preorder.php
 */
require_once __DIR__ . '/config.php';
require_once SHARED_PATH . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = db_connect();

$statements = [
    'Product.isPreorder' => 'ALTER TABLE Product ADD COLUMN isPreorder TINYINT(1) NOT NULL DEFAULT 0',
    'OrderItem.isPreorder' => 'ALTER TABLE OrderItem ADD COLUMN isPreorder TINYINT(1) NOT NULL DEFAULT 0',
];

foreach ($statements as $label => $sql) {
    try {
        $pdo->exec($sql);
        echo "OK: $label\n";
    } catch (PDOException $e) {
        echo "FAIL: $label — " . $e->getMessage() . "\n";
    }
}

echo "Done.\n";
