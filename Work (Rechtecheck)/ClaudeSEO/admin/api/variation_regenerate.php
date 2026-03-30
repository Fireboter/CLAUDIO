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
    $provider        = ProviderFactory::makeWithModel($model);

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
    error_log('variation_regenerate.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Ein Fehler ist aufgetreten. Bitte versuche es erneut.']);
}
