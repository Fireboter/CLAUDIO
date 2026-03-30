<?php
// Shared product form (new + edit)
$p = $product ?? [];
$existingVariants = $variants ?? [];
$existingImages   = $productImages ?? [];
$isEdit = !empty($p);

// Only keep admin-uploaded images (p_-prefixed filename); discard Vercel Blob / old-format entries
$existingImages = array_values(array_filter($existingImages, fn($img) => preg_match('#/p_[^/]+$#', $img['url'] ?? '')));
// Fall back to JSON images column if no admin-uploaded images
if ($isEdit && empty($existingImages) && !empty($p['images'])) {
    $jsonImgs = json_decode($p['images'], true) ?? [];
    foreach ($jsonImgs as $i => $url) {
        $existingImages[] = ['id' => null, 'url' => $url, 'displayOrder' => $i];
    }
}

// Decode customValues in variants
foreach ($existingVariants as &$v) {
    $cv = $v['customValues'] ?? null;
    $v['customValues'] = !empty($cv)
        ? (is_array($cv) ? $cv : (json_decode($cv, true) ?? []))
        : [];
}
unset($v);

// Extract existing option dimensions (customValues first, then columns as fallback)
$optionMap = []; // name → [value, ...]
foreach ($existingVariants as $v) {
    foreach ($v['customValues'] as $k => $val) {
        if (!isset($optionMap[$k])) $optionMap[$k] = [];
        if (!in_array($val, $optionMap[$k])) $optionMap[$k][] = $val;
    }
    foreach ([['Talla', 'size'], ['Color', 'color'], ['Material', 'material']] as [$dim, $col]) {
        if (!empty($v[$col])) {
            if (!isset($optionMap[$dim])) $optionMap[$dim] = [];
            if (!in_array($v[$col], $optionMap[$dim])) $optionMap[$dim][] = $v[$col];
        }
    }
}
$existingOptions = [];
foreach ($optionMap as $name => $vals) {
    $existingOptions[] = ['name' => $name, 'values' => $vals];
}
?>

