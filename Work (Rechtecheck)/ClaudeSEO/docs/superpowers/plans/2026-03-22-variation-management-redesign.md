# Variation Management Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace static variation data with a 2-phase interactive admin workflow: select 6 variation set types via AI → fill each with values → review & approve → save 300 values per Rechtsgebiet to DB.

**Architecture:** Four new PHP API endpoints handle AI generation and DB writes. The existing `toggleRGVariations` JS function is fully replaced with a state-machine rendering loop (Phase 1 / Phase 2 / read view) backed by localStorage. ClaudeProvider gets a minimal model-override parameter so variation APIs can use the dashboard's selected model.

**Tech Stack:** PHP 8.5, vanilla JS (ES2020), MySQL via PDO, Claude API via ClaudeProvider/Guzzle, localStorage for draft state.

---

## File Map

### New files (create)
| File | Responsibility |
|------|---------------|
| `admin/api/variation_generate_types.php` | Phase 1: call Claude → return 10 type candidates |
| `admin/api/variation_generate_values.php` | Phase 2: call Claude → return values for all 6 sets |
| `admin/api/variation_regenerate.php` | Phase 2: call Claude → replace rejected values |
| `admin/api/variation_finalize.php` | Phase 2: save approved types + values to DB (transaction) |
| `admin/api/variation_reset.php` | Delete all types + values for a Rechtsgebiet |

### Modified files
| File | What changes |
|------|-------------|
| `lib/Providers/ClaudeProvider.php:11` | Add optional `?string $modelOverride = null` to constructor |
| `admin/api/content.php` | Add `ini_set('display_errors', 0)` — fixes JSON error |
| `admin/api/actions.php` | Same error suppression |
| `admin/api/rechtsgebiete.php` | Same |
| `admin/api/rechtsfragen.php` | Same |
| `admin/api/variations.php` | Same |
| `admin/api/variation_types_by_rg.php` | Same |
| `admin/api/analytics.php` | Same |
| `admin/api/model.php` | Same |
| `admin/assets/dashboard.css` | Append ~120 lines of new variation panel CSS |
| `admin/assets/dashboard.js:140-172` | `createRGRow`: add `tr.dataset.rgName` |
| `admin/assets/dashboard.js:374-443` | Replace `toggleRGVariations` + `toggleVTGroup` section with ~350 lines |

---

## Task 1: Fix JSON Error — Error Suppression in All Existing API Files

**Files:** Modify `admin/api/content.php`, `actions.php`, `rechtsgebiete.php`, `rechtsfragen.php`, `variations.php`, `variation_types_by_rg.php`, `analytics.php`, `model.php`

Add these two lines immediately after the opening `<?php` in each file:

```php
ini_set('display_errors', 0);
error_reporting(0);
```

- [ ] **Step 1: Add error suppression to content.php**

In `admin/api/content.php`, change line 1 from:
```php
<?php
set_time_limit(0);
```
To:
```php
<?php
ini_set('display_errors', 0);
error_reporting(0);
set_time_limit(0);
```

- [ ] **Step 2: Add error suppression to the remaining 7 API files**

For each of these files, add the two lines immediately after `<?php`:
- `admin/api/actions.php`
- `admin/api/rechtsgebiete.php`
- `admin/api/rechtsfragen.php`
- `admin/api/variations.php`
- `admin/api/variation_types_by_rg.php`
- `admin/api/analytics.php`
- `admin/api/model.php`

Pattern for each (e.g., `rechtsgebiete.php` currently starts with `<?php\nheader(...`):
```php
<?php
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');
// ... rest unchanged
```

- [ ] **Step 3: Test that JSON errors are gone**

```bash
curl -s -X POST http://localhost:8084/api/content.php \
  -H "Content-Type: application/json" \
  -d '{"type":"rechtsgebiet","id":1}' | head -c 100
```
Expected: starts with `{"status":` not `<br />`

- [ ] **Step 4: Commit**

```bash
git add admin/api/
git commit -m "fix: suppress PHP display errors in all admin API files"
```

---

## Task 2: Add Model Override to ClaudeProvider

**File:** `lib/Providers/ClaudeProvider.php:11-23`

The constructor currently reads the model from `config/api_keys.php` with no way to override. The variation APIs need to pass the dashboard's selected model at runtime.

- [ ] **Step 1: Verify current constructor signature**

Read `lib/Providers/ClaudeProvider.php` lines 11-23. Confirm it starts with `public function __construct() {`.

- [ ] **Step 2: Modify constructor to accept optional model override**

Change the constructor from:
```php
public function __construct() {
    $this->config = (require __DIR__ . '/../../config/api_keys.php')['claude'];
```
To:
```php
public function __construct(?string $modelOverride = null) {
    $this->config = (require __DIR__ . '/../../config/api_keys.php')['claude'];
    if ($modelOverride !== null) {
        $this->config['model'] = $modelOverride;
    }
```

- [ ] **Step 3: Verify existing callers still work (no args = unchanged behavior)**

```bash
curl -s http://localhost:8084/api/model.php | python -m json.tool
```
Expected: returns current model info with no error.

- [ ] **Step 4: Commit**

```bash
git add lib/Providers/ClaudeProvider.php
git commit -m "feat: add optional model override to ClaudeProvider constructor"
```

---

## Task 3: Create variation_reset.php

**File:** Create `admin/api/variation_reset.php`

- [ ] **Step 1: Create the file**

```php
<?php
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../lib/Database.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $rgId  = (int)($input['rechtsgebiet_id'] ?? 0);

    if (!$rgId) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'rechtsgebiet_id required']);
        exit;
    }

    $db = Database::getInstance();

    // Delete values first (join to get type-scoped values), then types
    $db->query(
        'DELETE vv FROM variation_values vv
         INNER JOIN variation_types vt ON vv.variation_type_id = vt.id
         WHERE vt.rechtsgebiet_id = ?',
        [$rgId]
    );
    $db->query('DELETE FROM variation_types WHERE rechtsgebiet_id = ?', [$rgId]);

    echo json_encode(['status' => 'ok']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
```

- [ ] **Step 2: Test the endpoint**

