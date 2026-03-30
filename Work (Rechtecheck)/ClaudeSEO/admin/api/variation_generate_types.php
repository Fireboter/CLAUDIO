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

    $provider = ProviderFactory::makeWithModel($model);
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
    error_log('variation_generate_types.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Ein Fehler ist aufgetreten. Bitte versuche es erneut.']);
}
