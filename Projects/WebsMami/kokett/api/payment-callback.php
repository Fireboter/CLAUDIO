<?php
require_once dirname(__DIR__) . '/config.php';
require_once SHARED_PATH . '/db.php';
require_once SHARED_PATH . '/helpers.php';
require_once SHARED_PATH . '/payment.php';
require_once SHARED_PATH . '/email.php';
require_once SHARED_PATH . '/cart.php';

$dsSignatureVersion   = $_POST['Ds_SignatureVersion'] ?? '';
$dsMerchantParameters = $_POST['Ds_MerchantParameters'] ?? '';
$dsSignature          = $_POST['Ds_Signature'] ?? '';

$payment = new RedsysPayment();
if (!$payment->verifyCallback($dsSignatureVersion, $dsMerchantParameters, $dsSignature)) {
    http_response_code(400);
    exit('Signature verification failed');
}

$params       = $payment->decodeParameters($dsMerchantParameters);
$orderNumber  = $params['Ds_Order'] ?? '';
$responseCode = (int)($params['Ds_Response'] ?? 9999);
$isSuccess    = $responseCode >= 0 && $responseCode <= 99;

$orders = db_query("SELECT * FROM `Order` WHERE orderNumber = ?", [$orderNumber]);
if (empty($orders)) { http_response_code(404); exit('Order not found'); }
$order = $orders[0];

if ($isSuccess && $order['status'] === 'pending') {
    db_run("UPDATE `Order` SET status = 'processing', updatedAt = NOW() WHERE orderNumber = ?", [$orderNumber]);

    // Deduct stock
    $items = db_query('SELECT * FROM OrderItem WHERE orderId = ?', [$order['id']]);
    foreach ($items as $item) {
        if ($item['variantId']) {
            db_run('UPDATE ProductVariant SET stock = GREATEST(0, stock - ?), reserved = GREATEST(0, reserved - ?) WHERE id = ?',
                [$item['quantity'], $item['quantity'], $item['variantId']]);
        }
    }

    // Clear cart reservation
    session_start();
    if ($order['sessionId']) {
        cart_clear($order['sessionId']);
    }

    // Send confirmation email
    $orderItems = db_query('SELECT oi.*, p.name as productName FROM OrderItem oi JOIN Product p ON oi.productId = p.id WHERE oi.orderId = ?', [$order['id']]);
    send_order_confirmation($order['customerEmail'], $order['customerName'], $orderNumber, (float)$order['totalAmount'], $orderItems, $order['shippingMethod']);

    // Gift card products
    foreach ($orderItems as $item) {
        $giftCheck = db_query("SELECT 1 FROM Product WHERE id = ? AND collectionId IN (SELECT id FROM Collection WHERE name = 'Tarjeta Regalo')", [$item['productId']]);
        if (!empty($giftCheck)) {
            $code = generate_gift_code();
            $expiresAt = date('Y-m-d H:i:s', strtotime('+3 months'));
            db_execute('INSERT INTO DiscountCode (code, orderId, productId, amount, expiresAt, customerEmail) VALUES (?, ?, ?, ?, ?, ?)',
                [$code, $order['id'], $item['productId'], $item['price'], $expiresAt, $order['customerEmail']]);
            send_gift_card_email($order['customerEmail'], $order['customerName'], $orderNumber, (float)$item['price'], $code, date('d/m/Y', strtotime($expiresAt)));
        }
    }
} else {
    db_run("UPDATE `Order` SET status = 'payment_failed', updatedAt = NOW() WHERE orderNumber = ?", [$orderNumber]);
}

http_response_code(200);
echo 'OK';
