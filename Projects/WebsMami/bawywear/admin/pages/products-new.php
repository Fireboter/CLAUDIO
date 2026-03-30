<?php
$pageTitle = 'Nuevo Producto';
$collections = db_query('SELECT id, name FROM Collection ORDER BY name');
require __DIR__ . '/layout-header.php';
?>

<div class="page-header">
  <h1>Nuevo Producto</h1>
  <a href="/admin/products" class="btn btn-secondary">← Volver</a>
</div>

<?php require __DIR__ . '/product-form.php'; ?>

<?php require __DIR__ . '/layout-footer.php'; ?>
