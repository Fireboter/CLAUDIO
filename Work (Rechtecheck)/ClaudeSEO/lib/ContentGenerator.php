<?php

class ContentGenerator {
    private AIProvider $provider;
    private array $seoConfig;
    private Database $db;

    public function __construct(?AIProvider $provider = null) {
        $this->provider  = $provider ?? ProviderFactory::make();
        $this->seoConfig = require __DIR__ . '/../config/seo.php';
        $this->db        = Database::getInstance();
    }

    /**
     * Generate content for a Rechtsgebiet (legal area) overview page.
     *
     * @param array $rechtsgebiet Rechtsgebiet record from database
     * @param array $rechtsfragen Array of Rechtsfrage records belonging to this Rechtsgebiet
     * @return array Parsed content with keys: html_content, title, meta_description, meta_keywords, og_title, og_description
     * @throws RuntimeException
     */
    public function generateRechtsgebietContent(array $rechtsgebiet, array $rechtsfragen): array {
        if (!$this->checkDailyBudget()) {
            throw new RuntimeException('Daily API budget exceeded.');
        }

        $name = $rechtsgebiet['name'];

        $rfList = '';
        foreach ($rechtsfragen as $rf) {
            $rfList .= "- {$rf['name']}\n";
        }

        // Call 1: Outline
        $outlinePrompt = <<<PROMPT
Du bist ein deutscher Rechtsexperte. Erstelle eine Gliederung für eine Übersichtsseite.

Rechtsgebiet: "{$name}"
Enthaltene Rechtsfragen:
{$rfList}

Erstelle genau 4 Abschnitte. Für jeden: H2-Titel und 3 Stichpunkte.
Abschnitte sollen sein: Überblick, Häufige Probleme, Ihre Rechte, Warum Rechtecheck.
H2-Titel: max 5 Wörter, kurz, kein Doppelpunkt.

AUSGABE: Nur JSON:
{"sections":[{"title":"...","points":["...","...","..."]},...]}
PROMPT;

        $outlineRaw = $this->callProvider($outlinePrompt, 512);
        $outline    = json_decode($this->extractJson($outlineRaw), true);
        if (!isset($outline['sections'])) {
            $outline = ['sections' => [
                ['title' => "Überblick: {$name}",     'points' => ['Was ist das?', 'Wann betrifft es Sie?', 'Ihre Optionen']],
                ['title' => 'Häufige Rechtsprobleme', 'points' => ['Problem 1', 'Problem 2', 'Problem 3']],
                ['title' => 'Ihre Rechte',            'points' => ['Recht 1', 'Recht 2', 'Recht 3']],
                ['title' => 'Warum Rechtecheck',      'points' => ['Experten', 'Kostenlos', 'Schnell']],
            ]];
        }

        $sectionsText = '';
        foreach ($outline['sections'] as $s) {
            $sectionsText .= "- {$s['title']}: " . implode(', ', $s['points']) . "\n";
        }

        // Call 2: Content
        $contentPrompt = <<<PROMPT
Du bist ein deutscher Rechtsexperte. Schreibe eine SEO-optimierte Übersichtsseite.

Rechtsgebiet: "{$name}"

Struktur:
{$sectionsText}

Anforderungen:
- 800-1000 Wörter
- Jeder Abschnitt als <h2> mit Fließtext
- H2-Titel: max 5 Wörter, kurz und prägnant, kein Doppelpunkt, keine Nebensätze
- 2 CTAs: "Kostenlose Ersteinschätzung bei Rechtecheck"
- Nur HTML body-Inhalt (kein Markdown, keine Code-Blöcke)
- Verwende echte HTML-Zeilenumbrüche, keine \\n Zeichen

Beginne direkt mit <h2>.
PROMPT;

        $htmlContent = $this->cleanHtmlOutput($this->callProvider($contentPrompt, 2000));

        // Call 3: Meta
        $firstParagraph = mb_substr(strip_tags($htmlContent), 0, 300);
        $metaPrompt = <<<PROMPT
Erstelle SEO-Metadaten für eine Rechtsgebiets-Übersichtsseite.

Thema: "{$name} Anwalt"
Einleitung: {$firstParagraph}

AUSGABE: Nur JSON:
{"title":"...max 60 Zeichen...","meta_description":"...max 155 Zeichen...","meta_keywords":"...kommagetrennt...","og_title":"...","og_description":"...max 155 Zeichen..."}
PROMPT;

        $metaRaw = $this->extractJson($this->callProvider($metaPrompt, 300));
        $meta    = json_decode($metaRaw, true) ?? [];

        return [
            'html_content'     => $htmlContent,
            'title'            => mb_substr($meta['title']            ?? "{$name} Anwalt - Rechtecheck", 0, 60),
            'meta_description' => mb_substr($meta['meta_description'] ?? "Informationen zu {$name}.", 0, 155),
            'meta_keywords'    => $meta['meta_keywords']    ?? "{$name}, Anwalt, Rechtsberatung",
            'og_title'         => mb_substr($meta['og_title']         ?? "{$name} - Rechtecheck", 0, 60),
            'og_description'   => mb_substr($meta['og_description']   ?? "Informationen zu {$name}.", 0, 155),
        ];
    }

