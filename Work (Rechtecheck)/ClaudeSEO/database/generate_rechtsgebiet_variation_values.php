<?php
/**
 * Generate AI-driven, Rechtsgebiet-specific variation values.
 * Replaces generic values for personenstatus, ziel, dringlichkeit,
 * beratungsphase, beratungsform with contextually relevant ones.
 * Cities (staedte) are kept unchanged.
 *
 * Usage: php database/generate_rechtsgebiet_variation_values.php [rechtsgebiet_id]
 *        Omit rechtsgebiet_id to process ALL 40 Rechtsgebiete.
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/ContentGenerator.php';

$db  = Database::getInstance();
$gen = new ContentGenerator($db);

// Per-type target counts
$typeCounts = [
    'personenstatus' => 40,
    'ziel'           => 35,
    'dringlichkeit'  => 12,
    'beratungsphase' => 12,
    'beratungsform'  => 8,
];

// Optionally limit to one Rechtsgebiet (for testing)
$singleId = isset($argv[1]) ? (int)$argv[1] : null;
$where    = $singleId ? "WHERE id = $singleId" : '';
$rechtsgebiete = $db->fetchAll("SELECT id, name, slug FROM rechtsgebiete $where ORDER BY id");

if (empty($rechtsgebiete)) {
    echo "No Rechtsgebiete found.\n"; exit(1);
}

$totalGenerated = 0;

foreach ($rechtsgebiete as $rg) {
    echo "\n=== {$rg['name']} (id={$rg['id']}) ===\n";

    foreach ($typeCounts as $typeSlug => $count) {
        $vtRow = $db->fetchOne(
            "SELECT id FROM variation_types WHERE rechtsgebiet_id = ? AND slug = ?",
            [$rg['id'], $typeSlug]
        );
        if (!$vtRow) {
            echo "  SKIP $typeSlug: variation_type not found\n";
            continue;
        }
        $vtId = $vtRow['id'];

        echo "  Generating $count values for $typeSlug... ";
        flush();

        try {
            $values = $gen->generateVariationValuesForType($rg, $typeSlug, $count);

            if (empty($values)) {
                echo "FAILED (empty result)\n";
                continue;
            }

            // Replace existing values for this type
            $db->query("DELETE FROM variation_values WHERE variation_type_id = ?", [$vtId]);

            foreach ($values as $val) {
                $db->query(
                    "INSERT IGNORE INTO variation_values (variation_type_id, value, slug, tier) VALUES (?, ?, ?, 1)",
                    [$vtId, $val['value'], $val['slug']]
                );
            }

            echo "OK (" . count($values) . " values)\n";
            $totalGenerated += count($values);

            // Small delay between API calls to avoid rate limits
            usleep(300000); // 300ms

        } catch (Exception $e) {
            echo "ERROR: " . $e->getMessage() . "\n";
        }
    }
}

echo "\n=== Done. Total values generated: $totalGenerated ===\n";
