<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Database.php';

$db = Database::getInstance();

// ─── Cities to add (we already have 15, these are additional) ───────────────
$additionalCities = [
    ['value' => 'Bochum',             'slug' => 'bochum'],
    ['value' => 'Wuppertal',          'slug' => 'wuppertal'],
    ['value' => 'Bielefeld',          'slug' => 'bielefeld'],
    ['value' => 'Bonn',               'slug' => 'bonn'],
    ['value' => 'Mannheim',           'slug' => 'mannheim'],
    ['value' => 'Karlsruhe',          'slug' => 'karlsruhe'],
    ['value' => 'Münster',            'slug' => 'muenster'],
    ['value' => 'Augsburg',           'slug' => 'augsburg'],
    ['value' => 'Wiesbaden',          'slug' => 'wiesbaden'],
    ['value' => 'Mönchengladbach',    'slug' => 'moenchengladbach'],
    ['value' => 'Gelsenkirchen',      'slug' => 'gelsenkirchen'],
    ['value' => 'Aachen',             'slug' => 'aachen'],
    ['value' => 'Braunschweig',       'slug' => 'braunschweig'],
    ['value' => 'Kiel',               'slug' => 'kiel'],
    ['value' => 'Krefeld',            'slug' => 'krefeld'],
    ['value' => 'Chemnitz',           'slug' => 'chemnitz'],
    ['value' => 'Halle (Saale)',      'slug' => 'halle-saale'],
    ['value' => 'Magdeburg',          'slug' => 'magdeburg'],
    ['value' => 'Freiburg im Breisgau','slug' => 'freiburg-im-breisgau'],
    ['value' => 'Oberhausen',         'slug' => 'oberhausen'],
    ['value' => 'Lübeck',             'slug' => 'luebeck'],
    ['value' => 'Erfurt',             'slug' => 'erfurt'],
    ['value' => 'Rostock',            'slug' => 'rostock'],
    ['value' => 'Mainz',              'slug' => 'mainz'],
    ['value' => 'Kassel',             'slug' => 'kassel'],
    ['value' => 'Hagen',              'slug' => 'hagen'],
    ['value' => 'Hamm',               'slug' => 'hamm'],
    ['value' => 'Saarbrücken',        'slug' => 'saarbruecken'],
    ['value' => 'Mülheim an der Ruhr','slug' => 'muelheim-an-der-ruhr'],
    ['value' => 'Potsdam',            'slug' => 'potsdam'],
    ['value' => 'Ludwigshafen',       'slug' => 'ludwigshafen'],
    ['value' => 'Oldenburg',          'slug' => 'oldenburg'],
    ['value' => 'Leverkusen',         'slug' => 'leverkusen'],
    ['value' => 'Osnabrück',          'slug' => 'osnabrueck'],
    ['value' => 'Solingen',           'slug' => 'solingen'],
    ['value' => 'Heidelberg',         'slug' => 'heidelberg'],
    ['value' => 'Herne',              'slug' => 'herne'],
    ['value' => 'Neuss',              'slug' => 'neuss'],
    ['value' => 'Darmstadt',          'slug' => 'darmstadt'],
    ['value' => 'Paderborn',          'slug' => 'paderborn'],
    ['value' => 'Regensburg',         'slug' => 'regensburg'],
    ['value' => 'Ingolstadt',         'slug' => 'ingolstadt'],
    ['value' => 'Würzburg',           'slug' => 'wuerzburg'],
    ['value' => 'Wolfsburg',          'slug' => 'wolfsburg'],
    ['value' => 'Offenbach am Main',  'slug' => 'offenbach-am-main'],
    ['value' => 'Göttingen',          'slug' => 'goettingen'],
    ['value' => 'Bottrop',            'slug' => 'bottrop'],
    ['value' => 'Remscheid',          'slug' => 'remscheid'],
    ['value' => 'Recklinghausen',     'slug' => 'recklinghausen'],
    ['value' => 'Trier',              'slug' => 'trier'],
    ['value' => 'Jena',               'slug' => 'jena'],
    ['value' => 'Erlangen',           'slug' => 'erlangen'],
    ['value' => 'Ulm',                'slug' => 'ulm'],
    ['value' => 'Moers',              'slug' => 'moers'],
    ['value' => 'Heilbronn',          'slug' => 'heilbronn'],
    ['value' => 'Pforzheim',          'slug' => 'pforzheim'],
    ['value' => 'Cottbus',            'slug' => 'cottbus'],
    ['value' => 'Siegen',             'slug' => 'siegen'],
    ['value' => 'Bremerhaven',        'slug' => 'bremerhaven'],
    ['value' => 'Hildesheim',         'slug' => 'hildesheim'],
    ['value' => 'Koblenz',            'slug' => 'koblenz'],
    ['value' => 'Kaiserslautern',     'slug' => 'kaiserslautern'],
    ['value' => 'Schwerin',           'slug' => 'schwerin'],
    ['value' => 'Gießen',             'slug' => 'giessen'],
    ['value' => 'Gütersloh',          'slug' => 'guetersloh'],
    ['value' => 'Reutlingen',         'slug' => 'reutlingen'],
    ['value' => 'Salzgitter',         'slug' => 'salzgitter'],
    ['value' => 'Kempten (Allgäu)',   'slug' => 'kempten-allgaeu'],
    ['value' => 'Villingen-Schwenningen','slug' => 'villingen-schwenningen'],
    ['value' => 'Flensburg',          'slug' => 'flensburg'],
    ['value' => 'Lüneburg',           'slug' => 'lueneburg'],
    ['value' => 'Bergisch Gladbach',  'slug' => 'bergisch-gladbach'],
    ['value' => 'Witten',             'slug' => 'witten'],
    ['value' => 'Iserlohn',           'slug' => 'iserlohn'],
    ['value' => 'Tübingen',           'slug' => 'tuebingen'],
    ['value' => 'Zwickau',            'slug' => 'zwickau'],
    ['value' => 'Dessau-Roßlau',      'slug' => 'dessau-rosslau'],
    ['value' => 'Lünen',              'slug' => 'luenen'],
    ['value' => 'Ratingen',           'slug' => 'ratingen'],
    ['value' => 'Neumünster',         'slug' => 'neumuenster'],
    ['value' => 'Troisdorf',          'slug' => 'troisdorf'],
    ['value' => 'Friedrichshafen',    'slug' => 'friedrichshafen'],
    ['value' => 'Düren',              'slug' => 'dueren'],
];