    /**
     * Generate content for a Rechtsfrage (legal question) detail page.
     *
     * @param array $rechtsfrage Rechtsfrage record from database
     * @param array $rechtsgebiet Rechtsgebiet record this Rechtsfrage belongs to
     * @return array Parsed content with keys: html_content, title, meta_description, meta_keywords, og_title, og_description
     * @throws RuntimeException
     */
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
H2-Titel: max 5 Wörter, kurz und prägnant, kein Doppelpunkt.

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
- H2-Titel: max 5 Wörter, kurz und prägnant, kein Doppelpunkt, keine Nebensätze
- H3-Titel: max 8 Wörter
- FAQ-Abschnitt mit mindestens 5 <h3>-Fragen und Antworten
- 2-3 CTAs: "Kostenlose Ersteinschätzung bei Rechtecheck"
- Nur HTML body-Inhalt (kein <html>, <head>, <body>)
- Kein Markdown, keine Code-Blöcke, keine \\n Zeichen — nur echtes HTML

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

    /**
     * Generate a complete, unique article for a Rechtsfrage × Variation combination.
     * Uses a 3-call chain: outline → full HTML content → meta.
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
H2-Titel: max 5 Wörter, kurz und prägnant, kein Doppelpunkt.

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
- H2-Titel: max 5 Wörter, kurz und prägnant, kein Doppelpunkt, keine Nebensätze
- H3-Titel: max 8 Wörter
- FAQ: mindestens 5 <h3>-Fragen mit Antworten
- 2 CTAs: "Kostenlose Ersteinschätzung bei Rechtecheck"
- Nur HTML body-Inhalt (kein Markdown, keine Code-Blöcke, keine \\n Zeichen — nur echtes HTML)

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

    /**
     * Generate a localized variation paragraph for a Rechtsfrage in a specific city.
     *
     * @param string $rechtsfrage_name Name of the Rechtsfrage
     * @param string $city City name
     * @return string HTML paragraph with localized content
     * @throws RuntimeException
     */
    public function generateVariationLocalization(string $rechtsfrage_name, string $city): string {
        if (!$this->checkDailyBudget()) {
            throw new RuntimeException('Daily API budget exceeded. No more API calls allowed today.');
        }

        $prompt = <<<PROMPT
Schreibe 2-3 Sätze über die Besonderheiten von "{$rechtsfrage_name}" speziell in {$city}.
Erwähne lokale Gerichte oder regionale Besonderheiten. Kurz und sachlich.
Ausgabe: nur HTML (ein <p> Tag).
PROMPT;

        return $this->cleanHtmlOutput($this->callProvider($prompt, 512));
    }

    /**
     * Generate AI-driven variation values for a specific Rechtsgebiet + type.
     * Returns array of ['value' => string, 'slug' => string] entries,
     * contextually relevant to the legal area.
     *
     * @param array  $rechtsgebiet Rechtsgebiet record
     * @param string $typeSlug     One of: personenstatus, ziel, dringlichkeit, beratungsphase, beratungsform
     * @param int    $count        How many values to generate
     * @return array
     */
    public function generateVariationValuesForType(array $rechtsgebiet, string $typeSlug, int $count = 30): array {
        if (!$this->checkDailyBudget()) {
            throw new RuntimeException('Daily API budget exceeded.');
        }

        $rgName = $rechtsgebiet['name'];

        $typeDescriptions = [
            'personenstatus' => 'Personenstatus-Bezeichnungen: Wer sucht rechtliche Hilfe? (z.B. Arbeitnehmer, Vermieter, GmbH-Geschäftsführer)',
            'ziel'           => 'Konkrete rechtliche Ziele: Was möchte die Person erreichen? (z.B. Schadensersatz erhalten, Kündigung anfechten)',
            'dringlichkeit'  => 'Dringlichkeitsstufen: Wie dringend ist das Anliegen? (z.B. Sofortberatung, Innerhalb einer Woche, Notfall)',
            'beratungsphase' => 'Beratungsphasen: In welchem Stadium befindet sich der Fall? (z.B. Erstberatung, Laufendes Verfahren, Berufung)',
            'beratungsform'  => 'Beratungsformen: Wie soll beraten werden? (z.B. Online-Beratung, Telefonberatung, Vor-Ort-Beratung)',
        ];

        $desc = $typeDescriptions[$typeSlug] ?? "{$typeSlug} für {$rgName}";

        $prompt = <<<PROMPT
Du bist ein SEO-Experte für Rechtsgebiete.

Rechtsgebiet: "{$rgName}"

Erstelle eine Liste von genau {$count} relevanten {$desc} für Menschen, die im Bereich "{$rgName}" rechtliche Hilfe suchen.

REGELN (strikt einhalten):
- 1-4 Wörter pro Begriff
- Auf Deutsch
- Spezifisch relevant für "{$rgName}"
- Echte Suchbegriffe (was würde jemand googeln?)
- Keine Duplikate
- Sortiert nach Relevanz (häufigste Suchanfragen zuerst)

AUSGABE: NUR ein JSON-Array, KEIN Markdown, KEIN Text davor/danach:
["Wert 1", "Wert 2", "Wert 3"]
PROMPT;

        $raw = $this->callProvider($prompt, 1024);

        // Extract JSON array from response (handle markdown code fences)
        $raw = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
        $raw = preg_replace('/\s*```\s*$/', '', $raw);
        $raw = trim($raw);

        if (!preg_match('/\[[\s\S]*\]/s', $raw, $m)) {
            return [];
        }
        $values = json_decode($m[0], true);
        if (!is_array($values)) {
            return [];
        }

        $result = [];
        $seen   = [];
        foreach ($values as $val) {
            if (!is_string($val) || empty(trim($val))) continue;
            $val  = trim($val);
            $slug = $this->toSlug($val);
            if (isset($seen[$slug])) continue; // deduplicate
            $seen[$slug] = true;
            $result[] = ['value' => $val, 'slug' => $slug];
        }

        return $result;
    }

