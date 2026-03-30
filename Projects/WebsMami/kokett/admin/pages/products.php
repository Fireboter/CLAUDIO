<?php
$pageTitle = 'Productos';

// Data
$allGroups      = db_query('SELECT * FROM CollectionGroup ORDER BY name');
$allCollections = db_query('SELECT * FROM Collection ORDER BY name');

$colsByGroup = [];
foreach ($allCollections as $c) {
    $colsByGroup[(int)($c['groupId'] ?? 0)][] = $c;
}

$allProducts = db_query("
    SELECT p.*,
        COALESCE(
            (SELECT url FROM ProductImage WHERE productId = p.id AND url REGEXP 'p_[^/]+$' ORDER BY displayOrder ASC LIMIT 1),
            JSON_UNQUOTE(JSON_EXTRACT(p.images, '\$[0]'))
        ) as firstImage,
        IF(pn.productId IS NOT NULL, 1, 0) as isNovedades
    FROM Product p
    LEFT JOIN ProductNovedades pn ON pn.productId = p.id
    ORDER BY p.name ASC
");

$prodsByCollection = [];
$novedadesProds    = [];
$uncategorized     = [];
foreach ($allProducts as $p) {
    if ($p['isNovedades']) $novedadesProds[] = $p;
    if ($p['collectionId']) {
        $prodsByCollection[(int)$p['collectionId']][] = $p;
    } else {
        $uncategorized[] = $p;
    }
}

require __DIR__ . '/layout-header.php';
?>

<div class="page-header">
  <h1>Productos</h1>
  <div style="display:flex;gap:0.5rem;align-items:center">
    <div style="display:flex;border:1px solid var(--color-gray-300);border-radius:4px;overflow:hidden">
      <button id="btn-board" onclick="switchView('board')" class="btn btn-sm" style="border-radius:0;border:none">Tablero</button>
      <button id="btn-list"  onclick="switchView('list')"  class="btn btn-sm" style="border-radius:0;border:none;border-left:1px solid var(--color-gray-300)">Lista</button>
    </div>
    <div style="position:relative">
      <button onclick="toggleAddMenu(event)" class="btn btn-primary">+ Añadir ▾</button>
      <div id="add-menu" style="display:none;position:absolute;right:0;top:calc(100% + 4px);background:#fff;border:1px solid var(--color-gray-200);border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,0.12);min-width:200px;z-index:500;overflow:hidden">
        <a href="/admin/products/new" style="display:flex;align-items:center;gap:0.5rem;padding:0.625rem 1rem;font-size:0.875rem;border-bottom:1px solid var(--color-gray-100)">📦 Nuevo producto</a>
        <a href="/admin/collections/new" style="display:flex;align-items:center;gap:0.5rem;padding:0.625rem 1rem;font-size:0.875rem;border-bottom:1px solid var(--color-gray-100)">🗂 Nueva colección</a>
        <button onclick="showGroupModal(null,null)" style="width:100%;display:flex;align-items:center;gap:0.5rem;padding:0.625rem 1rem;font-size:0.875rem;border:none;background:none;cursor:pointer;font-family:inherit">🏷 Nuevo grupo</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ BOARD VIEW ═══ -->
<div id="board-view">
  <div class="board-wrap">

    <div class="board-group">
      <div class="board-group-title">⭐ Novedades</div>
      <div class="board-col" ondragover="allowDrop(event)" ondragleave="leaveDrop(event)" ondrop="dropTo(event,null,'nov_add')">
        <div class="col-title"><span class="col-count"><?= count($novedadesProds) ?></span></div>
        <div class="col-cards">
          <?php foreach ($novedadesProds as $p): renderCard($p, 'nov'); endforeach; ?>
        </div>
      </div>
    </div>

    <?php foreach ($allGroups as $group):
      $cols = $colsByGroup[(int)$group['id']] ?? [];
    ?>
    <div class="board-group">
      <div class="board-group-title">
        <span><?= e($group['name']) ?></span>
        <div style="margin-left:auto;display:flex;gap:0.25rem">
          <button onclick="showGroupModal(<?= (int)$group['id'] ?>,'<?= e(addslashes($group['name'])) ?>')" style="background:none;border:none;cursor:pointer;color:var(--color-gray-400);font-size:0.85rem;line-height:1;padding:2px" title="Editar grupo">✎</button>
          <button onclick="delGroup(<?= (int)$group['id'] ?>,'<?= e(addslashes($group['name'])) ?>')" style="background:none;border:none;cursor:pointer;color:var(--color-gray-400);font-size:1rem;line-height:1;padding:2px" title="Eliminar grupo">×</button>
        </div>
      </div>
      <?php if (empty($cols)): ?>
      <div class="board-col" style="min-height:60px;display:flex;align-items:center;justify-content:center">
        <a href="/admin/collections/new" style="font-size:0.75rem;color:var(--color-gray-400)">+ Colección</a>
      </div>
      <?php else: ?>
      <?php foreach ($cols as $col): ?>
      <div class="board-col" ondragover="allowDrop(event)" ondragleave="leaveDrop(event)" ondrop="dropTo(event,<?= (int)$col['id'] ?>,'col')">
        <div class="col-title">
          <?= e($col['name']) ?>
          <span class="col-count"><?= count($prodsByCollection[(int)$col['id']] ?? []) ?></span>
          <a href="/admin/collections/<?= (int)$col['id'] ?>/edit" class="col-edit-link" title="Editar">✎</a>
        </div>
        <div class="col-cards">
          <?php foreach ($prodsByCollection[(int)$col['id']] ?? [] as $p): renderCard($p, 'col_'.(int)$col['id']); endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($uncategorized)): ?>
    <div class="board-group">
      <div class="board-group-title" style="color:var(--color-gray-500)">Sin colección</div>
      <div class="board-col" ondragover="allowDrop(event)" ondragleave="leaveDrop(event)" ondrop="dropTo(event,null,'col')">
        <div class="col-cards">
          <?php foreach ($uncategorized as $p): renderCard($p, 'none'); endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- ═══ LIST VIEW ═══ -->