```bash
# First check how many types exist for rgId=1
curl -s "http://localhost:8084/api/variation_types_by_rg.php?rechtsgebiet_id=1" | python -m json.tool | grep '"name"' | wc -l

# Reset rgId=1
curl -s -X POST http://localhost:8084/api/variation_reset.php \
  -H "Content-Type: application/json" \
  -d '{"rechtsgebiet_id": 1}'
```
Expected: `{"status":"ok"}`

```bash
# Confirm types gone
curl -s "http://localhost:8084/api/variation_types_by_rg.php?rechtsgebiet_id=1"
```
Expected: `[]`

- [ ] **Step 3: Commit**

```bash
git add admin/api/variation_reset.php
git commit -m "feat: add variation_reset API endpoint"
```

---

## Task 4: Create variation_generate_types.php

**File:** Create `admin/api/variation_generate_types.php`

- [ ] **Step 1: Create the file**

```php
<?php
ini_set('display_errors', 0);
error_reporting(0);
set_time_limit(120);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/AIProvider.php';
require_once __DIR__ . '/../../lib/Providers/ClaudeProvider.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $rgId  = (int)($input['rechtsgebiet_id'] ?? 0);
    $model = $input['model'] ?? null;

    if (!$rgId) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'rechtsgebiet_id required']);
        exit;
    }

    $db = Database::getInstance();
    $rg = $db->fetchOne('SELECT name FROM rechtsgebiete WHERE id = ?', [$rgId]);
    if (!$rg) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Rechtsgebiet not found']);
        exit;
    }

    $provider = new ClaudeProvider($model);
    $rgName   = $rg['name'];

    $prompt = <<<PROMPT
Du bist ein SEO-Experte für rechtliche Inhalte.

Rechtsgebiet: "{$rgName}"

Erstelle genau 10 Variations-Set-Typen für das Rechtsgebiet "{$rgName}".
Diese Sets werden für SEO-Variationsseiten genutzt, die verschiedene Suchanfragen abdecken.

"Generelle Informationen" ist bereits vorgegeben — erstelle NUR die anderen 10 Typen.

Jeder Typ soll:
- Einen konkreten SEO-Suchkontext für "{$rgName}" abdecken (z.B. Städte, Personengruppen, Ziele, Situationen, Dringlichkeit)
- Sich klar von den anderen Typen unterscheiden
- 10–100 konkrete Werte ermöglichen

AUSGABE: NUR ein JSON-Array, KEIN Markdown, KEIN Text davor/danach:
[
  {"name": "Typ-Name", "description": "Kurze Beschreibung welche Werte dieser Typ enthält"},
  ...
]
PROMPT;

    $raw = $provider->call($prompt, 1024);

    // Strip markdown fences
    $raw = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
    $raw = preg_replace('/\s*```\s*$/m', '', $raw);
    $raw = trim($raw);

    if (!preg_match('/\[[\s\S]*\]/s', $raw, $m)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'AI did not return a JSON array.']);
        exit;
    }

    $types = json_decode($m[0], true);
    if (!is_array($types)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'AI returned invalid JSON structure.']);
        exit;
    }

    $result = [];
    foreach ($types as $t) {
        if (!is_array($t) || empty(trim($t['name'] ?? ''))) continue;
        $name = trim($t['name']);
        // Exclude Generelle Informationen (always injected client-side)
        if (mb_strtolower($name) === 'generelle informationen') continue;
        $result[] = [
            'name'        => $name,
            'description' => trim($t['description'] ?? ''),
        ];
        if (count($result) >= 10) break;
    }

    echo json_encode(['status' => 'ok', 'types' => $result]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
```

- [ ] **Step 2: Test the endpoint**

```bash
curl -s -X POST http://localhost:8084/api/variation_generate_types.php \
  -H "Content-Type: application/json" \
  -d '{"rechtsgebiet_id": 2, "model": "claude-haiku-4-5-20251001"}' | python -m json.tool
```
Expected: `{"status":"ok","types":[{"name":"...","description":"..."},...]}` with 10 items, none named "Generelle Informationen".

- [ ] **Step 3: Commit**

```bash
git add admin/api/variation_generate_types.php
git commit -m "feat: add variation_generate_types API endpoint"
```

---

## Task 5: Create variation_generate_values.php

**File:** Create `admin/api/variation_generate_values.php`

- [ ] **Step 1: Create the file**

```php
<?php
ini_set('display_errors', 0);
error_reporting(0);
set_time_limit(120);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/AIProvider.php';
require_once __DIR__ . '/../../lib/Providers/ClaudeProvider.php';

try {
    $input  = json_decode(file_get_contents('php://input'), true);
    $rgId   = (int)($input['rechtsgebiet_id'] ?? 0);
    $rgName = trim($input['rechtsgebiet_name'] ?? '');
    $types  = $input['types'] ?? [];
    $model  = $input['model'] ?? null;

    if (!$rgId || !$rgName || !is_array($types) || count($types) !== 6) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'rechtsgebiet_id, rechtsgebiet_name, and exactly 6 types required']);
        exit;
    }

    $typesJson = json_encode($types, JSON_UNESCAPED_UNICODE);
    $provider  = new ClaudeProvider($model);

    $prompt = <<<PROMPT
Du bist ein SEO-Experte für rechtliche Inhalte.

Rechtsgebiet: "{$rgName}"
Variation-Sets: {$typesJson}

Erstelle für jedes der 6 Sets relevante Werte für das Rechtsgebiet "{$rgName}".
Ziel: ca. 300 Werte insgesamt, verteilt auf alle Sets (10–100 pro Set, je nach Typ).

REGELN:
- Auf Deutsch, 1–5 Wörter pro Wert
- Keine Duplikate innerhalb eines Sets
- Spezifisch für "{$rgName}"
- Echte Suchbegriffe (was würde jemand bei Google eingeben?)
- "Generelle Informationen": allgemeine rechtliche Informationsbedürfnisse zu "{$rgName}"
- Städte-Sets: deutsche Städte und Gemeinden

