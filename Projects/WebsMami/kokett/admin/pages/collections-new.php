<?php
$pageTitle = 'Nueva Colección';
$groups = db_query('SELECT * FROM CollectionGroup ORDER BY name');
require __DIR__ . '/layout-header.php';
?>

<div class="page-header">
  <h1>Nueva Colección</h1>
  <a href="/admin/collections" class="btn btn-secondary">← Volver</a>
</div>

<?php require __DIR__ . '/collection-form.php'; ?>
<?php require __DIR__ . '/layout-footer.php'; ?>
