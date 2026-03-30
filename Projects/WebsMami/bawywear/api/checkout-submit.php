<?php
require_once dirname(__DIR__) . '/config.php';
require_once SHARED_PATH . '/db.php';
require_once SHARED_PATH . '/helpers.php';
require_once SHARED_PATH . '/cart.php';
require_once SHARED_PATH . '/payment.php';
session_start();

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'Method not allowed'], 405);

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$required = ['name', 'email', 'phone', 'address', 'city', 'postalCode', 'shippingMethod'];
foreach ($required as $field) {
    if (empty($input[$field])) json_response(['success' => false, 'message' => "Campo requerido: $field"]);
}

$cartItems = cart_get();
if (empty($cartItems)) json_response(['success' => false, 'message' => 'La cesta está vacía']);

$settingsRows = db_query('SELECT `key`, `value` FROM SiteSettings');
$settings = [];
foreach ($settingsRows as $r) $settings[$r['key']] = $r['value'];

$cartTotal         = cart_total();
$shippingMethod    = $input['shippingMethod'];
$shippingCost      = 0.0;
$spainPrice        = (float)($settings['shipping_spain_price'] ?? 7.50);
$spainThreshold    = (float)($settings['shipping_spain_free_threshold'] ?? 80);
$europePrice       = (float)($settings['shipping_europe_price'] ?? 12.00);
$europeDiscounted  = (float)($settings['shipping_europe_discounted_price'] ?? 4.50);
$europeThreshold   = (float)($settings['shipping_europe_discount_threshold'] ?? 80);

if ($shippingMethod === 'spain') {
    $shippingCost = $cartTotal > $spainThreshold ? 0 : $spainPrice;
} elseif ($shippingMethod === 'europe') {
    $shippingCost = $cartTotal > $europeThreshold ? $europeDiscounted : $europePrice;
}

$discountCode   = $input['discountCode'] ? strtoupper(trim($input['discountCode'])) : null;
$discountAmount = 0.0;
$discountCodeId = null;
if ($discountCode) {
    $codes = db_query('SELECT id, amount, expiresAt, usedAt FROM DiscountCode WHERE code = ?', [$discountCode]);
    if (empty($codes) || $codes[0]['usedAt'] || strtotime($codes[0]['expiresAt']) < time()) {
        json_response(['success' => false, 'message' => 'Código no válido o expirado']);
    }
    $discountAmount = (float)$codes[0]['amount'];
    $discountCodeId = $codes[0]['id'];
}

$totalAmount    = max(0.01, $cartTotal + $shippingCost - $discountAmount);
$orderNumber    = generate_order_number();
$shippingAddress = trim($input['address'] . ', ' . $input['city'] . ', ' . $input['postalCode']);
$sessionId      = cart_session_id();

$pdo = db_connect();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("INSERT INTO `Order` (orderNumber, customerName, customerEmail, customerPhone, shippingAddress, totalAmount, shippingMethod, shippingCost, status, sessionId, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW())");
    $stmt->execute([$orderNumber, $input['name'], $input['email'], $input['phone'], $shippingAddress, $totalAmount, $shippingMethod, $shippingCost, $sessionId]);
    $orderId = (int)$pdo->lastInsertId();

    foreach ($cartItems as $item) {
        $prod = db_query('SELECT isPreorder FROM Product WHERE id = ?', [$item['productId']]);
        $isPreorder = !empty($prod[0]['isPreorder']) ? 1 : 0;

        $stmt2 = $pdo->prepare('INSERT INTO OrderItem (orderId, productId, variantId, quantity, price, isPreorder, createdAt) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $stmt2->execute([$orderId, $item['productId'], $item['variantId'], $item['quantity'], $item['price'], $isPreorder]);
    }

    if ($discountCode && $discountCodeId) {
        $pdo->prepare('UPDATE DiscountCode SET usedAt = NOW(), usedInOrderId = ? WHERE id = ?')->execute([$orderId, $discountCodeId]);
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    json_response(['success' => false, 'message' => 'Error al crear el pedido']);
}

$payment    = new RedsysPayment();
$amountCents = (int)round($totalAmount * 100);
$paymentData = $payment->generatePaymentRequest(
    $amountCents,
    $orderNumber,
    SITE_URL . '/payment/success?order=' . urlencode($orderNumber),
    SITE_URL . '/payment/failure?order=' . urlencode($orderNumber),
    SITE_URL . '/api/payment-callback.php',
    'Pedido ' . $orderNumber
);

json_response(['success' => true, 'orderId' => $orderId, 'orderNumber' => $orderNumber, 'payment' => $paymentData]);