    /**
     * Convert a German string to a URL slug.
     */
    private function toSlug(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        $map  = ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss','Ä'=>'ae','Ö'=>'oe','Ü'=>'ue'];
        $text = strtr($text, $map);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim($text, '-');
    }

    /**
     * Core method: call the active AI provider and return the text response.
     *
     * @param string $prompt    The prompt to send
     * @param int    $maxTokens Maximum tokens in the response
     * @return string The text content from the provider's response
     * @throws RuntimeException
     */
    private function callProvider(string $prompt, int $maxTokens = 2048): string {
        return $this->provider->call($prompt, $maxTokens);
    }

    /**
     * Check whether the daily API budget still allows calls.
     *
     * @return bool true if within budget, false if budget exceeded
     */
    public function checkDailyBudget(): bool {
        $today = date('Y-m-d');
        $safeguards = $this->seoConfig['safeguards'];

        $usage = $this->db->fetchOne(
            'SELECT calls_count, cost_cents FROM api_usage WHERE date = ? AND api_name = ?',
            [$today, $this->provider->getApiName()]
        );

        if (!$usage) {
            return true;
        }

        if ((int) $usage['calls_count'] >= $safeguards['max_api_calls_per_day']) {
            return false;
        }

        if ((int) $usage['cost_cents'] >= $safeguards['max_cost_per_day_cents']) {
            return false;
        }

        return true;
    }

    /**
     * Strip markdown JSON code fences from a raw provider response,
     * and extract a JSON object or array from prose-wrapped responses.
     */
    private function extractJson(string $raw): string {
        // Strip markdown fences
        $raw = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
        $raw = preg_replace('/\s*```\s*$/', '', $raw);
        $raw = trim($raw);

        // If it already looks like pure JSON, return as-is
        if (str_starts_with($raw, '{') || str_starts_with($raw, '[')) {
            return $raw;
        }

        // Try to extract JSON object from prose
        if (preg_match('/\{[\s\S]*\}/s', $raw, $m)) {
            return $m[0];
        }

        // Try to extract JSON array from prose
        if (preg_match('/\[[\s\S]*\]/s', $raw, $m)) {
            return $m[0];
        }

        return $raw;
    }

    /**
     * Strip markdown HTML code fences, unescape literal \n sequences,
     * and enforce short H2/H3 headings.
     */
    private function cleanHtmlOutput(string $raw): string {
        $raw = preg_replace('/^```(?:html)?\s*/i', '', trim($raw));
        $raw = preg_replace('/\s*```\s*$/', '', $raw);
        $raw = trim($raw);

        // Unescape literal \n sequences the AI sometimes emits
        $raw = str_replace('\\n', "\n", $raw);

        // Truncate overly long H2/H3 headings (keep max 60 chars of inner text)
        $raw = preg_replace_callback('/<(h[23])([^>]*)>(.*?)<\/h[23]>/si', function ($m) {
            $tag     = $m[1];
            $attrs   = $m[2];
            $inner   = $m[3];
            $text    = strip_tags($inner);
            // If heading text is excessively long, shorten at last word boundary before 60 chars
            if (mb_strlen($text) > 60) {
                $cut = mb_substr($text, 0, 60);
                $cut = preg_replace('/\s+\S+$/', '', $cut); // trim to last word
                $inner = htmlspecialchars($cut, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            return "<{$tag}{$attrs}>{$inner}</{$tag}>";
        }, $raw);

        return $raw;
    }

}
