# Rechtecheck SEO System - Design Document

**Date**: 2026-03-05
**Project**: ClaudeSEO - Automated legal SEO page generator for Rechtecheck.de
**Location**: C:\ClaudeSEO

---

## 1. Overview

A fully automated PHP system that generates, publishes, monitors, and optimizes legal SEO pages for Rechtecheck.de across 4 hierarchical levels. The system creates persuasive legal content driving users to click "Kostenlose Ersteinschaetzung", monitors performance via Google Search Console (free), and autonomously decides what to expand, optimize, or prune.

**No DataForSEO dependency** - the strategy is: create content first from known legal topics (CSV seed), measure with GSC, then optimize based on real data.

---

## 2. Tech Stack

- **Backend**: PHP (vanilla, no framework)
- **Frontend**: HTML + CSS + JS (Bootstrap 5 via CDN, Chart.js via CDN)
- **Database**: MySQL (existing server at 45.9.61.45)
- **Content Generation**: Claude Haiku API (cheapest, ~$0.01/page)
- **Analytics**: Google Search Console API (free)
- **HTTP Client**: Guzzle (composer)

---

## 3. Architecture

### Project Structure

```
C:\ClaudeSEO/
├── public/                    # Document root for production
│   ├── index.php              # Front controller / router
│   ├── .htaccess              # URL rewriting rules
│   └── assets/
│       ├── css/style.css
│       └── js/app.js
│
├── admin/                     # Dashboard (separate entry point)
│   ├── index.php              # Dashboard SPA
│   ├── api/                   # Admin REST endpoints
│   │   ├── rechtsgebiete.php
│   │   ├── rechtsfragen.php
│   │   ├── variations.php
│   │   ├── content.php
│   │   ├── analytics.php
│   │   └── actions.php
│   └── assets/
│
├── templates/                 # 4 page templates
│   ├── rechtsgebiete.php      # Level 1: All legal areas grid
│   ├── rechtsgebiet.php       # Level 2: Single area + its questions
│   ├── rechtsfrage.php        # Level 3: Question page with full content
│   └── variation.php          # Level 4: City/variation-specific page
│
├── cron/                      # Automated daily scripts
│   ├── phase1_generate.php
│   ├── phase2_publish.php
│   ├── phase3_analytics.php
│   ├── phase4_optimize.php
│   └── phase5_sitemap.php
│
├── config/
│   ├── database.php
│   ├── api_keys.php
│   └── seo.php
│
├── lib/                       # Core classes
│   ├── Database.php
│   ├── Router.php
│   ├── ContentGenerator.php
│   ├── SeoAnalyzer.php
│   ├── SearchConsole.php
│   ├── PriorityQueue.php
│   └── CronLock.php
│
├── database/
│   ├── schema.sql
│   └── seed.php
│
├── logs/
│
├── data/
│   └── rechtsbereiche_v2.csv
│
├── docs/plans/
│
└── composer.json
```

### URL Routing (mirrors rechtecheck.de)

- `/experten-service/unsere-services/` -> rechtsgebiete.php template
- `/experten-service/{slug}/` -> rechtsgebiet.php template
- `/{rechtsgebiet-slug}-{rechtsfrage-slug}/` -> rechtsfrage.php template
- `/{rechtsgebiet-slug}-{rechtsfrage-slug}-{variation-slug}/` -> variation.php template

---

## 4. Database Schema

### Core Hierarchy

```sql
rechtsgebiete (
    id, name, slug, status ENUM('draft','published','unpublished'),
    performance_score, avg_position, total_clicks, total_impressions,
    created_at, updated_at
)

rechtsfragen (
    id, rechtsgebiet_id FK, name, slug, description,
    status ENUM('draft','published','unpublished'),
    performance_score, avg_position, total_clicks, total_impressions,
    created_at, updated_at
)
```

### Variation System (per-Rechtsgebiet types)

```sql
variation_types (
    id, rechtsgebiet_id FK, name, slug
)
-- e.g., Arbeitsrecht has: Staedte, Branchen, Arbeitgeber

variation_values (
    id, variation_type_id FK, value, slug, tier INT DEFAULT 1
)
-- e.g., Berlin (tier 1), Bochum (tier 2), Celle (tier 3)
```

### Content / Pages

```sql
rechtsgebiet_pages (
    id, rechtsgebiet_id FK UNIQUE,
    title, meta_description, meta_keywords, html_content,
    og_title, og_description,
    generation_status ENUM('pending','generating','generated','failed','published'),
    generated_by VARCHAR(50),
    published_at, created_at, updated_at
)

rechtsfrage_pages (
    id, rechtsfrage_id FK UNIQUE,
    title, meta_description, meta_keywords, html_content,
    og_title, og_description,
    generation_status ENUM('pending','generating','generated','failed','published'),
    generated_by VARCHAR(50),
    published_at, created_at, updated_at
)

variation_pages (
    id, rechtsfrage_id FK, variation_value_id FK,
    title, meta_description, meta_keywords, html_content,
    generation_status ENUM('pending','generating','generated','failed','published'),
    generated_by VARCHAR(50),
    published_at, created_at, updated_at,
    UNIQUE(rechtsfrage_id, variation_value_id)
)
```

