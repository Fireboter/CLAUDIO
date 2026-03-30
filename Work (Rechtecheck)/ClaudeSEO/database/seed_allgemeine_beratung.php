<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Database.php';

$db = Database::getInstance();

$missing = [
    ['id' => 2,  'name' => 'Architektenrecht',        'slug' => 'architektenrecht'],
    ['id' => 3,  'name' => 'Asylrecht',               'slug' => 'asylrecht'],
    ['id' => 5,  'name' => 'Bankrecht',               'slug' => 'bankrecht'],
    ['id' => 4,  'name' => 'Baurecht',                'slug' => 'baurecht'],
    ['id' => 10, 'name' => 'Compliance',              'slug' => 'compliance'],
    ['id' => 11, 'name' => 'Diesel',                  'slug' => 'diesel'],
    ['id' => 13, 'name' => 'Fluggastrecht',           'slug' => 'fluggastrecht'],
    ['id' => 17, 'name' => 'Insolvenzrecht',          'slug' => 'insolvenzrecht'],
    ['id' => 19, 'name' => 'Medizinrecht',            'slug' => 'medizinrecht'],
    ['id' => 20, 'name' => 'Mietrecht',               'slug' => 'mietrecht'],
    ['id' => 21, 'name' => 'Migrationsrecht',         'slug' => 'migrationsrecht'],
    ['id' => 22, 'name' => 'Nachbarrecht',            'slug' => 'nachbarrecht'],
    ['id' => 23, 'name' => 'Patentrecht',             'slug' => 'patentrecht'],
    ['id' => 25, 'name' => 'Reiserecht',              'slug' => 'reiserecht'],
    ['id' => 26, 'name' => 'Schadensersatzrecht',     'slug' => 'schadensersatzrecht'],
    ['id' => 27, 'name' => 'Scheidungsrecht',         'slug' => 'scheidungsrecht'],
    ['id' => 29, 'name' => 'Schulrecht',              'slug' => 'schulrecht'],
    ['id' => 30, 'name' => 'Sozialrecht',             'slug' => 'sozialrecht'],
    ['id' => 34, 'name' => 'Unternehmenskrise',       'slug' => 'unternehmenskrise'],
    ['id' => 35, 'name' => 'Unternehmensrecht',       'slug' => 'unternehmensrecht'],
    ['id' => 40, 'name' => 'Wirtschaftsrecht',        'slug' => 'wirtschaftsrecht'],
];

$inserted = 0;
foreach ($missing as $rg) {
    $rfName = $rg['name'] . ' (Allgemeine Beratung)';
    $rfSlug = $rg['slug'] . '-' . $rg['slug'] . '-allgemeine-beratung';
    $desc   = 'Allgemeine rechtliche Beratung im Bereich ' . $rg['name'] . '. Kostenlose Ersteinschätzung durch spezialisierte Anwälte.';

    $exists = $db->fetchOne("SELECT id FROM rechtsfragen WHERE slug = ?", [$rfSlug]);
    if ($exists) {
        echo "SKIP (exists): $rfName\n";
        continue;
    }

    $db->query(
        "INSERT INTO rechtsfragen (rechtsgebiet_id, name, slug, description, status) VALUES (?, ?, ?, ?, 'draft')",
        [$rg['id'], $rfName, $rfSlug, $desc]
    );
    echo "CREATED: $rfName => $rfSlug\n";
    $inserted++;
}

echo "\nDone. Inserted: $inserted\n";
