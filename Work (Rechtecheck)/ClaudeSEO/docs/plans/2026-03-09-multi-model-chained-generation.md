# Multi-Model Chained Generation Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace single-call content generation with a 3-call chain (outline → content → meta), support Claude Haiku + GPT-4.1 nano via a provider abstraction, and add a model switcher dropdown to the admin dashboard top-right.

**Architecture:** `AIProvider` interface → `ClaudeProvider` / `OpenAIProvider` implementations → `ProviderFactory` reads active model from `settings` DB table → `ContentGenerator` receives provider, handles chaining only.

**Tech Stack:** PHP 8.1, GuzzleHttp (existing), OpenAI REST API, Anthropic API (existing), Bootstrap 5 (existing admin UI), vanilla JS fetch.

---

### Task 1: DB migration — settings table + seed active model

**Files:**
- Create: `database/migrate_settings.php`

**Step 1: Write migration script**

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/Database.php';
$pdo = Database::getInstance()->getPdo();

$pdo->exec("
    CREATE TABLE IF NOT EXISTS settings (
        `key`        VARCHAR(64)  NOT NULL PRIMARY KEY,
        `value`      TEXT         NOT NULL,
        `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Seed default active model
$pdo->exec("
    INSERT IGNORE INTO settings (`key`, `value`)
    VALUES ('active_model', 'claude-haiku-4-5-20251001')
");

echo "Settings table created. Default model: claude-haiku-4-5-20251001\n";
```

**Step 2: Run it**

```bash
cd C:/ClaudeSEO && php database/migrate_settings.php
```

Expected output: `Settings table created. Default model: claude-haiku-4-5-20251001`

**Step 3: Commit**

```bash
git add database/migrate_settings.php
git commit -m "feat: add settings table with active_model seed"
```

---

### Task 2: AIProvider interface

**Files:**
- Create: `lib/AIProvider.php`

**Step 1: Write the interface**

```php
<?php

interface AIProvider {
    /**
     * Send a prompt and return the text response.
     *
     * @param string $prompt
     * @param int    $maxTokens
     * @return string
     * @throws RuntimeException
     */
    public function call(string $prompt, int $maxTokens = 2048): string;

    /**
     * Return the provider name for usage tracking (e.g. 'claude', 'openai').
     */
    public function getApiName(): string;

    /**
     * Return the model identifier string (e.g. 'claude-haiku-4-5-20251001').
     */
    public function getModel(): string;
}
```

**Step 2: Verify PHP parse**

```bash
cd C:/ClaudeSEO && php -l lib/AIProvider.php
```

Expected: `No syntax errors detected in lib/AIProvider.php`

**Step 3: Commit**

```bash
git add lib/AIProvider.php
git commit -m "feat: add AIProvider interface"
```

---

### Task 3: ClaudeProvider — extract Anthropic API logic

**Files:**
- Create: `lib/Providers/ClaudeProvider.php`
- Reference: `lib/ContentGenerator.php` (existing `callClaude()` and `trackUsage()` methods to copy from)

**Step 1: Create the Providers directory and ClaudeProvider**

```php
<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class ClaudeProvider implements AIProvider {
    private Client $http;
    private array  $config;
    private Database $db;

    public function __construct() {
        $this->config = (require __DIR__ . '/../../config/api_keys.php')['claude'];
        $this->db     = Database::getInstance();

        $caBundle = ini_get('curl.cainfo');
        $caBundle = $caBundle ? str_replace('\\', '/', $caBundle) : 'C:/php/cacert.pem';

        $this->http = new Client([
            'base_uri' => 'https://api.anthropic.com/v1/',
            'timeout'  => 120,
            'verify'   => file_exists($caBundle) ? $caBundle : false,
        ]);
    }

    public function call(string $prompt, int $maxTokens = 2048): string {
        try {
            $response = $this->http->post('messages', [
                'headers' => [
                    'x-api-key'         => $this->config['api_key'],
                    'anthropic-version'  => '2023-06-01',
                    'content-type'       => 'application/json',
                ],
                'json' => [
                    'model'      => $this->config['model'],
                    'max_tokens' => $maxTokens,
                    'messages'   => [['role' => 'user', 'content' => $prompt]],
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Claude API error: ' . $e->getMessage(), 0, $e);
        }

        $body = json_decode($response->getBody()->getContents(), true);
        if (!isset($body['content'][0]['text'])) {
            throw new RuntimeException('Unexpected Claude response format');
        }

        $this->trackUsage($body['usage'] ?? []);
        return $body['content'][0]['text'];
    }

    public function getApiName(): string { return 'claude'; }

    public function getModel(): string { return $this->config['model']; }

    private function trackUsage(array $usage): void {
        $input  = $usage['input_tokens']  ?? 0;
        $output = $usage['output_tokens'] ?? 0;
        $cost   = (int) ceil(($input * 0.000025) + ($output * 0.000125));

        $this->db->query(
            "INSERT INTO api_usage (date, api_name, calls_count, tokens_used, cost_cents)
             VALUES (?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE
                 calls_count = calls_count + 1,
                 tokens_used = tokens_used + VALUES(tokens_used),
                 cost_cents  = cost_cents  + VALUES(cost_cents)",
            [date('Y-m-d'), 'claude', $input + $output, $cost]
        );
    }
}
```

**Step 2: Verify parse**

```bash
cd C:/ClaudeSEO && php -l lib/Providers/ClaudeProvider.php
```

Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add lib/Providers/ClaudeProvider.php
git commit -m "feat: ClaudeProvider extracts Anthropic API transport"
```

---

### Task 4: OpenAIProvider — GPT-4.1 nano

**Files:**
- Modify: `config/api_keys.php` — add `openai` key
- Create: `lib/Providers/OpenAIProvider.php`

**Step 1: Add OpenAI key to config**

Open `config/api_keys.php` and add alongside existing claude entry:

```php
'openai' => [
    'api_key' => 'YOUR_OPENAI_API_KEY_HERE',
    'model'   => 'gpt-4.1-nano',
],
```

**Step 2: Write OpenAIProvider**

```php
<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class OpenAIProvider implements AIProvider {
    private Client $http;
    private array  $config;
    private Database $db;

    public function __construct() {
        $this->config = (require __DIR__ . '/../../config/api_keys.php')['openai'];
        $this->db     = Database::getInstance();

        $caBundle = ini_get('curl.cainfo');
        $caBundle = $caBundle ? str_replace('\\', '/', $caBundle) : 'C:/php/cacert.pem';

        $this->http = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'timeout'  => 120,
            'verify'   => file_exists($caBundle) ? $caBundle : false,
        ]);
    }

    public function call(string $prompt, int $maxTokens = 2048): string {
        try {
            $response = $this->http->post('chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['api_key'],
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model'      => $this->config['model'],
                    'max_tokens' => $maxTokens,
                    'messages'   => [['role' => 'user', 'content' => $prompt]],
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('OpenAI API error: ' . $e->getMessage(), 0, $e);
        }

        $body = json_decode($response->getBody()->getContents(), true);
        if (!isset($body['choices'][0]['message']['content'])) {
            throw new RuntimeException('Unexpected OpenAI response format');
        }

        $this->trackUsage($body['usage'] ?? []);
        return $body['choices'][0]['message']['content'];
    }

    public function getApiName(): string { return 'openai'; }

    public function getModel(): string { return $this->config['model']; }

    private function trackUsage(array $usage): void {
        $input  = $usage['prompt_tokens']     ?? 0;
        $output = $usage['completion_tokens'] ?? 0;
        // GPT-4.1 nano pricing: ~$0.10/MTok input, ~$0.40/MTok output
        $cost   = (int) ceil(($input * 0.0000001) + ($output * 0.0000004));

        $this->db->query(
            "INSERT INTO api_usage (date, api_name, calls_count, tokens_used, cost_cents)
             VALUES (?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE
                 calls_count = calls_count + 1,
                 tokens_used = tokens_used + VALUES(tokens_used),
                 cost_cents  = cost_cents  + VALUES(cost_cents)",
            [date('Y-m-d'), 'openai', $input + $output, $cost]
        );
    }
}
```

**Step 3: Verify parse**

```bash
cd C:/ClaudeSEO && php -l lib/Providers/OpenAIProvider.php
```

Expected: `No syntax errors detected`

**Step 4: Commit**

```bash
git add config/api_keys.php lib/Providers/OpenAIProvider.php
git commit -m "feat: OpenAIProvider for GPT-4.1 nano"
```

---

### Task 5: ProviderFactory

**Files:**
- Create: `lib/ProviderFactory.php`

**Step 1: Write the factory**

```php
<?php

class ProviderFactory {
    /**
     * Return the active AIProvider based on the settings table.
     * Falls back to ClaudeProvider if setting is missing.
     */
    public static function make(): AIProvider {
        $db      = Database::getInstance();
        $setting = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'active_model'");
        $model   = $setting['value'] ?? 'claude-haiku-4-5-20251001';

        if (str_starts_with($model, 'gpt-')) {
            return new OpenAIProvider();
        }

        return new ClaudeProvider();
    }

    /**
     * Save the active model to the settings table.
     */
    public static function setModel(string $model): void {
        $db = Database::getInstance();
        $db->query(
            "INSERT INTO settings (`key`, `value`) VALUES ('active_model', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()",
            [$model]
        );
    }

    /**
     * Return list of available models for the UI.
     */
    public static function availableModels(): array {
        return [
            'claude-haiku-4-5-20251001' => 'Claude Haiku',
            'gpt-4.1-nano'              => 'GPT-4.1 nano',
        ];
    }
}
```

**Step 2: Verify parse**

```bash
cd C:/ClaudeSEO && php -l lib/ProviderFactory.php
```

**Step 3: Commit**

```bash
git add lib/ProviderFactory.php
git commit -m "feat: ProviderFactory reads active model from settings DB"
```

---

### Task 6: Refactor ContentGenerator — chained generation

**Files:**
- Modify: `lib/ContentGenerator.php`

This is the largest change. Replace `callClaude()` with provider injection, and replace single-call generation methods with 3-call chains.

**Step 1: Replace constructor — inject AIProvider**

Old constructor accepted no arguments. New one accepts an optional `AIProvider`:

```php
public function __construct(?AIProvider $provider = null) {
    $this->provider  = $provider ?? ProviderFactory::make();
    $this->seoConfig = require __DIR__ . '/../config/seo.php';
    $this->db        = Database::getInstance();
}
```

Add property: `private AIProvider $provider;`

Remove properties: `private array $apiConfig;`, `private Client $httpClient;`

**Step 2: Replace `callClaude()` with `callProvider()`**

Remove the old `callClaude()` method entirely. Add:

```php
private function callProvider(string $prompt, int $maxTokens = 2048): string {
    return $this->provider->call($prompt, $maxTokens);
}
```

Update all internal calls: `$this->callClaude(...)` → `$this->callProvider(...)`

**Step 3: Replace `generateRechtsfragContent()` with chained version**

Remove the single-prompt method and replace with:

```php
public function generateRechtsfragContent(array $rechtsfrage, array $rechtsgebiet): array {
    if (!$this->checkDailyBudget()) {
        throw new RuntimeException('Daily API budget exceeded.');
    }

    $rfName = $rechtsfrage['name'];
    $rgName = $rechtsgebiet['name'];

    // Call 1: Outline
    $outlinePrompt = <<<PROMPT
Du bist ein deutscher Rechtsexperte. Erstelle eine strukturierte Gliederung für einen Ratgeberartikel.

Thema: "{$rfName}" im Rechtsgebiet "{$rgName}"

Erstelle genau 5 Abschnitte. Für jeden Abschnitt: ein H2-Titel und 3 Stichpunkte zum Inhalt.

AUSGABE: Nur JSON, kein Markdown, kein Text davor/danach:
{"sections":[{"title":"...","points":["...","...","..."]},...]}
PROMPT;

    $outlineRaw  = $this->callProvider($outlinePrompt, 512);
    $outlineRaw  = $this->extractJson($outlineRaw);
    $outline     = json_decode($outlineRaw, true);
    if (!isset($outline['sections'])) {
        $outline = ['sections' => [
            ['title' => 'Das Wichtigste in Kürze',   'points' => ['Überblick', 'Ihre Rechte', 'Nächste Schritte']],
            ['title' => 'Ihre Rechte',                'points' => ['Gesetzliche Grundlagen', 'Ansprüche', 'Fristen']],
            ['title' => 'Wichtige Fristen',           'points' => ['Gesetzliche Fristen', 'Verjährung', 'Handlungsbedarf']],
            ['title' => 'Ablauf und Vorgehen',        'points' => ['Erstberatung', 'Rechtliche Schritte', 'Kosten']],
            ['title' => 'Häufige Fragen (FAQ)',       'points' => ['Frage 1', 'Frage 2', 'Frage 3']],
        ]];
    }

    // Call 2: Full content
    $sectionsText = '';
    foreach ($outline['sections'] as $s) {
        $sectionsText .= "- {$s['title']}: " . implode(', ', $s['points']) . "\n";
    }

    $contentPrompt = <<<PROMPT
Du bist ein deutscher Rechtsexperte. Schreibe einen vollständigen Ratgeberartikel.

Thema: "{$rfName}" im Rechtsgebiet "{$rgName}"

Struktur (verwende diese H2-Abschnitte in dieser Reihenfolge):
{$sectionsText}

Anforderungen:
- 1500-2000 Wörter gesamt
- Jeder Abschnitt als <h2> mit Fließtext darunter
- FAQ-Abschnitt mit mindestens 5 <h3>-Fragen und Antworten
- 2-3 CTAs: "Kostenlose Ersteinschätzung bei Rechtecheck"
- Nur HTML body-Inhalt (kein <html>, <head>, <body>)
- Alle Zeilenumbrüche als \\n kodieren
- Kein Markdown, keine Code-Blöcke

Antworte NUR mit dem HTML. Beginne direkt mit <h2>.
PROMPT;

    $htmlContent = $this->callProvider($contentPrompt, 3000);
    $htmlContent = $this->cleanHtmlOutput($htmlContent);

    // Call 3: Meta
    $firstParagraph = mb_substr(strip_tags($htmlContent), 0, 300);
    $metaPrompt = <<<PROMPT
Erstelle SEO-Metadaten für einen Rechtsratgeber-Artikel.

Thema: "{$rfName} {$rgName}"
Einleitung: {$firstParagraph}

AUSGABE: Nur JSON, kein Markdown:
{"title":"...max 60 Zeichen...","meta_description":"...max 155 Zeichen...","meta_keywords":"...kommagetrennt...","og_title":"...","og_description":"...max 155 Zeichen..."}
PROMPT;

    $metaRaw = $this->callProvider($metaPrompt, 300);
    $metaRaw = $this->extractJson($metaRaw);
    $meta    = json_decode($metaRaw, true) ?? [];

    return [
        'html_content'     => $htmlContent,
        'title'            => mb_substr($meta['title']            ?? "{$rfName} - Rechtecheck", 0, 60),
        'meta_description' => mb_substr($meta['meta_description'] ?? "Informationen zu {$rfName}.", 0, 155),
        'meta_keywords'    => $meta['meta_keywords']    ?? "{$rfName}, {$rgName}, Anwalt",
        'og_title'         => mb_substr($meta['og_title']         ?? "{$rfName} - Rechtecheck", 0, 60),
        'og_description'   => mb_substr($meta['og_description']   ?? "Informationen zu {$rfName}.", 0, 155),
    ];
}
```

**Step 4: Add helper methods**

Add these two private methods to ContentGenerator:

```php
/**
 * Extract a JSON object or array from raw text (strips markdown fences).
 */
private function extractJson(string $raw): string {
    $raw = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
    $raw = preg_replace('/\s*```\s*$/', '', $raw);
    return trim($raw);
}

/**
 * Clean raw HTML from model: strip markdown fences, unescape \\n.
 */
private function cleanHtmlOutput(string $raw): string {
    $raw = preg_replace('/^```(?:html)?\s*/i', '', trim($raw));
    $raw = preg_replace('/\s*```\s*$/', '', $raw);
    $raw = str_replace('\\n', "\n", trim($raw));
    return $raw;
}
```

**Step 5: Replace `generateRechtsgebietContent()` with chained version**

Same 3-call pattern but for rechtsgebiet (no variation context). Outline has 4 sections:
- Überblick über {$name}
- Häufige Rechtsprobleme
- Ihre Rechte im Überblick
- Warum Rechtecheck wählen

Follow same chain: outline → content (800-1000 words) → meta.

**Step 6: Update `generateVariationIntros()`**

This method is now REMOVED — variations get full unique content via `generateVariationContent()` (Task 7).

**Step 7: Update `checkDailyBudget()`**

Replace hardcoded `'claude'` api_name reference with `$this->provider->getApiName()` — already accessed via the provider. The budget check itself stays the same (reads from `api_usage` table, checks `max_api_calls_per_day` and `max_cost_per_day_cents` from seoConfig).

**Step 8: Verify parse**

```bash
cd C:/ClaudeSEO && php -l lib/ContentGenerator.php
```

**Step 9: Commit**

```bash
git add lib/ContentGenerator.php
git commit -m "feat: ContentGenerator uses AIProvider, 3-call chain for rechtsgebiet + rechtsfrage"
```

---

### Task 7: Full variation content generation

**Files:**
- Modify: `lib/ContentGenerator.php` — add `generateVariationContent()`
- Modify: `admin/api/content.php` — use new method for `type=variation`

**Step 1: Add `generateVariationContent()` to ContentGenerator**

```php
/**
 * Generate a complete, unique article for a Rechtsfrage × Variation combination.
 * Uses a 3-call chain: outline → full HTML content → meta.
 *
 * @param array  $rechtsfrage    Rechtsfrage DB record
 * @param array  $rechtsgebiet   Rechtsgebiet DB record
 * @param string $variationType  e.g. 'staedte', 'personenstatus'
 * @param string $variationValue e.g. 'Berlin', 'Arbeitnehmer'
 */
public function generateVariationContent(
    array  $rechtsfrage,
    array  $rechtsgebiet,
    string $variationType,
    string $variationValue
): array {
    if (!$this->checkDailyBudget()) {
        throw new RuntimeException('Daily API budget exceeded.');
    }

    $rfName = $rechtsfrage['name'];
    $rgName = $rechtsgebiet['name'];

    $typeLabel = match($variationType) {
        'staedte'        => "in der Stadt {$variationValue}",
        'personenstatus' => "für {$variationValue}",
        'dringlichkeit'  => "bei Dringlichkeit: {$variationValue}",
        'beratungsphase' => "in der Phase: {$variationValue}",
        'ziel'           => "mit dem Ziel: {$variationValue}",
        'beratungsform'  => "per {$variationValue}",
        default          => "({$variationValue})",
    };

    // Call 1: Outline
    $outlinePrompt = <<<PROMPT
Du bist ein deutscher Rechtsexperte. Erstelle eine Gliederung für einen Ratgeberartikel.

Thema: "{$rfName}" {$typeLabel}
Rechtsgebiet: "{$rgName}"

Der Artikel soll spezifisch auf "{$variationValue}" ausgerichtet sein — nicht generisch.

Erstelle genau 5 Abschnitte. Für jeden: H2-Titel und 3 Stichpunkte.

AUSGABE: Nur JSON:
{"sections":[{"title":"...","points":["...","...","..."]},...]}
PROMPT;

    $outlineRaw = $this->callProvider($outlinePrompt, 512);
    $outline    = json_decode($this->extractJson($outlineRaw), true);
    if (!isset($outline['sections'])) {
        $outline = ['sections' => [
            ['title' => "Überblick: {$rfName} {$typeLabel}", 'points' => ['Situation', 'Rechte', 'Optionen']],
            ['title' => 'Rechtliche Grundlagen',             'points' => ['Gesetze', 'Ansprüche', 'Fristen']],
            ['title' => 'Schritt-für-Schritt Vorgehen',      'points' => ['Erstberatung', 'Schritte', 'Kosten']],
            ['title' => 'Besonderheiten',                    'points' => ['Spezifika', 'Tipps', 'Fallen']],
            ['title' => 'Häufige Fragen (FAQ)',              'points' => ['Frage 1', 'Frage 2', 'Frage 3']],
        ]];
    }

    // Call 2: Full content
    $sectionsText = '';
    foreach ($outline['sections'] as $s) {
        $sectionsText .= "- {$s['title']}: " . implode(', ', $s['points']) . "\n";
    }

    $contentPrompt = <<<PROMPT
Du bist ein deutscher Rechtsexperte. Schreibe einen vollständigen, einzigartigen Ratgeberartikel.

Hauptthema: "{$rfName}" {$typeLabel}
Rechtsgebiet: "{$rgName}"

WICHTIG: Der gesamte Artikel muss spezifisch auf "{$variationValue}" zugeschnitten sein.
Erwähne "{$variationValue}" natürlich im Text (nicht erzwungen). Schreibe nicht generisch.

Struktur:
{$sectionsText}

Anforderungen:
- 1200-1800 Wörter
- Jeder Abschnitt als <h2> mit Fließtext
- FAQ: mindestens 5 <h3>-Fragen mit Antworten
- 2 CTAs: "Kostenlose Ersteinschätzung bei Rechtecheck"
- Nur HTML body-Inhalt
- Zeilenumbrüche als \\n
- Kein Markdown

Beginne direkt mit <h2>.
PROMPT;

    $htmlContent = $this->cleanHtmlOutput($this->callProvider($contentPrompt, 2500));

    // Call 3: Meta
    $firstParagraph = mb_substr(strip_tags($htmlContent), 0, 300);
    $metaPrompt = <<<PROMPT
Erstelle SEO-Metadaten für diesen Artikel.

Thema: "{$rfName} {$typeLabel}"
Einleitung: {$firstParagraph}

AUSGABE: Nur JSON:
{"title":"...max 60 Zeichen...","meta_description":"...max 155 Zeichen...","meta_keywords":"...","og_title":"...","og_description":"...max 155 Zeichen..."}
PROMPT;

    $metaRaw = $this->extractJson($this->callProvider($metaPrompt, 300));
    $meta    = json_decode($metaRaw, true) ?? [];

    return [
        'html_content'     => $htmlContent,
        'title'            => mb_substr($meta['title']            ?? "{$rfName} {$typeLabel} | Rechtecheck", 0, 60),
        'meta_description' => mb_substr($meta['meta_description'] ?? "{$rfName} {$typeLabel} — kostenlose Ersteinschätzung.", 0, 155),
        'meta_keywords'    => $meta['meta_keywords']    ?? "{$rfName}, {$variationValue}, {$rgName}, Anwalt",
        'og_title'         => mb_substr($meta['og_title']         ?? "{$rfName} {$typeLabel}", 0, 60),
        'og_description'   => mb_substr($meta['og_description']   ?? "{$rfName} {$typeLabel} — kostenlose Ersteinschätzung.", 0, 155),
    ];
}
```

**Step 2: Update `admin/api/content.php` — case 'variation'**

Replace the old variation case (which called `generateVariationLocalization()` and cloned parent content) with:

```php
case 'variation':
    $variationValueId = (int)($input['variation_value_id'] ?? 0);
    if (!$variationValueId) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'variation_value_id required']);
        exit;
    }

    $rechtsfrage = $db->fetchOne('SELECT * FROM rechtsfragen WHERE id = ?', [$id]);
    if (!$rechtsfrage) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Rechtsfrage not found']); exit; }

    $rechtsgebiet = $db->fetchOne('SELECT * FROM rechtsgebiete WHERE id = ?', [$rechtsfrage['rechtsgebiet_id']]);
    if (!$rechtsgebiet) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Rechtsgebiet not found']); exit; }

    $variationValue = $db->fetchOne(
        'SELECT vv.*, vt.slug as type_slug FROM variation_values vv
         JOIN variation_types vt ON vt.id = vv.variation_type_id
         WHERE vv.id = ?',
        [$variationValueId]
    );
    if (!$variationValue) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Variation value not found']); exit; }

    $content = $gen->generateVariationContent(
        $rechtsfrage,
        $rechtsgebiet,
        $variationValue['type_slug'],
        $variationValue['value']
    );

    $existing = $db->fetchOne(
        'SELECT id FROM variation_pages WHERE rechtsfrage_id = ? AND variation_value_id = ?',
        [$id, $variationValueId]
    );

    if ($existing) {
        $db->update('variation_pages', [
            'title'             => $content['title'],
            'meta_description'  => $content['meta_description'],
            'meta_keywords'     => $content['meta_keywords'],
            'html_content'      => $content['html_content'],
            'og_title'          => $content['og_title'],
            'og_description'    => $content['og_description'],
            'generation_status' => 'generated',
            'generated_by'      => 'admin_api',
            'updated_at'        => date('Y-m-d H:i:s'),
        ], 'id = ?', [$existing['id']]);
    } else {
        $db->insert('variation_pages', [
            'rechtsfrage_id'     => $id,
            'variation_value_id' => $variationValueId,
            'title'              => $content['title'],
            'meta_description'   => $content['meta_description'],
            'meta_keywords'      => $content['meta_keywords'],
            'html_content'       => $content['html_content'],
            'og_title'           => $content['og_title'],
            'og_description'     => $content['og_description'],
            'generation_status'  => 'generated',
            'generated_by'       => 'admin_api',
            'created_at'         => date('Y-m-d H:i:s'),
        ]);
    }

    echo json_encode(['status' => 'success', 'title' => $content['title']]);
    break;
