# Smart Variation System Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the single "Städte" variation type with 6 universal variation types and smart substitution, so each generated Rechtsfrage automatically supports ~127 variation pages rendered on-the-fly without pre-generating them individually.

**Architecture:** Each Rechtsfrage generation call now also runs 6 additional Claude prompts (one per variation type), producing intro paragraphs stored in a new `rechtsfrage_variation_intros` table. Each paragraph contains the literal `[VARIATION_VALUE]` placeholder. At render time, the router fetches the intro for the matched variation type, substitutes the placeholder with the actual value (e.g. "Berlin", "Privatperson"), and prepends it to the base content. The `variation_pages` table is kept for backward compatibility but no longer written to by new generations.

**Tech Stack:** PHP 8.5, MySQL (remote at 45.9.61.45), Bootstrap 5, vanilla JS

---

## Context

- Admin dashboard: `admin/index.php` + `admin/assets/dashboard.js`
- Variations admin API: `admin/api/variations.php`
- Content generation API: `admin/api/content.php`
- Content generator: `lib/ContentGenerator.php`
- Router: `lib/Router.php`
- Public templates: `templates/variation.php`, `templates/rechtsfrage.php`
- Database lib: `lib/Database.php` — use `$db->fetchAll($sql, $params)`, `$db->fetchOne()`, `$db->query()`, `$db->insert()`, `$db->update()`
- Servers: admin on port 8081, public on port 8080

**Current variation system:**
- `variation_types`: 40 rows, 1 per Rechtsgebiet, all named "Städte" (slug: `staedte`)
- `variation_values`: ~107 cities × 40 Rechtsgebiete = ~4280 rows
- `variation_pages`: pre-generated HTML per (rechtsfrage_id, variation_value_id) — kept but not written by new flow
- `Router::tryVariation()`: splits slug from end to find Rechtsfrage + VariationValue by slug
- `Router::renderVariation()`: loads pre-generated `variation_pages` or falls back to parent

**New variation types to add (5 new ones, so 6 total):**

| Slug | Name | Values |
|------|------|--------|
| staedte | Städte | (already exists, ~107 cities) |
| personenstatus | Personenstatus | Privatperson, Unternehmen, Selbstständiger, Beamter, Rentner |
| dringlichkeit | Dringlichkeit | Sofortberatung, Notfall, Innerhalb einer Woche, Langfristig |
| beratungsphase | Beratungsphase | Erstberatung, Laufendes Verfahren, Berufung, Vergleich |
| ziel | Ziel | Nur informieren, Außergerichtlich, Gerichtlich vorgehen, Einigung erzielen |
| beratungsform | Beratungsform | Online-Beratung, Telefonberatung, Vor-Ort-Beratung |

---

## Task 1: Seed 5 New Variation Types + Values

**Files:**
- Create: `database/seed_variation_types_v2.php`

**Step 1: Create the seed script**

```php
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
```

**Step 2: Run it**

```bash
cd /c/ClaudeSEO && php database/seed_variation_types_v2.php 2>&1 | tail -5
```

Expected last line: `Done. Types added: 200 / 200 expected. Values added: 800 / 800 expected.`

**Step 3: Verify counts**

Create `/tmp/vt_verify.php`:
```php
<?php
require 'vendor/autoload.php'; require 'lib/Database.php';
$db = Database::getInstance();
echo "variation_types: " . $db->fetchOne("SELECT COUNT(*) as c FROM variation_types")['c'] . " (expected: 240 = 40x6)\n";
echo "variation_values: " . $db->fetchOne("SELECT COUNT(*) as c FROM variation_values")['c'] . "\n";
$types = $db->fetchAll("SELECT slug, COUNT(*) as cnt FROM variation_types GROUP BY slug ORDER BY slug");
foreach ($types as $t) echo "  {$t['slug']}: {$t['cnt']} types\n";
```

