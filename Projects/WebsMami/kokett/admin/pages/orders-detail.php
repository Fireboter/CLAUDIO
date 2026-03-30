<?php
$orders = db_query("SELECT * FROM `Order` WHERE id = ?", [$orderId]);
if (empty($orders)) { http_response_code(404); echo '404'; exit; }
$order = $orders[0];
$orderItems = db_query('SELECT oi.*, p.name as productName, p.images FROM OrderItem oi JOIN Product p ON oi.productId = p.id WHERE oi.orderId = ?', [$order['id']]);
$pageTitle = 'Pedido #' . $order['orderNumber'];

$statuses = ['pending','processing','shipped','delivered','cancelled','payment_failed'];
$statusLabels = ['pending'=>'Pendiente','processing'=>'En preparación','shipped'=>'Enviado','delivered'=>'Entregado','cancelled'=>'Cancelado','payment_failed'=>'Pago fallido'];

require __DIR__ . '/layout-header.php';
?>

<div class="page-header">
  <h1>Pedido #<?= e($order['orderNumber']) ?></h1>
  <div style="display:flex;gap:0.5rem">
    <a href="/admin/orders" class="btn btn-secondary">← Pedidos</a>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 340px;gap:2rem;align-items:start">
  <!-- Order items -->
  <div>
    <div class="admin-form" style="margin-bottom:1.5rem">
      <h2 style="font-weight:600;margin-bottom:1rem;font-size:1rem">Productos</h2>
      <?php foreach ($orderItems as $item): ?>
      <?php $imgs = json_decode($item['images'] ?? '[]', true) ?? []; $img = $imgs[0] ?? null; ?>
      <div style="display:flex;gap:1rem;margin-bottom:0.75rem;align-items:center">
        <?php if($img): ?><img src="<?= e($img) ?>" style="width:56px;height:56px;object-fit:cover;border-radius:4px;flex-shrink:0"><?php endif; ?>
        <div style="flex:1">
          <p style="font-weight:500"><?= e($item['productName']) ?></p>
          <p style="font-size:0.875rem;color:var(--color-gray-500)"><?= $item['quantity'] ?> × <?= number_format((float)$item['price'], 2) ?> €</p>
        </div>
        <p style="font-weight:600"><?= number_format($item['price'] * $item['quantity'], 2) ?> €</p>
      </div>
      <?php endforeach; ?>
      <hr style="margin:1rem 0;border:none;border-top:1px solid var(--color-gray-200)">
      <?php if ((float)$order['shippingCost'] > 0): ?>
      <div style="display:flex;justify-content:space-between;margin-bottom:0.5rem;font-size:0.9rem">
        <span>Envío (<?= e($order['shippingMethod']) ?>)</span><span><?= number_format((float)$order['shippingCost'], 2) ?> €</span>
      </div>
      <?php endif; ?>
      <div style="display:flex;justify-content:space-between;font-weight:700">
        <span>Total</span><span><?= number_format((float)$order['totalAmount'], 2) ?> €</span>
      </div>
    </div>
  </div>

  <!-- Order info -->
  <div>
    <div class="admin-form" style="margin-bottom:1rem">
      <h2 style="font-weight:600;margin-bottom:1rem;font-size:1rem">Cliente</h2>
      <p style="font-weight:500"><?= e($order['customerName']) ?></p>
      <p style="color:var(--color-gray-500);font-size:0.875rem"><?= e($order['customerEmail']) ?></p>
      <p style="color:var(--color-gray-500);font-size:0.875rem"><?= e($order['customerPhone'] ?? '') ?></p>
      <?php if ($order['shippingAddress']): ?>
      <p style="margin-top:0.75rem;font-size:0.875rem"><?= e($order['shippingAddress']) ?></p>
      <?php endif; ?>
    </div>

    <div class="admin-form">
      <h2 style="font-weight:600;margin-bottom:1rem;font-size:1rem">Estado</h2>
      <p style="margin-bottom:0.75rem">Actual: <span class="badge badge-<?= e($order['status']) ?>"><?= e($statusLabels[$order['status']] ?? $order['status']) ?></span></p>
      <select id="status-select" style="width:100%;padding:0.5rem;border:1px solid var(--color-gray-300);border-radius:4px;margin-bottom:0.75rem;font-family:inherit">
        <?php foreach ($statuses as $s): ?>
          <option value="<?= $s ?>" <?= $order['status'] === $s ? 'selected' : '' ?>><?= e($statusLabels[$s] ?? $s) ?></option>
        <?php endforeach; ?>
      </select>
      <button onclick="updateStatus()" class="btn btn-primary" style="width:100%">Actualizar estado</button>
      <p id="status-msg" style="margin-top:0.5rem;font-size:0.875rem"></p>
    </div>
  </div>
</div>

<script>
function updateStatus() {
  const status = document.getElementById('status-select').value;
  fetch('/admin/api/orders.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'update-status', orderId: <?= $order['id'] ?>, status}) })
    .then(r=>r.json()).then(d=>{
      const msg = document.getElementById('status-msg');
      msg.textContent = d.success ? 'Estado actualizado.' : (d.message || 'Error.');
      msg.style.color = d.success ? 'var(--color-green)' : 'var(--color-red)';
    });
}
</script>

<?php require __DIR__ . '/layout-footer.php'; ?>