AUSGABE: NUR ein JSON-Array, KEIN Markdown, KEIN Text davor/danach:
[
  {"type": "Set-Name", "values": ["Wert1", "Wert2", ...]},
  ...
]
PROMPT;

    $raw = $provider->call($prompt, 4096);

    // Strip markdown fences
    $raw = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
    $raw = preg_replace('/\s*```\s*$/m', '', $raw);
    $raw = trim($raw);

    if (!preg_match('/\[[\s\S]*\]/s', $raw, $m)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'AI did not return a JSON array.']);
        exit;
    }

    $sets = json_decode($m[0], true);
    if (!is_array($sets)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'AI returned invalid JSON structure.']);
        exit;
    }

    $result = [];
    foreach ($sets as $s) {
        if (!is_array($s) || empty(trim($s['type'] ?? '')) || !is_array($s['values'] ?? null)) continue;
        $values = array_values(array_filter(
            array_map(fn($v) => is_string($v) ? trim($v) : null, $s['values'])
        ));
        $result[] = ['type' => trim($s['type']), 'values' => $values];
    }

    echo json_encode(['status' => 'ok', 'sets' => $result]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
```

- [ ] **Step 2: Test the endpoint**

```bash
curl -s -X POST http://localhost:8084/api/variation_generate_values.php \
  -H "Content-Type: application/json" \
  -d '{
    "rechtsgebiet_id": 2,
    "rechtsgebiet_name": "Architektenrecht",
    "types": ["Generelle Informationen","Städte","Personenstatus","Ziel","Dringlichkeit","Beratungsform"],
    "model": "claude-haiku-4-5-20251001"
  }' | python -m json.tool
```
Expected: `{"status":"ok","sets":[...]}` with 6 sets containing values, total ≥ 100 values.

- [ ] **Step 3: Commit**

```bash
git add admin/api/variation_generate_values.php
git commit -m "feat: add variation_generate_values API endpoint"
```

---

## Task 6: Create variation_regenerate.php

**File:** Create `admin/api/variation_regenerate.php`

- [ ] **Step 1: Create the file**

```php
<?php
ini_set('display_errors', 0);
error_reporting(0);
set_time_limit(120);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/AIProvider.php';
require_once __DIR__ . '/../../lib/Providers/ClaudeProvider.php';

try {
    $input  = json_decode(file_get_contents('php://input'), true);
    $rgId   = (int)($input['rechtsgebiet_id'] ?? 0);
    $rgName = trim($input['rechtsgebiet_name'] ?? '');
    $sets   = $input['sets'] ?? [];
    $model  = $input['model'] ?? null;

    if (!$rgId || !$rgName || !is_array($sets) || empty($sets)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'rechtsgebiet_id, rechtsgebiet_name, and sets required']);
        exit;
    }

    // Build per-set instructions for the prompt
    $instructions = [];
    foreach ($sets as $s) {
        $type     = trim($s['type'] ?? '');
        $rejected = array_map('trim', $s['rejected'] ?? []);
        $approved = array_map('trim', $s['approved'] ?? []);
        if (!$type || empty($rejected)) continue;

        $count    = count($rejected);
        $rejStr   = '"' . implode('", "', $rejected) . '"';
        $appStr   = empty($approved) ? 'keine' : '"' . implode('", "', array_slice($approved, 0, 5)) . '"';
        $instructions[] = "Set \"{$type}\": ersetze {$count} Wert(e) [{$rejStr}] durch {$count} neue, die sich von bereits akzeptierten [{$appStr}] unterscheiden.";
    }

    if (empty($instructions)) {
        echo json_encode(['status' => 'ok', 'sets' => []]);
        exit;
    }

    $instructionText = implode("\n", $instructions);
    $provider        = new ClaudeProvider($model);

    $prompt = <<<PROMPT
Du bist ein SEO-Experte für rechtliche Inhalte.

Rechtsgebiet: "{$rgName}"

Generiere Ersatz-Werte für abgelehnte Variation-Werte:

{$instructionText}

REGELN:
- Auf Deutsch, 1–5 Wörter pro Wert
- Keine Duplikate zu bereits akzeptierten Werten
- Spezifisch für "{$rgName}"
- Genau so viele neue Werte wie abgelehnte pro Set
- Echte Suchbegriffe

AUSGABE: NUR ein JSON-Array, KEIN Markdown, KEIN Text davor/danach:
[
  {"type": "Set-Name", "values": ["NeuerWert1", "NeuerWert2", ...]},
  ...
]
PROMPT;

    $raw = $provider->call($prompt, 2048);

    // Strip markdown fences
    $raw = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
    $raw = preg_replace('/\s*```\s*$/m', '', $raw);
    $raw = trim($raw);

    if (!preg_match('/\[[\s\S]*\]/s', $raw, $m)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'AI did not return a JSON array.']);
        exit;
    }

    $resultSets = json_decode($m[0], true);
    if (!is_array($resultSets)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'AI returned invalid JSON structure.']);
        exit;
    }

    $result = [];
    foreach ($resultSets as $s) {
        if (!is_array($s) || empty(trim($s['type'] ?? '')) || !is_array($s['values'] ?? null)) continue;
        $values = array_values(array_filter(
            array_map(fn($v) => is_string($v) ? trim($v) : null, $s['values'])
        ));
        $result[] = ['type' => trim($s['type']), 'values' => $values];
    }

    echo json_encode(['status' => 'ok', 'sets' => $result]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
```

- [ ] **Step 2: Test the endpoint**

```bash
curl -s -X POST http://localhost:8084/api/variation_regenerate.php \
  -H "Content-Type: application/json" \
  -d '{
    "rechtsgebiet_id": 2,
    "rechtsgebiet_name": "Architektenrecht",
    "model": "claude-haiku-4-5-20251001",
    "sets": [
      {"type": "Städte", "rejected": ["Trier", "Siegen"], "approved": ["Berlin", "München"]}
    ]
  }' | python -m json.tool
```
Expected: `{"status":"ok","sets":[{"type":"Städte","values":["...","..."]}]}` with exactly 2 replacement values.

- [ ] **Step 3: Commit**

```bash
git add admin/api/variation_regenerate.php
git commit -m "feat: add variation_regenerate API endpoint"
```

---

## Task 7: Create variation_finalize.php

**File:** Create `admin/api/variation_finalize.php`

- [ ] **Step 1: Create the file**

```php
<?php
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../lib/Database.php';

