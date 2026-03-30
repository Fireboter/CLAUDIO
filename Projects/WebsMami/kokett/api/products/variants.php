<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once SHARED_PATH . '/db.php';
require_once SHARED_PATH . '/helpers.php';

header('Content-Type: application/json');
$productId = (int)($_GET['productId'] ?? 0);
if (!$productId) json_response(['error' => 'Missing productId'], 400);

$variants = db_query(
    'SELECT id, size, color, material, (stock - reserved) as stock, onDemand FROM ProductVariant WHERE productId = ?',
    [$productId]
);

foreach ($variants as &$v) {
    $v['stock'] = max(0, (int)$v['stock']);
    $v['onDemand'] = (bool)$v['onDemand'];
}

json_response(['variants' => $variants]);
