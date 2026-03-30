<?php
$orderNumber = $_GET['order'] ?? '';
$pageTitle = '¡Pedido confirmado!';
require dirname(__DIR__) . '/pages/layout-header.php';
?>
<div class="container py-8 text-center" style="max-width:600px;margin:4rem auto">
  <div style="font-size:3rem;margin-bottom:1rem">✓</div>
  <h1 style="font-size:2rem;font-weight:700;margin-bottom:1rem;color:var(--color-green)">¡Pedido confirmado!</h1>
  <?php if ($orderNumber): ?>
    <p style="color:var(--color-gray-500);margin-bottom:0.5rem">Número de pedido: <strong><?= e($orderNumber) ?></strong></p>
  <?php endif; ?>
  <p style="color:var(--color-gray-700);margin-bottom:2rem">Recibirás un email de confirmación en breve. Gracias por tu compra.</p>
  <a href="/shop" class="btn btn-black">Seguir comprando</a>
</div>
<?php require dirname(__DIR__) . '/pages/layout-footer.php'; ?>