```bash
cd /c/ClaudeSEO && php /tmp/vt_verify.php && rm /tmp/vt_verify.php
```

Expected:
```
variation_types: 240 (expected: 240 = 40x6)
  beratungsform: 40
  beratungsphase: 40
  dringlichkeit: 40
  personenstatus: 40
  staedte: 40
  ziel: 40
```

**Step 4: Commit**

```bash
cd /c/ClaudeSEO && git add database/seed_variation_types_v2.php && git commit -m "feat: seed 5 new universal variation types with values (Personenstatus, Dringlichkeit, Beratungsphase, Ziel, Beratungsform)"
```

---

## Task 2: DB Migration — Create rechtsfrage_variation_intros Table

**Files:**
- Create: `database/migrate_variation_intros.php`

**Step 1: Create the migration script**

```php
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
```

**Step 2: Run it**

```bash
cd /c/ClaudeSEO && php database/migrate_variation_intros.php
```

Expected: `Table rechtsfrage_variation_intros created (or already exists).`

**Step 3: Commit**

```bash
cd /c/ClaudeSEO && git add database/migrate_variation_intros.php && git commit -m "feat: add rechtsfrage_variation_intros table for smart substitution"
```

---

## Task 3: ContentGenerator — Add generateVariationIntros() Method

**Files:**
- Modify: `lib/ContentGenerator.php`

Add this method after `generateVariationLocalization()` (around line 133, before `callClaude()`):

**Step 1: Insert the method**

```php
    /**
     * Generate 6 intro paragraphs (one per universal variation type) for a Rechtsfrage.
     * Each paragraph contains the literal placeholder [VARIATION_VALUE] in the text.
     * These are stored once and reused for ALL values of that type at render time.
     *
     * @param array $rechtsfrage Rechtsfrage record from database
     * @param array $rechtsgebiet Rechtsgebiet record
     * @return array Keyed by variation_type_slug => HTML paragraph string
     * @throws RuntimeException
     */
    public function generateVariationIntros(array $rechtsfrage, array $rechtsgebiet): array {
        if (!$this->checkDailyBudget()) {
            throw new RuntimeException('Daily API budget exceeded.');
        }

        $rfName = $rechtsfrage['name'];
        $rgName = $rechtsgebiet['name'];

        // Map: type slug => context phrase telling Claude what [VARIATION_VALUE] represents
        $types = [
            'staedte'        => 'die Stadt [VARIATION_VALUE]',
            'personenstatus' => 'den Personenstatus [VARIATION_VALUE]',
            'dringlichkeit'  => 'die Dringlichkeit: [VARIATION_VALUE]',
            'beratungsphase' => 'die Beratungsphase: [VARIATION_VALUE]',
            'ziel'           => 'das Beratungsziel: [VARIATION_VALUE]',
            'beratungsform'  => 'die Beratungsform [VARIATION_VALUE]',
        ];

        $results = [];
        foreach ($types as $typeSlug => $typeContext) {
            $prompt = <<<PROMPT
Du bist ein deutscher Rechtsexperte. Schreibe genau 3 Sätze zu "{$rfName}" im Bereich "{$rgName}", speziell für {$typeContext}.

WICHTIG: Nutze exakt den Text [VARIATION_VALUE] (in eckigen Klammern) als Platzhalter im Satz — nicht die Beschreibung.
Beispiel für Städte: "In [VARIATION_VALUE] gelten für {$rfName} besondere regionale Regelungen..."
Beispiel für Personenstatus: "Als [VARIATION_VALUE] haben Sie im Bereich {$rfName} spezifische Rechte..."

Ausgabe: Genau ein HTML-Absatz (<p>...</p>) der den Platzhalter [VARIATION_VALUE] mindestens einmal enthält.
PROMPT;

            $results[$typeSlug] = $this->callClaude($prompt, 512);
        }

        return $results;
    }
```

**Step 2: Verify PHP syntax**

```bash
cd /c/ClaudeSEO && php -l lib/ContentGenerator.php
```

