<?php
$giftCollection = db_query("SELECT id FROM Collection WHERE name = 'Tarjeta Regalo'");
$giftProducts = [];
if (!empty($giftCollection)) {
    $giftProducts = db_query('SELECT id, name, price, images FROM Product WHERE collectionId = ? ORDER BY price', [$giftCollection[0]['id']]);
}
$pageTitle = 'Tarjeta Regalo';
require dirname(__DIR__) . '/pages/layout-header.php';
?>

<div class="container py-8" style="max-width:900px">
  <h1 style="font-size:2rem;font-weight:700;text-align:center;margin-bottom:0.75rem">Tarjeta Regalo</h1>
  <p style="text-align:center;color:var(--color-gray-500);margin-bottom:3rem">El regalo perfecto para quien lo tiene todo.</p>

  <?php if (empty($giftProducts)): ?>
    <p style="text-align:center;color:var(--color-gray-500)">No hay tarjetas regalo disponibles.</p>
  <?php else: ?>
    <div class="product-grid">
      <?php foreach ($giftProducts as $p): ?>
        <?php
        $images = json_decode($p['images'] ?? '[]', true) ?? [];
        $img = $images[0] ?? null;
        ?>
        <a href="/product/<?= $p['id'] ?>" class="product-card">
          <?php if ($img): ?>
            <img src="<?= e($img) ?>" alt="<?= e($p['name']) ?>">
          <?php else: ?>
            <div style="aspect-ratio:3/4;background:var(--color-gray-100);display:flex;align-items:center;justify-content:center;font-size:3rem">🎁</div>
          <?php endif; ?>
          <div class="product-card-body">
            <h3><?= e($p['name']) ?></h3>
            <div class="product-price"><?= number_format($p['price'], 2) ?> €</div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div style="background:var(--color-gray-50);padding:2rem;border-radius:8px;margin-top:3rem;text-align:center">
    <h2 style="font-weight:700;margin-bottom:0.75rem">¿Cómo funciona?</h2>
    <p style="color:var(--color-gray-700);margin-bottom:0.5rem">1. Compra una tarjeta regalo.</p>
    <p style="color:var(--color-gray-700);margin-bottom:0.5rem">2. Recibirás un código único por email.</p>
    <p style="color:var(--color-gray-700)">3. Usa el código en el checkout para aplicar el descuento. Válido 3 meses.</p>
  </div>
</div>

<?php require dirname(__DIR__) . '/pages/layout-footer.php'; ?>
