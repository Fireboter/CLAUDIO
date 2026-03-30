<?php
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../lib/Database.php';

$db = Database::getInstance();

try {
    $rechtsfageId = $_GET['rechtsfrage_id'] ?? null;

    if (!$rechtsfageId) {
        http_response_code(400);
        echo json_encode(['error' => 'rechtsfrage_id required']);
        exit;
    }

    $sql = "SELECT
                vv.id,
                vv.value,
                vv.slug,
                vv.tier,
                vt.name  AS variation_type_name,
                vt.slug  AS variation_type_slug,
                rf.slug  AS rf_slug,
                rf.id    AS rf_id,
                COALESCE(vp.generation_status, 'pending') AS page_status
            FROM variation_values vv
            INNER JOIN variation_types vt ON vv.variation_type_id = vt.id
            INNER JOIN rechtsgebiete rg ON vt.rechtsgebiet_id = rg.id
            INNER JOIN rechtsfragen rf ON rf.rechtsgebiet_id = rg.id
            LEFT JOIN variation_pages vp
                ON vp.rechtsfrage_id = rf.id
                AND vp.variation_value_id = vv.id
            WHERE rf.id = ?
            ORDER BY vt.slug ASC, vv.tier ASC, vv.value ASC";

    $results = $db->fetchAll($sql, [(int) $rechtsfageId]);

    echo json_encode($results);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}