```

**Step 3: Verify parse**

```bash
cd C:/ClaudeSEO && php -l lib/ContentGenerator.php && php -l admin/api/content.php
```

**Step 4: Commit**

```bash
git add lib/ContentGenerator.php admin/api/content.php
git commit -m "feat: full unique variation content via 3-call chain"
```

---

### Task 8: Model API endpoint

**Files:**
- Create: `admin/api/model.php`

**Step 1: Write the endpoint**

```php
<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/AIProvider.php';
require_once __DIR__ . '/../../lib/ProviderFactory.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $db      = Database::getInstance();
    $setting = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'active_model'");
    echo json_encode([
        'active_model'    => $setting['value'] ?? 'claude-haiku-4-5-20251001',
        'available_models'=> ProviderFactory::availableModels(),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $model = $input['model'] ?? '';

    if (!array_key_exists($model, ProviderFactory::availableModels())) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid model: ' . $model]);
        exit;
    }

    ProviderFactory::setModel($model);
    echo json_encode(['status' => 'success', 'active_model' => $model]);
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
```

**Step 2: Verify parse**

```bash
cd C:/ClaudeSEO && php -l admin/api/model.php
```

**Step 3: Commit**

```bash
git add admin/api/model.php
git commit -m "feat: model GET/POST API endpoint"
```

---

### Task 9: Dashboard model switcher UI

**Files:**
- Modify: `admin/index.php` — add switcher to header
- Modify: `admin/assets/dashboard.js` — add `loadModel()` and `switchModel()` functions

**Step 1: Update `admin/index.php` header**

Replace the current header `<div>` content:

```html
<header class="dashboard-header">
    <div class="header-content">
        <div>
            <h1><i class="fas fa-chart-line"></i> SEO Dashboard - Rechtecheck Project Management</h1>
            <p class="header-subtitle">
                Gesamt: <span id="total-pages-count">0</span> Seiten verwaltet
            </p>
        </div>
        <div class="model-switcher">
            <div class="dropdown">
                <button class="btn-model dropdown-toggle" type="button" id="model-dropdown-btn" onclick="toggleModelDropdown()">
                    <i class="fas fa-robot"></i>
                    <span id="model-label">Lade...</span>
                    <i class="fas fa-chevron-down ms-1"></i>
                </button>
                <ul class="model-dropdown-menu" id="model-dropdown-menu">
                    <!-- populated by JS -->
                </ul>
            </div>
        </div>
    </div>
</header>
```

**Step 2: Add CSS to `admin/assets/dashboard.css`**

```css
.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.model-switcher {
    position: relative;
}

.btn-model {
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.3);
    color: #fff;
    padding: 8px 16px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: background 0.2s;
}

