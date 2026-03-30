<?php
$pageTitle = 'Editar Producto';
$products = db_query('SELECT * FROM Product WHERE id = ?', [$editId]);
if (empty($products)) { http_response_code(404); echo '404'; exit; }
$product = $products[0];
$collections = db_query('SELECT id, name FROM Collection ORDER BY name');
$variants = db_query('SELECT * FROM ProductVariant WHERE productId = ?', [$editId]);
$productImages = db_query('SELECT * FROM ProductImage WHERE productId = ? ORDER BY displayOrder', [$editId]);
$isNovedades = !empty(db_query('SELECT 1 FROM ProductNovedades WHERE productId = ?', [$editId]));
require __DIR__ . '/layout-header.php';
?>

<div class="page-header">
  <h1>Editar: <?= e($product['name']) ?></h1>
  <a href="/admin/products" class="btn btn-secondary">← Volver</a>
</div>

<?php require __DIR__ . '/product-form.php'; ?>

<?php require __DIR__ . '/layout-footer.php'; ?>