### Analytics & Tracking

```sql
page_analytics (
    id, page_type ENUM('rechtsgebiet','rechtsfrage','variation'),
    page_id INT, url VARCHAR(500),
    clicks INT, impressions INT, ctr FLOAT, avg_position FLOAT,
    date DATE, fetched_at TIMESTAMP,
    INDEX(page_type, page_id, date)
)

page_decisions (
    id, page_type, page_id,
    action ENUM('keep','update','delete','create','expand'),
    reason TEXT, priority_score FLOAT,
    decided_at TIMESTAMP, executed_at TIMESTAMP NULL
)

api_usage (
    id, date DATE, api_name VARCHAR(50),
    calls_count INT, tokens_used INT, cost_cents INT,
    UNIQUE(date, api_name)
)

cron_log (
    id, phase VARCHAR(50), started_at TIMESTAMP, ended_at TIMESTAMP,
    items_processed INT, errors INT, notes TEXT
)
```

### Data Retention

- page_analytics: daily rows kept 90 days, then aggregated to weekly, deleted after 365 days
- cron_log: kept 90 days
- api_usage: kept forever (small table)

---

## 5. Page Templates & SEO

### Level 1: Rechtsgebiete Overview

- Grid of all Rechtsgebiete as filterable cards
- Search/filter JS functionality
- Meta: "Rechtsgebiete - Finden Sie den richtigen Anwalt | Rechtecheck"
- Internal linking hub passing authority to all children

### Level 2: Rechtsgebiet

- Hero + AI-generated intro (400-600 words) about this legal area
- Grid of Rechtsfragen cards with descriptions + CTAs
- Sidebar: related Rechtsgebiete for internal linking
- Schema.org: LegalService + BreadcrumbList

### Level 3: Rechtsfrage

- Full AI content (1500-2500 words): Ihre Rechte, Fristen, Ablauf, FAQ
- Auto-generated table of contents from H2s
- Sticky sidebar CTA: "Kostenlose Ersteinschaetzung"
- Variation links at bottom
- Schema.org: LegalService + FAQPage + BreadcrumbList
- Breadcrumb navigation

### Level 4: Variation

- Parent Rechtsfrage content cloned + city-specific localization
- Localized H1, meta title, meta description
- "Fachanwaelte in {Stadt}" section (AI micro-prompt, ~100 tokens)
- Links to parent + sibling variations
- Own canonical (targets geo keyword)

### SEO on every page

- `<title>`, `<meta description>`, `<meta keywords>`
- Open Graph tags
- Schema.org JSON-LD
- Canonical URL
- Auto-generated sitemap.xml (split if > 1000 URLs)
- robots.txt

---

## 6. Content Generation Prompts

### Rechtsgebiet Prompt

```
Du bist ein deutscher Rechtsexperte. Schreibe eine SEO-optimierte Uebersichtsseite
fuer das Rechtsgebiet "{name}".

Kontext - Rechtsfragen in diesem Gebiet:
{list of rechtsfragen names + descriptions}

Anforderungen:
- 400-600 Woerter
- H2-Abschnitte: Ueberblick, Haeufige Probleme, Ihre Rechte, Warum Rechtecheck
- Ueberzeugend: Leser soll "Kostenlose Ersteinschaetzung" anklicken
- Ausgabe: reines HTML (nur body-Inhalt)
- Zielkeyword: "{name} Anwalt"
```

### Rechtsfrage Prompt

```
Du bist ein deutscher Rechtsexperte. Schreibe einen ausfuehrlichen Ratgeberartikel
fuer die Rechtsfrage "{name}" im Bereich "{rechtsgebiet}".

Kontext: {description from CSV}

Anforderungen:
- 1500-2500 Woerter
- Struktur mit H2/H3:
  1. Das Wichtigste in Kuerze
  2. Ihre Rechte
  3. Wichtige Fristen
  4. Ablauf / Vorgehen
  5. Haeufige Fragen (FAQ - mind. 5 Fragen)
  6. So hilft Ihnen Rechtecheck
- CTA-Absaetze einbauen
- Ausgabe: reines HTML
- Zielkeywords: "{name} {rechtsgebiet}", "{name} Anwalt"
```

### Variation Micro-Prompt

