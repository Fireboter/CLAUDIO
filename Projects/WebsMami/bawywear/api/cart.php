<?php
require_once dirname(__DIR__) . '/config.php';
require_once SHARED_PATH . '/db.php';
require_once SHARED_PATH . '/helpers.php';
require_once SHARED_PATH . '/cart.php';
session_start();

$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
}

$action = $input['action'] ?? $_GET['action'] ?? '';

header('Content-Type: application/json');

switch ($action) {
    case 'add':
        $variantId = (int)($input['variantId'] ?? 0);
        $quantity  = max(1, (int)($input['quantity'] ?? 1));
        if (!$variantId) json_response(['success' => false, 'message' => 'Invalid variant']);

        $rows = db_query(
            'SELECT pv.*, p.name, p.price, p.discount, p.onDemand,
             (SELECT url FROM ProductImage WHERE productId = p.id ORDER BY displayOrder LIMIT 1) as imageUrl
             FROM ProductVariant pv JOIN Product p ON pv.productId = p.id WHERE pv.id = ?',
            [$variantId]
        );
        if (empty($rows)) json_response(['success' => false, 'message' => 'Not found']);
        $v = $rows[0];
        $price = ($v['discount'] && (float)$v['discount'] < (float)$v['price']) ? (float)$v['discount'] : (float)$v['price'];

        $result = cart_add($variantId, (int)$v['productId'], $quantity, $price, $v['name'], $v['imageUrl'] ?? '', $v['size'] ?? '', $v['color'] ?? '');
        json_response($result);

    case 'remove':
        $variantId = (int)($input['variantId'] ?? $_GET['variantId'] ?? 0);
        cart_remove($variantId);
        json_response(['success' => true, 'count' => cart_item_count()]);

    case 'count':
        json_response(['count' => cart_item_count()]);

    case 'get':
        json_response(['items' => array_values(cart_get()), 'total' => cart_total()]);

    case 'expiry':
        json_response(['expiresAt' => cart_get_expiry()]);

    default:
        json_response(['error' => 'Unknown action'], 400);
}