.btn-model:hover {
    background: rgba(255,255,255,0.25);
}

.model-dropdown-menu {
    display: none;
    position: absolute;
    right: 0;
    top: calc(100% + 6px);
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.12);
    list-style: none;
    padding: 6px 0;
    margin: 0;
    min-width: 180px;
    z-index: 1000;
}

.model-dropdown-menu.open {
    display: block;
}

.model-dropdown-menu li {
    padding: 10px 16px;
    cursor: pointer;
    color: #374151;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.model-dropdown-menu li:hover {
    background: #f8fafc;
}

.model-dropdown-menu li.active {
    color: #6366f1;
    font-weight: 600;
}

.model-dropdown-menu li .check-icon {
    visibility: hidden;
    color: #6366f1;
}

.model-dropdown-menu li.active .check-icon {
    visibility: visible;
}
```

**Step 3: Add JS functions to `admin/assets/dashboard.js`**

Add at the top of the file (before existing code):

```javascript
// ── Model Switcher ────────────────────────────────────────────────────────────

let activeModel = null;

async function loadModel() {
    try {
        const res  = await fetch('api/model.php');
        const data = await res.json();
        activeModel = data.active_model;
        renderModelSwitcher(data.active_model, data.available_models);
    } catch (e) {
        console.error('Failed to load model', e);
    }
}

