<?php
/**
 * Seed script: reads rechtsbereiche_v2.csv and populates the database
 * with Rechtsgebiete, Rechtsfragen, and default Städte variation types/values.
 *
 * Usage: php database/seed.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Database.php';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function slugify(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    // German umlauts
    $text = str_replace(
        ['ä', 'ö', 'ü', 'ß', 'Ä', 'Ö', 'Ü'],
        ['ae', 'oe', 'ue', 'ss', 'ae', 'oe', 'ue'],
        $text
    );
    // Remove anything not alphanumeric or hyphens
    $text = preg_replace('/[^a-z0-9\-]/', '-', $text);
    // Collapse multiple hyphens
    $text = preg_replace('/-+/', '-', $text);
    return trim($text, '-');
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

$db  = Database::getInstance();
$pdo = $db->getPdo();

$csvPath = __DIR__ . '/../data/rechtsbereiche_v2.csv';
if (!file_exists($csvPath)) {
    die("CSV file not found: {$csvPath}\n");
}

$seoConfig = require __DIR__ . '/../config/seo.php';
$tier1Cities = $seoConfig['cities_tier1'];

// ---------------------------------------------------------------------------
// 0. Truncate tables (disable FK checks, truncate in correct order, re-enable)
// ---------------------------------------------------------------------------

echo "Truncating tables...\n";

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

$truncateOrder = [
    'variation_pages',
    'variation_values',
    'variation_types',
    'rechtsfrage_pages',
    'rechtsgebiet_pages',
    'rechtsfragen',
    'rechtsgebiete',
];

foreach ($truncateOrder as $table) {
    $pdo->exec("TRUNCATE TABLE {$table}");
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "Tables truncated.\n";

// ---------------------------------------------------------------------------
// Read CSV into memory
// ---------------------------------------------------------------------------

$handle = fopen($csvPath, 'r');
if ($handle === false) {
    die("Could not open CSV file: {$csvPath}\n");
}

// Skip header row
fgetcsv($handle, 0, ',', '"', '\\');

$rechtsgebieteRows = [];
$rechtsfragenRows  = [];

while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
    // CSV columns: id, name, description, parent_id
    if (count($row) < 4) {
        continue; // skip malformed rows
    }

    $csvId      = (int) $row[0];
    $name       = trim($row[1]);
    $description = trim($row[2]);
    $parentId   = (int) $row[3];

    if ($parentId === 0) {
        $rechtsgebieteRows[] = [
            'csv_id'      => $csvId,
            'name'        => $name,
            'description' => $description,
        ];
    } else {
        $rechtsfragenRows[] = [
            'csv_id'      => $csvId,
            'name'        => $name,
            'description' => $description,
            'parent_id'   => $parentId,
        ];
    }
}

fclose($handle);

// ---------------------------------------------------------------------------
// 1. First pass: Insert Rechtsgebiete
// ---------------------------------------------------------------------------

echo "Inserting Rechtsgebiete...\n";

// Maps CSV id -> ['db_id' => ..., 'slug' => ...]
$csvIdToRg = [];
$rgCount   = 0;

foreach ($rechtsgebieteRows as $rg) {
    $slug = slugify($rg['name']);
    $dbId = $db->insert('rechtsgebiete', [
        'name'   => $rg['name'],
        'slug'   => $slug,
        'status' => 'draft',
    ]);

    $csvIdToRg[$rg['csv_id']] = [
        'db_id' => $dbId,
        'slug'  => $slug,
    ];
    $rgCount++;
}

echo "  -> {$rgCount} rechtsgebiete inserted.\n";

// ---------------------------------------------------------------------------
// 2. Second pass: Insert Rechtsfragen
// ---------------------------------------------------------------------------

echo "Inserting Rechtsfragen...\n";

$rfCount = 0;

foreach ($rechtsfragenRows as $rf) {
    $parentCsvId = $rf['parent_id'];

    if (!isset($csvIdToRg[$parentCsvId])) {
        echo "  WARNING: Skipping Rechtsfrage '{$rf['name']}' — parent CSV id {$parentCsvId} not found.\n";
        continue;
    }

    $parentInfo    = $csvIdToRg[$parentCsvId];
    $rgDbId        = $parentInfo['db_id'];
    $rgSlug        = $parentInfo['slug'];
    $rfSlug        = $rgSlug . '-' . slugify($rf['name']);

    $db->insert('rechtsfragen', [
        'rechtsgebiet_id' => $rgDbId,
        'name'            => $rf['name'],
        'slug'            => $rfSlug,
        'description'     => $rf['description'] ?: null,
        'status'          => 'draft',
    ]);

    $rfCount++;
}

echo "  -> {$rfCount} rechtsfragen inserted.\n";

// ---------------------------------------------------------------------------
// 3. Third pass: Create a "Städte" variation_type for each Rechtsgebiet
// ---------------------------------------------------------------------------

echo "Creating Städte variation types...\n";

// Maps rechtsgebiet DB id -> variation_type DB id
$rgToVtId = [];
$vtCount  = 0;

foreach ($csvIdToRg as $csvId => $info) {
    $vtId = $db->insert('variation_types', [
        'rechtsgebiet_id' => $info['db_id'],
        'name'            => 'Städte',
        'slug'            => 'staedte',
    ]);
    $rgToVtId[$info['db_id']] = $vtId;
    $vtCount++;
}

echo "  -> {$vtCount} variation types created.\n";

// ---------------------------------------------------------------------------
// 4. Fourth pass: Insert Tier 1 cities as variation_values for each type
// ---------------------------------------------------------------------------

echo "Inserting Tier 1 city variation values...\n";

$vvCount = 0;

foreach ($rgToVtId as $rgDbId => $vtId) {
    foreach ($tier1Cities as $city) {
        $db->insert('variation_values', [
            'variation_type_id' => $vtId,
            'value'             => $city,
            'slug'              => slugify($city),
            'tier'              => 1,
        ]);
        $vvCount++;
    }
}

echo "  -> {$vvCount} variation values inserted.\n";

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo "\nSeeded:\n";
echo "- {$rgCount} rechtsgebiete\n";
echo "- {$rfCount} rechtsfragen\n";
echo "- {$vtCount} variation types (Städte)\n";
echo "- {$vvCount} variation values (Tier 1 cities)\n";
