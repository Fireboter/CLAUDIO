# Variation Management Redesign

**Date:** 2026-03-22
**Project:** ClaudeSEO / Rechtecheck Admin Dashboard
**Status:** Approved for implementation

---

## Problem

1. Content generation API calls return HTML (PHP errors/warnings) mixed into JSON responses, causing `SyntaxError: Unexpected token '<'` in the frontend.
2. The existing variation system has 7,384 values and 230 types seeded from static scripts — no admin UI to manage them interactively.
3. Users cannot pick which variation types matter for a given Rechtsgebiet, edit values, or regenerate with a chosen AI model.

---

## Goal

Replace the static variation data with a 2-phase interactive workflow inside the existing inline Variationen panel. Target: **300 approved values per Rechtsgebiet across 6 selected variation types**, generated and reviewed entirely from the admin UI using the currently selected AI model (Haiku or Sonnet).

---

## Prerequisite: Fix JSON Error

**All `admin/api/*.php` files** (both existing and new) must suppress PHP display errors before outputting JSON:

```php
ini_set('display_errors', 0);
error_reporting(0);
```

Add these two lines at the top of every file in `admin/api/`, including existing ones (`content.php`, `actions.php`, `rechtsgebiete.php`, `rechtsfragen.php`, `variations.php`, `variation_types_by_rg.php`, `analytics.php`, `model.php`). This prevents PHP warnings from appearing in JSON responses.

---

## Data Model

No new tables. Existing tables are reused:

| Table | Role |
|-------|------|
| `variation_types` | One row per set (6 per Rechtsgebiet after finalization) |
| `variation_values` | Values per type (~50 each, 300 total per Rechtsgebiet) |

**Tier column:** All newly generated values are inserted with `tier = 1`. The tier column is kept for backward compatibility but not used in the new workflow.

**Reset scope:** Per-Rechtsgebiet only. Delete `variation_values` first (or rely on FK CASCADE if confirmed), then `variation_types` for that `rechtsgebiet_id`.

---

## Model Integration

The admin dashboard already has a model switcher. The frontend variable holding the active model is **`activeModel`** (defined at the module level in `dashboard.js`). All variation API calls send it as `"model"` in the JSON body.

On the backend, each new variation API file instantiates the provider directly:

```php
require_once __DIR__ . '/../../lib/Providers/ClaudeProvider.php';
$config = require __DIR__ . '/../../config/app.php';
$modelId = $input['model'] ?? $config['claude']['model'];
$provider = new ClaudeProvider(['model' => $modelId] + $config['claude']);
```

This bypasses ProviderFactory and directly instantiates ClaudeProvider with a model override. No changes to ContentGenerator or existing classes needed.

**Valid model IDs** come from whatever ProviderFactory lists as available — frontend sends the exact model ID string from the switcher.

---

## Error Handling

All four new variation API endpoints return a consistent shape on failure:

```json
{ "status": "error", "message": "Human-readable error." }
```

HTTP status codes: 400 for bad input, 500 for AI/DB failure.

**Frontend behavior on error:**
- Show a toast with the error message
- Keep current localStorage state intact (user can retry)
- Do not advance or reset the phase
- If AI returns malformed JSON (parse failure), treat as a 500 error and prompt retry

**Timeouts:** PHP `set_time_limit(120)` on variation generation endpoints. If exceeded, the error response is returned and the user can retry.

---

## UI Entry Point

The existing **"Variationen" button** on each Rechtsgebiet row opens the inline panel (unchanged).
A new **"Reset"** button appears in the panel header — clears all types + values for that Rechtsgebiet (calls `api/variation_reset.php`) and returns the panel to Phase 1.

Panel state is persisted in `localStorage` under key `vstate_${rgId}` so it survives page refresh mid-workflow. On error or partial failure, partial state remains in localStorage — user retries without losing progress.

---

## Phase 1 — Type Selection

### Trigger
Panel opens with no existing `variation_types` for this Rechtsgebiet (first time or after reset). If types already exist, panel skips to the normal read view.

### UX
- **"Generate Sets"** button (uses `activeModel`) — calls `api/variation_generate_types.php`
- AI returns **10 candidate variation type names** tailored to the Rechtsgebiet
- Types rendered as **checkbox cards** with name + one-line description
- **"Generelle Informationen" is always the first card, pre-checked, locked (cannot be unchecked, name not editable).** It counts as 1 of the 6. If AI returns it, the duplicate is removed client-side.
- User selects **5 more** (6 total including the locked one) — UI disables unchecked cards once limit is reached
- Each non-locked card has **inline name editing** (click to edit)
- **"Regenerate"** button: fetches 10 fresh candidates; already-checked cards are preserved and prepended
- Once exactly 6 are selected → **"Weiter: Sets befüllen →"** becomes active

### API: `POST api/variation_generate_types.php`

**Request:**
```json
{ "rechtsgebiet_id": 1, "model": "claude-haiku-4-5-20251001" }
```