Expected: `No syntax errors detected in lib/ContentGenerator.php`

**Step 3: Commit**

```bash
cd /c/ClaudeSEO && git add lib/ContentGenerator.php && git commit -m "feat: add generateVariationIntros() to ContentGenerator for 6 universal variation types"
```

---

## Task 4: Content API — Generate + Store Intros During Rechtsfrage Generation

**Files:**
- Modify: `admin/api/content.php`

**Step 1: Replace the `case 'rechtsfrage':` block (lines 83–137)**

The new block adds intro generation + storage after the base page upsert. Replace the entire `case 'rechtsfrage':` section:

```php
        case 'rechtsfrage':
            $rechtsfrage = $db->fetchOne('SELECT * FROM rechtsfragen WHERE id = ?', [$id]);
            if (!$rechtsfrage) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Rechtsfrage not found']);
                exit;
            }

            $rechtsgebiet = $db->fetchOne(
                'SELECT * FROM rechtsgebiete WHERE id = ?',
                [$rechtsfrage['rechtsgebiet_id']]
            );
            if (!$rechtsgebiet) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Parent Rechtsgebiet not found']);
                exit;
            }

            // Generate base content (1 API call)
            $content = $gen->generateRechtsfragContent($rechtsfrage, $rechtsgebiet);

            // Upsert base page
            $existingPage = $db->fetchOne(
                'SELECT id FROM rechtsfrage_pages WHERE rechtsfrage_id = ?',
                [$id]
            );

            if ($existingPage) {
                $db->update('rechtsfrage_pages', [
                    'title'             => $content['title'],
                    'meta_description'  => $content['meta_description'],
                    'meta_keywords'     => $content['meta_keywords'],
                    'html_content'      => $content['html_content'],
                    'og_title'          => $content['og_title'],
                    'og_description'    => $content['og_description'],
                    'generation_status' => 'generated',
                    'generated_by'      => 'admin_api',
                    'updated_at'        => date('Y-m-d H:i:s'),
                ], 'id = ?', [$existingPage['id']]);
            } else {
                $db->insert('rechtsfrage_pages', [
                    'rechtsfrage_id'    => $id,
                    'title'             => $content['title'],
                    'meta_description'  => $content['meta_description'],
                    'meta_keywords'     => $content['meta_keywords'],
                    'html_content'      => $content['html_content'],
                    'og_title'          => $content['og_title'],
                    'og_description'    => $content['og_description'],
                    'generation_status' => 'generated',
                    'generated_by'      => 'admin_api',
                    'created_at'        => date('Y-m-d H:i:s'),
                ]);
            }

            // Generate 6 variation intro paragraphs (6 additional API calls)
            $intros = $gen->generateVariationIntros($rechtsfrage, $rechtsgebiet);
            foreach ($intros as $typeSlug => $introHtml) {
                $db->query(
                    "INSERT INTO rechtsfrage_variation_intros (rechtsfrage_id, variation_type_slug, intro_content)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE intro_content = VALUES(intro_content), updated_at = NOW()",
                    [$id, $typeSlug, $introHtml]
                );
            }

            echo json_encode(['status' => 'success', 'title' => $content['title']]);
            break;
```

**Step 2: Verify PHP syntax**

```bash
cd /c/ClaudeSEO && php -l admin/api/content.php
```

Expected: `No syntax errors detected in admin/api/content.php`

**Step 3: Test via curl (pick a real Rechtsfrage id)**

Find an existing Rechtsfrage id:

```bash
curl -s "http://localhost:8081/api/rechtsfragen.php?rechtsgebiet_id=1" | python3 -m json.tool | grep '"id"' | head -3
```

Then generate it (replace ID with a real one):

```bash
curl -s -X POST http://localhost:8081/api/content.php \
  -H "Content-Type: application/json" \
  -d '{"type":"rechtsfrage","id":REPLACE_WITH_ID}'
```

