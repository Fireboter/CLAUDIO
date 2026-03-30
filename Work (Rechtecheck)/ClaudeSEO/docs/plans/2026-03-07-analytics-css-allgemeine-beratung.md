# Analytics Cost Tracking, CSS Update & Allgemeine Beratung Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add API cost tracking to the analytics dashboard, update public CSS colors to match rechtecheck.de, and create missing "Allgemeine Beratung" Rechtsfrage entries for all 40 Rechtsgebiete while removing the generate button from Rechtsgebiet rows.

**Architecture:** Three independent changes: (1) analytics.php gets a new `api_costs` action, dashboard.js and admin/index.php get a cost table section; (2) style.css color variable update only; (3) DB migration script inserts 21 missing rows, dashboard.js removes generate from RG-level rows.

**Tech Stack:** PHP 8.5, MySQL (remote at 45.9.61.45), Bootstrap 5, vanilla JS

---

## Context

- Admin dashboard: `admin/index.php` + `admin/assets/dashboard.js`
- Admin API: `admin/api/analytics.php`, `admin/api/rechtsgebiete.php`
- Public CSS: `public/assets/css/style.css`
- Database lib: `lib/Database.php` — use `$db->fetchAll($sql, $params)`, `$db->fetchOne()`, `$db->query()`
- Servers: admin on port 8081, public on port 8080 (both already running)

---

## Task 1: Add API Cost Endpoint to analytics.php

**Files:**
- Modify: `admin/api/analytics.php` — add new `case 'api_costs'` before the `default:` case

**Step 1: Add the case**

Insert this block before `default:` in the switch statement (around line 140):

```php
        case 'api_costs':
            $days = (int) ($_GET['days'] ?? 30);
            if ($days < 1 || $days > 90) $days = 30;

            $rows = $db->fetchAll("
                SELECT date, api_name, calls_count, tokens_used, cost_cents
                FROM api_usage
                WHERE date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                ORDER BY date DESC
            ", [$days]);

            $totals = $db->fetchOne("
                SELECT
                    COALESCE(SUM(calls_count), 0) AS total_calls,
                    COALESCE(SUM(tokens_used), 0) AS total_tokens,
                    COALESCE(SUM(cost_cents), 0) AS total_cost_cents
                FROM api_usage
                WHERE date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            ", [$days]);

            echo json_encode([
                'rows'   => $rows,
                'totals' => $totals,
            ]);
            break;
```

**Step 2: Test via curl**

```bash
curl "http://localhost:8081/api/analytics.php?action=api_costs&days=30"
```

Expected: JSON with `rows` array and `totals` object. If api_usage has data (3 calls on 2026-03-07), you should see one row.

**Step 3: Commit**

```bash
git add admin/api/analytics.php
git commit -m "feat: add api_costs analytics endpoint"
```

---

## Task 2: Add API Cost Section to Analytics Tab (UI)

**Files:**
- Modify: `admin/index.php` — add cost card to summary, add cost table section
- Modify: `admin/assets/dashboard.js` — add `loadApiCosts()`, call from `loadAnalytics()`

**Step 1: Add API cost summary card to admin/index.php**

In the `<!-- Summary Cards -->` div (around line 112), add this card after the last `.summary-card`:

```html
            <div class="summary-card">
                <div class="summary-icon"><i class="fas fa-euro-sign"></i></div>
                <div class="summary-value" id="stat-api-cost">-</div>
                <div class="summary-label">API Kosten (30T)</div>
            </div>
            <div class="summary-card">
                <div class="summary-icon"><i class="fas fa-robot"></i></div>
                <div class="summary-value" id="stat-api-calls">-</div>
                <div class="summary-label">API Calls (30T)</div>
            </div>
```

**Step 2: Add API cost table section to admin/index.php**

After the `<!-- Top Performers Table -->` section (around line 178), add:

```html
        <!-- API Cost Table -->
        <div class="analytics-section">
            <h3><i class="fas fa-euro-sign"></i> API Kostenübersicht (30 Tage)</h3>
            <table class="analytics-table" id="api-costs-table">
                <thead>
                    <tr><th>Datum</th><th>API</th><th>Calls</th><th>Tokens</th><th>Kosten</th></tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
```

**Step 3: Add loadApiCosts() to dashboard.js**

Add this function after `loadTopPerformers()`:

```js
async function loadApiCosts() {
    try {
        const res = await fetch(API_BASE + 'analytics.php?action=api_costs&days=30');
        const data = await res.json();
        const tbody = document.querySelector('#api-costs-table tbody');

        // Update summary cards
        const totalCostEur = ((data.totals?.total_cost_cents || 0) / 100).toFixed(2);
        const totalCalls = data.totals?.total_calls || 0;
        document.getElementById('stat-api-cost').textContent = totalCostEur + ' €';
        document.getElementById('stat-api-calls').textContent = formatNumber(totalCalls);

        if (!data.rows || data.rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-muted)">Noch keine API-Daten</td></tr>';
            return;
        }

        tbody.innerHTML = data.rows.map(r => `
            <tr>
                <td>${escapeHtml(r.date)}</td>
                <td>${escapeHtml(r.api_name)}</td>
                <td>${formatNumber(r.calls_count)}</td>
                <td>${formatNumber(r.tokens_used)}</td>
                <td>${(r.cost_cents / 100).toFixed(4)} €</td>
            </tr>
        `).join('');
    } catch (e) {
        console.error('Failed to load API costs:', e);
    }
}
```