<div id="list-view" style="display:none">
  <form method="GET" style="margin-bottom:1rem">
    <input type="hidden" name="view" value="list">
    <input type="text" name="search" value="<?= e($_GET['search'] ?? '') ?>" placeholder="Buscar productos..." style="padding:0.5rem 0.75rem;border:1px solid var(--color-gray-300);border-radius:4px;font-size:0.9rem;width:300px">
  </form>
  <?php
  $search  = trim($_GET['search'] ?? '');
  $lParams = [];
  $lWhere  = '1=1';
  if ($search) { $lWhere .= ' AND p.name LIKE ?'; $lParams[] = "%$search%"; }
  $listProds = db_query("SELECT p.*, c.name as collectionName, COUNT(v.id) as variantCount,
      COALESCE((SELECT url FROM ProductImage WHERE productId = p.id AND url REGEXP 'p_[^/]+$' ORDER BY displayOrder ASC LIMIT 1), JSON_UNQUOTE(JSON_EXTRACT(p.images, '\$[0]'))) as firstImage
      FROM Product p LEFT JOIN Collection c ON p.collectionId = c.id LEFT JOIN ProductVariant v ON p.id = v.productId
      WHERE $lWhere GROUP BY p.id ORDER BY p.id DESC", $lParams);
  ?>
  <table class="admin-table">
    <thead><tr><th>Imagen</th><th>Nombre</th><th>Colección</th><th>Precio</th><th>Variantes</th><th>Acciones</th></tr></thead>
    <tbody>
      <?php foreach ($listProds as $p): ?>
      <tr>
        <td><?php if ($p['firstImage']): ?><img src="<?= e($p['firstImage']) ?>" style="width:48px;height:48px;object-fit:cover;border-radius:4px"><?php else: ?><div style="width:48px;height:48px;background:var(--color-gray-100);border-radius:4px"></div><?php endif; ?></td>
        <td><a href="/admin/products/<?= $p['id'] ?>/edit" style="font-weight:500"><?= e($p['name']) ?></a></td>
        <td style="color:var(--color-gray-500)"><?= e($p['collectionName'] ?? '—') ?></td>
        <td><?php if ($p['discount'] && (float)$p['discount'] < (float)$p['price']): ?><span style="text-decoration:line-through;color:var(--color-gray-500);font-size:0.8rem"><?= number_format((float)$p['price'],2) ?></span> <strong style="color:var(--color-red)"><?= number_format((float)$p['discount'],2) ?> €</strong><?php else: ?><?= number_format((float)$p['price'],2) ?> €<?php endif; ?></td>
        <td><?= (int)$p['variantCount'] ?></td>
        <td>
          <a href="/admin/products/<?= $p['id'] ?>/edit" class="btn btn-secondary btn-sm">Editar</a>
          <button onclick="confirmDelete('¿Eliminar <?= e(addslashes($p['name'])) ?>?', <?= $p['id'] ?>)" class="btn btn-danger btn-sm">Eliminar</button>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php
function renderCard($p, $src) { $isNov = ($src === 'nov'); ?>
<div class="product-card" draggable="true"
     ondragstart="dragStart(event,<?= (int)$p['id'] ?>,'<?= htmlspecialchars($src, ENT_QUOTES) ?>')"
     ondragend="this.classList.remove('dragging')" data-id="<?= (int)$p['id'] ?>">
  <input type="checkbox" class="card-check" onclick="toggleSelect(event,<?= (int)$p['id'] ?>)" title="Seleccionar">
  <?php if (!empty($p['firstImage'])): ?>
    <img src="<?= e($p['firstImage']) ?>" alt="">
  <?php else: ?>
    <div class="card-no-img"></div>
  <?php endif; ?>
  <div class="card-info">
    <div class="card-name"><?= e($p['name']) ?></div>
    <div class="card-price"><?= number_format((float)$p['price'],2) ?> €<?= $p['isNovedades'] ? ' <span title="Novedades" style="color:#f59e0b">★</span>' : '' ?></div>
  </div>
  <?php if ($isNov): ?>
    <button class="card-nov-remove" onclick="removeNov(event,<?= (int)$p['id'] ?>)" title="Quitar de Novedades">✕</button>
  <?php else: ?>
    <a href="/admin/products/<?= (int)$p['id'] ?>/edit" class="card-edit-btn">✎</a>
  <?php endif; ?>
</div>
<?php }
?>

<div id="bulk-bar" style="display:none">
  <span id="bulk-count">0 seleccionados</span>
  <button onclick="bulkNovedades(true)">★ Añadir a Novedades</button>
  <button onclick="bulkNovedades(false)">✕ Quitar de Novedades</button>
  <button onclick="bulkMove()">↗ Mover a colección</button>
  <button class="danger" onclick="bulkDelete()">🗑 Eliminar</button>
  <button onclick="clearSelection()">Cancelar</button>
</div>

<!-- Bulk move modal -->
<div id="move-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:2000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:10px;padding:1.5rem;min-width:300px;max-width:400px">
    <h3 style="margin:0 0 1rem;font-size:1rem">Mover a colección</h3>
    <select id="move-col-select" style="width:100%;padding:0.5rem;border:1px solid var(--color-gray-300);border-radius:4px;margin-bottom:1rem">
      <option value="">— Sin colección —</option>
      <?php foreach ($allCollections as $c): ?>
      <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <div style="display:flex;gap:0.5rem;justify-content:flex-end">
      <button class="btn btn-secondary btn-sm" onclick="document.getElementById('move-modal').style.display='none'">Cancelar</button>
      <button class="btn btn-primary btn-sm" onclick="confirmMove()">Mover</button>
    </div>
  </div>
</div>

<!-- Group create/edit modal -->
<div id="group-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:2000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:10px;padding:1.5rem;min-width:300px;max-width:400px">
    <h3 id="group-modal-title" style="margin:0 0 1rem;font-size:1rem">Nuevo grupo</h3>
    <input id="group-modal-input" type="text" placeholder="Nombre del grupo" style="width:100%;padding:0.5rem 0.75rem;border:1px solid var(--color-gray-300);border-radius:4px;font-size:0.9rem;font-family:inherit">
    <div style="display:flex;gap:0.5rem;justify-content:flex-end;margin-top:1rem">
      <button class="btn btn-secondary btn-sm" onclick="closeGroupModal()">Cancelar</button>
      <button class="btn btn-primary btn-sm" onclick="saveGroup()">Guardar</button>
    </div>
  </div>
</div>

<script>
let dragId = null, dragSrc = null;
let selectedIds = new Set();
let editingGroupId = null;

// ── Add menu ─────────────────────────────────────────────────────────────────
function toggleAddMenu(e) {
  e.stopPropagation();
  const m = document.getElementById('add-menu');
  m.style.display = m.style.display === 'none' ? 'block' : 'none';
}
document.addEventListener('click', () => {
  const m = document.getElementById('add-menu');
  if (m) m.style.display = 'none';
});

// ── Group modal ───────────────────────────────────────────────────────────────
function showGroupModal(id, name) {
  editingGroupId = id;
  document.getElementById('group-modal-title').textContent = id ? 'Editar grupo' : 'Nuevo grupo';
  document.getElementById('group-modal-input').value = name || '';
  document.getElementById('group-modal').style.display = 'flex';
  document.getElementById('add-menu').style.display = 'none';
  setTimeout(() => document.getElementById('group-modal-input').focus(), 50);
}
function closeGroupModal() {
  document.getElementById('group-modal').style.display = 'none';
}
function saveGroup() {
  const name = document.getElementById('group-modal-input').value.trim();
  if (!name) return;
  const payload = editingGroupId
    ? { action: 'update', id: editingGroupId, name }
    : { action: 'create', name };
  fetch('/admin/api/groups.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
  }).then(r => r.json()).then(d => { if (d.success) location.reload(); else alert(d.message || 'Error'); });
}
document.getElementById('group-modal-input').addEventListener('keydown', e => { if (e.key === 'Enter') saveGroup(); });

