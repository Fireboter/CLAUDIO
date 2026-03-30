<?php
$c = $collection ?? [];
$isEdit = !empty($c);
?>
<form id="col-form" class="admin-form" style="max-width:600px">
  <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'create' ?>">
  <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $c['id'] ?>"><?php endif; ?>

  <div class="form-group">
    <label>Nombre *</label>
    <input type="text" name="name" value="<?= e($c['name'] ?? '') ?>" required>
  </div>
  <div class="form-group">
    <label>Grupo</label>
    <select name="groupId">
      <option value="">Sin grupo</option>
      <?php foreach ($groups as $g): ?>
        <option value="<?= $g['id'] ?>" <?= ($c['groupId'] ?? '') == $g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label>Imagen</label>
    <?php if (!empty($c['image'])): ?><img src="<?= e($c['image']) ?>" id="img-preview" style="width:120px;height:160px;object-fit:cover;border-radius:4px;margin-bottom:0.5rem;display:block"><?php endif; ?>
    <input type="file" name="image" accept="image/*" data-preview="img-preview">
  </div>
  <div style="display:flex;gap:0.75rem;margin-top:1rem">
    <button type="button" onclick="saveCollection()" class="btn btn-primary">Guardar</button>
  </div>
  <p id="col-msg" style="margin-top:0.75rem;font-size:0.875rem"></p>
</form>

<script>
async function saveCollection() {
  const form = document.getElementById('col-form');
  const msg = document.getElementById('col-msg');
  const btn = form.querySelector('button');
  btn.disabled = true; btn.textContent = 'Guardando...';

  // Upload image if selected
  let imageUrl = null;
  const fileInput = form.querySelector('input[type="file"]');
  if (fileInput.files[0]) {
    const fd = new FormData(); fd.append('image', fileInput.files[0]);
    const r = await fetch('/admin/api/collections.php?action=upload-image', { method:'POST', body: fd });
    const d = await r.json();
    if (d.url) imageUrl = d.url;
  }

  const fd2 = new FormData(form);
  const data = Object.fromEntries(fd2.entries());
  if (imageUrl) data.image = imageUrl;

  const res = await fetch('/admin/api/collections.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(data) }).then(r=>r.json());
  if (res.success) {
    msg.textContent = 'Guardado.'; msg.style.color = 'var(--color-green)';
    if (res.id && data.action === 'create') setTimeout(() => location.href = '/admin/collections/' + res.id + '/edit', 700);
  } else { msg.textContent = res.message || 'Error.'; msg.style.color = 'var(--color-red)'; }
  btn.disabled = false; btn.textContent = 'Guardar';
}
</script>
