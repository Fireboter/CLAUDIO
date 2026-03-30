# Rechtecheck SEO System - Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a fully automated PHP system that generates, publishes, monitors, and optimizes legal SEO pages for Rechtecheck.de across 4 hierarchical levels.

**Architecture:** Vanilla PHP with MySQL backend. Public pages served by a front-controller router with .htaccess rewriting. Admin dashboard is a separate SPA. 5 independent cron phases handle daily automation. Claude Haiku API generates content. Google Search Console API provides free analytics.

**Tech Stack:** PHP 8+, MySQL, Guzzle HTTP, Bootstrap 5 (CDN), Chart.js (CDN), Claude Haiku API, Google Search Console API

**Design Doc:** `docs/plans/2026-03-05-rechtecheck-seo-system-design.md`

**Seed Data:** `C:\RechtecheckSEO\new_project\rechtsbereiche_v2.csv` (413 rows: 40 Rechtsgebiete with parent_id=0, ~370 Rechtsfragen with parent_id referencing their Rechtsgebiet)

**DB Credentials (existing server):** host=45.9.61.45, db=rechtecheck_seo, user=geltix, pass=1xzsR1*35

---

## Phase A: Foundation

### Task 1: Project Scaffolding

**Files:**
- Create: `composer.json`
- Create: `config/database.php`
- Create: `config/api_keys.php`
- Create: `config/seo.php`
- Create: `.gitignore`

**Step 1: Create directory structure**

```bash
cd C:\ClaudeSEO
mkdir -p public/assets/css public/assets/js
mkdir -p admin/api admin/assets
mkdir -p templates
mkdir -p cron
mkdir -p config
mkdir -p lib
mkdir -p database
mkdir -p data
mkdir -p logs
```

**Step 2: Create composer.json**

```json
{
    "name": "rechtecheck/claudeseo",
    "description": "Automated legal SEO page generator for Rechtecheck.de",
    "require": {
        "guzzlehttp/guzzle": "^7.0"
    },
    "autoload": {
        "classmap": ["lib/"]
    }
}
```

**Step 3: Run composer install**

```bash
cd C:\ClaudeSEO && composer install
```

**Step 4: Create config/database.php**

```php
<?php
return [
    'host'    => '45.9.61.45',
    'dbname'  => 'rechtecheck_seo',
    'user'    => 'geltix',
    'pass'    => '1xzsR1*35',
    'charset' => 'utf8mb4',
];
```

**Step 5: Create config/api_keys.php**

```php
<?php
return [
    'claude' => [
        'api_key' => '',           // User fills in
        'model'   => 'claude-haiku-4-5-20251001',
        'max_tokens' => 4096,
    ],
    'gsc' => [
        'credentials_path' => __DIR__ . '/gsc_credentials.json',  // User provides
        'property_url'     => 'https://rechtecheck.de/',
    ],
];
```

**Step 6: Create config/seo.php**

```php
<?php
return [
    'site_name'    => 'Rechtecheck',
    'site_url'     => 'https://rechtecheck.de',
    'cta_base_url' => 'https://rechtecheck.de/experten-service/',

    'cities_tier1' => [
        'Berlin','Muenchen','Hamburg','Koeln','Frankfurt',
        'Stuttgart','Duesseldorf','Leipzig','Dortmund','Essen',
        'Bremen','Dresden','Hannover','Nuernberg','Duisburg',
    ],
    'cities_tier2' => [
        'Bochum','Wuppertal','Bielefeld','Bonn','Muenster',
        'Mannheim','Karlsruhe','Augsburg','Wiesbaden',
        'Moenchengladbach','Gelsenkirchen','Aachen','Braunschweig',
        'Kiel','Chemnitz','Freiburg','Mainz','Rostock',
    ],
    'cities_tier3' => [
        'Luebeck','Erfurt','Hagen','Kassel','Oberhausen',
        'Hamm','Saarbruecken','Herne','Solingen','Leverkusen',
        'Neuss','Heidelberg','Paderborn','Darmstadt','Regensburg',
        'Wuerzburg','Ingolstadt','Wolfsburg','Ulm','Heilbronn',
        'Goettingen','Reutlingen','Koblenz','Bremerhaven','Trier',
        'Jena','Erlangen','Moers','Cottbus','Siegen',
    ],

    'safeguards' => [
        'max_api_calls_per_day'   => 500,
        'max_cost_per_day_cents'  => 500,
        'cooldown_after_generate' => 7,
        'cooldown_after_optimize' => 14,
        'min_days_before_judging' => 30,
        'min_days_before_delete'  => 60,
    ],
];
```

**Step 7: Create .gitignore**

```
vendor/
config/api_keys.php
config/gsc_credentials.json
logs/*.log
*.lock
```

**Step 8: Copy seed CSV**

```bash
cp "C:\RechtecheckSEO\new_project\rechtsbereiche_v2.csv" "C:\ClaudeSEO\data\rechtsbereiche_v2.csv"
```

**Step 9: Commit**

```bash
git init && git add -A && git commit -m "feat: project scaffolding with config and composer"
```

---

### Task 2: Database Schema & Core Library

**Files:**
- Create: `database/schema.sql`
- Create: `lib/Database.php`

**Step 1: Create database/schema.sql**