function delGroup(id, name) {
  if (!confirm('¿Eliminar grupo "' + name + '"?')) return;
  fetch('/admin/api/groups.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', id }) })
    .then(r => r.json()).then(d => { if (d.success) location.reload(); else alert(d.message || 'Error'); });
}

// ── Drag & drop ───────────────────────────────────────────────────────────────
function dragStart(e, id, src) {
  dragId = id; dragSrc = src;
  e.currentTarget.classList.add('dragging');
  e.dataTransfer.effectAllowed = 'move';
}
function allowDrop(e) {
  e.preventDefault();
  e.currentTarget.classList.add('drag-over');
}
function leaveDrop(e) {
  if (!e.currentTarget.contains(e.relatedTarget)) {
    e.currentTarget.classList.remove('drag-over');
  }
}
function dropTo(e, colId, type) {
  e.preventDefault();
  e.currentTarget.classList.remove('drag-over');
  if (!dragId) return;
  let body;
  if (type === 'nov_add') {
    body = { action: 'move', id: dragId, type: 'novedades', add: true };
  } else {
    body = { action: 'move', id: dragId, type: 'collection', collectionId: colId };
  }
  fetch('/admin/api/products.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
  }).then(r => r.json()).then(d => {
    if (d.success) location.reload(); else alert('Error al mover el producto');
  });
}
function removeNov(e, id) {
  e.stopPropagation(); e.preventDefault();
  fetch('/admin/api/products.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'move', id, type: 'novedades', add: false })
  }).then(r => r.json()).then(d => { if (d.success) location.reload(); });
}

