<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Database.php';

$db = Database::getInstance();

$newTypes = [
    ['name' => 'Personenstatus', 'slug' => 'personenstatus'],
    ['name' => 'Dringlichkeit',  'slug' => 'dringlichkeit'],
    ['name' => 'Beratungsphase', 'slug' => 'beratungsphase'],
    ['name' => 'Ziel',           'slug' => 'ziel'],
    ['name' => 'Beratungsform',  'slug' => 'beratungsform'],
];

$typeValues = [
    'personenstatus' => [
        ['value' => 'Privatperson',    'slug' => 'privatperson',         'tier' => 1],
        ['value' => 'Unternehmen',     'slug' => 'als-unternehmen',      'tier' => 1],
        ['value' => 'Selbstständiger', 'slug' => 'selbststaendiger',     'tier' => 1],
        ['value' => 'Beamter',         'slug' => 'als-beamter',          'tier' => 1],
        ['value' => 'Rentner',         'slug' => 'als-rentner',          'tier' => 1],
    ],
    'dringlichkeit' => [
        ['value' => 'Sofortberatung',        'slug' => 'sofortberatung',        'tier' => 1],
        ['value' => 'Notfall',               'slug' => 'notfall',               'tier' => 1],
        ['value' => 'Innerhalb einer Woche', 'slug' => 'innerhalb-einer-woche', 'tier' => 1],
        ['value' => 'Langfristig',           'slug' => 'langfristig',           'tier' => 1],
    ],
    'beratungsphase' => [
        ['value' => 'Erstberatung',        'slug' => 'erstberatung',        'tier' => 1],
        ['value' => 'Laufendes Verfahren', 'slug' => 'laufendes-verfahren', 'tier' => 1],
        ['value' => 'Berufung',            'slug' => 'berufung',            'tier' => 1],
        ['value' => 'Vergleich',           'slug' => 'vergleich',           'tier' => 1],
    ],
    'ziel' => [
        ['value' => 'Nur informieren',      'slug' => 'nur-informieren',     'tier' => 1],
        ['value' => 'Außergerichtlich',     'slug' => 'aussergerichtlich',   'tier' => 1],
        ['value' => 'Gerichtlich vorgehen', 'slug' => 'gerichtlich-vorgehen','tier' => 1],
        ['value' => 'Einigung erzielen',    'slug' => 'einigung-erzielen',   'tier' => 1],
    ],
    'beratungsform' => [
        ['value' => 'Online-Beratung',  'slug' => 'online-beratung',  'tier' => 1],
        ['value' => 'Telefonberatung',  'slug' => 'telefonberatung',  'tier' => 1],
        ['value' => 'Vor-Ort-Beratung', 'slug' => 'vor-ort-beratung', 'tier' => 1],
    ],
];

$rechtsgebiete = $db->fetchAll("SELECT id FROM rechtsgebiete ORDER BY id");
$typesAdded = 0;
$valuesAdded = 0;

foreach ($rechtsgebiete as $rg) {
    foreach ($newTypes as $type) {
        $exists = $db->fetchOne(
            "SELECT id FROM variation_types WHERE rechtsgebiet_id = ? AND slug = ?",
            [$rg['id'], $type['slug']]
        );
        if ($exists) {
            echo "SKIP: {$type['name']} for rg_id={$rg['id']}\n";
            continue;
        }

        $db->query(
            "INSERT INTO variation_types (rechtsgebiet_id, name, slug) VALUES (?, ?, ?)",
            [$rg['id'], $type['name'], $type['slug']]
        );

        // Get the inserted ID via fetchOne since $db->query() returns PDOStatement
        $vtRow = $db->fetchOne(
            "SELECT id FROM variation_types WHERE rechtsgebiet_id = ? AND slug = ?",
            [$rg['id'], $type['slug']]
        );
        $vtId = $vtRow['id'];
        $typesAdded++;

        foreach ($typeValues[$type['slug']] as $val) {
            $db->query(
                "INSERT INTO variation_values (variation_type_id, value, slug, tier) VALUES (?, ?, ?, ?)",
                [$vtId, $val['value'], $val['slug'], $val['tier']]
            );
            $valuesAdded++;
        }

        echo "CREATED: {$type['name']} (id=$vtId) + " . count($typeValues[$type['slug']]) . " values for rg_id={$rg['id']}\n";
    }
}

$expectedTypes  = count($rechtsgebiete) * count($newTypes);
$expectedValues = count($rechtsgebiete) * (5 + 4 + 4 + 4 + 3);
echo "\nDone. Types added: $typesAdded / $expectedTypes expected. Values added: $valuesAdded / $expectedValues expected.\n";