// ─── Additional values for other types ──────────────────────────────────────
$additionalTypeValues = [
    'personenstatus' => [
        ['value' => 'Arbeitnehmer',       'slug' => 'als-arbeitnehmer',      'tier' => 1],
        ['value' => 'Vermieter',          'slug' => 'als-vermieter',         'tier' => 1],
        ['value' => 'Mieter',             'slug' => 'als-mieter',            'tier' => 1],
        ['value' => 'GmbH-Geschäftsführer','slug' => 'gmbh-geschaeftsfuehrer','tier' => 1],
        ['value' => 'Verbraucher',        'slug' => 'als-verbraucher',       'tier' => 1],
    ],
    'dringlichkeit' => [
        ['value' => 'Innerhalb 24 Stunden', 'slug' => 'innerhalb-24-stunden', 'tier' => 1],
        ['value' => 'Innerhalb eines Monats','slug' => 'innerhalb-eines-monats','tier' => 1],
        ['value' => 'Terminvereinbarung',   'slug' => 'terminvereinbarung',   'tier' => 1],
        ['value' => 'Kein Zeitdruck',       'slug' => 'kein-zeitdruck',       'tier' => 1],
    ],
    'beratungsphase' => [
        ['value' => 'Vor Klageerhebung',    'slug' => 'vor-klageerhebung',   'tier' => 1],
        ['value' => 'Zwangsvollstreckung',  'slug' => 'zwangsvollstreckung', 'tier' => 1],
        ['value' => 'Revision',             'slug' => 'revision',            'tier' => 1],
        ['value' => 'Mediationsverfahren',  'slug' => 'mediationsverfahren', 'tier' => 1],
    ],
    'ziel' => [
        ['value' => 'Anwalt beauftragen',   'slug' => 'anwalt-beauftragen',  'tier' => 1],
        ['value' => 'Kosten einschätzen',   'slug' => 'kosten-einschaetzen', 'tier' => 1],
    ],
    'beratungsform' => [
        ['value' => 'Videoberatung',        'slug' => 'videoberatung',        'tier' => 1],
        ['value' => 'Schriftliche Beratung','slug' => 'schriftliche-beratung','tier' => 1],
        ['value' => 'E-Mail-Beratung',      'slug' => 'e-mail-beratung',      'tier' => 1],
    ],
];

