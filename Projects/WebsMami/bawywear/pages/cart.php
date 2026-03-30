<?php
$cartItems = array_values(cart_get());
$cartTotal = cart_total();
$expiresAt = cart_get_expiry();
$pageTitle = 'Cesta';

require dirname(__DIR__) . '/pages/layout-header.php';
?>

<div class="container py-8" style="max-width:800px">
  <h1 style="font-size:1.875rem;font-weight:700;margin-bottom:2rem">Tu cesta</h1>

  <?php if (empty($cartItems)): ?>
    <div style="text-align:center;padding:4rem 0">
      <p style="color:var(--color-gray-500);margin-bottom:1.5rem">La cesta está vacía.</p>
      <a href="/shop" class="btn btn-black">Ir a la tienda</a>
    </div>
  <?php else: ?>
    <?php if ($expiresAt): ?>
    <div id="cart-timer" style="background:var(--color-gray-100);padding:0.75rem 1rem;border-radius:4px;margin-bottom:1.5rem;font-size:0.875rem">
      Tu reserva expira en: <strong id="timer-countdown"></strong>
    </div>
    <?php endif; ?>

    <div style="border:1px solid var(--color-gray-200);border-radius:8px;overflow:hidden;margin-bottom:2rem">
      <?php foreach ($cartItems as $item): ?>
      <div class="cart-item" data-variant-id="<?= (int)$item['variantId'] ?>">
        <?php if ($item['image']): ?>
          <img src="<?= e($item['image']) ?>" alt="<?= e($item['name']) ?>" style="width:80px;height:80px;object-fit:cover;border-radius:4px;flex-shrink:0">
        <?php else: ?>
          <div style="width:80px;height:80px;background:var(--color-gray-100);border-radius:4px;flex-shrink:0"></div>
        <?php endif; ?>
        <div style="flex:1;min-width:0">
          <h3 style="font-weight:500;margin-bottom:0.25rem"><?= e($item['name']) ?></h3>
          <p style="color:var(--color-gray-500);font-size:0.875rem;margin-bottom:0.25rem">
            <?= implode(' / ', array_filter([e($item['color'] ?? ''), e($item['size'] ?? '')])) ?>
          </p>
          <p style="font-weight:600"><?= number_format($item['price'] * $item['quantity'], 2) ?> €
            <?php if ($item['quantity'] > 1): ?><span style="color:var(--color-gray-500);font-weight:400;font-size:0.875rem"> (<?= $item['quantity'] ?> × <?= number_format($item['price'], 2) ?> €)</span><?php endif; ?>
          </p>
        </div>
        <div style="display:flex;align-items:center;flex-shrink:0">
          <button onclick="removeFromCart(<?= (int)$item['variantId'] ?>)" style="border:none;background:none;color:var(--color-gray-500);cursor:pointer;font-size:1.5rem;line-height:1;padding:0.25rem">&times;</button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center">
      <p style="font-size:1.125rem">Total: <strong><?= number_format($cartTotal, 2) ?> €</strong></p>
      <a href="/checkout" class="btn btn-black" style="padding:0.875rem 2rem">Tramitar pedido</a>
    </div>
  <?php endif; ?>
</div>

<script>
function removeFromCart(variantId) {
  if (!confirm('¿Eliminar este producto?')) return;
  fetch('/api/cart.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'remove', variantId })
  }).then(() => location.reload());
}

<?php if ($expiresAt): ?>
const expiresAt = new Date('<?= $expiresAt ?>').getTime();
function updateTimer() {
  const diff = Math.max(0, expiresAt - Date.now());
  const mins = Math.floor(diff / 60000);
  const secs = Math.floor((diff % 60000) / 1000);
  const el = document.getElementById('timer-countdown');
  if (el) el.textContent = `${mins}:${secs.toString().padStart(2, '0')}`;
  if (diff === 0) {
    const timerEl = document.getElementById('cart-timer');
    if (timerEl) timerEl.textContent = 'La reserva ha expirado. Recarga la página.';
  }
}
updateTimer();
setInterval(updateTimer, 1000);
<?php endif; ?>
</script>

<?php require dirname(__DIR__) . '/pages/layout-footer.php'; ?>
