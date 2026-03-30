<?php
$pageTitle = 'Editar Colección';
$cols = db_query('SELECT * FROM Collection WHERE id = ?', [$editId]);
if (empty($cols)) { http_response_code(404); echo '404'; exit; }
$collection = $cols[0];
$groups = db_query('SELECT * FROM CollectionGroup ORDER BY name');
require __DIR__ . '/layout-header.php';
?>

<div class="page-header">
  <h1>Editar: <?= e($collection['name']) ?></h1>
  <a href="/admin/collections" class="btn btn-secondary">← Volver</a>
</div>

<?php require __DIR__ . '/collection-form.php'; ?>
<?php require __DIR__ . '/layout-footer.php'; ?>