function toSlug(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    $text = str_replace(['ä','ö','ü','ß'], ['ae','oe','ue','ss'], $text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $rgId  = (int)($input['rechtsgebiet_id'] ?? 0);
    $sets  = $input['sets'] ?? [];

    if (!$rgId || !is_array($sets) || empty($sets)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'rechtsgebiet_id and sets required']);
        exit;
    }

    $db  = Database::getInstance();
    $pdo = $db->getPdo();

    $pdo->beginTransaction();
    try {
        // 1. Delete all values for this RG's types
        $db->query(
            'DELETE vv FROM variation_values vv
             INNER JOIN variation_types vt ON vv.variation_type_id = vt.id
             WHERE vt.rechtsgebiet_id = ?',
            [$rgId]
        );
        // 2. Delete all types for this RG
        $db->query('DELETE FROM variation_types WHERE rechtsgebiet_id = ?', [$rgId]);

        $saved = 0;
        foreach ($sets as $s) {
            $typeName = trim($s['type'] ?? '');
            $values   = $s['values'] ?? [];
            if (!$typeName || !is_array($values)) continue;

            // 3. Insert type
            $typeId = $db->insert('variation_types', [
                'rechtsgebiet_id' => $rgId,
                'name'            => $typeName,
                'slug'            => toSlug($typeName),
            ]);

            // 4. Insert values
            foreach ($values as $val) {
                $val = trim($val);
                if (!$val) continue;
                $db->insert('variation_values', [
                    'variation_type_id' => $typeId,
                    'value'             => $val,
                    'slug'              => toSlug($val),
                    'tier'              => 1,
                ]);
                $saved++;
            }
        }

        $pdo->commit();
        echo json_encode(['status' => 'ok', 'saved' => $saved]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
```

- [ ] **Step 2: Test the endpoint**

```bash
curl -s -X POST http://localhost:8084/api/variation_finalize.php \
  -H "Content-Type: application/json" \
  -d '{
    "rechtsgebiet_id": 2,
    "sets": [
      {"type": "Generelle Informationen", "values": ["Architektenfehler", "Planungsmangel", "Baugenehmigung"]},
      {"type": "Städte", "values": ["Berlin", "München", "Hamburg"]}
    ]
  }' | python -m json.tool
```
Expected: `{"status":"ok","saved":6}`

```bash
# Verify saved in DB
curl -s "http://localhost:8084/api/variation_types_by_rg.php?rechtsgebiet_id=2" | python -m json.tool
```
Expected: 2 types with values listed.

- [ ] **Step 3: Commit**

```bash
git add admin/api/variation_finalize.php
git commit -m "feat: add variation_finalize API endpoint with DB transaction"
```

---

## Task 8: Add CSS for Variation Management Panel

**File:** Append to `admin/assets/dashboard.css`

- [ ] **Step 1: Append the new CSS rules**

Add the following at the end of `admin/assets/dashboard.css`:

```css
/* ============================================
   Variation Management — Phase 1 & 2
   ============================================ */

/* Loading state within panel body */
.vt-loading {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1.5rem;
    color: var(--text-muted);
    font-size: 0.9rem;
}

.spinner-ring-sm {
    width: 20px;
    height: 20px;
    border: 2px solid var(--border-color);
    border-top-color: var(--accent-blue);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    flex-shrink: 0;
}

/* Panel header: title + Reset button */
.vt-detail-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.6rem 1rem;
    border-bottom: 1px solid var(--border-color);
    font-weight: 600;
    font-size: 0.88rem;
    background: var(--bg-secondary);
}

.btn-danger-sm {
    background: transparent;
    border: 1px solid var(--accent-red);
    color: var(--accent-red);
    padding: 0.2rem 0.55rem;
    border-radius: 4px;
    font-size: 0.76rem;
    cursor: pointer;
    transition: background 0.15s;
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
}
.btn-danger-sm:hover { background: rgba(239,68,68,0.12); }

/* ── Phase 1: Type selection ───────────────── */

.vt-p1-panel { padding: 1rem; }

.vt-p1-actions {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}

.vt-p1-hint {
    color: var(--text-muted);
    font-size: 0.83rem;
}

.vt-candidates {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
    gap: 0.5rem;
    margin-bottom: 0.75rem;
}

.vt-type-card {
    display: flex;
    align-items: flex-start;
    gap: 0.55rem;
    padding: 0.55rem 0.7rem;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: var(--bg-secondary);
    transition: border-color 0.15s, background 0.15s;
}

.vt-type-card.selected {
    border-color: var(--accent-blue);
    background: rgba(59,130,246,0.07);
}

.vt-type-card.locked {
    border-color: var(--accent-green);
    background: rgba(34,197,94,0.07);
}

.vt-type-card.disabled { opacity: 0.38; }

.vt-card-check {
    display: flex;
    align-items: flex-start;
    padding-top: 2px;
    cursor: pointer;
}

.vt-card-body { flex: 1; min-width: 0; }

.vt-card-name {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-primary);
    outline: none;
    word-break: break-word;
}
.vt-card-name[contenteditable="true"]:focus {
    border-bottom: 1px dashed var(--accent-blue);
}

.vt-card-desc {
    font-size: 0.76rem;
    color: var(--text-muted);
    margin-top: 0.15rem;
    line-height: 1.3;
}

.vt-card-lock {
    color: var(--accent-green);
    font-size: 0.72rem;
    flex-shrink: 0;
    margin-top: 2px;
}

.vt-p1-footer {
    display: flex;
    justify-content: flex-end;
}

/* ── Phase 2: Review grid ─────────────────── */

.vt-p2-panel { padding: 0.75rem 1rem; }

.vt-p2-toolbar {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
    margin-bottom: 0.75rem;
    padding-bottom: 0.6rem;
    border-bottom: 1px solid var(--border-color);
}

.vt-progress-wrap {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex: 1;
    min-width: 160px;
}

.vt-progress-bar {
    flex: 1;
    height: 7px;
    background: var(--border-color);
    border-radius: 4px;
    overflow: hidden;
}