$rechtsgebiete = $db->fetchAll("SELECT id FROM rechtsgebiete ORDER BY id");
$citiesAdded = 0;
$valuesAdded = 0;
$skipped = 0;

// ─── Add additional cities ───────────────────────────────────────────────────
foreach ($rechtsgebiete as $rg) {
    $vtRow = $db->fetchOne(
        "SELECT id FROM variation_types WHERE rechtsgebiet_id = ? AND slug = 'staedte'",
        [$rg['id']]
    );
    if (!$vtRow) {
        echo "WARN: No staedte type for rg_id={$rg['id']}\n";
        continue;
    }
    $vtId = $vtRow['id'];

    foreach ($additionalCities as $city) {
        $exists = $db->fetchOne(
            "SELECT id FROM variation_values WHERE variation_type_id = ? AND slug = ?",
            [$vtId, $city['slug']]
        );
        if ($exists) { $skipped++; continue; }

        $db->query(
            "INSERT INTO variation_values (variation_type_id, value, slug, tier) VALUES (?, ?, ?, 1)",
            [$vtId, $city['value'], $city['slug']]
        );
        $citiesAdded++;
    }
}

echo "Cities added: $citiesAdded (skipped: $skipped)\n";

// ─── Add additional values for other types ───────────────────────────────────
$skipped = 0;
foreach ($rechtsgebiete as $rg) {
    foreach ($additionalTypeValues as $typeSlug => $vals) {
        $vtRow = $db->fetchOne(
            "SELECT id FROM variation_types WHERE rechtsgebiet_id = ? AND slug = ?",
            [$rg['id'], $typeSlug]
        );
        if (!$vtRow) continue;
        $vtId = $vtRow['id'];

        foreach ($vals as $val) {
            $exists = $db->fetchOne(
                "SELECT id FROM variation_values WHERE variation_type_id = ? AND slug = ?",
                [$vtId, $val['slug']]
            );
            if ($exists) { $skipped++; continue; }

            $db->query(
                "INSERT INTO variation_values (variation_type_id, value, slug, tier) VALUES (?, ?, ?, ?)",
                [$vtId, $val['value'], $val['slug'], $val['tier']]
            );
            $valuesAdded++;
        }
    }
}

echo "Other values added: $valuesAdded (skipped: $skipped)\n";

// ─── Final counts ────────────────────────────────────────────────────────────
$db->query("SELECT 1"); // noop
$types = $db->fetchAll("SELECT vt.slug, COUNT(vv.id) as total, COUNT(DISTINCT vv.value) as unique_vals
    FROM variation_types vt JOIN variation_values vv ON vv.variation_type_id = vt.id
    GROUP BY vt.slug ORDER BY vt.slug");
echo "\nFinal counts per type:\n";
foreach ($types as $t) {
    echo "  {$t['slug']}: {$t['unique_vals']} unique values, {$t['total']} total rows\n";
}