Expected: `{"status":"success","title":"..."}`

**Step 4: Verify intros were stored**

Create `/tmp/check_intros.php`:
```php
<?php
require 'vendor/autoload.php'; require 'lib/Database.php';
$db = Database::getInstance();
$rfId = (int)($argv[1] ?? 1);
$rows = $db->fetchAll(
    "SELECT variation_type_slug, LEFT(intro_content, 100) AS preview FROM rechtsfrage_variation_intros WHERE rechtsfrage_id = ?",
    [$rfId]
);
echo "Intros for rechtsfrage_id=$rfId: " . count($rows) . " rows\n\n";
foreach ($rows as $r) echo $r['variation_type_slug'] . ":\n  " . $r['preview'] . "\n\n";
```

```bash
cd /c/ClaudeSEO && php /tmp/check_intros.php REPLACE_WITH_ID && rm /tmp/check_intros.php
```

Expected: 6 rows (staedte, personenstatus, dringlichkeit, beratungsphase, ziel, beratungsform), each containing `[VARIATION_VALUE]` in the preview.

**Step 5: Commit**

```bash
cd /c/ClaudeSEO && git add admin/api/content.php && git commit -m "feat: generate and store 6 variation intro paragraphs during Rechtsfrage generation"
```

---

## Task 5: Router — Smart Substitution in renderVariation()

**Files:**
- Modify: `lib/Router.php`

**Step 1: Update renderRechtsfrage() — filter variation links to Städte only**

The `$variations` array in `renderRechtsfrage()` is used for the "Ihre Stadt" section. With 6 types, we now have ~127 values — too many. Filter to only Städte:

In `renderRechtsfrage()` (around line 84), replace:
```php
        // Get variation values for this rechtsgebiet's Städte type
        $variations = $this->db->fetchAll(
            'SELECT vv.* FROM variation_values vv
             JOIN variation_types vt ON vv.variation_type_id = vt.id
             WHERE vt.rechtsgebiet_id = ? ORDER BY vv.tier, vv.value', [$rg['id']]
        );
```

With:
```php
        // Get city variation values only (Städte type) for the variation links section
        $variations = $this->db->fetchAll(
            'SELECT vv.* FROM variation_values vv
             JOIN variation_types vt ON vv.variation_type_id = vt.id
             WHERE vt.rechtsgebiet_id = ? AND vt.slug = ? ORDER BY vv.tier, vv.value',
            [$rg['id'], 'staedte']
        );
```

**Step 2: Replace renderVariation() (lines 126–153) with smart substitution logic**

