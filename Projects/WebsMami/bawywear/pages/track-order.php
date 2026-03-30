<?php
$order = null;
$orderItems = [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderNumber = trim($_POST['orderNumber'] ?? '');
    $email       = strtolower(trim($_POST['email'] ?? ''));
    if ($orderNumber && $email) {
        $rows = db_query("SELECT * FROM `Order` WHERE orderNumber = ? AND LOWER(customerEmail) = ?", [$orderNumber, $email]);
        if (!empty($rows)) {
            $order = $rows[0];
            $orderItems = db_query('SELECT oi.*, p.name as productName FROM OrderItem oi JOIN Product p ON oi.productId = p.id WHERE oi.orderId = ?', [$order['id']]);
        } else {
            $error = 'No se encontró el pedido. Verifica el número y el email.';
        }
    }
}

$statusLabels = [
    'pending'        => 'Pendiente de pago',
    'processing'     => 'En preparación',
    'shipped'        => 'Enviado',
    'delivered'      => 'Entregado',
    'cancelled'      => 'Cancelado',
    'payment_failed' => 'Pago fallido',
];

$pageTitle = 'Seguimiento de pedido';
require dirname(__DIR__) . '/pages/layout-header.php';
?>

<div class="container py-8" style="max-width:600px">
  <h1 style="font-size:1.875rem;font-weight:700;margin-bottom:2rem">Seguimiento de pedido</h1>

  <?php if (!$order): ?>
  <form method="POST" style="background:var(--color-gray-50);padding:2rem;border-radius:8px">
    <?php if ($error): ?><p class="text-red" style="margin-bottom:1rem"><?= e($error) ?></p><?php endif; ?>
    <div class="form-group">
      <label>Número de pedido</label>
      <input type="text" name="orderNumber" placeholder="260307ABC123" required>
    </div>
    <div class="form-group">
      <label>Email del pedido</label>
      <input type="email" name="email" required>
    </div>
    <button type="submit" class="btn btn-black" style="width:100%">Buscar pedido</button>
  </form>
  <?php else: ?>
  <div style="border:1px solid var(--color-gray-200);border-radius:8px;overflow:hidden">
    <div style="padding:1.5rem;background:var(--color-gray-50)">
      <div style="display:flex;justify-content:space-between;align-items:start">
        <div>
          <p style="font-size:0.875rem;color:var(--color-gray-500)">Pedido</p>
          <p style="font-weight:700;font-size:1.125rem">#<?= e($order['orderNumber']) ?></p>
        </div>
        <span style="background:var(--c-primary);color:var(--c-text-on-primary);padding:0.25rem 0.75rem;border-radius:0;font-size:0.875rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em">
          <?= e($statusLabels[$order['status']] ?? $order['status']) ?>
        </span>
      </div>
      <p style="font-size:0.875rem;color:var(--color-gray-500);margin-top:0.5rem"><?= date('d/m/Y H:i', strtotime($order['createdAt'])) ?></p>
    </div>
    <div style="padding:1.5rem">
      <?php foreach ($orderItems as $item): ?>
      <div style="display:flex;justify-content:space-between;margin-bottom:0.75rem;font-size:0.9rem">
        <span><?= e($item['productName']) ?> × <?= $item['quantity'] ?></span>
        <span><?= number_format($item['price'] * $item['quantity'], 2) ?> €</span>
      </div>
      <?php endforeach; ?>
      <hr style="margin:1rem 0;border:none;border-top:1px solid var(--color-gray-200)">
      <div style="display:flex;justify-content:space-between;font-weight:700">
        <span>Total</span><span><?= number_format($order['totalAmount'], 2) ?> €</span>
      </div>
    </div>
    <?php if ($order['shippingAddress']): ?>
    <div style="padding:1.5rem;border-top:1px solid var(--color-gray-200)">
      <p style="font-size:0.875rem;color:var(--color-gray-500);margin-bottom:0.25rem">Dirección de envío</p>
      <p style="font-size:0.9rem"><?= e($order['shippingAddress']) ?></p>
    </div>
    <?php endif; ?>
  </div>
  <p style="margin-top:1rem;font-size:0.875rem"><a href="/track-order">Buscar otro pedido</a></p>
  <?php endif; ?>
</div>

<?php require dirname(__DIR__) . '/pages/layout-footer.php'; ?>
