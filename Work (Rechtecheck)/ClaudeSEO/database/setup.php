<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Database.php';

$db = Database::getInstance();
$sql = file_get_contents(__DIR__ . '/schema.sql');

// Split by semicolons and execute each statement
$statements = array_filter(array_map('trim', explode(';', $sql)));
foreach ($statements as $stmt) {
    if (!empty($stmt)) {
        $db->getPdo()->exec($stmt);
    }
}
echo "Schema created successfully\n";
