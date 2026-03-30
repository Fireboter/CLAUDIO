<?php
// Cart is stored in $_SESSION['cart'] as array of:
// ['variantId' => int, 'productId' => int, 'quantity' => int, 'price' => float,
//  'name' => string, 'image' => string, 'size' => string, 'color' => string]

const RESERVATION_MINUTES = 15;

function cart_get(): array {
    return $_SESSION['cart'] ?? [];
}

function cart_session_id(): string {
    if (empty($_SESSION['cart_session_id'])) {
        $_SESSION['cart_session_id'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['cart_session_id'];
}

function cart_total(): float {
    $total = 0.0;
    foreach (cart_get() as $item) {
        $total += $item['price'] * $item['quantity'];
    }
    return $total;
}

function cart_item_count(): int {
    $count = 0;
    foreach (cart_get() as $item) {
        $count += $item['quantity'];
    }
    return $count;
}

function cart_add(
    int $variantId,
    int $productId,
    int $quantity,
    float $price,
    string $name,
    string $image,
    string $size,
    string $color
): array {
    // 1. Cleanup expired reservations (10% chance)
    if (rand(1, 10) === 1) {
        cart_cleanup_expired();
    }

    // 2. Check stock availability
    $sessionId = cart_session_id();
    $variants = db_query(
        'SELECT v.stock, v.reserved, p.onDemand, p.isPreorder FROM ProductVariant v JOIN Product p ON v.productId = p.id WHERE v.id = ?',
        [$variantId]
    );
    if (empty($variants)) return ['success' => false, 'message' => 'Variant not found'];

    $variant = $variants[0];
    $isOnDemand = (bool)$variant['onDemand'];
    $isPreorder = (bool)$variant['isPreorder'];

    if (!$isOnDemand && !$isPreorder) {
        $available = $variant['stock'] - (int)$variant['reserved'];
        if ($quantity > $available) {
            return ['success' => false, 'message' => 'Not enough stock', 'available' => $available];
        }
    }

    // 3. Upsert reservation in DB
    $expiresAt = date('Y-m-d H:i:s', time() + RESERVATION_MINUTES * 60);
    $existing = db_query(
        'SELECT id, quantity FROM CartReservation WHERE sessionId = ? AND productVariantId = ?',
        [$sessionId, $variantId]
    );

    if (!empty($existing)) {
        db_run(
            'UPDATE CartReservation SET quantity = quantity + ?, expiresAt = ? WHERE id = ?',
            [$quantity, $expiresAt, $existing[0]['id']]
        );
    } else {
        db_execute(
            'INSERT INTO CartReservation (sessionId, productVariantId, quantity, expiresAt) VALUES (?, ?, ?, ?)',
            [$sessionId, $variantId, $quantity, $expiresAt]
        );
    }

    // 4. Update reserved column
    db_run('UPDATE ProductVariant SET reserved = reserved + ? WHERE id = ?', [$quantity, $variantId]);

    // 5. Extend all session reservations
    db_run('UPDATE CartReservation SET expiresAt = ? WHERE sessionId = ?', [$expiresAt, $sessionId]);

    // 6. Add to session cart
    $cart = cart_get();
    $key = $variantId;
    if (isset($cart[$key])) {
        $cart[$key]['quantity'] += $quantity;
    } else {
        $cart[$key] = compact('variantId', 'productId', 'quantity', 'price', 'name', 'image', 'size', 'color');
    }
    $_SESSION['cart'] = $cart;

    return ['success' => true, 'expiresAt' => $expiresAt];
}

function cart_remove(int $variantId): void {
    $sessionId = cart_session_id();
    $cart = cart_get();
    $quantity = $cart[$variantId]['quantity'] ?? 0;

    if ($quantity > 0) {
        db_run('DELETE FROM CartReservation WHERE sessionId = ? AND productVariantId = ?', [$sessionId, $variantId]);
        db_run('UPDATE ProductVariant SET reserved = GREATEST(0, reserved - ?) WHERE id = ?', [$quantity, $variantId]);
    }

    unset($_SESSION['cart'][$variantId]);
}

function cart_clear(string $sessionId): void {
    $reservations = db_query('SELECT productVariantId, quantity FROM CartReservation WHERE sessionId = ?', [$sessionId]);
    db_run('DELETE FROM CartReservation WHERE sessionId = ?', [$sessionId]);
    foreach ($reservations as $r) {
        db_run('UPDATE ProductVariant SET reserved = GREATEST(0, reserved - ?) WHERE id = ?', [$r['quantity'], $r['productVariantId']]);
    }
    $_SESSION['cart'] = [];
}

function cart_get_expiry(): ?string {
    $sessionId = cart_session_id();
    $result = db_query('SELECT MAX(expiresAt) as expiresAt FROM CartReservation WHERE sessionId = ?', [$sessionId]);
    return $result[0]['expiresAt'] ?? null;
}

function cart_cleanup_expired(): void {
    $expired = db_query('SELECT productVariantId, SUM(quantity) as totalQuantity FROM CartReservation WHERE expiresAt < NOW() GROUP BY productVariantId');
    foreach ($expired as $exp) {
        db_run('UPDATE ProductVariant SET reserved = GREATEST(0, reserved - ?) WHERE id = ?', [$exp['totalQuantity'], $exp['productVariantId']]);
    }
    db_run('DELETE FROM CartReservation WHERE expiresAt < NOW()');
}