// ── Selection ─────────────────────────────────────────────────────────────────
function toggleSelect(e, id) {
  e.stopPropagation();
  const card = e.target.closest('.product-card');
  if (e.target.checked) { selectedIds.add(id); card.classList.add('selected'); }
  else { selectedIds.delete(id); card.classList.remove('selected'); }
  updateBulkBar();
}
function updateBulkBar() {
  const bar = document.getElementById('bulk-bar');
  document.getElementById('bulk-count').textContent = selectedIds.size + ' seleccionado' + (selectedIds.size !== 1 ? 's' : '');
  bar.style.display = selectedIds.size > 0 ? 'flex' : 'none';
}
function clearSelection() {
  selectedIds.clear();
  document.querySelectorAll('.product-card.selected').forEach(c => {
    c.classList.remove('selected');
    c.querySelector('.card-check').checked = false;
  });
  updateBulkBar();
}
async function bulkNovedades(add) {
  for (const id of selectedIds) {
    await fetch('/admin/api/products.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'move', id, type: 'novedades', add })
    }).then(r => r.json());
  }
  location.reload();
}
function bulkMove() { document.getElementById('move-modal').style.display = 'flex'; }
async function confirmMove() {
  const colId = document.getElementById('move-col-select').value;
  for (const id of selectedIds) {
    await fetch('/admin/api/products.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'move', id, type: 'collection', collectionId: colId ? parseInt(colId) : null })
    }).then(r => r.json());
  }
  location.reload();
}
async function bulkDelete() {
  if (!confirm('¿Eliminar ' + selectedIds.size + ' producto(s)?')) return;
  for (const id of selectedIds) {
    await fetch('/admin/api/products.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'delete', id })
    }).then(r => r.json());
  }
  location.reload();
}
function confirmDelete(msg, id) {
  if (confirm(msg)) {
    fetch('/admin/api/products.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', id }) })
      .then(r => r.json()).then(d => { if (d.success) location.reload(); else alert(d.message || 'Error'); });
  }
}

// ── View toggle ───────────────────────────────────────────────────────────────
function switchView(v) {
  document.getElementById('board-view').style.display = v === 'board' ? '' : 'none';
  document.getElementById('list-view').style.display  = v === 'list'  ? '' : 'none';
  document.getElementById('btn-board').className = 'btn btn-sm ' + (v === 'board' ? 'btn-primary' : 'btn-secondary');
  document.getElementById('btn-list').className  = 'btn btn-sm ' + (v === 'list'  ? 'btn-primary' : 'btn-secondary');
  localStorage.setItem('adm_pv', v);
}
const initView = '<?= !empty($_GET['search']) ? 'list' : '' ?>' || localStorage.getItem('adm_pv') || 'board';
switchView(initView);
</script>

<?php require __DIR__ . '/layout-footer.php'; ?>