**Step 4: Call loadApiCosts() from loadAnalytics()**

In `dashboard.js`, find the `loadAnalytics()` function (around line 589) and add the call:

```js
async function loadAnalytics() {
    loadAnalyticsSummary();
    loadTrendChart(30);
    loadRecommendations();
    loadTopPerformers();
    loadApiCosts();   // ADD THIS LINE
}
```

**Step 5: Verify in browser**

- Open http://localhost:8081/ → click Analytics tab
- Should see two new summary cards: "API Kosten (30T)" and "API Calls (30T)"
- Should see "API Kostenübersicht" table at the bottom with today's data (3 calls, ~0.0003 €)

**Step 6: Commit**

```bash
git add admin/index.php admin/assets/dashboard.js
git commit -m "feat: API cost cards and table in analytics dashboard"
```

---

## Task 3: Update CSS Colors to Match rechtecheck.de

**Files:**
- Modify: `public/assets/css/style.css` — update two CSS variables

**Step 1: Update color variables**

In `style.css`, find the `:root` block at the top and change:

```css
/* BEFORE */
    --rc-primary: #003366;
    --rc-primary-light: #004c99;

/* AFTER */
    --rc-primary: #406081;
    --rc-primary-light: #5a7fa0;
```

**Step 2: Verify visually**

Open http://localhost:8080/experten-service/unsere-services in the browser.
- Header border should now be a medium slate blue (#406081) instead of dark navy
- Card titles, TOC links, breadcrumbs should all reflect the new color
- Compare against https://rechtecheck.de/experten-service/unsere-services/ — should look close

**Step 3: Commit**

```bash
git add public/assets/css/style.css
git commit -m "style: update primary color to match rechtecheck.de (#406081)"
```

---

## Task 4: Create Missing Allgemeine Beratung Entries + Remove RG Generate Button

### Part A: DB Migration

**Files:**
- Create: `database/seed_allgemeine_beratung.php` — one-time migration script

**Step 1: Create the migration script**

```php
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

    // Skip if already exists
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
```

**Step 2: Run the migration**

```bash
cd /c/ClaudeSEO && php database/seed_allgemeine_beratung.php
```

Expected output: 21 lines "CREATED: ..." followed by "Done. Inserted: 21"

**Step 3: Verify**

```bash
cd /c/ClaudeSEO && cat > /tmp/verify_ab.php << 'EOF'
<?php
require 'vendor/autoload.php';
require 'lib/Database.php';
$db = Database::getInstance();
$count = $db->fetchOne("SELECT COUNT(*) as c FROM rechtsfragen WHERE name LIKE '%(Allgemeine Beratung)%'");
echo "Total Allgemeine Beratung entries: " . $count['c'] . " (expected: 40)\n";
EOF
php /tmp/verify_ab.php
```

Expected: `Total Allgemeine Beratung entries: 40 (expected: 40)`

### Part B: Remove Generate Button from Rechtsgebiet Rows

**Files:**
- Modify: `admin/assets/dashboard.js` — edit `createRGRow()` function

**Step 4: Remove the generate button from createRGRow()**

In `dashboard.js`, find `createRGRow()` (around line 73). Locate this line in the `tr.innerHTML` template:

```js
                ${!pageExists ? `<button class="btn-row btn-generate" onclick="event.stopPropagation(); generateContent('rechtsgebiet', ${rg.id})" title="Seite generieren"><i class="fas fa-wand-magic-sparkles"></i> Generate</button>` : ''}
```

Remove it entirely. The `<td>` for actions should now only contain the Preview button:

```js
        <td>
            <div class="row-actions">
                <button class="btn-row btn-preview" onclick="event.stopPropagation(); previewPage('rechtsgebiet', '${escapeAttr(rg.slug)}')" title="Vorschau"><i class="fas fa-eye"></i> Preview</button>
            </div>
        </td>
```

**Step 5: Verify in browser**

- Open http://localhost:8081/ and reload
- Rechtsgebiet rows should only show "Preview", no "Generate" button
- Expand a Rechtsgebiet → its Rechtsfragen should still show "Generate" buttons (including the new Allgemeine Beratung entries)

**Step 6: Commit**

```bash
git add database/seed_allgemeine_beratung.php admin/assets/dashboard.js
git commit -m "feat: create Allgemeine Beratung entries for all 40 Rechtsgebiete, remove RG generate button"
```

---

## Task 5: Final Verification

**Step 1: Run all verifications**

```bash
# Check API costs endpoint
curl "http://localhost:8081/api/analytics.php?action=api_costs&days=30"

# Check Allgemeine Beratung count
php /tmp/verify_ab.php

# Check public site CSS (look for #406081)
curl -s http://localhost:8080/assets/css/style.css | grep "rc-primary:"
```

Expected:
- JSON with rows + totals from api_costs
- "40 (expected: 40)" from verify_ab
- `--rc-primary: #406081;` from style.css

**Step 2: Browser smoke test**

1. http://localhost:8081/ → Analytics tab → see API Kosten card + table
2. http://localhost:8080/experten-service/unsere-services → slate blue header
3. http://localhost:8081/ → expand any Rechtsgebiet → see Allgemeine Beratung Rechtsfrage with Generate button, no Generate on RG row itself