Full schema with all tables from design doc. Include:
- `rechtsgebiete` (id, name, slug, status, performance_score, avg_position, total_clicks, total_impressions, created_at, updated_at)
- `rechtsfragen` (id, rechtsgebiet_id FK, name, slug, description, status, performance_score, avg_position, total_clicks, total_impressions, created_at, updated_at)
- `variation_types` (id, rechtsgebiet_id FK, name, slug, created_at)
- `variation_values` (id, variation_type_id FK, value, slug, tier INT DEFAULT 1, created_at)
- `rechtsgebiet_pages` (id, rechtsgebiet_id FK UNIQUE, title, meta_description, meta_keywords, html_content, og_title, og_description, generation_status ENUM, generated_by, published_at, created_at, updated_at)
- `rechtsfrage_pages` (id, rechtsfrage_id FK UNIQUE, title, meta_description, meta_keywords, html_content, og_title, og_description, generation_status ENUM, generated_by, published_at, created_at, updated_at)
- `variation_pages` (id, rechtsfrage_id FK, variation_value_id FK, title, meta_description, meta_keywords, html_content, generation_status ENUM, generated_by, published_at, created_at, updated_at, UNIQUE)
- `page_analytics` (id, page_type ENUM, page_id, url, clicks, impressions, ctr, avg_position, date, fetched_at, INDEX)
- `page_decisions` (id, page_type, page_id, action ENUM, reason, priority_score, decided_at, executed_at)
- `api_usage` (id, date, api_name, calls_count, tokens_used, cost_cents, UNIQUE)
- `cron_log` (id, phase, started_at, ended_at, items_processed, errors, notes)

generation_status ENUM: 'pending','generating','generated','failed','published'

**Step 2: Create lib/Database.php**

Singleton PDO wrapper. Constructor reads config/database.php, creates PDO connection with utf8mb4, ERRMODE_EXCEPTION, FETCH_ASSOC defaults.

Methods:
- `static getInstance(): self` - singleton
- `getPdo(): PDO`
- `query(string $sql, array $params = []): PDOStatement` - prepared statement shorthand
- `fetchAll(string $sql, array $params = []): array`
- `fetchOne(string $sql, array $params = []): ?array`
- `insert(string $table, array $data): int` - returns lastInsertId
- `update(string $table, array $data, string $where, array $whereParams = []): int` - returns rowCount

**Step 3: Run schema against DB**

```bash
php -r "
require 'vendor/autoload.php';
require 'lib/Database.php';
\$db = Database::getInstance();
\$sql = file_get_contents('database/schema.sql');
\$db->getPdo()->exec(\$sql);
echo 'Schema created successfully';
"
```

**Step 4: Verify tables exist**

```bash
php -r "
require 'vendor/autoload.php';
require 'lib/Database.php';
\$tables = Database::getInstance()->fetchAll('SHOW TABLES');
print_r(\$tables);
"
```

Expected: all 11 tables listed.

**Step 5: Commit**

```bash
git add database/schema.sql lib/Database.php && git commit -m "feat: database schema and Database class"
```

---

### Task 3: CSV Seed Script

**Files:**
- Create: `database/seed.php`

**Step 1: Create database/seed.php**

This script:
1. Reads `data/rechtsbereiche_v2.csv`
2. First pass: inserts all rows with parent_id=0 as rechtsgebiete (name, slug auto-generated via slugify)
3. Second pass: inserts all rows with parent_id>0 as rechtsfragen (name, slug, description, rechtsgebiet_id mapped from parent_id)
4. Creates a default "Staedte" variation_type for ALL rechtsgebiete
5. Inserts all Tier 1 cities as variation_values for each Staedte type

Slug function: `strtolower(str_replace([' ', 'ae', 'oe', 'ue', 'ss', ...], ['-', 'ae', 'oe', 'ue', 'ss', ...], $name))` — convert umlauts and spaces to URL-safe slugs.

**Step 2: Run the seed**

```bash
php database/seed.php
```

Expected output: "Seeded X rechtsgebiete, Y rechtsfragen, Z variation types, W variation values"

**Step 3: Verify counts**

```bash
php -r "
require 'vendor/autoload.php';
require 'lib/Database.php';
\$db = Database::getInstance();
echo 'Rechtsgebiete: ' . \$db->fetchOne('SELECT COUNT(*) as c FROM rechtsgebiete')['c'] . PHP_EOL;
echo 'Rechtsfragen: ' . \$db->fetchOne('SELECT COUNT(*) as c FROM rechtsfragen')['c'] . PHP_EOL;
echo 'Variation types: ' . \$db->fetchOne('SELECT COUNT(*) as c FROM variation_types')['c'] . PHP_EOL;
echo 'Variation values: ' . \$db->fetchOne('SELECT COUNT(*) as c FROM variation_values')['c'] . PHP_EOL;
"
```

Expected: ~40 rechtsgebiete, ~370 rechtsfragen, ~40 variation types, ~600 variation values (40 rechtsgebiete x 15 cities)

**Step 4: Commit**

```bash
git add database/seed.php && git commit -m "feat: CSV seed script with tier 1 cities"
```

---

### Task 4: Router & .htaccess

**Files:**
- Create: `lib/Router.php`
- Create: `public/index.php`
- Create: `public/.htaccess`

**Step 1: Create lib/Router.php**

The Router class:
1. Takes the REQUEST_URI
2. Strips query string
3. Matches against patterns in order:
   - Exact match `/experten-service/unsere-services/` -> renders `templates/rechtsgebiete.php`
   - Pattern `/experten-service/{slug}/` -> looks up slug in rechtsgebiete table -> renders `templates/rechtsgebiet.php` with data
   - Pattern `/{slug}/` -> tries to match against rechtsfragen slug -> renders `templates/rechtsfrage.php`
   - Pattern `/{slug}-{city}/` -> tries to split slug, find rechtsfrage + variation_value -> renders `templates/variation.php`
   - No match -> 404

