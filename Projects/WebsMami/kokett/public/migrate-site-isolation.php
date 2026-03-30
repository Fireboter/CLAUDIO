<?php
/**
 * Migration: Add site column to all shared tables for multi-site isolation.
 * Run once at: https://kokett.ad/migrate-site-isolation.php
 * DELETE after use!
 */
require_once __DIR__ . '/../config.php';
require_once SHARED_PATH . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = db_connect();

$steps = [

// 1. Add site column to each table
"ALTER TABLE Product ADD COLUMN IF NOT EXISTS site VARCHAR(20) NOT NULL DEFAULT 'kokett' AFTER id",
"ALTER TABLE CollectionGroup ADD COLUMN IF NOT EXISTS site VARCHAR(20) NOT NULL DEFAULT 'kokett' AFTER id",
"ALTER TABLE Collection ADD COLUMN IF NOT EXISTS site VARCHAR(20) NOT NULL DEFAULT 'kokett' AFTER id",
"ALTER TABLE `Order` ADD COLUMN IF NOT EXISTS site VARCHAR(20) NOT NULL DEFAULT 'kokett' AFTER id",
"ALTER TABLE ContactSubmission ADD COLUMN IF NOT EXISTS site VARCHAR(20) NOT NULL DEFAULT 'kokett' AFTER id",
"ALTER TABLE SiteSettings ADD COLUMN IF NOT EXISTS site VARCHAR(20) NOT NULL DEFAULT 'kokett' AFTER id",
"ALTER TABLE NewsletterSubscriber ADD COLUMN IF NOT EXISTS site VARCHAR(20) NOT NULL DEFAULT 'kokett' AFTER id",
"ALTER TABLE DiscountCode ADD COLUMN IF NOT EXISTS site VARCHAR(20) NOT NULL DEFAULT 'kokett' AFTER id",

// 2. Fix SiteSettings unique key to be (site, key) instead of just (key)
"ALTER TABLE SiteSettings DROP INDEX IF EXISTS `key`",
"ALTER TABLE SiteSettings ADD UNIQUE KEY IF NOT EXISTS site_key (site, `key`)",

// 3. Fix NewsletterSubscriber unique key to be (site, email) instead of just (email)
"ALTER TABLE NewsletterSubscriber DROP INDEX IF EXISTS email",
"ALTER TABLE NewsletterSubscriber ADD UNIQUE KEY IF NOT EXISTS site_email (site, email)",

];

echo "Running site isolation migration...\n\n";
$ok = 0; $fail = 0;
foreach ($steps as $sql) {
    try {
        $pdo->exec($sql);
        echo "OK: " . substr($sql, 0, 80) . "\n";
        $ok++;
    } catch (PDOException $e) {
        echo "FAIL: " . substr($sql, 0, 80) . "\n";
        echo "  -> " . $e->getMessage() . "\n";
        $fail++;
    }
}

echo "\nDone. OK: $ok, Failed: $fail\n";
echo "\nKokett data already has site='kokett' by default.\n";
echo "Bawywear data will use site='bawy' for new records.\n";
echo "\nDELETE THIS FILE after migration is complete!\n";