<form id="product-form" class="admin-form" style="max-width:960px">
  <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'create' ?>">
  <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><?php endif; ?>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem">
    <!-- Left column -->
    <div>
      <div class="form-group">
        <label>Nombre *</label>
        <input type="text" name="name" value="<?= e($p['name'] ?? '') ?>" required>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
        <div class="form-group">
          <label>Precio (€) *</label>
          <input type="number" name="price" step="0.01" min="0" value="<?= $p['price'] ?? '' ?>" required>
        </div>
        <div class="form-group">
          <label>Precio c/descuento (€)</label>
          <input type="number" name="discount" step="0.01" min="0" value="<?= $p['discount'] ?? '' ?>" placeholder="—">
        </div>
      </div>
      <div class="form-group">
        <label>Colección</label>
        <select name="collectionId">
          <option value="">Sin colección</option>
          <?php foreach ($collections as $c): ?>
            <option value="<?= $c['id'] ?>" <?= ($p['collectionId'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-top:0.25rem">
        <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer;font-size:0.875rem">
          <input type="checkbox" name="onDemand" value="1" <?= !empty($p['onDemand']) ? 'checked' : '' ?>>
          Bajo pedido
        </label>
        <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer;font-size:0.875rem">
          <input type="checkbox" name="isNovedades" id="isNovedades" value="1" <?= !empty($isNovedades) ? 'checked' : '' ?>>
          En Novedades
        </label>
        <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer;font-size:0.875rem">
          <input type="checkbox" name="isPreorder" id="isPreorder" value="1" <?= !empty($p['isPreorder']) ? 'checked' : '' ?>>
          Preventa
        </label>
      </div>
    </div>
    <!-- Right column: description -->
    <div>
      <div class="form-group">
        <label>Descripción</label>
        <textarea name="description" rows="7" style="resize:vertical"><?= e($p['description'] ?? '') ?></textarea>
      </div>
    </div>
  </div>

  <!-- Images -->
  <div style="margin-top:1.5rem">
    <h3 style="font-weight:600;margin-bottom:0.75rem;font-size:0.9rem">Imágenes</h3>
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.75rem" id="existing-images">
      <?php foreach ($existingImages as $i => $img): ?>
      <div style="position:relative" <?= $img['id'] ? 'data-image-id="'.$img['id'].'"' : '' ?>>
        <img src="<?= e($img['url']) ?>" title="Clic para recortar"
             onclick="openCropModal('<?= e($img['url']) ?>', <?= $img['id'] ? (int)$img['id'] : 'null' ?>)"
             style="width:80px;height:80px;object-fit:cover;border-radius:4px;cursor:pointer;outline:<?= $i === 0 ? '2px solid #f59e0b' : 'none' ?>">
        <?php if ($img['id']): ?>
        <button type="button" onclick="removeImage(<?= $img['id'] ?>, this)" style="position:absolute;top:-6px;right:-6px;background:red;color:#fff;border:none;border-radius:50%;width:20px;height:20px;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;padding:0;line-height:1">&times;</button>
        <button type="button" onclick="setCover(<?= $img['id'] ?>, <?= (int)($p['id'] ?? 0) ?>, this)" title="Usar como portada" style="position:absolute;bottom:-6px;right:-6px;background:<?= $i === 0 ? '#f59e0b' : '#fff' ?>;color:<?= $i === 0 ? '#fff' : '#f59e0b' ?>;border:1px solid #f59e0b;border-radius:50%;width:20px;height:20px;cursor:pointer;font-size:11px;display:flex;align-items:center;justify-content:center;padding:0;line-height:1">★</button>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <div id="add-image-slot"
           onclick="document.getElementById('add-image-input').click()"
           style="width:80px;height:80px;border:2px dashed var(--color-gray-300);border-radius:4px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:2rem;color:var(--color-gray-400);flex-shrink:0;transition:border-color .15s,color .15s"
           onmouseover="this.style.borderColor='var(--color-gray-500)';this.style.color='var(--color-gray-500)'"
           onmouseout="this.style.borderColor='var(--color-gray-300)';this.style.color='var(--color-gray-400)'">+</div>
    </div>
    <p style="font-size:0.78rem;color:var(--color-gray-500);margin-top:0.25rem">JPG, PNG, WEBP. La primera imagen será la principal.</p>
  </div>

  <!-- Options -->
  <div style="margin-top:1.5rem">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem">
      <h3 style="font-weight:600;font-size:0.9rem">Opciones del producto</h3>
      <button type="button" onclick="addOption()" class="btn btn-secondary btn-sm">+ Añadir opción</button>
    </div>
    <div id="options-container" style="display:flex;flex-direction:column;gap:0.75rem"></div>
    <p id="combo-info" style="font-size:0.8rem;color:var(--color-gray-500);margin-top:0.5rem"></p>
  </div>

  <!-- Stock-only (shown when no options defined) -->
  <div id="stock-only-row" style="margin-top:0.75rem;display:none">
    <label style="font-size:0.875rem;font-weight:500;display:flex;align-items:center;gap:0.75rem">
      Stock total:
      <input type="number" id="stock-only-input" min="0" value="0"
             style="width:80px;padding:0.3rem 0.5rem;border:1px solid var(--color-gray-300);border-radius:4px;font-size:0.875rem">
    </label>
  </div>

  <!-- Variants table -->
  <div id="variants-section" style="margin-top:1.5rem">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem">
      <h3 style="font-weight:600;font-size:0.9rem">Variantes y stock</h3>
      <button type="button" onclick="addBlankVariant()" class="btn btn-secondary btn-sm">+ Variante manual</button>
    </div>
    <div style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse" id="variants-table">
        <thead id="variants-thead"></thead>
        <tbody id="variants-container"></tbody>
      </table>
    </div>
  </div>

  <div style="margin-top:1.5rem;display:flex;gap:0.75rem;align-items:center">
    <button type="button" onclick="saveProduct()" class="btn btn-primary">Guardar producto</button>
    <?php if ($isEdit): ?>
      <a href="/product/<?= (int)$p['id'] ?>" target="_blank" class="btn btn-secondary">Ver en tienda</a>
    <?php endif; ?>
    <p id="form-msg" style="margin:0;font-size:0.875rem"></p>
  </div>
</form>
<input type="file" accept="image/*" id="add-image-input" style="display:none">

<style>
.opt-row { background:var(--color-gray-50);border:1px solid var(--color-gray-200);border-radius:8px;padding:0.75rem 1rem; }
.opt-name { padding:0.3rem 0.5rem;border:1px solid var(--color-gray-300);border-radius:4px;font-size:0.875rem;font-family:inherit;width:140px; }
.opt-name:focus { outline:none;border-color:#000; }
.tags-wrap { display:flex;flex-wrap:wrap;gap:0.35rem;align-items:center;flex:1; }
.tag { display:inline-flex;align-items:center;gap:0.25rem;background:#fff;border:1px solid var(--color-gray-300);border-radius:9999px;padding:0.15rem 0.5rem;font-size:0.78rem; }
.tag button { border:none;background:none;cursor:pointer;color:var(--color-gray-400);font-size:0.9rem;line-height:1;padding:0; }
.tag button:hover { color:var(--color-red); }
.tag-input { border:1px dashed var(--color-gray-300);border-radius:9999px;padding:0.15rem 0.5rem;font-size:0.78rem;font-family:inherit;width:100px;outline:none; }
.tag-input:focus { border-color:#000; }
.preset-btn { background:var(--color-gray-100);border:1px solid var(--color-gray-300);border-radius:4px;padding:0.15rem 0.4rem;font-size:0.72rem;cursor:pointer;font-family:inherit; }
.preset-btn:hover { background:var(--color-gray-200); }
.vrow td { padding:0.3rem 0.5rem;border-bottom:1px solid var(--color-gray-100); }
</style>

<script>
const DEFAULT_PRESETS = {
  'Ropa':    ['XS','S','M','L','XL','XXL'],
  'Anillos': ['T9','T10','T11','T12','T13','T14','T15','T16','T17','T18','T19','T20','T21','T22','T23'],
  'Calzado': ['35','36','37','38','39','40','41','42','43','44'],
  'Letras':  'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split(''),
  'Números': '0123456789'.split(''),
};
let PRESETS = JSON.parse(localStorage.getItem('kokett_presets') || 'null') || JSON.parse(JSON.stringify(DEFAULT_PRESETS));
function savePresets() { localStorage.setItem('kokett_presets', JSON.stringify(PRESETS)); }

// ── Preset editor ────────────────────────────────────────────────────────────
function openPresetEditor() {
  let html = `<div style="position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center" id="preset-overlay" onclick="if(event.target===this)closePresetEditor()">
  <div style="background:#fff;border-radius:8px;padding:1.5rem;width:540px;max-width:95vw;max-height:80vh;overflow-y:auto">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
      <h3 style="font-weight:700;font-size:1rem">Editar plantillas de opciones</h3>
      <button type="button" onclick="closePresetEditor()" style="border:none;background:none;cursor:pointer;font-size:1.2rem;color:var(--color-gray-400)">×</button>
    </div>
    <div id="preset-rows" style="display:flex;flex-direction:column;gap:0.75rem"></div>
    <div style="display:flex;gap:0.5rem;margin-top:1rem">
      <button type="button" onclick="addPresetRow()" class="btn btn-secondary btn-sm">+ Nueva plantilla</button>
      <button type="button" onclick="resetPresets()" class="btn btn-secondary btn-sm" style="margin-left:auto;color:var(--color-gray-400)">Restaurar</button>
      <button type="button" onclick="savePresetEditor()" class="btn btn-primary btn-sm">Guardar</button>
    </div>
  </div></div>`;
  document.body.insertAdjacentHTML('beforeend', html);
  renderPresetRows();
}
function closePresetEditor() { document.getElementById('preset-overlay')?.remove(); }
function renderPresetRows() {
  const container = document.getElementById('preset-rows');
  if (!container) return;
  const entries = Object.entries(PRESETS);
  container.innerHTML = entries.map(([name, vals], i) => `
    <div style="display:flex;gap:0.5rem;align-items:flex-start;background:var(--color-gray-50);border-radius:6px;padding:0.6rem 0.75rem">
      <input type="text" value="${escHtml(name)}" data-pi="${i}" data-pkey="name"
             style="width:110px;flex-shrink:0;padding:0.3rem 0.5rem;border:1px solid var(--color-gray-300);border-radius:4px;font-size:0.85rem;font-family:inherit">
      <textarea data-pi="${i}" data-pkey="vals" rows="2"
                style="flex:1;padding:0.3rem 0.5rem;border:1px solid var(--color-gray-300);border-radius:4px;font-size:0.8rem;font-family:inherit;resize:vertical">${escHtml(vals.join(', '))}</textarea>
      <button type="button" onclick="deletePresetRow(${JSON.stringify(name)})"
              style="border:none;background:none;cursor:pointer;color:var(--color-gray-400);font-size:1.1rem;flex-shrink:0;padding:0.2rem">🗑</button>
    </div>`).join('');
}
function addPresetRow() {
  PRESETS['Nueva'] = [];
  renderPresetRows();
}
function deletePresetRow(name) {
  delete PRESETS[name];
  renderPresetRows();
}
function savePresetEditor() {
  const newPresets = {};
  document.querySelectorAll('#preset-rows [data-pkey="name"]').forEach(nameEl => {
    const i = nameEl.dataset.pi;
    const name = nameEl.value.trim();
    const valsEl = document.querySelector(`#preset-rows [data-pi="${i}"][data-pkey="vals"]`);
    const vals = valsEl.value.split(',').map(s => s.trim()).filter(Boolean);
    if (name) newPresets[name] = vals;
  });
  PRESETS = newPresets;
  savePresets();
  closePresetEditor();
  renderOptions(); // refresh preset buttons
}
function resetPresets() {
  PRESETS = JSON.parse(JSON.stringify(DEFAULT_PRESETS));
  renderPresetRows();
}
// Standard option name → column mapping
const STD = { 'Talla': 'size', 'Color': 'color', 'Material': 'material' };

let options = []; // [{name, values:[]}]
let variantRows = []; // [{optionValues:{}, id, stock, reserved}]

// Initial data from PHP
const initialOptions = <?= json_encode($existingOptions) ?>;
const initialVariants = <?= json_encode($existingVariants) ?>;

// Build stock lookup: key → {id, stock, reserved}
function variantKey(optVals) {
  return Object.keys(optVals).sort().map(k => k + '=' + optVals[k]).join('|');
}
const stockMap = {};
initialVariants.forEach(v => {
  const opts = Object.assign({}, v.customValues || {});
  if (v.size)     opts['Talla']    = opts['Talla']    || v.size;
  if (v.color)    opts['Color']    = opts['Color']    || v.color;
  if (v.material) opts['Material'] = opts['Material'] || v.material;
  const key = variantKey(opts);
  stockMap[key] = { id: v.id, stock: v.stock, reserved: parseInt(v.reserved || 0) };
});

// ── Option editor ────────────────────────────────────────────────────────────
function addOption(name = '', values = []) {
  options.push({ name, values: [...values] });
  renderOptions();
}

function removeOption(idx) {
  options.splice(idx, 1);
  renderOptions();
  generateCombinations();
}

function renderOptions() {
  const container = document.getElementById('options-container');
  container.innerHTML = '';
  options.forEach((opt, idx) => {
    const div = document.createElement('div');
    div.className = 'opt-row';
    div.innerHTML = `
      <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap">
        <input class="opt-name" type="text" value="${escHtml(opt.name)}" placeholder="Ej: Color, Talla…"
               onchange="options[${idx}].name=this.value;generateCombinations()">
        <div class="tags-wrap" id="tags-${idx}"></div>
        <input class="tag-input" type="text" placeholder="+ valor"
               onkeydown="addTag(event,${idx},this)"
               onblur="if(this.value.trim())addTagVal(${idx},this.value.trim(),this)">
        <div style="display:flex;gap:0.25rem;flex-wrap:wrap;align-items:center;margin-left:auto">
          ${Object.keys(PRESETS).map(p => `<button type="button" class="preset-btn" onclick="openPresetModal(${idx},'${p.replace(/\\/g,'\\\\').replace(/'/g,"\\'")}')">${escHtml(p)}</button>`).join('')}
          <button type="button" onclick="openPresetEditor()" title="Editar plantillas" style="border:none;background:none;cursor:pointer;font-size:0.85rem;color:var(--color-gray-400);padding:0.1rem 0.2rem;line-height:1" title="Editar plantillas">✎</button>
        </div>
        <button type="button" onclick="removeOption(${idx})" style="border:none;background:none;cursor:pointer;color:var(--color-gray-400);font-size:1.1rem;line-height:1;padding:0;flex-shrink:0" title="Eliminar opción">🗑</button>
      </div>`;
    container.appendChild(div);
    renderTags(idx);
  });
  updateComboInfo();
  updateStockOnlyVisibility();
}

function renderTags(idx) {
  const wrap = document.getElementById('tags-' + idx);
  if (!wrap) return;
  wrap.innerHTML = options[idx].values.map((v, vi) =>
    `<span class="tag">${escHtml(v)}<button type="button" onclick="removeTag(${idx},${vi})">×</button></span>`
  ).join('');
}

function addTag(e, idx, input) {
  if (e.key === 'Enter') { e.preventDefault(); addTagVal(idx, input.value.trim(), input); }
}
function addTagVal(idx, val, input) {
  if (!val) return;
  // Support comma-separated bulk add
  const vals = val.split(',').map(s => s.trim()).filter(Boolean);
  vals.forEach(v => { if (!options[idx].values.includes(v)) options[idx].values.push(v); });
  if (input) input.value = '';
  renderTags(idx);
  generateCombinations();
}
function removeTag(idx, vi) {
  options[idx].values.splice(vi, 1);
  renderTags(idx);
  generateCombinations();
}
function openPresetModal(idx, preset) {
  const vals = PRESETS[preset] || [];
  const already = new Set(options[idx].values);
  // Start with all selected
  const selected = new Set(vals);

  function render() {
    const grid = document.getElementById('pm-grid');
    if (!grid) return;
    grid.innerHTML = vals.map(v => {
      const on = selected.has(v);
      const safeV = v.replace(/\\/g,'\\\\').replace(/'/g,"\\'");
      return `<button type="button" onclick="pmToggle('${safeV}')"
        style="padding:0.3rem 0.65rem;border-radius:9999px;border:1px solid ${on ? '#000' : 'var(--color-gray-300)'};
               background:${on ? '#000' : '#fff'};color:${on ? '#fff' : 'inherit'};
               font-size:0.8rem;cursor:pointer;font-family:inherit;transition:all 0.1s">
        ${escHtml(v)}</button>`;
    }).join('');
    const btn = document.getElementById('pm-add-btn');
    if (btn) btn.textContent = `Añadir ${selected.size} valor${selected.size !== 1 ? 'es' : ''}`;
  }

  window.pmToggle = function(v) { selected.has(v) ? selected.delete(v) : selected.add(v); render(); };
  window.pmAll    = function() { vals.forEach(v => selected.add(v)); render(); };
  window.pmNone   = function() { selected.clear(); render(); };
  window.pmSave   = function() {
    // Set option name if blank
    if (!options[idx].name) {
      options[idx].name = preset;
      const nameInputs = document.querySelectorAll('.opt-name');
      if (nameInputs[idx]) nameInputs[idx].value = preset;
    }
    selected.forEach(v => { if (!options[idx].values.includes(v)) options[idx].values.push(v); });
    renderTags(idx);
    generateCombinations();
    document.getElementById('pm-overlay')?.remove();
  };

  const existing = document.getElementById('pm-overlay');
  if (existing) existing.remove();

  document.body.insertAdjacentHTML('beforeend', `
    <div id="pm-overlay" onclick="if(event.target===this)this.remove()"
         style="position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9999;display:flex;align-items:center;justify-content:center">
      <div style="background:#fff;border-radius:10px;padding:1.5rem;width:480px;max-width:95vw;max-height:80vh;display:flex;flex-direction:column;gap:1rem">
        <div style="display:flex;align-items:center;justify-content:space-between">
          <h3 style="font-weight:700;font-size:1rem;margin:0">Plantilla: ${escHtml(preset)}</h3>
          <button type="button" onclick="document.getElementById('pm-overlay').remove()"
                  style="border:none;background:none;cursor:pointer;font-size:1.3rem;color:var(--color-gray-400);line-height:1">×</button>
        </div>
        <div style="display:flex;gap:0.5rem">
          <button type="button" onclick="pmAll()" class="btn btn-secondary btn-sm">Seleccionar todo</button>
          <button type="button" onclick="pmNone()" class="btn btn-secondary btn-sm">Ninguno</button>
        </div>
        <div id="pm-grid" style="display:flex;flex-wrap:wrap;gap:0.4rem;overflow-y:auto;max-height:300px;padding:0.25rem 0"></div>
        <div style="display:flex;gap:0.5rem;justify-content:flex-end;border-top:1px solid var(--color-gray-100);padding-top:0.75rem">
          <button type="button" onclick="document.getElementById('pm-overlay').remove()" class="btn btn-secondary btn-sm">Cancelar</button>
          <button type="button" id="pm-add-btn" onclick="pmSave()" class="btn btn-primary btn-sm">Añadir</button>
        </div>
      </div>
    </div>`);
  render();
}

function updateComboInfo() {
  const total = options.reduce((acc, o) => acc * (o.values.length || 1), 1);
  const hasOpts = options.some(o => o.values.length > 0);
  document.getElementById('combo-info').textContent = hasOpts ? `${total} combinación${total !== 1 ? 'es' : ''}` : '';
}

function updateStockOnlyVisibility() {
  const hasOptions = options.some(o => o.name && o.values.length > 0);
  document.getElementById('stock-only-row').style.display = hasOptions ? 'none' : 'block';
  document.getElementById('variants-section').style.display = hasOptions ? '' : 'none';
}

// ── Combination generator ────────────────────────────────────────────────────
function cartesian(arrs) {
  return arrs.reduce((acc, arr) => acc.flatMap(a => arr.map(v => [...a, v])), [[]]);
}

function generateCombinations() {
  const activeOpts = options.filter(o => o.name && o.values.length > 0);
  if (activeOpts.length === 0) {
    variantRows = [];
    renderVariantsTable();
    updateComboInfo();
    updateStockOnlyVisibility();
    return;
  }
  const names = activeOpts.map(o => o.name);
  const valueLists = activeOpts.map(o => o.values);
  const combos = cartesian(valueLists);
  variantRows = combos.map(combo => {
    const optVals = {};
    names.forEach((n, i) => optVals[n] = combo[i]);
    const key = variantKey(optVals);
    const existing = stockMap[key] || {};
    return { optionValues: optVals, id: existing.id || '', stock: existing.stock ?? 0, reserved: existing.reserved ?? 0 };
  });
  renderVariantsTable();
  updateComboInfo();
  updateStockOnlyVisibility();
}

// ── Variants table ───────────────────────────────────────────────────────────
function renderVariantsTable(initialRows) {
  // Determine active option names
  const optNames = options.filter(o => o.name).map(o => o.name);

  // Build thead
  const thead = document.getElementById('variants-thead');
  const thStyle = 'padding:0.4rem 0.5rem;text-align:left;font-size:0.72rem;text-transform:uppercase;color:var(--color-gray-500);border-bottom:1px solid var(--color-gray-200)';
  thead.innerHTML = `<tr>
    ${optNames.map(n => `<th style="${thStyle}">${escHtml(n)}</th>`).join('')}
    <th style="${thStyle};width:80px">Stock</th>
    <th style="${thStyle};width:70px">Reservado</th>
    <th style="${thStyle};width:70px">Disponible</th>
    <th style="width:32px"></th>
  </tr>`;

  // If called on init with existing variants
  if (initialRows) {
    variantRows = initialRows.map(v => {
      const optVals = Object.assign({}, v.customValues || {});
      if (v.size && !optVals['Talla'])     optVals['Talla']    = v.size;
      if (v.color && !optVals['Color'])    optVals['Color']    = v.color;
      if (v.material && !optVals['Material']) optVals['Material'] = v.material;
      return { optionValues: optVals, id: v.id, stock: v.stock, reserved: parseInt(v.reserved || 0) };
    });
  }

  const tbody = document.getElementById('variants-container');
  const tdStyle = 'padding:0.3rem 0.5rem';
  tbody.innerHTML = variantRows.map((row, ri) => {
    const avail = Math.max(0, (row.stock || 0) - (row.reserved || 0));
    const availColor = avail > 0 ? 'var(--color-green)' : 'var(--color-red)';
    return `<tr class="vrow">
      ${optNames.map(n => `<td style="${tdStyle}">${escHtml(row.optionValues[n] || '')}</td>`).join('')}
      <td style="${tdStyle}">
        <input type="number" min="0" value="${parseInt(row.stock || 0)}"
               onchange="variantRows[${ri}].stock=parseInt(this.value)||0;updateAvail(this)"
               style="width:65px;padding:0.2rem 0.35rem;border:1px solid var(--color-gray-300);border-radius:3px;font-size:0.85rem">
        <input type="hidden" value="${row.id || ''}">
      </td>
      <td style="${tdStyle};color:var(--color-gray-500);font-size:0.85rem">${row.reserved || 0}</td>
      <td style="${tdStyle};font-weight:600;font-size:0.85rem;color:${availColor}">${avail}</td>
      <td style="${tdStyle}">
        <button type="button" onclick="variantRows.splice(${ri},1);renderVariantsTable()"
                style="border:none;background:none;cursor:pointer;color:var(--color-gray-400);font-size:1.1rem;padding:0">&times;</button>
      </td>
    </tr>`;
  }).join('');
}

function updateAvail(stockInput) {
  const row = stockInput.closest('tr');
  const cells = row.querySelectorAll('td');
  const reserved = parseInt(cells[cells.length - 3].textContent) || 0;
  const avail = Math.max(0, parseInt(stockInput.value) || 0) - reserved;
  const availCell = cells[cells.length - 2];
  availCell.textContent = avail;
  availCell.style.color = avail > 0 ? 'var(--color-green)' : 'var(--color-red)';
}

function addBlankVariant() {
  variantRows.push({ optionValues: {}, id: '', stock: 0, reserved: 0 });
  // Add empty string for any current option
  options.forEach(o => { if (o.name) variantRows[variantRows.length-1].optionValues[o.name] = ''; });
  renderVariantsTable();
}

// ── Image helpers ────────────────────────────────────────────────────────────
function removeImage(imageId, btn) {
  fetch('/admin/api/products.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'delete-image', imageId})})
    .then(r=>r.json()).then(d => { if (d.success) btn.closest('[data-image-id]').remove(); });
}
function setCover(imageId, productId, btn) {
  fetch('/admin/api/products.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'set-cover-image', imageId, productId})})
    .then(r=>r.json()).then(d => { if (d.success) location.reload(); });
}

// ── Save ─────────────────────────────────────────────────────────────────────
async function saveProduct() {
  const form = document.getElementById('product-form');
  const msg  = document.getElementById('form-msg');
  const btn  = form.querySelector('button[onclick="saveProduct()"]');
  btn.disabled = true; btn.textContent = 'Guardando...';
  msg.textContent = '';

  try {
    // Basic fields
    const fd2 = new FormData(form);
    const data = {};
    fd2.forEach((v, k) => data[k] = v);
    data.newImages   = [...pendingImages];
    data.isNovedades = form.querySelector('#isNovedades').checked ? 1 : 0;
    data.onDemand    = form.querySelector('[name="onDemand"]')?.checked ? 1 : 0;
    data.isPreorder  = form.querySelector('#isPreorder').checked ? 1 : 0;

    // Variants with customValues
    data.variants = variantRows.map(row => {
      const cv = Object.assign({}, row.optionValues);
      return {
        id:           row.id || '',
        customValues: cv,
        size:         cv['Talla'] || null,
        color:        cv['Color'] || null,
        material:     cv['Material'] || null,
        stock:        row.stock || 0,
      };
    }).filter(v => Object.keys(v.customValues).length > 0 || v.stock > 0);

    // Stock-only mode: if no options, send a single variant with just stock
    if (!options.some(o => o.name && o.values.length > 0)) {
      const stockVal = parseInt(document.getElementById('stock-only-input').value) || 0;
      const existingSimple = initialVariants.find(v => Object.keys(v.customValues || {}).length === 0);
      data.variants = [{ id: existingSimple?.id || '', customValues: {}, size: null, color: null, material: null, stock: stockVal }];
    }

    // Derive has* flags
    data.hasSize     = data.variants.some(v => v.size)     ? 1 : 0;
    data.hasColor    = data.variants.some(v => v.color)    ? 1 : 0;
    data.hasMaterial = data.variants.some(v => v.material) ? 1 : 0;

    const res = await fetch('/admin/api/products.php', {
      method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data)
    }).then(r => {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });

    if (res.success) {
      msg.textContent = '✓ Guardado';
      msg.style.color = 'var(--color-green)';
      if (res.id && data.action === 'create') setTimeout(() => location.href = '/admin/products/' + res.id + '/edit', 600);
      else setTimeout(() => location.reload(), 600);
    } else {
      msg.textContent = res.message || 'Error al guardar.';
      msg.style.color = 'var(--color-red)';
    }
  } catch (err) {
    msg.textContent = 'Error al guardar: ' + err.message;
    msg.style.color = 'var(--color-red)';
  } finally {
    btn.disabled = false; btn.textContent = 'Guardar producto';
  }
}

function escHtml(str) {
  return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Image crop tool ──────────────────────────────────────────────────────────
const CROP_SIZE = 300;
let cropState = {};
let pendingImages = [];

function openCropModal(imgUrl, imageId, isNew = false) {
  cropState = { imageId, isNew, objectUrl: isNew ? imgUrl : null, left: 0, top: 0, imgW: 0, dragging: false, sx: 0, sy: 0, sleft: 0, stop: 0 };
  document.body.insertAdjacentHTML('beforeend', `
    <div id="crop-overlay" onclick="if(event.target===this)closeCropModal()"
         style="position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:9999;display:flex;align-items:center;justify-content:center">
      <div style="background:#fff;border-radius:10px;padding:1.25rem;display:flex;flex-direction:column;align-items:center;gap:0.75rem;max-width:95vw">
        <div style="display:flex;align-items:center;justify-content:space-between;width:100%;gap:1rem">
          <h3 style="font-weight:700;font-size:0.95rem;margin:0">Recortar imagen</h3>
          <button onclick="closeCropModal()" style="border:none;background:none;cursor:pointer;font-size:1.4rem;color:#666;line-height:1">×</button>
        </div>
        <div id="crop-vp"
             style="position:relative;width:${CROP_SIZE}px;height:${CROP_SIZE}px;overflow:hidden;cursor:grab;flex-shrink:0;background:#f3f4f6;border:2px solid var(--color-gray-300)"
             onmousedown="cropDragStart(event)" onmousemove="cropDragMove(event)"
             onmouseup="cropDragEnd()" onmouseleave="cropDragEnd()" onwheel="cropWheel(event)">
          <img id="crop-img" src="${imgUrl}" style="position:absolute;left:0;top:0;user-select:none;max-width:none" draggable="false"
               ontouchstart="cropTouchStart(event)" ontouchmove="cropTouchMove(event)" ontouchend="cropTouchEnd()">
        </div>
        <div style="display:flex;gap:0.5rem;align-items:center;font-size:0.8rem;color:var(--color-gray-500)">
          <button type="button" onclick="cropZoom(1/1.2)" style="width:28px;height:28px;border:1px solid var(--color-gray-300);border-radius:4px;cursor:pointer;font-size:1.1rem;background:#fff;line-height:1">−</button>
          <span>Zoom · arrastra para mover</span>
          <button type="button" onclick="cropZoom(1.2)" style="width:28px;height:28px;border:1px solid var(--color-gray-300);border-radius:4px;cursor:pointer;font-size:1.1rem;background:#fff;line-height:1">+</button>
        </div>
        <div style="display:flex;gap:0.5rem;width:100%;justify-content:flex-end">
          <button type="button" onclick="closeCropModal()" style="padding:0.4rem 1rem;border:1px solid var(--color-gray-300);border-radius:4px;cursor:pointer;font-size:0.85rem;background:#fff">Cancelar</button>
          <button type="button" id="crop-save-btn" onclick="saveCrop()" style="padding:0.4rem 1rem;border:none;border-radius:4px;cursor:pointer;font-size:0.85rem;font-weight:600;background:var(--c-primary,#000);color:var(--c-text-on-primary,#fff)">Recortar y guardar</button>
        </div>
        <p id="crop-msg" style="font-size:0.8rem;margin:0;color:var(--color-red)"></p>
      </div>
    </div>`);
  const img = document.getElementById('crop-img');
  img.onload = () => initCrop(img);
  if (img.complete && img.naturalWidth) initCrop(img);
}

function initCrop(img) {
  const aspect = img.naturalWidth / img.naturalHeight;
  const imgW = aspect >= 1 ? CROP_SIZE * aspect : CROP_SIZE;
  const imgH = aspect >= 1 ? CROP_SIZE : CROP_SIZE / aspect;
  cropState.imgW = imgW;
  cropState.left = (CROP_SIZE - imgW) / 2;
  cropState.top  = (CROP_SIZE - imgH) / 2;
  applyCrop();
}

function applyCrop() {
  const img = document.getElementById('crop-img');
  if (!img) return;
  img.style.left  = cropState.left + 'px';
  img.style.top   = cropState.top  + 'px';
  img.style.width = cropState.imgW + 'px';
  img.style.height = 'auto';
}

function cropDragStart(e) {
  cropState.dragging = true;
  cropState.sx = e.clientX; cropState.sy = e.clientY;
  cropState.sleft = cropState.left; cropState.stop = cropState.top;
  document.getElementById('crop-vp').style.cursor = 'grabbing';
}
function cropDragMove(e) {
  if (!cropState.dragging) return;
  cropState.left = cropState.sleft + (e.clientX - cropState.sx);
  cropState.top  = cropState.stop  + (e.clientY - cropState.sy);
  applyCrop();
}
function cropDragEnd() {
  cropState.dragging = false;
  const vp = document.getElementById('crop-vp');
  if (vp) vp.style.cursor = 'grab';
}
function cropWheel(e) { e.preventDefault(); cropZoom(e.deltaY > 0 ? 1/1.1 : 1.1); }
function cropZoom(factor) {
  const img = document.getElementById('crop-img');
  if (!img) return;
  const oldH = img.offsetHeight;
  const newW = Math.max(CROP_SIZE * 0.2, Math.min(CROP_SIZE * 10, cropState.imgW * factor));
  const newH = newW * (img.naturalHeight / img.naturalWidth);
  const cx = CROP_SIZE / 2, cy = CROP_SIZE / 2;
  cropState.left = cx - (cx - cropState.left) * (newW / cropState.imgW);
  cropState.top  = cy - (cy - cropState.top)  * (newH / (oldH || newH));
  cropState.imgW = newW;
  applyCrop();
}

let _cropTouchDist = 0;
function cropTouchStart(e) {
  e.preventDefault();
  if (e.touches.length === 1) cropDragStart({ clientX: e.touches[0].clientX, clientY: e.touches[0].clientY });
  else if (e.touches.length === 2) _cropTouchDist = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
}
function cropTouchMove(e) {
  e.preventDefault();
  if (e.touches.length === 1 && cropState.dragging) cropDragMove({ clientX: e.touches[0].clientX, clientY: e.touches[0].clientY });
  else if (e.touches.length === 2 && _cropTouchDist) {
    const d = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
    cropZoom(d / _cropTouchDist); _cropTouchDist = d;
  }
}
function cropTouchEnd() { cropDragEnd(); _cropTouchDist = 0; }

async function saveCrop() {
  const img = document.getElementById('crop-img');
  const btn = document.getElementById('crop-save-btn');
  const msg = document.getElementById('crop-msg');
  btn.disabled = true; btn.textContent = 'Guardando...';
  const OUT = 800;
  const canvas = document.createElement('canvas');
  canvas.width = OUT; canvas.height = OUT;
  const ctx = canvas.getContext('2d');
  const scale = cropState.imgW / img.naturalWidth;
  ctx.drawImage(img, -cropState.left / scale, -cropState.top / scale, CROP_SIZE / scale, CROP_SIZE / scale, 0, 0, OUT, OUT);
  canvas.toBlob(async blob => {
    const fd = new FormData();
    fd.append('image', blob, 'crop.jpg');
    try {
      const res = await fetch('/admin/api/products.php?action=upload-image', { method: 'POST', body: fd }).then(r => r.json());
      if (!res.url) throw new Error(res.message || 'Upload error');
      const productId = parseInt(document.querySelector('[name="id"]')?.value) || 0;

      if (!cropState.isNew) {
        // Path A: re-cropping an existing saved image
        if (productId) {
          await fetch('/admin/api/products.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ action: 'link-image', productId, imageUrl: res.url }) });
        }
        if (cropState.imageId) {
          await fetch('/admin/api/products.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ action: 'delete-image', imageId: cropState.imageId }) });
        }
        location.reload();
      } else {
        // Path B: adding a new image
        const slot = document.getElementById('add-image-slot');
        const escapedUrl = res.url.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
        const thumb = document.createElement('div');
        thumb.style.position = 'relative';
        if (productId) {
          const linkRes = await fetch('/admin/api/products.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ action: 'link-image', productId, imageUrl: res.url }) }).then(r => r.json());
          const newImageId = linkRes.imageId;
          thumb.dataset.imageId = newImageId;
          thumb.innerHTML = `<img src="${res.url}" title="Clic para recortar"
                               onclick="openCropModal('${escapedUrl}', ${newImageId})"
                               style="width:80px;height:80px;object-fit:cover;border-radius:4px;cursor:pointer">
            <button type="button" onclick="removeImage(${newImageId}, this)"
                    style="position:absolute;top:-6px;right:-6px;background:red;color:#fff;border:none;border-radius:50%;width:20px;height:20px;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;padding:0;line-height:1">&times;</button>
            <button type="button" onclick="setCover(${newImageId}, ${productId}, this)"
                    title="Usar como portada"
                    style="position:absolute;bottom:-6px;right:-6px;background:#fff;color:#f59e0b;border:1px solid #f59e0b;border-radius:50%;width:20px;height:20px;cursor:pointer;font-size:11px;display:flex;align-items:center;justify-content:center;padding:0;line-height:1">★</button>`;
        } else {
          thumb.dataset.pendingUrl = res.url;
          thumb.innerHTML = `<img src="${res.url}" style="width:80px;height:80px;object-fit:cover;border-radius:4px">
            <button type="button" onclick="removePendingImage(this, '${escapedUrl}')"
                    style="position:absolute;top:-6px;right:-6px;background:red;color:#fff;border:none;border-radius:50%;width:20px;height:20px;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;padding:0;line-height:1">&times;</button>`;
          pendingImages.push(res.url);
        }
        slot.parentNode.insertBefore(thumb, slot);
        closeCropModal();
      }
    } catch(err) {
      msg.textContent = 'Error: ' + err.message;
      btn.disabled = false; btn.textContent = 'Recortar y guardar';
    }
  }, 'image/jpeg', 0.92);
}

function removePendingImage(btn, url) {
  pendingImages = pendingImages.filter(u => u !== url);
  btn.closest('[data-pending-url]').remove();
}

function closeCropModal() {
  if (cropState.objectUrl) URL.revokeObjectURL(cropState.objectUrl);
  cropState = {};
  document.getElementById('crop-overlay')?.remove();
}

// ── Add-image input handler ───────────────────────────────────────────────────
document.getElementById('add-image-input').addEventListener('change', function () {
  const file = this.files[0];
  if (!file) return;
  const objectUrl = URL.createObjectURL(file);
  openCropModal(objectUrl, null, true);
  this.value = '';
});

// ── Init ──────────────────────────────────────────────────────────────────────
if (initialOptions.length > 0) {
  options = initialOptions.map(o => ({ name: o.name, values: [...o.values] }));
  renderOptions();
} else {
  renderOptions(); // empty
}
renderVariantsTable(initialVariants);
// If single variant with no customValues, populate stock-only input
if (initialVariants.length === 1 && Object.keys(initialVariants[0].customValues || {}).length === 0) {
  document.getElementById('stock-only-input').value = initialVariants[0].stock || 0;
}
updateStockOnlyVisibility();
</script>