Key method: `dispatch()` which:
- Resolves the URL to a template + data array
- The data array contains the DB row(s) needed by the template
- Calls `include` on the template file with the data extracted to local vars
- For rechtsfrage and variation: only renders if page exists in DB with generation_status='published'

Slug resolution strategy for rechtsfrage vs variation:
1. Try the full slug as a rechtsfrage slug first
2. If not found, try splitting from the end: last segment might be a city slug
3. Look up the city slug in variation_values, the rest in rechtsfragen
4. If both found, it's a variation page

**Step 2: Create public/.htaccess**

```apache
RewriteEngine On
RewriteBase /

# Skip real files and directories
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d

# Route everything to front controller
RewriteRule ^(.*)$ index.php [QSA,L]
```

**Step 3: Create public/index.php**

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Router.php';

$router = new Router();
$router->dispatch();
```

**Step 4: Verify routing works**

Start PHP built-in server:
```bash
cd C:\ClaudeSEO\public && php -S localhost:8080
```

Test URLs (should return 404 for now since templates don't exist yet, but router should not crash):
```bash
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/experten-service/unsere-services/
```
Expected: 404 or 200 (depending on template check)

**Step 5: Commit**

```bash
git add lib/Router.php public/index.php public/.htaccess && git commit -m "feat: front controller router with URL pattern matching"
```

---

## Phase B: Public Page Templates

### Task 5: Shared Layout & CSS

**Files:**
- Create: `templates/layout.php`
- Create: `public/assets/css/style.css`

**Step 1: Create templates/layout.php**

A shared layout wrapper that all 4 templates include. Contains:
- `<!DOCTYPE html>` + `<html lang="de">`
- `<head>` with: charset, viewport, `<title>`, `<meta description>`, `<meta keywords>`, OG tags, canonical URL, favicon
- Schema.org JSON-LD (BreadcrumbList, passed as variable)
- Bootstrap 5 CDN link
- `public/assets/css/style.css` link
- Sticky header with RECHTECHECK logo + nav (Rechtsgebiete, Magazin links)
- `<?= $content ?>` body slot
- Footer with copyright, Datenschutz, Kontakt, Impressum links
- Bootstrap JS CDN

Variables expected: `$title`, `$meta_description`, `$meta_keywords`, `$og_title`, `$og_description`, `$canonical_url`, `$breadcrumbs` (array), `$schema_extra` (additional JSON-LD), `$content` (HTML string)

**Step 2: Create public/assets/css/style.css**

Style the pages to match Rechtecheck.de aesthetic:
- Primary color: #003366 (dark blue)
- Success/CTA color: #28a745 (green)
- Clean, professional, legal-industry look
- Sticky sidebar CTA styling
- Card grid for Rechtsgebiete/Rechtsfragen
- Table of contents styling
- Responsive (mobile-first)
- FAQ accordion styling
- Breadcrumb styling

**Step 3: Commit**

```bash
git add templates/layout.php public/assets/css/style.css && git commit -m "feat: shared layout template and CSS"
```

---

### Task 6: Template Level 1 - Rechtsgebiete Overview

**Files:**
- Create: `templates/rechtsgebiete.php`

**Step 1: Create templates/rechtsgebiete.php**

Receives from Router: `$rechtsgebiete` (array of all published rechtsgebiete with their rechtsfragen counts)

Template structure:
- Sets `$title = "Rechtsgebiete - Finden Sie den richtigen Anwalt | Rechtecheck"`
- Hero section: "Auf der Suche nach dem richtigen Anwalt fuer Ihr Thema?"
- Search/filter input with JS filtering
- Grid of cards (Bootstrap `col-md-6 col-lg-4`), each card shows:
  - Rechtsgebiet name
  - Number of Rechtsfragen
  - Link to `/experten-service/{slug}/`
  - Small "Kostenlose Ersteinschaetzung" CTA
- Breadcrumbs: Home > Rechtsgebiete
- Schema.org: BreadcrumbList
- Includes layout.php with content buffer (ob_start/ob_get_clean)

**Step 2: Update Router to serve this template**

In Router.php, the `/experten-service/unsere-services/` route should:
1. Query: `SELECT rg.*, (SELECT COUNT(*) FROM rechtsfragen rf WHERE rf.rechtsgebiet_id = rg.id) as rf_count FROM rechtsgebiete rg WHERE rg.status = 'published' ORDER BY rg.name`
2. Pass result as `$rechtsgebiete` to template

**Step 3: Test in browser**

Note: rechtsgebiete won't show yet since status is 'draft'. Temporarily change seed to set status='published' OR just check the page loads without errors.

```bash
curl http://localhost:8080/experten-service/unsere-services/
```
Expected: HTML page with empty grid (no published items yet)

**Step 4: Commit**

```bash
git add templates/rechtsgebiete.php lib/Router.php && git commit -m "feat: rechtsgebiete overview template (level 1)"
```

---

### Task 7: Template Level 2 - Rechtsgebiet

**Files:**
- Create: `templates/rechtsgebiet.php`

**Step 1: Create templates/rechtsgebiet.php**

Receives from Router: `$rechtsgebiet` (row), `$rechtsfragen` (array), `$page` (rechtsgebiet_pages row or null), `$related` (array of other rechtsgebiete for sidebar)

Template structure:
- Title: "{name} - Haeufige Rechtsfragen & Kostenlose Ersteinschaetzung | Rechtecheck"
- Hero section with rechtsgebiet name
- If `$page` exists and has html_content: render the AI-generated intro
- Grid of Rechtsfragen cards, each with:
  - Name, description (truncated to 150 chars)
  - "Weitere Informationen" link to `/{rg-slug}-{rf-slug}/`
  - "Kostenlose Ersteinschaetzung" green CTA button
- Sidebar (col-lg-4):
  - Sticky CTA box
  - Related Rechtsgebiete links (internal linking)
- Breadcrumbs: Home > Rechtsgebiete > {name}
- Schema.org: LegalService + BreadcrumbList

**Step 2: Update Router for this route**

Pattern `/experten-service/{slug}/`:
1. Look up slug in rechtsgebiete table
2. Fetch rechtsfragen for this rechtsgebiet_id
3. Fetch rechtsgebiet_pages row (may be null if not generated yet)
4. Fetch 5 random other rechtsgebiete for sidebar
5. Pass all to template

**Step 3: Test**

```bash
curl http://localhost:8080/experten-service/arbeitsrecht/
```

**Step 4: Commit**

```bash
git add templates/rechtsgebiet.php && git commit -m "feat: rechtsgebiet template (level 2)"
```

---

### Task 8: Template Level 3 - Rechtsfrage

**Files:**
- Create: `templates/rechtsfrage.php`

**Step 1: Create templates/rechtsfrage.php**

Receives from Router: `$rechtsfrage` (row), `$rechtsgebiet` (parent row), `$page` (rechtsfrage_pages row), `$variations` (array of variation_values with their page status), `$sibling_fragen` (other rechtsfragen in same rechtsgebiet)

Template structure:
- Title from page.title or fallback: "{rf.name} - {rg.name} | Rechtecheck"
- Hero section with urgency styling
- Two-column layout (col-lg-8 + col-lg-4):
  - Left column:
    - Table of contents (auto-generated from H2 tags in html_content via PHP regex)
    - html_content from rechtsfrage_pages
    - FAQ section (if content contains FAQ H2, extract for schema.org FAQPage)
    - Variation links grid: "Auch in Ihrer Stadt:" with links to variation pages
    - Related Rechtsfragen links
  - Right column (sticky sidebar):
    - CTA box: "Jetzt handeln!" + "Kostenlose Ersteinschaetzung" button
    - "Verfuegbare Staedte" list linking to variation pages
- Breadcrumbs: Home > Rechtsgebiete > {rg.name} > {rf.name}
- Schema.org: LegalService + FAQPage + BreadcrumbList

TOC extraction: PHP function that parses html_content with regex to find `<h2>` tags, generates anchor links, and injects `id` attributes into the H2s.

**Step 2: Update Router**

Pattern `/{slug}/`:
1. Look up slug in rechtsfragen table
2. If found, load parent rechtsgebiet
3. Load rechtsfrage_pages row
4. Load variation_values linked to this rechtsfrage's rechtsgebiet's "Staedte" variation_type
5. Load sibling rechtsfragen (same rechtsgebiet, limit 5)
6. Render template

**Step 3: Test**

```bash
curl http://localhost:8080/arbeitsrecht-kuendigung/
```

**Step 4: Commit**

```bash
git add templates/rechtsfrage.php && git commit -m "feat: rechtsfrage template with TOC and FAQ schema (level 3)"
```

---

### Task 9: Template Level 4 - Variation

**Files:**
- Create: `templates/variation.php`

**Step 1: Create templates/variation.php**

Receives from Router: `$rechtsfrage`, `$rechtsgebiet`, `$variation_value` (row), `$page` (variation_pages row), `$parent_page` (rechtsfrage_pages row), `$sibling_variations` (other cities)

Template structure:
- Title: "{rf.name} in {city} - {rg.name} | Rechtecheck"
- Hero with localized heading
- Two-column layout:
  - Left column:
    - Same TOC as parent
    - html_content (either from variation_pages if generated, or fallback to parent content)
    - Localized "Fachanwaelte in {city}" section
    - FAQ section (same as parent)
  - Right column (sticky sidebar):
    - CTA: "Anwaltliche Ersteinschaetzung in {city}"
    - Link back to parent Rechtsfrage page
    - Sibling variation links (other cities)
- Breadcrumbs: Home > Rechtsgebiete > {rg.name} > {rf.name} > {city}
- Canonical: self (own geo keyword)
- Schema.org: LegalService + FAQPage + BreadcrumbList

**Step 2: Update Router**

The variation route is the trickiest. Strategy:
1. Take full slug, e.g. `arbeitsrecht-kuendigung-berlin`
2. Try progressively splitting from the end:
   - Check if `berlin` is a variation_value slug -> yes
   - Check if `arbeitsrecht-kuendigung` is a rechtsfrage slug -> yes
   - Match! Load both records
3. Load variation_pages row for this (rechtsfrage_id, variation_value_id) pair
4. If no variation_page exists, fall back to parent rechtsfrage_pages content

**Step 3: Test**

```bash
curl http://localhost:8080/arbeitsrecht-kuendigung-berlin/
```

**Step 4: Commit**

```bash
git add templates/variation.php && git commit -m "feat: variation template with city localization (level 4)"
```

---

## Phase C: Content Generation

### Task 10: ContentGenerator Class

**Files:**
- Create: `lib/ContentGenerator.php`

**Step 1: Create lib/ContentGenerator.php**

Class that wraps Claude Haiku API calls via Guzzle.

Constructor: loads config/api_keys.php, creates Guzzle client.

Methods:

`generateRechtsgebietContent(array $rechtsgebiet, array $rechtsfragen): array`
- Builds prompt from design doc (400-600 words, H2 sections, persuasive)
- Calls Claude Haiku API
- Returns: `['html_content' => ..., 'title' => ..., 'meta_description' => ..., 'meta_keywords' => ..., 'og_title' => ..., 'og_description' => ...]`
- Extracts title/meta from the generated content or generates separately

`generateRechtsfragContent(array $rechtsfrage, array $rechtsgebiet): array`
- Builds prompt (1500-2500 words, structured with FAQ)
- Returns same structure as above

`generateVariationLocalization(string $rechtsfrage_name, string $city): string`
- Micro-prompt: 2-3 sentences about local specifics
- Returns just the localized paragraph HTML

`callClaude(string $prompt, int $maxTokens = 4096): string`
- Core method: POST to https://api.anthropic.com/v1/messages
- Headers: x-api-key, anthropic-version, content-type
- Body: model, max_tokens, messages array
- Tracks usage in api_usage table
- Returns response text content
- Throws on error

`checkDailyBudget(): bool`
- Reads api_usage for today
- Returns false if over safeguard limits

**Step 2: Verify API connectivity**

Create a quick test script:
```bash
php -r "
require 'vendor/autoload.php';
require 'lib/Database.php';
require 'lib/ContentGenerator.php';
\$gen = new ContentGenerator();
echo \$gen->callClaude('Sage nur: Hallo Welt', 50);
"
```
Expected: "Hallo Welt" (or similar)

**Step 3: Commit**

```bash
git add lib/ContentGenerator.php && git commit -m "feat: ContentGenerator with Claude Haiku API integration"
```

---

### Task 11: CronLock Utility

**Files:**
- Create: `lib/CronLock.php`

**Step 1: Create lib/CronLock.php**

Simple lock file mechanism:

`__construct(string $phaseName)` - sets lock path to `C:\ClaudeSEO\logs\{phase}.lock`

`acquire(): bool`
- If lock file exists, read PID, check if process alive (Windows: `tasklist /FI "PID eq {pid}"`)
- If alive: return false (another instance running)
- If dead or no file: write current PID to lock file, return true

`release(): void`
- Delete lock file

`__destruct()` - calls release() as safety net

**Step 2: Commit**

```bash
git add lib/CronLock.php && git commit -m "feat: CronLock for preventing overlapping cron runs"
```

---

### Task 12: Cron Phase 1 - Generate Content

**Files:**
- Create: `cron/phase1_generate.php`

**Step 1: Create cron/phase1_generate.php**

This is the main content generation cron. Logic:

```
1. Acquire lock ("phase1_generate"), exit if locked
2. Log start to cron_log
3. Check daily API budget

