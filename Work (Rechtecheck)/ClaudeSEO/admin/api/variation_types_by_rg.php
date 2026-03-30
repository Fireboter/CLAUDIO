<?php
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../lib/Database.php';

$db = Database::getInstance();

try {
    $rgId = $_GET['rechtsgebiet_id'] ?? null;

    if (!$rgId) {
        http_response_code(400);
        echo json_encode(['error' => 'rechtsgebiet_id required']);
        exit;
    }

    // Fetch types
    $typesSql = "SELECT vt.id, vt.name, vt.slug,
                        COUNT(vv.id) AS value_count
                 FROM variation_types vt
                 LEFT JOIN variation_values vv ON vv.variation_type_id = vt.id
                 WHERE vt.rechtsgebiet_id = ?
                 GROUP BY vt.id, vt.name, vt.slug
                 ORDER BY vt.id ASC";

    $types = $db->fetchAll($typesSql, [(int) $rgId]);

    // Fetch all values for this RG in one query
    $valsSql = "SELECT vv.variation_type_id, vv.id, vv.value, vv.slug
                FROM variation_values vv
                INNER JOIN variation_types vt ON vv.variation_type_id = vt.id
                WHERE vt.rechtsgebiet_id = ?
                ORDER BY vv.variation_type_id ASC, vv.value ASC";

    $allValues = $db->fetchAll($valsSql, [(int) $rgId]);

    // Group values by type_id
    $valuesByType = [];
    foreach ($allValues as $v) {
        $valuesByType[$v['variation_type_id']][] = ['id' => $v['id'], 'value' => $v['value'], 'slug' => $v['slug']];
    }

    // Attach values to each type
    foreach ($types as &$t) {
        $t['values'] = $valuesByType[$t['id']] ?? [];
    }

    echo json_encode($types);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