.vt-progress-fill {
    height: 100%;
    background: var(--accent-blue);
    border-radius: 4px;
    transition: width 0.25s ease;
}

.vt-progress-label {
    font-size: 0.8rem;
    color: var(--text-muted);
    white-space: nowrap;
}

.vt-sets-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.6rem;
}

@media (max-width: 960px) { .vt-sets-grid { grid-template-columns: 1fr; } }

.vt-set-card {
    border: 1px solid var(--border-color);
    border-radius: 6px;
    overflow: hidden;
}

.vt-set-header {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    width: 100%;
    padding: 0.45rem 0.7rem;
    background: var(--bg-secondary);
    border: none;
    border-bottom: 1px solid var(--border-color);
    cursor: pointer;
    text-align: left;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-primary);
}

.vt-set-chevron {
    font-size: 0.72rem;
    transition: transform 0.2s;
    color: var(--text-muted);
}

.vt-set-name { flex: 1; }

.vt-set-count {
    font-size: 0.76rem;
    color: var(--text-muted);
    font-weight: 400;
}

.vt-set-body {
    display: none;
    flex-wrap: wrap;
    gap: 0.3rem;
    padding: 0.6rem 0.7rem;
    background: var(--bg-primary);
}

.vt-set-body.open { display: flex; }

/* Value pills */
.vt-val-pill {
    display: inline-flex;
    align-items: center;
    padding: 0.18rem 0.5rem;
    border-radius: 12px;
    background: rgba(59,130,246,0.1);
    border: 1px solid rgba(59,130,246,0.3);
    font-size: 0.78rem;
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s, opacity 0.15s;
    user-select: none;
}

.vt-val-pill input[type="checkbox"] { display: none; }

.vt-val-pill span {
    outline: none;
    cursor: text;
}
.vt-val-pill span:focus {
    border-bottom: 1px dashed var(--accent-blue);
}

.vt-val-pill.rejected {
    background: rgba(239,68,68,0.07);
    border-color: rgba(239,68,68,0.25);
    color: var(--text-muted);
    text-decoration: line-through;
    opacity: 0.7;
}

.btn-sm {
    padding: 0.28rem 0.7rem;
    font-size: 0.8rem;
}
```

- [ ] **Step 2: Verify CSS loaded (visual)**

Open `http://localhost:8084/` in browser, open DevTools → Sources → `dashboard.css`. Confirm the new `.vt-val-pill` and `.vt-sets-grid` rules are present at the bottom.

- [ ] **Step 3: Commit**

```bash
git add admin/assets/dashboard.css
git commit -m "feat: add variation management panel CSS (phase 1 & 2)"
```

---

## Task 9: Update dashboard.js — Phase 1 UI

**File:** `admin/assets/dashboard.js`

This task makes two changes: (a) adds `data-rg-name` to the RG row, (b) replaces `toggleRGVariations` and adds Phase 1 JS functions. Phase 2 JS is added in Task 10.

- [ ] **Step 1: Add `data-rg-name` to `createRGRow`**

In `createRGRow` (line ~140-172), add one line after `tr.dataset.rgId = rg.id;`:

```js
tr.dataset.rgId   = rg.id;
tr.dataset.rgName = rg.name;   // ADD THIS LINE
```

- [ ] **Step 2: Replace the `toggleRGVariations` and `toggleVTGroup` functions**

Locate the block from the comment `/** * Toggle the variation-types detail row...` through the closing `}` of `toggleVTGroup` (currently lines ~371–443). Replace the entire block with:

```js
// ── localStorage helpers ──────────────────────────────────────────────────────

function getVState(rgId) {
    try { return JSON.parse(localStorage.getItem(`vstate_${rgId}`)) || null; }
    catch { return null; }
}
function setVState(rgId, state) {
    localStorage.setItem(`vstate_${rgId}`, JSON.stringify(state));
}
function clearVState(rgId) {
    localStorage.removeItem(`vstate_${rgId}`);
}

// ── Panel entry point ─────────────────────────────────────────────────────────

async function toggleRGVariations(rgId) {
    const rgRow = document.querySelector(`.rg-row[data-rg-id="${rgId}"]`);
    const rgName = rgRow?.dataset.rgName || '';
    const detailId = `vt-detail-${rgId}`;
    const existing = document.getElementById(detailId);

    if (existing) {
        const isHidden = existing.classList.contains('vt-detail-hidden');
        existing.classList.toggle('vt-detail-hidden', !isHidden);
        const btn = rgRow?.querySelector('.btn-tags i');
        if (btn) btn.className = isHidden ? 'fas fa-times' : 'fas fa-tags';
        return;
    }

    // Create panel shell
    const tr = document.createElement('tr');
    tr.id = detailId;
    tr.className = 'vt-detail-row';
    tr.innerHTML = `<td colspan="8">
        <div class="vt-detail-panel" id="vt-panel-${rgId}">
            <div class="vt-detail-header">
                <span><i class="fas fa-tags"></i> Variations-Sets — ${escapeHtml(rgName)}</span>
                <button class="btn-danger-sm" onclick="resetVariations(${rgId})">
                    <i class="fas fa-trash"></i> Reset
                </button>
            </div>
            <div class="vt-panel-body" id="vt-body-${rgId}">
                <div class="vt-loading"><div class="spinner-ring-sm"></div> Lade...</div>
            </div>
        </div>
    </td>`;
    rgRow.after(tr);

    const btn = rgRow?.querySelector('.btn-tags i');
    if (btn) btn.className = 'fas fa-times';

    await initVPanel(rgId, rgName);
}

// Determines which view to show based on DB state and localStorage draft
async function initVPanel(rgId, rgName) {
    try {
        const res   = await fetch(`${API_BASE}variation_types_by_rg.php?rechtsgebiet_id=${rgId}`);
        const types = await res.json();
        if (Array.isArray(types) && types.length > 0) {
            renderReadView(rgId, rgName, types);
            return;
        }
    } catch(e) { /* fall through */ }

    const draft = getVState(rgId);
    if (draft?.phase === 2 && draft.sets?.length) {
        renderPhase2(rgId, rgName);
        return;
    }
    renderPhase1(rgId, rgName);
}

// ── Read view (finalized sets in DB) ──────────────────────────────────────────

function renderReadView(rgId, rgName, types) {
    const body = document.getElementById(`vt-body-${rgId}`);
    const groupsHtml = types.map(t => {
        const uid      = `vt-values-${rgId}-${t.id}`;
        const valPills = (t.values || []).map(v =>
            `<span class="vt-value-pill">${escapeHtml(v.value)}</span>`
        ).join('');
        return `<div class="vt-group">
            <button class="vt-group-header" onclick="toggleVTGroup('${uid}', this)">
                <i class="fas fa-chevron-right vt-group-chevron"></i>
                <span class="vt-group-name">${escapeHtml(t.name)}</span>
                <span class="vt-group-count">${t.value_count}</span>
            </button>
            <div class="vt-group-values" id="${uid}">${valPills}</div>
        </div>`;
    }).join('');
    body.innerHTML = `<div class="vt-groups">${groupsHtml || '<span style="color:var(--text-muted);padding:1rem;display:block">Keine Sets gefunden.</span>'}</div>`;
}

function toggleVTGroup(uid, btn) {
    const panel   = document.getElementById(uid);
    const chevron = btn.querySelector('.vt-group-chevron');
    const isOpen  = panel.classList.toggle('open');
    if (chevron) chevron.style.transform = isOpen ? 'rotate(90deg)' : '';
}

// ── Phase 1: Type selection ────────────────────────────────────────────────────

function renderPhase1(rgId, rgName) {
    const body       = document.getElementById(`vt-body-${rgId}`);
    const draft      = getVState(rgId);
    const candidates = draft?.types || [];
    const selectedCount = candidates.filter(t => t.selected).length;

    body.innerHTML = `
        <div class="vt-p1-panel">
            <div class="vt-p1-actions">
                <button class="btn-action btn-blue btn-sm"
                    onclick="generateTypes(${rgId}, '${escapeAttr(rgName)}')">
                    <i class="fas fa-wand-magic-sparkles"></i> Generate Sets
                </button>
                <span class="vt-p1-hint">${
                    candidates.length
                        ? `${selectedCount}/6 ausgewählt — klicke Karten an oder editiere Namen`
                        : 'Klicke "Generate Sets" um 10 Kandidaten zu erstellen'
                }</span>
            </div>
            <div class="vt-candidates" id="vt-candidates-${rgId}">
                ${renderTypeCards(candidates, rgId)}
            </div>
            <div class="vt-p1-footer">
                <button class="btn-action btn-green btn-sm"
                    id="vt-proceed-${rgId}"
                    onclick="proceedToPhase2(${rgId}, '${escapeAttr(rgName)}')"
                    ${selectedCount === 6 ? '' : 'disabled'}>
                    Weiter: Sets befüllen →
                </button>
            </div>
        </div>`;
}

function renderTypeCards(types, rgId) {
    if (!types.length) return '';
    const selectedCount = types.filter(t => t.selected).length;
    const limitReached  = selectedCount >= 6;

    return types.map((t, i) => {
        const isSelected = t.selected;
        const isLocked   = t.locked;
        const isDisabled = !isSelected && limitReached;
        const classes    = ['vt-type-card',
            isSelected ? 'selected' : '',
            isLocked   ? 'locked'   : '',
            isDisabled ? 'disabled' : '',
        ].filter(Boolean).join(' ');

        return `<div class="${classes}">
            <label class="vt-card-check">
                <input type="checkbox"
                    ${isSelected ? 'checked' : ''}
                    ${isLocked || isDisabled ? 'disabled' : ''}
                    onchange="toggleTypeCard(${rgId}, ${i}, this.checked)">
            </label>
            <div class="vt-card-body">
                <div class="vt-card-name"
                    ${isLocked ? '' : `contenteditable="true" onblur="editTypeName(${rgId}, ${i}, this.textContent)"`}
                >${escapeHtml(t.name)}</div>
                <div class="vt-card-desc">${escapeHtml(t.description || '')}</div>
            </div>
            ${isLocked ? '<span class="vt-card-lock"><i class="fas fa-lock"></i></span>' : ''}
        </div>`;
    }).join('');
}

async function generateTypes(rgId, rgName) {
    const candidatesEl = document.getElementById(`vt-candidates-${rgId}`);
    if (candidatesEl) {
        candidatesEl.innerHTML = '<div class="vt-loading"><div class="spinner-ring-sm"></div> Generiere Sets...</div>';
    }

    try {
        const res  = await fetch(`${API_BASE}variation_generate_types.php`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ rechtsgebiet_id: rgId, model: activeModel }),
        });
        const data = await res.json();
        if (data.status !== 'ok') throw new Error(data.message || 'Generation failed');

        // Always keep Generelle Informationen as locked first entry
        const gi   = { name: 'Generelle Informationen', description: 'Allgemeine Informationen zum Rechtsgebiet', selected: true, locked: true };
        const draft = getVState(rgId) || {};

        // Preserve previously selected non-locked types
        const prevSelected = (draft.types || []).filter(t => t.selected && !t.locked);
        const prevNames    = new Set(prevSelected.map(t => t.name.toLowerCase()));

        // Filter new candidates: exclude GI and already-selected
        const newCandidates = data.types
            .filter(t => t.name.toLowerCase() !== 'generelle informationen')
            .filter(t => !prevNames.has(t.name.toLowerCase()))
            .map(t => ({ ...t, selected: false, locked: false }));

        const allTypes = [gi, ...prevSelected, ...newCandidates].slice(0, 11);
        setVState(rgId, { phase: 1, types: allTypes, sets: [] });
        renderPhase1(rgId, rgName);

    } catch(e) {
        showToast('Fehler: ' + e.message, 'error');
        renderPhase1(rgId, rgName);
    }
}

function toggleTypeCard(rgId, idx, checked) {
    const state = getVState(rgId);
    if (!state?.types) return;
    state.types[idx].selected = checked;
    setVState(rgId, state);
    const rgRow = document.querySelector(`.rg-row[data-rg-id="${rgId}"]`);
    renderPhase1(rgId, rgRow?.dataset.rgName || '');
}

function editTypeName(rgId, idx, newName) {
    const state = getVState(rgId);
    if (!state?.types?.[idx]) return;
    state.types[idx].name = newName.trim();
    setVState(rgId, state);
}

async function proceedToPhase2(rgId, rgName) {
    const state = getVState(rgId);
    const selectedTypes = (state?.types || []).filter(t => t.selected).map(t => t.name);
    if (selectedTypes.length !== 6) {
        showToast('Bitte genau 6 Sets auswählen.', 'error');
        return;
    }

    const body = document.getElementById(`vt-body-${rgId}`);
    body.innerHTML = '<div class="vt-loading"><div class="spinner-ring-sm"></div> Generiere Werte (30–60 Sek.)...</div>';

    try {
        const res  = await fetch(`${API_BASE}variation_generate_values.php`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                rechtsgebiet_id:   rgId,
                rechtsgebiet_name: rgName,
                types:             selectedTypes,
                model:             activeModel,
            }),
        });
        const data = await res.json();
        if (data.status !== 'ok') throw new Error(data.message || 'Generation failed');

        const sets = data.sets.map(s => ({
            type:   s.type,
            values: s.values.map(v => ({ text: v, approved: true })),
        }));

        setVState(rgId, { ...state, phase: 2, sets });
        renderPhase2(rgId, rgName);

    } catch(e) {
        showToast('Fehler: ' + e.message, 'error');
        renderPhase1(rgId, rgName);
    }
}
```