4. RECHTSGEBIET PAGES:
   - Query: rechtsgebiete that have NO row in rechtsgebiet_pages
   - For each (in batches of 5):
     a. Insert rechtsgebiet_pages row with generation_status='generating'
     b. Fetch all rechtsfragen for this rechtsgebiet
     c. Call ContentGenerator->generateRechtsgebietContent()
     d. Update row: html_content, title, meta_*, generation_status='generated'
     e. On failure: set generation_status='failed'
     f. Check budget after each

5. RECHTSFRAGE PAGES:
   - Query: rechtsfragen that have NO row in rechtsfrage_pages
   - For each (in batches of 10):
     a. Insert rechtsfrage_pages row with generation_status='generating'
     b. Fetch parent rechtsgebiet
     c. Call ContentGenerator->generateRechtsfragContent()
     d. Update row with content + status
     e. Check budget after each

6. VARIATION PAGES:
   - Query: all (rechtsfrage_id, variation_value_id) combos where:
     - variation_value.tier = 1
     - no row exists in variation_pages
     - the parent rechtsfrage_pages has generation_status IN ('generated','published')
   - For each (in batches of 20):
     a. Insert variation_pages row with generation_status='generating'
     b. Clone parent rechtsfrage html_content
     c. Call ContentGenerator->generateVariationLocalization() for city paragraph
     d. Inject: localized H1, city paragraph after intro, localized meta
     e. Update row with content + status
     f. Check budget after each

