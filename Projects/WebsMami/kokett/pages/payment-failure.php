<?php
$orderNumber = $_GET['order'] ?? '';
$pageTitle = 'Pago no procesado';
require dirname(__DIR__) . '/pages/layout-header.php';
?>
<div class="container py-8 text-center" style="max-width:600px;margin:4rem auto">
  <div style="font-size:3rem;margin-bottom:1rem">✕</div>
  <h1 style="font-size:2rem;font-weight:700;margin-bottom:1rem;color:var(--color-red)">Pago no procesado</h1>
  <p style="color:var(--color-gray-700);margin-bottom:1.5rem">El pago no pudo completarse. Tu carrito sigue guardado.</p>
  <?php if ($orderNumber): ?>
    <p style="color:var(--color-gray-500);margin-bottom:1.5rem;font-size:0.875rem">Referencia: <?= e($orderNumber) ?></p>
  <?php endif; ?>
  <div style="display:flex;gap:1rem;justify-content:center">
    <a href="/cart" class="btn btn-black">Volver a la cesta</a>
    <a href="/pages/contact" class="btn btn-outline">Contactar</a>
  </div>
</div>
<?php require dirname(__DIR__) . '/pages/layout-footer.php'; ?>