- [ ] **Step 3: Verify Phase 1 opens in browser**

1. Open `http://localhost:8084/`
2. Click "Variationen" on any Rechtsgebiet that has been reset (from Task 3)
3. Should see Phase 1 panel with "Generate Sets" button
4. Click "Generate Sets" → candidates appear as checkbox cards
5. "Generelle Informationen" card is pre-checked and locked
6. Selecting 5 more enables "Weiter" button

- [ ] **Step 4: Commit**

```bash
git add admin/assets/dashboard.js
git commit -m "feat: variation management Phase 1 UI (type selection)"
```

---

## Task 10: Add Phase 2 JS to dashboard.js

**File:** `admin/assets/dashboard.js` — append after the Phase 1 functions added in Task 9

- [ ] **Step 1: Append Phase 2 functions after `proceedToPhase2`**

Add the following code block after the `proceedToPhase2` function:

```js
// ── Phase 2: Value review & finalize ──────────────────────────────────────────

function countApproved(sets) {
    let approved = 0, total = 0;
    (sets || []).forEach(s => (s.values || []).forEach(v => {
        total++;
        if (v.approved) approved++;
    }));
    return { approved, total };
}

function renderPhase2(rgId, rgName) {
    const body  = document.getElementById(`vt-body-${rgId}`);
    const state = getVState(rgId);
    if (!state?.sets?.length) { renderPhase1(rgId, rgName); return; }

    const { approved } = countApproved(state.sets);
    const canFinalize   = approved >= 200;
    const pct           = Math.min(100, (approved / 300) * 100).toFixed(1);

    const setsHtml = state.sets.map((s, si) => {
        const approvedCount = s.values.filter(v => v.approved).length;
        const pillsHtml     = s.values.map((v, vi) =>
            `<label class="vt-val-pill ${v.approved ? '' : 'rejected'}">
                <input type="checkbox" ${v.approved ? 'checked' : ''}
                    onchange="toggleValue(${rgId}, ${si}, ${vi}, this.checked)">
                <span contenteditable="true"
                    onblur="editValue(${rgId}, ${si}, ${vi}, this.textContent)"
                >${escapeHtml(v.text)}</span>
            </label>`
        ).join('');

        return `<div class="vt-set-card">
            <button class="vt-set-header" onclick="toggleSetCard(this)">
                <i class="fas fa-chevron-right vt-set-chevron"></i>
                <span class="vt-set-name">${escapeHtml(s.type)}</span>
                <span class="vt-set-count" id="vt-setcount-${rgId}-${si}">${approvedCount}/${s.values.length}</span>
            </button>
            <div class="vt-set-body open">${pillsHtml}</div>
        </div>`;
    }).join('');

    body.innerHTML = `
        <div class="vt-p2-panel">
            <div class="vt-p2-toolbar">
                <button class="btn-row" onclick="goBackToPhase1(${rgId}, '${escapeAttr(rgName)}')">← Zurück</button>
                <div class="vt-progress-wrap">
                    <div class="vt-progress-bar">
                        <div class="vt-progress-fill" id="vt-prog-fill-${rgId}" style="width:${pct}%"></div>
                    </div>
                    <span class="vt-progress-label" id="vt-prog-label-${rgId}">${approved} / 300 approved</span>
                </div>
                <button class="btn-action btn-orange btn-sm"
                    onclick="regenerateUnselected(${rgId}, '${escapeAttr(rgName)}')">
                    <i class="fas fa-sync-alt"></i> Regenerate unselected
                </button>
                <button class="btn-action btn-green btn-sm"
                    id="vt-finalize-${rgId}"
                    onclick="finalizeVariations(${rgId})"
                    ${canFinalize ? '' : 'disabled'}>
                    <i class="fas fa-check"></i> Finalisieren
                </button>
            </div>
            <div class="vt-sets-grid">${setsHtml}</div>
        </div>`;
}

function toggleSetCard(btn) {
    const body    = btn.nextElementSibling;
    const chevron = btn.querySelector('.vt-set-chevron');
    const isOpen  = body.classList.toggle('open');
    if (chevron) chevron.style.transform = isOpen ? 'rotate(90deg)' : '';
}

function toggleValue(rgId, setIdx, valIdx, checked) {
    const state = getVState(rgId);
    if (!state?.sets) return;
    state.sets[setIdx].values[valIdx].approved = checked;
    setVState(rgId, state);

    // Live-update progress bar
    const { approved } = countApproved(state.sets);
    const fill  = document.getElementById(`vt-prog-fill-${rgId}`);
    const label = document.getElementById(`vt-prog-label-${rgId}`);
    if (fill)  fill.style.width   = Math.min(100, (approved / 300) * 100).toFixed(1) + '%';
    if (label) label.textContent  = `${approved} / 300 approved`;

    // Live-update set count badge
    const setCount = document.getElementById(`vt-setcount-${rgId}-${setIdx}`);
    if (setCount) {
        const s = state.sets[setIdx];
        setCount.textContent = `${s.values.filter(v => v.approved).length}/${s.values.length}`;
    }

    // Enable/disable Finalisieren
    const finalizeBtn = document.getElementById(`vt-finalize-${rgId}`);
    if (finalizeBtn) finalizeBtn.disabled = approved < 200;
}

function editValue(rgId, setIdx, valIdx, newText) {
    const state = getVState(rgId);
    if (!state?.sets?.[setIdx]?.values?.[valIdx]) return;
    state.sets[setIdx].values[valIdx].text = newText.trim();
    setVState(rgId, state);
}

function goBackToPhase1(rgId, rgName) {
    const state = getVState(rgId);
    if (state) { state.phase = 1; setVState(rgId, state); }
    renderPhase1(rgId, rgName);
}

async function regenerateUnselected(rgId, rgName) {
    const state = getVState(rgId);
    if (!state?.sets) return;

    const setsPayload = state.sets
        .map(s => ({
            type:     s.type,
            rejected: s.values.filter(v => !v.approved).map(v => v.text),
            approved: s.values.filter(v =>  v.approved).map(v => v.text),
        }))
        .filter(s => s.rejected.length > 0);

    if (!setsPayload.length) {
        showToast('Keine abgelehnten Werte vorhanden.', 'info');
        return;
    }

    // Dim the toolbar while loading
    const toolbar = document.querySelector(`#vt-body-${rgId} .vt-p2-toolbar`);
    if (toolbar) toolbar.style.opacity = '0.5';

    try {
        const res  = await fetch(`${API_BASE}variation_regenerate.php`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                rechtsgebiet_id:   rgId,
                rechtsgebiet_name: rgName,
                model:             activeModel,
                sets:              setsPayload,
            }),
        });
        const data = await res.json();
        if (data.status !== 'ok') throw new Error(data.message);

        // Replace rejected values with returned replacements
        data.sets.forEach(newSet => {
            const local = state.sets.find(s => s.type === newSet.type);
            if (!local) return;
            let ri = 0;
            local.values = local.values.map(v => {
                if (!v.approved && ri < newSet.values.length) {
                    return { text: newSet.values[ri++], approved: true };
                }
                return v;
            });
        });

        setVState(rgId, state);
        renderPhase2(rgId, rgName);
        showToast('Werte erfolgreich regeneriert!', 'success');

    } catch(e) {
        if (toolbar) toolbar.style.opacity = '1';
        showToast('Fehler: ' + e.message, 'error');
    }
}