7. Log completion to cron_log
8. Release lock
```

Key: each item is its own DB transaction. If script crashes at item 15, items 1-14 are saved. Next run picks up at item 15.

**Step 2: Test with a dry run (generate 1 of each)**

```bash
php cron/phase1_generate.php --limit=1
```

Support a `--limit=N` flag for testing that overrides batch sizes.

**Step 3: Verify generated content in DB**

```bash
php -r "
require 'vendor/autoload.php';
require 'lib/Database.php';
\$db = Database::getInstance();
\$rg = \$db->fetchOne('SELECT title, generation_status, LENGTH(html_content) as len FROM rechtsgebiet_pages LIMIT 1');
print_r(\$rg);
\$rf = \$db->fetchOne('SELECT title, generation_status, LENGTH(html_content) as len FROM rechtsfrage_pages LIMIT 1');
print_r(\$rf);
"
```

Expected: rows with generation_status='generated' and non-zero content length.

**Step 4: Commit**

```bash
git add cron/phase1_generate.php && git commit -m "feat: cron phase 1 - content generation with chunked processing"
```

---

### Task 13: Cron Phase 2 - Publish & Sitemap

**Files:**
- Create: `cron/phase2_publish.php`
- Create: `lib/SitemapGenerator.php`

**Step 1: Create cron/phase2_publish.php**

Logic:
```
1. Acquire lock, log start
2. For each page table (rechtsgebiet_pages, rechtsfrage_pages, variation_pages):
   - UPDATE SET generation_status='published', published_at=NOW()
     WHERE generation_status='generated'
   - Also update parent entity status to 'published'
     (rechtsgebiete.status, rechtsfragen.status)
