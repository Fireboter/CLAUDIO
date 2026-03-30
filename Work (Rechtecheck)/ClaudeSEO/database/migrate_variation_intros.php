<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Database.php';

$db = Database::getInstance();

$db->query("
    CREATE TABLE IF NOT EXISTS rechtsfrage_variation_intros (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        rechtsfrage_id      INT NOT NULL,
        variation_type_slug VARCHAR(50) NOT NULL,
        intro_content       TEXT NOT NULL,
        created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_rf_type (rechtsfrage_id, variation_type_slug),
        KEY idx_rechtsfrage_id (rechtsfrage_id)
    )
");

echo "Table rechtsfrage_variation_intros created (or already exists).\n";