function renderModelSwitcher(activeModelId, availableModels) {
    const label = document.getElementById('model-label');
    const menu  = document.getElementById('model-dropdown-menu');
    if (!label || !menu) return;

    label.textContent = availableModels[activeModelId] || activeModelId;
    menu.innerHTML = '';

    for (const [id, name] of Object.entries(availableModels)) {
        const li = document.createElement('li');
        li.innerHTML = `<i class="fas fa-check check-icon"></i> ${name}`;
        if (id === activeModelId) li.classList.add('active');
        li.onclick = () => switchModel(id, name, availableModels);
        menu.appendChild(li);
    }
}

function toggleModelDropdown() {
    document.getElementById('model-dropdown-menu').classList.toggle('open');
}

async function switchModel(modelId, modelName, availableModels) {
    document.getElementById('model-dropdown-menu').classList.remove('open');
    if (modelId === activeModel) return;

    try {
        const res  = await fetch('api/model.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ model: modelId }),
        });
        const data = await res.json();
        if (data.status === 'success') {
            activeModel = modelId;
            renderModelSwitcher(modelId, availableModels);
            showToast(`Model switched to ${modelName}`, 'success');
        } else {
            showToast('Failed to switch model: ' + data.message, 'error');
        }
    } catch (e) {
        showToast('Network error switching model', 'error');
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', (e) => {
    if (!e.target.closest('.model-switcher')) {
        document.getElementById('model-dropdown-menu')?.classList.remove('open');
    }
});
```

**Step 4: Call `loadModel()` in the existing init flow**

Find the existing `document.addEventListener('DOMContentLoaded', ...)` or equivalent init call in `dashboard.js` and add `loadModel();` alongside the other init calls.

**Step 5: Verify no JS parse errors by opening admin in browser**

Open `http://localhost:8001` — model switcher should appear top-right showing "Claude Haiku".

