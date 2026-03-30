<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once SHARED_PATH . '/db.php';
require_once SHARED_PATH . '/helpers.php';

header('Content-Type: application/json');
$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) json_response(['results' => []]);

$results = db_query(
    'SELECT id, name, price, discount, images FROM Product WHERE name LIKE ? LIMIT 10',
    ["%$q%"]
);

foreach ($results as &$r) {
    $images = json_decode($r['images'] ?? '[]', true) ?? [];
    $r['image'] = $images[0] ?? null;
    unset($r['images']);
}

json_response(['results' => $results]);
