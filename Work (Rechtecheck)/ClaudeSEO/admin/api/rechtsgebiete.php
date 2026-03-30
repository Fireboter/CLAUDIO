<?php
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../lib/Database.php';

$db = Database::getInstance();

// POST: create a new Rechtsgebiet
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim($body['name'] ?? '');
        if ($name === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Name darf nicht leer sein.']);
            exit;
        }
        // Generate slug from name
        $slug = mb_strtolower($name, 'UTF-8');
        $slug = strtr($slug, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        // Ensure slug uniqueness
        $existing = $db->fetchOne('SELECT id FROM rechtsgebiete WHERE slug = ?', [$slug]);
        if ($existing) {
            http_response_code(409);
            echo json_encode(['error' => "Rechtsgebiet mit Slug \"{$slug}\" existiert bereits."]);
            exit;
        }
        $db->query(
            'INSERT INTO rechtsgebiete (name, slug, status) VALUES (?, ?, ?)',
            [$name, $slug, 'draft']
        );
        $id = $db->lastInsertId();
        echo json_encode(['success' => true, 'id' => $id, 'slug' => $slug]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

try {
    $sortBy = $_GET['sort_by'] ?? 'alphabet';

    $allowedSorts = [
        'alphabet' => 'rg.name ASC',
        'score'    => 'rg.performance_score DESC',
        'clicks'   => 'rg.total_clicks DESC',
        'status'   => 'rg.status ASC, rg.name ASC',
    ];

    $sortClause = $allowedSorts[$sortBy] ?? $allowedSorts['alphabet'];

    $sql = "SELECT rg.*,
                (SELECT COUNT(*) FROM rechtsfragen rf WHERE rf.rechtsgebiet_id = rg.id) AS total_questions,
                (SELECT COUNT(*) FROM variation_pages vp
                 INNER JOIN rechtsfragen rf ON vp.rechtsfrage_id = rf.id
                 WHERE rf.rechtsgebiet_id = rg.id) AS total_variations,
                COALESCE(rp.generation_status, 'none') AS page_status,
                rp.title AS page_title
            FROM rechtsgebiete rg
            LEFT JOIN rechtsgebiet_pages rp ON rg.id = rp.rechtsgebiet_id
            ORDER BY {$sortClause}";

    $results = $db->fetchAll($sql);

    echo json_encode($results);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}