```
Schreibe 2-3 Saetze ueber die Besonderheiten von "{rechtsfrage}" speziell in {stadt}.
Erwaehne lokale Gerichte oder regionale Besonderheiten. Kurz und sachlich.
```

### Cost Estimate

| Entity | Count | Cost/Item | Total |
|--------|-------|-----------|-------|
| Rechtsgebiet pages | ~40 | ~$0.02 | $0.80 |
| Rechtsfrage pages | ~370 | ~$0.05 | $18.50 |
| Variation pages (Tier 1) | ~5,550 | ~$0.005 | $27.75 |
| **Total initial** | | | **~$47** |
| Daily optimization | ~10 pages | ~$0.05 | $0.50/day |

---

## 7. Intelligent Generation Strategy

### No arbitrary throttles. Priority queue with saturation detection.

### Phase 1: Initial Seed (Days 1-10)

Generate everything:
- ALL 40 Rechtsgebiet pages
- ALL 370 Rechtsfrage pages
- Tier 1 cities (15 largest) x all Rechtsfragen = ~5,550 variation pages
- Only limited by API rate limits, not artificial caps

### Phase 2: Wait for GSC (Days 10-30)

System idles. Pages get indexed. GSC data starts accumulating.

### Phase 3: Autonomous Optimization (Day 30+)

Daily priority queue built from GSC data:

**Decision tree per Rechtsfrage:**
- No page exists -> GENERATE (highest priority)
- Page < 30 days old, no GSC data -> WAIT
- Position 1-3, CTR > 5% -> SATURATED (do nothing)
- Position 1-3, CTR < 5% -> OPTIMIZE (title/meta needs work)
- Position 4-10 with < 5 variation cities -> EXPAND (add Tier 2 cities)
- Position 4-10 with 5+ cities -> OPTIMIZE parent content
- Position 10-30 -> EXPAND aggressively (more variations)
- Position 30+ with impressions -> REGENERATE content
- 0 impressions after 60 days -> DEPRIORITIZE / unpublish

### City Tier System

- **Tier 1** (created on seed): Berlin, Muenchen, Hamburg, Koeln, Frankfurt, Stuttgart, Duesseldorf, Leipzig, Dortmund, Essen, Bremen, Dresden, Hannover, Nuernberg, Duisburg
- **Tier 2** (when parent reaches position < 20): Bochum, Wuppertal, Bielefeld, Bonn, Muenster, Mannheim, Karlsruhe, Augsburg, Wiesbaden, etc.
- **Tier 3** (when parent reaches position < 10): Remaining smaller cities

---

## 8. Stability & Crash Prevention

### Chunked Processing
Never load all records. Process in batches of 10-50, free memory between.

### Lock Files
Each cron phase has its own lock file. Prevents overlapping runs.

### Per-Phase Cron
5 independent scripts, each with own timeout. One crash doesn't kill others.

```cron
0  2 * * *  php cron/phase1_generate.php
15 2 * * *  php cron/phase2_publish.php
20 2 * * *  php cron/phase3_analytics.php
35 2 * * *  php cron/phase4_optimize.php
50 2 * * *  php cron/phase5_sitemap.php
```

### Item-Level Status Tracking
generation_status per page: pending -> generating -> generated -> failed -> published.
Crash-safe: picks up where it left off.

### Safety Nets (not throttles)

```php
$safeguards = [
    'max_api_calls_per_day'   => 500,
    'max_cost_per_day_cents'  => 500,
    'cooldown_after_generate' => 7,   // days
    'cooldown_after_optimize' => 14,  // days
    'min_days_before_judging' => 30,
    'min_days_before_delete'  => 60,
];
```

### Data Retention
- page_analytics: 90 days daily, then weekly aggregates, delete after 365 days
- cron_log: 90 days
- Sitemap: streaming write, split at 1000 URLs

---

## 9. Dashboard

### Tab 1: Management
- Collapsible hierarchy: Rechtsgebiete > Rechtsfragen > Variations
- Per-row: name, status badge, performance score, clicks, impressions, avg position
- Actions: Generate Content, Preview, Publish/Unpublish
- Bulk: Generate All Missing, Sync GSC, Run Analyzer, Generate Sitemap

### Tab 2: Analytics
- Summary cards: total pages, published, clicks (30d), impressions (30d), avg CTR, avg position
- Trend chart (Chart.js): clicks & impressions over 30/60/90 days
- Recommendations table: color-coded (KEEP/UPDATE/DELETE/CREATE) with reasoning
- Top performers list
- GSC opportunity queries

---

## 10. Google Search Console Integration

- Free API, requires one-time OAuth setup
- Pulls: clicks, impressions, CTR, avg_position per URL per date
- Stores in page_analytics table
- Also pulls "queries" report to discover new keyword opportunities
- Runs daily in phase 3