async function finalizeVariations(rgId) {
    const state = getVState(rgId);
    if (!state?.sets) return;

    const { approved } = countApproved(state.sets);
    if (approved < 200) {
        showToast('Mindestens 200 Werte müssen genehmigt sein.', 'error');
        return;
    }

    const sets = state.sets.map(s => ({
        type:   s.type,
        values: s.values.filter(v => v.approved).map(v => v.text),
    }));

    try {
        const res  = await fetch(`${API_BASE}variation_finalize.php`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ rechtsgebiet_id: rgId, sets }),
        });
        const data = await res.json();
        if (data.status !== 'ok') throw new Error(data.message);

        clearVState(rgId);
        showToast(`${data.saved} Werte gespeichert!`, 'success');

        // Remove the panel and re-open to show read view
        const detail = document.getElementById(`vt-detail-${rgId}`);
        if (detail) detail.remove();
        loadedVT.delete(rgId);

        await toggleRGVariations(rgId);

    } catch(e) {
        showToast('Fehler beim Speichern: ' + e.message, 'error');
    }
}

async function resetVariations(rgId) {
    if (!confirm('Alle Variation-Sets für dieses Rechtsgebiet löschen? Dies kann nicht rückgängig gemacht werden.')) return;

    try {
        const res  = await fetch(`${API_BASE}variation_reset.php`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ rechtsgebiet_id: rgId }),
        });
        const data = await res.json();
        if (data.status !== 'ok') throw new Error(data.message);

        clearVState(rgId);
        showToast('Variationen zurückgesetzt.', 'success');

        const rgRow = document.querySelector(`.rg-row[data-rg-id="${rgId}"]`);
        renderPhase1(rgId, rgRow?.dataset.rgName || '');

    } catch(e) {
        showToast('Fehler: ' + e.message, 'error');
    }
}
```

- [ ] **Step 2: Verify end-to-end flow in browser**

1. Open `http://localhost:8084/`, find a Rechtsgebiet with no types (was reset in Task 3)
2. Click "Variationen" → Phase 1 appears with "Generate Sets" button
3. Click "Generate Sets" → 10 type cards appear; Generelle Informationen pre-checked
4. Select 5 more → "Weiter: Sets befüllen →" becomes active
5. Click "Weiter" → loading spinner → Phase 2 appears with all 6 sets
6. Uncheck a few values → progress bar decrements, unchecked pills get strikethrough
7. Click "Regenerate unselected" → unchecked values replaced with new ones (all re-checked)
8. Click "Finalisieren" (when ≥ 200 approved) → panel switches to read view showing saved sets

- [ ] **Step 3: Verify page refresh restores state**

1. After "Generate Sets" but before "Finalisieren", refresh the page
2. Click "Variationen" again on the same Rechtsgebiet
3. Should restore to the phase that was active (Phase 1 with candidates, or Phase 2 with values)

- [ ] **Step 4: Verify "← Zurück" works**

In Phase 2, click "← Zurück" → should return to Phase 1 with previously selected types still checked.

- [ ] **Step 5: Verify "Reset" button**

Click "Reset" button in panel header → confirm dialog → panel returns to empty Phase 1.

- [ ] **Step 6: Final commit**

```bash
git add admin/assets/dashboard.js
git commit -m "feat: variation management Phase 2 UI (value review, regenerate, finalize)"
```