3. Call SitemapGenerator->generate()
4. Ping Google: GET https://www.google.com/ping?sitemap={sitemap_url}
5. Log completion
6. Release lock
```

**Step 2: Create lib/SitemapGenerator.php**

Generates sitemap XML by streaming writes (never holds full XML in memory).

`generate(): void`
- Opens file handle to `public/sitemap.xml` (or sitemap index if > 1000 URLs)
- Queries published pages in chunks of 100
- Writes each `<url>` block directly to file
- For rechtsgebiete: `<loc>{site_url}/experten-service/{slug}/</loc>`
- For rechtsfragen: `<loc>{site_url}/{slug}/</loc>`
- For variations: `<loc>{site_url}/{rf_slug}-{var_slug}/</loc>`
- Adds `<lastmod>` from published_at
- Closes file

If total URLs > 1000: split into sitemap-1.xml, sitemap-2.xml, etc. and create a sitemap-index.xml.

Also generates `public/robots.txt`:
```
User-agent: *
Allow: /
Sitemap: https://rechtecheck.de/sitemap.xml
```

**Step 3: Test**

```bash
php cron/phase2_publish.php
cat public/sitemap.xml | head -20
```

**Step 4: Commit**

```bash
git add cron/phase2_publish.php lib/SitemapGenerator.php && git commit -m "feat: cron phase 2 - publish pages and generate sitemap"
```

---

## Phase D: Dashboard

### Task 14: Admin Dashboard - Management Tab

**Files:**
- Create: `admin/index.php`
- Create: `admin/assets/dashboard.css`
- Create: `admin/assets/dashboard.js`

**Step 1: Create admin/index.php**

Full HTML dashboard page with two tabs (Management | Analytics).

Management tab structure (similar to old project's index.html but improved):
- Header: "SEO Dashboard - Rechtecheck Project Management"
- Action buttons: "Generate All Missing", "Publish All", "Sync GSC", "Run Analyzer", "Generate Sitemap"
- Sort dropdown: by score, by clicks, by alphabet, by status
- Collapsible table:
  - Rechtsgebiete rows (click to expand)
    - Shows: name, status badge, performance score, clicks, impressions, avg position, actions
    - Expand shows Rechtsfragen
      - Each RF shows: name, status, score, clicks, impressions, actions
      - Expand shows Variation Types
        - Each type shows values with their page status

Dark theme matching old project aesthetic (--bg-color: #0f172a, etc.)

JS loads data from admin API endpoints via fetch().

**Step 2: Create admin/assets/dashboard.css**

Dark theme styles matching the old project's aesthetic but cleaner.

**Step 3: Create admin/assets/dashboard.js**

Functions:
- `loadRechtsgebiete(sortBy)` - fetches from admin/api/rechtsgebiete.php
- `toggleRG(id)` - expands/collapses, lazy-loads rechtsfragen
- `toggleRF(id)` - expands variations
- `handleAction(action)` - triggers bulk actions via admin/api/actions.php
- `previewPage(type, id)` - opens public URL in new tab

**Step 4: Commit**

```bash
git add admin/ && git commit -m "feat: admin dashboard with management tab"
```

---

### Task 15: Admin API Endpoints

**Files:**
- Create: `admin/api/rechtsgebiete.php`
- Create: `admin/api/rechtsfragen.php`
- Create: `admin/api/variations.php`
- Create: `admin/api/content.php`
- Create: `admin/api/actions.php`

**Step 1: Create admin/api/rechtsgebiete.php**

GET endpoint. Returns JSON array of all rechtsgebiete with:
- All columns from rechtsgebiete table
- total_questions (subquery COUNT from rechtsfragen)
- total_variations (subquery COUNT from variation_pages)
- page_status (from rechtsgebiet_pages.generation_status or 'none')
- Supports `?sort_by=score|clicks|alphabet|status`

**Step 2: Create admin/api/rechtsfragen.php**

GET endpoint. Requires `?rechtsgebiet_id=N`. Returns JSON array of rechtsfragen with:
- All columns
- total_variations count
- page_status from rechtsfrage_pages
- variation_types with their values (nested)
- Supports `?sort_by=score|clicks|alphabet`

**Step 3: Create admin/api/variations.php**

GET endpoint. Requires `?rechtsfrage_id=N`. Returns variation_types + values with page status for each.

**Step 4: Create admin/api/content.php**

POST endpoint. Triggers content generation for a specific item.
- Body: `{"type": "rechtsgebiet|rechtsfrage|variation", "id": N}`
- Calls ContentGenerator for that single item
- Returns JSON with status + generated title

**Step 5: Create admin/api/actions.php**

POST endpoint for bulk actions:
- `generate_all`: triggers cron/phase1_generate.php via exec (background)
- `publish_all`: triggers cron/phase2_publish.php
- `sync_gsc`: triggers cron/phase3_analytics.php
- `run_analyzer`: triggers cron/phase4_optimize.php
- `generate_sitemap`: triggers cron/phase5_sitemap.php
- `recalculate_scores`: recalculates performance scores from page_analytics

Each action runs the cron script via `exec('php /path/to/cron/phaseN.php > /dev/null 2>&1 &')` so it doesn't block the HTTP request.

Returns: `{"status": "success", "message": "..."}`

**Step 6: Test API endpoints**

```bash
curl http://localhost:8080/admin/api/rechtsgebiete.php?sort_by=alphabet
```

**Step 7: Commit**

```bash
git add admin/api/ && git commit -m "feat: admin REST API endpoints"
```

---

## Phase E: Analytics & Optimization

### Task 16: Google Search Console Integration

**Files:**
- Create: `lib/SearchConsole.php`
- Create: `cron/phase3_analytics.php`

**Step 1: Create lib/SearchConsole.php**

Uses Guzzle to call GSC API (REST, not the PHP client library - keeps dependencies minimal).

Setup requirement: User creates a Google Cloud project, enables Search Console API, creates OAuth2 credentials, downloads JSON. One-time manual auth flow to get refresh token.

Methods:

`__construct()` - loads credentials from config/api_keys.php

`getAccessToken(): string` - uses refresh_token to get fresh access_token via Google OAuth2 token endpoint

`fetchPerformanceData(string $startDate, string $endDate, int $rowLimit = 5000): array`
- POST to `https://www.googleapis.com/webmasters/v3/sites/{site}/searchAnalytics/query`
- Body: `{"startDate", "endDate", "dimensions": ["page"], "rowLimit": 5000}`
- Returns array of `[page_url => [clicks, impressions, ctr, position]]`