```php
    private function renderVariation(array $rf, array $vv): void {
        $rg = $this->db->fetchOne('SELECT * FROM rechtsgebiete WHERE id = ?', [$rf['rechtsgebiet_id']]);

        // Resolve variation type for this value
        $vt = $this->db->fetchOne(
            'SELECT slug AS type_slug, name AS type_name FROM variation_types WHERE id = ?',
            [$vv['variation_type_id']]
        );
        $typeSlug = $vt ? $vt['type_slug'] : 'staedte';
        $typeName = $vt ? $vt['type_name'] : 'Städte';

        // Fetch base content
        $parentPage = $this->db->fetchOne(
            'SELECT * FROM rechtsfrage_pages WHERE rechtsfrage_id = ?', [$rf['id']]
        );

        // Fetch smart intro paragraph for this type
        $introRow = $this->db->fetchOne(
            'SELECT intro_content FROM rechtsfrage_variation_intros WHERE rechtsfrage_id = ? AND variation_type_slug = ?',
            [$rf['id'], $typeSlug]
        );

        // Build page data: smart substitution if intro exists, else fallback to variation_pages
        if ($introRow && $parentPage) {
            $introHtml    = str_replace('[VARIATION_VALUE]', htmlspecialchars($vv['value']), $introRow['intro_content']);
            $combinedHtml = $introHtml . "\n" . ($parentPage['html_content'] ?? '');
            $page = [
                'html_content'     => $combinedHtml,
                'title'            => null, // template builds title from rf + vv
                'meta_description' => null,
                'meta_keywords'    => $parentPage['meta_keywords'] ?? '',
            ];
        } else {
            // Fallback: pre-generated variation page (old system)
            $page = $this->db->fetchOne(
                'SELECT * FROM variation_pages WHERE rechtsfrage_id = ? AND variation_value_id = ?',
                [$rf['id'], $vv['id']]
            );
        }

        // Sibling variations: same type, same rechtsgebiet, different value
        $siblingVariations = $this->db->fetchAll(
            'SELECT vv.* FROM variation_values vv
             JOIN variation_types vt ON vv.variation_type_id = vt.id
             WHERE vt.rechtsgebiet_id = ? AND vt.slug = ? AND vv.id != ?
             ORDER BY vv.tier ASC, vv.value ASC',
            [$rg['id'], $typeSlug, $vv['id']]
        );

        $data = [
            'rechtsfrage'       => $rf,
            'rechtsgebiet'      => $rg,
            'variation_value'   => $vv,
            'variation_type'    => ['slug' => $typeSlug, 'name' => $typeName],
            'page'              => $page,
            'parent_page'       => $parentPage,
            'sibling_variations' => $siblingVariations,
        ];
        $this->render('variation', $data);
    }
```

**Step 3: Verify PHP syntax**

```bash
cd /c/ClaudeSEO && php -l lib/Router.php
```

Expected: `No syntax errors detected in lib/Router.php`

**Step 4: Test routing for existing city slug**

Find a Rechtsfrage slug and city slug from the database:

```bash
php -r "require 'vendor/autoload.php'; require 'lib/Database.php'; \$db=Database::getInstance(); \$rf=\$db->fetchOne('SELECT slug FROM rechtsfragen LIMIT 1'); \$vv=\$db->fetchOne(\"SELECT slug FROM variation_values LIMIT 1\"); echo \$rf['slug'].'-'.\$vv['slug'].PHP_EOL;"
```

Then test in browser: `http://localhost:8080/{rf_slug}-{city_slug}` — should render without errors.

Also test a new type slug: `http://localhost:8080/{rf_slug}-privatperson` — should show "Seite nicht gefunden" only if no rechtsfrage has slug matching. The router will look for variation_value with slug `privatperson` and if found will call renderVariation() — which will try smart substitution.

**Step 5: Commit**

```bash
cd /c/ClaudeSEO && git add lib/Router.php && git commit -m "feat: smart substitution in Router renderVariation(), filter Städte for rechtsfrage variation links"
```

---

## Task 6: Update variation.php Template for All 6 Types

**Files:**
- Modify: `templates/variation.php`

The template currently uses city-specific language. We generalize using the new `$variation_type` variable passed by the router.

**Step 1: Replace the entire header/variables section (lines 1–32)**

