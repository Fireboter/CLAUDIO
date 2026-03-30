<?php
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../lib/Database.php';

$db = Database::getInstance();

try {
    $rechtsgebietId = $_GET['rechtsgebiet_id'] ?? null;

    if (!$rechtsgebietId) {
        http_response_code(400);
        echo json_encode(['error' => 'rechtsgebiet_id required']);
        exit;
    }

    $sortBy = $_GET['sort_by'] ?? 'alphabet';

    $allowedSorts = [
        'alphabet' => 'rf.name ASC',
        'score'    => 'rf.performance_score DESC',
        'clicks'   => 'rf.total_clicks DESC',
    ];

    $sortClause = $allowedSorts[$sortBy] ?? $allowedSorts['alphabet'];

    $sql = "SELECT rf.*,
                (SELECT COUNT(*) FROM variation_pages vp WHERE vp.rechtsfrage_id = rf.id) AS total_variations,
                COALESCE(rfp.generation_status, 'none') AS page_status,
                rfp.title AS page_title
            FROM rechtsfragen rf
            LEFT JOIN rechtsfrage_pages rfp ON rf.id = rfp.rechtsfrage_id
            WHERE rf.rechtsgebiet_id = ?
            ORDER BY {$sortClause}";

    $results = $db->fetchAll($sql, [(int) $rechtsgebietId]);

    echo json_encode($results);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}
