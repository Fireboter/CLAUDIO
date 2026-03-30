<?php
$pageTitle = 'Grupos';
$groups = db_query('SELECT g.*, COUNT(c.id) as collectionCount FROM CollectionGroup g LEFT JOIN Collection c ON g.id = c.groupId GROUP BY g.id ORDER BY g.name');
require __DIR__ . '/layout-header.php';
?>

<div class="page-header">
  <h1>Grupos de colecciones</h1>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:2rem;align-items:start">
  <!-- Create group form -->
  <div class="admin-form">
    <h2 style="font-weight:600;margin-bottom:1rem;font-size:1rem">Nuevo grupo</h2>
    <div class="form-group">
      <label>Nombre del grupo</label>
      <input type="text" id="group-name" placeholder="Ej: Temporada, Tipo...">
    </div>
    <button onclick="createGroup()" class="btn btn-primary">Crear grupo</button>
    <p id="group-msg" style="margin-top:0.5rem;font-size:0.875rem"></p>
  </div>

  <!-- Groups list -->
  <table class="admin-table">
    <thead><tr><th>Nombre</th><th>Colecciones</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($groups as $g): ?>
      <tr>
        <td><?= e($g['name']) ?></td>
        <td><?= $g['collectionCount'] ?></td>
        <td>
          <?php if ($g['collectionCount'] == 0): ?>
            <button onclick="delGroup(<?= $g['id'] ?>, '<?= e(addslashes($g['name'])) ?>')" class="btn btn-danger btn-sm">Eliminar</button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
function createGroup() {
  const name = document.getElementById('group-name').value.trim();
  if (!name) return;
  fetch('/admin/api/groups.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'create', name}) })
    .then(r=>r.json()).then(d=>{ if(d.success) location.reload(); else alert(d.message); });
}
function delGroup(id, name) {
  if (!confirm('¿Eliminar grupo "' + name + '"?')) return;
  fetch('/admin/api/groups.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'delete', id}) })
    .then(r=>r.json()).then(d=>{ if(d.success) location.reload(); else alert(d.message); });
}
</script>

<?php require __DIR__ . '/layout-footer.php'; ?>
