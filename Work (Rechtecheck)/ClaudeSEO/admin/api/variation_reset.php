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
    error_log('variation_reset.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Ein Fehler ist aufgetreten. Bitte versuche es erneut.']);
}