**Step 6: Commit**

```bash
git add admin/index.php admin/assets/dashboard.js admin/assets/dashboard.css
git commit -m "feat: model switcher dropdown in admin header"
```

---

### Task 10: Update autoloader + remaining call sites

**Files:**
- Modify: `admin/api/content.php` — use ProviderFactory
- Modify: `cron/phase1_generate.php` — use ProviderFactory
- Modify: `public/index.php` — ensure new lib files are required

**Step 1: Update `admin/api/content.php` bootstrap**

Add these requires after the existing ones:

```php
require_once __DIR__ . '/../../lib/AIProvider.php';
require_once __DIR__ . '/../../lib/ProviderFactory.php';
require_once __DIR__ . '/../../lib/Providers/ClaudeProvider.php';
require_once __DIR__ . '/../../lib/Providers/OpenAIProvider.php';
```

Replace:
```php
$gen = new ContentGenerator();
```
With:
```php
$gen = new ContentGenerator(ProviderFactory::make());
```

**Step 2: Update `cron/phase1_generate.php` bootstrap**

Same requires + same `ContentGenerator(ProviderFactory::make())` replacement.

Also remove the old variation-page generation block in phase 3 (which used `generateVariationLocalization()`) — the cron now skips variation pre-generation since variations are generated on demand from the admin UI or can be added back later.