```php
<?php
$rfName   = htmlspecialchars($rechtsfrage['name']);
$rgName   = htmlspecialchars($rechtsgebiet['name']);
$varValue = htmlspecialchars($variation_value['value']);
$typeSlug = $variation_type['slug'] ?? 'staedte';
$typeName = htmlspecialchars($variation_type['name'] ?? 'Städte');

// Context phrase adapts to variation type
$isCity        = ($typeSlug === 'staedte');
$contextPhrase = $isCity ? "in {$varValue}" : "für {$varValue}";

$pageTitleStr    = isset($page['title']) && $page['title']
    ? $page['title']
    : "{$rfName} {$contextPhrase} | Rechtecheck";
$pageMeta        = isset($page['meta_description']) && $page['meta_description']
    ? $page['meta_description']
    : mb_substr("{$rfName} {$contextPhrase} – Kostenlose Ersteinschätzung durch spezialisierte Anwälte bei Rechtecheck.", 0, 155);
$pageKeywords    = ($page['meta_keywords'] ?? "{$rfName}, {$rgName}") . ', ' . $variation_value['value'];

$title           = $pageTitleStr;
$meta_description = $pageMeta;
$meta_keywords   = $pageKeywords;
$og_title        = $title;
$og_description  = $meta_description;
$canonical_url   = "https://rechtecheck.de/{$rechtsfrage['slug']}-{$variation_value['slug']}/";
$breadcrumbs     = [
    ['name' => 'Home',          'url' => '/'],
    ['name' => 'Rechtsgebiete', 'url' => '/experten-service/unsere-services/'],
    ['name' => $rechtsgebiet['name'], 'url' => "/experten-service/{$rechtsgebiet['slug']}/"],
    ['name' => $rechtsfrage['name'], 'url' => "/{$rechtsfrage['slug']}/"],
    ['name' => $varValue, 'url' => "/{$rechtsfrage['slug']}-{$variation_value['slug']}/"],
];

$schemaData = [
    '@context'    => 'https://schema.org',
    '@type'       => 'LegalService',
    'name'        => "{$rfName} {$contextPhrase} – Rechtecheck",
    'description' => $meta_description,
    'url'         => $canonical_url,
];
if ($isCity) {
    $schemaData['areaServed'] = ['@type' => 'City', 'name' => $variation_value['value']];
}
$schema_extra = json_encode($schemaData, JSON_UNESCAPED_UNICODE);
```

**Step 2: Update the hero section (around line 67–71)**

Change:
```php
<h1><?= $rfName ?> in <?= $cityName ?></h1>
<p class="mt-3"><?= $rgName ?> - Rechtliche Unterstützung in <?= $cityName ?></p>
```

To:
```php
<h1><?= $rfName ?> <?= $contextPhrase ?></h1>
<p class="mt-3"><?= $rgName ?> – Rechtliche Unterstützung <?= $contextPhrase ?></p>
```

**Step 3: Wrap the Local Experts section (around line 105–109) to city-only**

Change from:
```php
                <!-- Local Experts Section -->
                <div id="local-experts" class="mt-5 p-4 border rounded-3 bg-light">
                    <h2 class="h4 fw-bold mb-3">Fachanwälte für <?= $rfName ?> in <?= $cityName ?></h2>
                    ...
                </div>
```

To:
```php
                <!-- Local Experts Section (cities only) -->
                <?php if ($isCity): ?>
                <div id="local-experts" class="mt-5 p-4 border rounded-3 bg-light">
                    <h2 class="h4 fw-bold mb-3">Fachanwälte für <?= $rfName ?> in <?= $varValue ?></h2>
                    <p>Finden Sie hier spezialisierte Anwälte für Ihr Anliegen in <?= $varValue ?>. Unsere Partneranwälte bieten Ihnen eine kostenlose Ersteinschätzung Ihres Falls.</p>
                    <a href="#" class="btn rc-btn-cta">Anwalt in <?= $varValue ?> finden</a>
                </div>
                <?php endif; ?>
```

**Step 4: Update CTA section (around line 112–116)**

Change `in <?= $cityName ?>` references to `<?= $contextPhrase ?>`:
```php
<h3 class="h4 mb-3">Brauchen Sie rechtliche Hilfe <?= $contextPhrase ?>?</h3>
<p>Unsere spezialisierten Partneranwälte helfen Ihnen weiter.</p>
```

**Step 5: Update sibling variation section heading (around line 121)**

Change:
```php
<h2 class="h4 fw-bold mb-3"><?= $rfName ?> in anderen Städten</h2>
```

To:
```php
<h2 class="h4 fw-bold mb-3"><?= $rfName ?> – Weitere <?= $typeName ?></h2>
```

**Step 6: Update sidebar CTA text (around line 138)**

