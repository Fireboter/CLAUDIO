<?php
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/AIProvider.php';
require_once __DIR__ . '/../../lib/ProviderFactory.php';
require_once __DIR__ . '/../../lib/Providers/ClaudeProvider.php';
require_once __DIR__ . '/../../lib/Providers/OpenAIProvider.php';

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