**Step 3: Update composer classmap (or add manual requires to `public/index.php`)**

Since `composer.json` uses `classmap: ["lib/"]`, the new `lib/Providers/` subdirectory will be picked up. Regenerate:

```bash
cd C:/ClaudeSEO && php composer.phar dump-autoload
```

**Step 4: Smoke test — run phase1 with limit 1**

```bash
cd C:/ClaudeSEO && php cron/phase1_generate.php --limit=1
```

Expected: generates 1 rechtsgebiet page using whichever model is active in settings, no fatal errors.

**Step 5: Commit**

```bash
git add admin/api/content.php cron/phase1_generate.php composer.lock
git commit -m "feat: wire ProviderFactory into all generation entry points"
```

---

## Summary

| Task | What it builds |
|------|---------------|
| 1 | `settings` DB table + active_model seed |
| 2 | `AIProvider` interface |
| 3 | `ClaudeProvider` (Anthropic transport) |
| 4 | `OpenAIProvider` (GPT-4.1 nano) + config key |
| 5 | `ProviderFactory` (reads from DB, returns provider) |
| 6 | `ContentGenerator` refactored — 3-call chain, provider-agnostic |
| 7 | `generateVariationContent()` — full unique article per variation |
| 8 | `admin/api/model.php` — GET/POST model endpoint |
| 9 | Dashboard model switcher UI (dropdown top-right) |
| 10 | Wire ProviderFactory into content.php + cron, regenerate autoloader |