Change `in <?= $cityName ?>` to `<?= $contextPhrase ?>`:
```php
<p class="small text-muted">Anwaltliche Ersteinschätzung durch Partner-Anwälte <?= $contextPhrase ?>.</p>
```

**Step 7: Update sidebar heading for sibling links (around line 152)**

Change `Weitere Städte` to `<?= $typeName ?>`:
```php
<h4 class="h6 fw-bold mb-3"><?= $typeName ?></h4>
```

**Step 8: Fix all remaining `$cityName` references**

After the above changes, grep for any remaining `$cityName` and replace with `$varValue`:

```bash
cd /c/ClaudeSEO && grep -n 'cityName' templates/variation.php
```

Replace any found occurrences with `$varValue`.

**Step 9: Verify PHP syntax**

```bash
cd /c/ClaudeSEO && php -l templates/variation.php
```

**Step 10: Browser test**

- `http://localhost:8080/{rf_slug}-berlin` — shows "in Berlin", shows Local Experts section, sibling label says "Städte"
- `http://localhost:8080/{rf_slug}-privatperson` — shows "für Privatperson", NO Local Experts section, sibling label says "Personenstatus"

**Step 11: Commit**

```bash
cd /c/ClaudeSEO && git add templates/variation.php && git commit -m "feat: generalize variation template for all 6 variation types"
```

---

## Task 7: Admin — Update variations.php API + dashboard.js

**Part A: variations.php**

**Files:**
- Modify: `admin/api/variations.php`

**Step 1: Replace the SQL query (lines 19–27)**

Old query fetched `page_status` from `variation_pages`. New query returns type info and intro status:

```php
    $sql = "SELECT
                vv.id,
                vv.value,
                vv.slug,
                vv.tier,
                vt.name  AS variation_type_name,
                vt.slug  AS variation_type_slug,
                rf.slug  AS rf_slug,
                CASE WHEN rvi.id IS NOT NULL THEN 'generated' ELSE 'pending' END AS intro_status
            FROM variation_values vv
            INNER JOIN variation_types vt ON vv.variation_type_id = vt.id
            INNER JOIN rechtsgebiete rg ON vt.rechtsgebiet_id = rg.id
            INNER JOIN rechtsfragen rf ON rf.rechtsgebiet_id = rg.id
            LEFT JOIN rechtsfrage_variation_intros rvi
                ON rvi.rechtsfrage_id = rf.id
                AND rvi.variation_type_slug = vt.slug
            WHERE rf.id = ?
            ORDER BY vt.slug ASC, vv.tier ASC, vv.value ASC";
```

**Step 2: Verify PHP syntax**

```bash
cd /c/ClaudeSEO && php -l admin/api/variations.php
```

**Step 3: Test API response**

```bash
curl -s "http://localhost:8081/api/variations.php?rechtsfrage_id=1" | python3 -m json.tool | head -40
```

Expected: array of objects with `variation_type_name`, `variation_type_slug`, `rf_slug`, `intro_status` fields.

**Part B: dashboard.js**

**Files:**
- Modify: `admin/assets/dashboard.js`

**Step 4: Replace createVarRow() (lines 146–176)**

The new version shows type label + value, shows intro status, removes generate button (on-the-fly rendering makes individual generation unnecessary):