**Success Response:**
```json
{
  "status": "ok",
  "types": [
    { "name": "Städte", "description": "Ortschaften, in denen Mandanten Rechtsberatung suchen" },
    { "name": "Personenstatus", "description": "Wer sucht rechtliche Hilfe?" }
  ]
}
```

**Error Response:**
```json
{ "status": "error", "message": "AI did not return valid JSON." }
```

AI prompt: return exactly 10 type name+description pairs relevant to the Rechtsgebiet, as a JSON array. Exclude "Generelle Informationen" (it is always injected client-side).

---

## Phase 2 — Value Filling & Review

### Trigger
User clicks "Weiter: Sets befüllen →". The 6 selected type names are read from localStorage.

### Generation
- Single `POST` to `api/variation_generate_values.php` with the 6 type names + model
- AI distributes ~300 values across all 6 sets (10–100 per set, AI decides split based on type richness)
- Loading spinner covers the panel during generation (`set_time_limit(120)`)
- On success, sets are written to localStorage and panel renders Phase 2

### UX — Review Grid
- All 6 sets shown simultaneously in a **2-column responsive grid**
- Each set = collapsible card: header shows type name + `(approved / total)` count, body shows value pills
- Each value = **checkbox pill** — checked = approved, unchecked = rejected — all start checked
- Click a value text to **edit it inline** (blur to save to localStorage)
- **Progress bar** at top: `X / 300 approved` — updates live on check/uncheck
- **"Regenerate unselected"** button (top): sends all unchecked values per set to `api/variation_regenerate.php` → AI returns replacement values → only unchecked slots update in place (checked values untouched). If a set has no unchecked values, it is omitted from the request.
- **"← Zurück"** returns to Phase 1 (localStorage state restored)
- **"Finalisieren"** active when ≥ 200 values are approved → calls `api/variation_finalize.php` → saves to DB → clears localStorage for this rgId → panel returns to normal read view

### API: `POST api/variation_generate_values.php`

**Request:**
```json
{
  "rechtsgebiet_id": 1,
  "rechtsgebiet_name": "Mietrecht",
  "types": ["Generelle Informationen", "Städte", "Personenstatus", "Ziel", "Dringlichkeit", "Beratungsform"],
  "model": "claude-haiku-4-5-20251001"
}
```

**Success Response:**
```json
{
  "status": "ok",
  "sets": [
    { "type": "Städte", "values": ["Berlin", "München", "Hamburg"] },
    { "type": "Generelle Informationen", "values": ["Mietvertrag prüfen", "Kündigung anfechten"] }
  ]
}
```

**Error Response:**
```json
{ "status": "error", "message": "Generation timed out." }
```

### API: `POST api/variation_regenerate.php`

**Request:**
```json
{
  "rechtsgebiet_id": 1,
  "rechtsgebiet_name": "Mietrecht",
  "model": "claude-haiku-4-5-20251001",
  "sets": [
    {
      "type": "Städte",
      "rejected": ["Trier", "Siegen"],
      "approved": ["Berlin", "München"]
    }
  ]
}
```

**Success Response:** Same shape as `variation_generate_values.php` but only contains sets that had rejected values. Each set's `values` array contains only replacements (same count as `rejected`).

**Error Response:**
```json
{ "status": "error", "message": "..." }
```

### API: `POST api/variation_finalize.php`

**Request:**
```json
{
  "rechtsgebiet_id": 1,
  "sets": [
    { "type": "Städte", "values": ["Berlin", "München", "Hamburg"] }
  ]
}
```

**Actions:**
1. Delete existing `variation_values` rows for all `variation_types` of this `rechtsgebiet_id`
2. Delete existing `variation_types` for this `rechtsgebiet_id`
3. Insert 6 new `variation_types` rows (slug = kebab-case of name)
4. Insert all approved `variation_values` rows (slug = kebab-case, `tier = 1`)

**Success Response:** `{ "status": "ok", "saved": 298 }`

**Error Response:** `{ "status": "error", "message": "..." }` — DB state unchanged on failure (wrap in transaction).

### API: `POST api/variation_reset.php`

**Request:** `{ "rechtsgebiet_id": 1 }`

**Actions:** Delete all `variation_values` (via type join) then all `variation_types` for this rgId.

**Response:** `{ "status": "ok" }` or `{ "status": "error", "message": "..." }`

---

## localStorage Schema

```json
{
  "phase": 1,
  "types": [
    { "name": "Generelle Informationen", "description": "...", "selected": true, "locked": true },
    { "name": "Städte", "description": "...", "selected": true, "locked": false }
  ],
  "sets": [
    { "type": "Städte", "values": [{ "text": "Berlin", "approved": true }] }
  ]
}
```

Key: `vstate_${rgId}`. Cleared on successful Finalisieren or Reset. Partial state on error is preserved for retry.

---

## Out of Scope

- Variation *page content* generation (intro paragraphs per Rechtsfrage × type) — existing system, unchanged
- Multi-user collaboration
- Bulk operations across multiple Rechtsgebiete at once
- The existing read-only value pills view (kept as-is for finalized sets)
- Tier-based sorting or display in the new workflow
