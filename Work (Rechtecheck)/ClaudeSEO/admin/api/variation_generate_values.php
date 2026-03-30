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
require_once __DIR__ . '/../../lib/Providers/OpenAIProvider.php';
require_once __DIR__ . '/../../lib/ProviderFactory.php';

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
    $provider  = ProviderFactory::makeWithModel($model);

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
    error_log('variation_generate_values.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Ein Fehler ist aufgetreten. Bitte versuche es erneut.']);
}