```js
/**
 * Create a Variation table row showing type + value + intro status.
 * Variations now render on-the-fly via smart substitution, so no generate button.
 */
function createVarRow(variation, rfId) {
    const tr = document.createElement('tr');
    tr.className = 'var-row visible';
    tr.dataset.varId = variation.id;
    tr.dataset.parentRf = rfId;

    const typeLabel  = escapeHtml(variation.variation_type_name || 'Städte');
    const varValue   = escapeHtml(variation.value || '');
    const rfSlug     = variation.rf_slug || '';
    const varSlug    = variation.slug || '';
    const introStatus = variation.intro_status || 'pending';
    const statusClass = getStatusClass(introStatus);
    const statusIcon  = getStatusIcon(introStatus);

    tr.innerHTML = `
        <td></td>
        <td>
            <span class="row-name">
                <span style="color:var(--text-muted);font-size:0.78em;margin-right:0.3rem">[${typeLabel}]</span>${varValue}
            </span>
        </td>
        <td><span class="status-badge ${statusClass}"><i class="fas ${statusIcon}"></i> ${escapeHtml(introStatus)}</span></td>
        <td><span class="score-value">-</span></td>
        <td><span class="metric-value">-</span></td>
        <td><span class="metric-value">-</span></td>
        <td><span class="metric-value">-</span></td>
        <td>
            <div class="row-actions">
                ${rfSlug && varSlug ? `<button class="btn-row btn-preview" onclick="event.stopPropagation(); previewPage('variation', '${escapeAttr(rfSlug)}-${escapeAttr(varSlug)}')" title="Vorschau"><i class="fas fa-eye"></i> Preview</button>` : ''}
            </div>
        </td>
    `;

    return tr;
}
```

**Step 5: Verify in browser**

Open http://localhost:8081/ → expand a Rechtsgebiet → expand a Rechtsfrage → verify:
- Variation rows show `[Städte] Berlin`, `[Personenstatus] Privatperson`, etc.
- Status shows "pending" (or "generated" if intros were generated in Task 4)
- Only Preview button visible, no Generate button

**Step 6: Commit**

```bash
cd /c/ClaudeSEO && git add admin/api/variations.php admin/assets/dashboard.js && git commit -m "feat: update variations API and dashboard to show 6 variation types with intro status"
```

---

## Task 8: Final Verification

**Step 1: Generate a Rechtsfrage and check all 6 intros**

```bash
curl -s -X POST http://localhost:8081/api/content.php \
  -H "Content-Type: application/json" \
  -d '{"type":"rechtsfrage","id":1}'
```

Expected: `{"status":"success","title":"..."}`

**Step 2: Verify no [VARIATION_VALUE] leaks in rendered pages**

After generation, test a city URL and confirm the placeholder is replaced:

```bash
# Replace rf_slug with actual slug from DB
curl -s "http://localhost:8080/{rf_slug}-berlin" | python3 -c "import sys; content=sys.stdin.read(); print('LEAK' if '[VARIATION_VALUE]' in content else 'OK - no placeholder leak')"
```

Expected: `OK - no placeholder leak`

**Step 3: Test all 6 variation types render correctly**

Visit in browser (adjust {rf_slug} to an actual slug):
1. `http://localhost:8080/{rf_slug}-berlin` → "in Berlin", hero title correct, Local Experts visible
2. `http://localhost:8080/{rf_slug}-privatperson` → "für Privatperson", no Local Experts
3. `http://localhost:8080/{rf_slug}-sofortberatung` → "für Sofortberatung"
4. `http://localhost:8080/{rf_slug}-erstberatung` → "für Erstberatung"
5. `http://localhost:8080/{rf_slug}-nur-informieren` → "für Nur informieren"
6. `http://localhost:8080/{rf_slug}-online-beratung` → "für Online-Beratung"

**Step 4: Verify dashboard variation list**

Open http://localhost:8081/ → expand any Rechtsgebiet → expand any Rechtsfrage → confirm ~127 rows appear grouped with type labels.

**Step 5: Count total potential variation pages**

```bash
php -r "require 'vendor/autoload.php'; require 'lib/Database.php'; \$db=Database::getInstance(); \$rf=\$db->fetchOne('SELECT COUNT(*) as c FROM rechtsfragen')['c']; \$vv=\$db->fetchOne('SELECT COUNT(*) as c FROM variation_values')['c']; echo 'Potential variation pages: ' . (\$rf * \$vv / 40) . ' (' . \$rf . ' RFs x ' . (\$vv/40) . ' values avg per RG)\n';"
```

Expected: shows the scale of potential pages (should be ~37,000+ potential variation pages).
