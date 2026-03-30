<?php
$pageTitle = 'Colecciones';
$collections = db_query('SELECT c.*, g.name as groupName, COUNT(p.id) as productCount FROM Collection c LEFT JOIN CollectionGroup g ON c.groupId = g.id LEFT JOIN Product p ON c.id = p.collectionId GROUP BY c.id ORDER BY g.name, c.name');
require __DIR__ . '/layout-header.php';
?>

<div class="page-header">
  <h1>Colecciones</h1>
  <a href="/admin/collections/new" class="btn btn-primary">+ Nueva colección</a>
</div>

<table class="admin-table">
  <thead><tr><th>Imagen</th><th>Nombre</th><th>Grupo</th><th>Productos</th><th>Acciones</th></tr></thead>
  <tbody>
    <?php foreach ($collections as $c): ?>
    <tr>
      <td><?php if($c['image']): ?><img src="<?= e($c['image']) ?>" style="width:48px;height:48px;object-fit:cover;border-radius:4px"><?php else: ?><div style="width:48px;height:48px;background:var(--color-gray-100);border-radius:4px"></div><?php endif; ?></td>
      <td><a href="/admin/collections/<?= $c['id'] ?>/edit" style="font-weight:500"><?= e($c['name']) ?></a></td>
      <td style="color:var(--color-gray-500)"><?= e($c['groupName'] ?? '—') ?></td>
      <td><?= $c['productCount'] ?></td>
      <td>
        <a href="/admin/collections/<?= $c['id'] ?>/edit" class="btn btn-secondary btn-sm">Editar</a>
        <button onclick="delCollection(<?= $c['id'] ?>, '<?= e(addslashes($c['name'])) ?>')" class="btn btn-danger btn-sm">Eliminar</button>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<script>
function delCollection(id, name) {
  if (!confirm('¿Eliminar colección "' + name + '"? Los productos quedarán sin colección.')) return;
  fetch('/admin/api/collections.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'delete', id}) })
    .then(r=>r.json()).then(d=>{ if(d.success) location.reload(); else alert(d.message); });
}
</script>

<?php require __DIR__ . '/layout-footer.php'; ?>