`fetchQueryData(string $startDate, string $endDate): array`
- Same endpoint but `"dimensions": ["query"]`
- Returns search terms with their metrics (for discovering new opportunities)

`storePerformanceData(array $data, string $date): int`
- Maps each URL back to a page_type + page_id by parsing the URL path
- Inserts/updates page_analytics rows
- Also updates aggregate fields on rechtsgebiete/rechtsfragen (total_clicks, total_impressions, avg_position)
- Returns count of rows stored

**Step 2: Create cron/phase3_analytics.php**

```
1. Acquire lock, log start
2. If GSC credentials not configured, log and exit gracefully
3. Fetch performance data for yesterday (or last 3 days to catch delays)
4. Store in page_analytics
5. Update aggregate metrics on rechtsgebiete and rechtsfragen tables
6. Log completion
7. Release lock
```

**Step 3: Commit**

```bash
git add lib/SearchConsole.php cron/phase3_analytics.php && git commit -m "feat: GSC integration and analytics cron phase 3"
```

---

### Task 17: SEO Analyzer & Priority Queue

**Files:**
- Create: `lib/SeoAnalyzer.php`
- Create: `lib/PriorityQueue.php`
- Create: `cron/phase4_optimize.php`

**Step 1: Create lib/PriorityQueue.php**

Simple priority queue backed by DB (page_decisions table).

Methods:

`buildQueue(): void`
- Clears old unexecuted decisions
- For each published rechtsfrage:
  - Calculate days_live = NOW - published_at
  - Fetch latest analytics (last 30 days aggregated)
  - Apply decision tree from design doc
  - Insert page_decisions row with action, reason, priority_score
- For each published rechtsgebiet: similar but simpler (just keep/update)
- Orders by priority_score DESC

`getNextItems(int $limit = 10): array`
- Returns top N unexecuted decisions

`markExecuted(int $decisionId): void`
- Sets executed_at = NOW

**Step 2: Create lib/SeoAnalyzer.php**

Performance score calculation and decision logic.

Methods:

`calculatePerformanceScore(int $clicks, int $impressions, float $ctr, float $avgPosition): int`
- Weighted formula: clicks (40%) + impressions (30%) + CTR (20%) + position inverse (10%)
- Returns 0-100 score

`analyzeRechtsfrage(array $rf, array $analytics): array`
- Implements the decision tree:
  - No analytics + > 30 days -> score 0, action 'wait'
  - Position 1-3, CTR > 5% -> SATURATED (keep)
  - Position 1-3, CTR < 5% -> OPTIMIZE (update title/meta)
  - Position 4-10, few variations -> EXPAND
  - Position 4-10, many variations -> OPTIMIZE
  - Position 10-30 -> EXPAND aggressively
  - Position 30+ with impressions -> REGENERATE
  - 0 impressions after 60 days -> DEPRIORITIZE
- Returns: `['action' => '...', 'reason' => '...', 'priority' => N]`

`getExpansionCities(array $rf): array`
- Checks which tier cities exist as variation pages
- If position < 20: return Tier 2 cities not yet created
- If position < 10: return Tier 2 + Tier 3

**Step 3: Create cron/phase4_optimize.php**

```
1. Acquire lock, log start
2. Build priority queue
3. Process top items:
   - EXPAND: create new variation_pages rows (pending) for recommended cities
     -> Phase 1 next run will generate their content
   - UPDATE/OPTIMIZE: set existing page generation_status='pending'
     with a flag to regenerate -> Phase 1 picks it up
   - DELETE/DEPRIORITIZE: set entity status='unpublished',
     page generation_status to a terminal state
   - Mark each decision as executed
4. Check safeguards (cooldowns)
5. Log completion
6. Release lock
```

**Step 4: Commit**

```bash
git add lib/SeoAnalyzer.php lib/PriorityQueue.php cron/phase4_optimize.php && git commit -m "feat: SEO analyzer, priority queue, and optimization cron phase 4"
```

---

### Task 18: Cron Phase 5 - Sitemap & Cleanup

**Files:**
- Create: `cron/phase5_sitemap.php`

**Step 1: Create cron/phase5_sitemap.php**

```
1. Acquire lock, log start
2. Regenerate sitemap (SitemapGenerator->generate())
3. Ping Google with sitemap URL
4. DATA RETENTION:
   - Delete page_analytics rows older than 90 days (keep weekly aggregates)
   - Aggregate daily rows older than 90 days into weekly summaries
   - Delete cron_log entries older than 90 days
5. Log completion
6. Release lock
```

**Step 2: Commit**

```bash
git add cron/phase5_sitemap.php && git commit -m "feat: cron phase 5 - sitemap regeneration and data cleanup"
```

---

### Task 19: Dashboard Analytics Tab

**Files:**
- Modify: `admin/index.php` (add analytics tab content)
- Modify: `admin/assets/dashboard.js` (add analytics JS)
- Create: `admin/api/analytics.php`

**Step 1: Create admin/api/analytics.php**

GET endpoint with `?action=summary|trends|recommendations|top|opportunities`

`summary`: Returns JSON with:
- total_pages (all page tables combined)
- published_pages
- total_clicks_30d, total_impressions_30d
- avg_ctr, avg_position
- pages_generated_today, pages_optimized_today

`trends`: Returns JSON with daily clicks + impressions for last 30/60/90 days (configurable via `?days=30`)
- Query: `SELECT date, SUM(clicks), SUM(impressions) FROM page_analytics WHERE date >= ? GROUP BY date ORDER BY date`

`recommendations`: Returns page_decisions with action, reason, entity name, priority, color
- Maps: keep=green, update=yellow, delete=red, create=blue, expand=purple

`top`: Returns top 20 performing pages by clicks (last 30 days)

`opportunities`: Returns GSC query data showing search terms with impressions but no dedicated page

**Step 2: Add Analytics tab HTML to admin/index.php**

- Summary cards row (6 cards): total pages, published, clicks 30d, impressions 30d, avg CTR, avg position
- Trend chart (Chart.js line chart): clicks + impressions over time, with 30/60/90 day toggles
- Recommendations table: color-coded rows, sortable, with "Apply" buttons
- Top performers table
- Opportunities table

**Step 3: Add analytics JS to dashboard.js**

Functions:
- `loadAnalyticsSummary()` - populates summary cards
- `loadTrendChart(days)` - draws Chart.js line chart
- `loadRecommendations()` - populates recommendations table
- `applyRecommendation(id)` - POST to actions.php to execute a decision

**Step 4: Test**

Open dashboard, switch to Analytics tab. Should show empty/zero state initially.

**Step 5: Commit**

```bash
git add admin/ && git commit -m "feat: analytics dashboard tab with trends, recommendations, and opportunities"
```

---

## Phase F: Final Integration

### Task 20: End-to-End Test & Polish

**Files:**
- Modify: various (fixes found during testing)

**Step 1: Run full seed**

```bash
php database/seed.php
```

**Step 2: Generate a small batch of content**

```bash
php cron/phase1_generate.php --limit=2
```

Verify: 2 rechtsgebiet pages, 2 rechtsfrage pages, some variation pages generated.

**Step 3: Publish**

```bash
php cron/phase2_publish.php
```

Verify: sitemap.xml exists, pages are marked published.

**Step 4: Test public pages**

```bash
php -S localhost:8080 -t public
```

Browse:
- http://localhost:8080/experten-service/unsere-services/ -> grid of rechtsgebiete
- http://localhost:8080/experten-service/anwaltshaftung/ -> rechtsgebiet page with content
- http://localhost:8080/anwaltshaftung-fristversaeumnis/ -> rechtsfrage page
- http://localhost:8080/anwaltshaftung-fristversaeumnis-berlin/ -> variation page

Verify each page has:
- Correct title and meta tags
- Schema.org JSON-LD in source
- Working CTAs
- Breadcrumbs
- Internal links

**Step 5: Test dashboard**

Browse: http://localhost:8080/admin/

Verify:
- Management tab loads all data
- Collapsible hierarchy works
- Generate Content button works for single items
- Analytics tab shows empty state gracefully

**Step 6: Run full generation (if API key configured)**

```bash
php cron/phase1_generate.php
php cron/phase2_publish.php
```

Monitor logs/phase1.log for errors.

**Step 7: Commit final fixes**

```bash
git add -A && git commit -m "feat: end-to-end integration testing and polish"
```

---

## Summary

| Task | Description | Depends On |
|------|-------------|------------|
| 1 | Project scaffolding | - |
| 2 | DB schema + Database class | 1 |
| 3 | CSV seed script | 2 |
| 4 | Router + .htaccess | 2 |
| 5 | Shared layout + CSS | 1 |
| 6 | Template Level 1 (Rechtsgebiete) | 4, 5 |
| 7 | Template Level 2 (Rechtsgebiet) | 4, 5 |
| 8 | Template Level 3 (Rechtsfrage) | 4, 5 |
| 9 | Template Level 4 (Variation) | 4, 5 |
| 10 | ContentGenerator class | 2 |
| 11 | CronLock utility | 1 |
| 12 | Cron Phase 1 (Generate) | 10, 11 |
| 13 | Cron Phase 2 (Publish + Sitemap) | 11 |
| 14 | Dashboard Management tab | 2 |
| 15 | Admin API endpoints | 2 |
| 16 | GSC Integration + Phase 3 | 2 |
| 17 | SEO Analyzer + Phase 4 | 16 |
| 18 | Cron Phase 5 (Cleanup) | 13 |
| 19 | Dashboard Analytics tab | 15, 16 |
| 20 | End-to-end testing | All |

**Parallel tracks possible:**
- Tasks 6-9 (templates) can be done in parallel
- Tasks 10-11 (content gen + lock) can be done in parallel with templates
- Tasks 14-15 (dashboard) can be done in parallel with cron phases
