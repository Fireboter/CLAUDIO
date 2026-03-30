<?php
$pageTitle = 'Marketing';
$subscribers = db_query('SELECT * FROM NewsletterSubscriber WHERE isActive = 1 ORDER BY createdAt DESC');
$allProducts = db_query("SELECT id, name, price, discount,
    COALESCE(
        (SELECT url FROM ProductImage WHERE productId = p.id AND url REGEXP 'p_[^/]+$' ORDER BY displayOrder ASC LIMIT 1),
        JSON_UNQUOTE(JSON_EXTRACT(p.images, '\$[0]'))
    ) as firstImage
    FROM Product p ORDER BY p.name ASC");
require __DIR__ . '/layout-header.php';
?>

<div class="page-header">
  <h1>Marketing / Newsletter</h1>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start">

  <!-- Left: Composer -->
  <div class="admin-form">
    <div class="form-group">
      <label>Asunto del email *</label>
      <input type="text" id="nl-subject" oninput="updatePreview()" placeholder="Nueva colección disponible">
    </div>
    <div class="form-group">
      <label>Título</label>
      <input type="text" id="nl-title" oninput="updatePreview()" placeholder="¡Novedades en Kokett!">
    </div>
    <div class="form-group">
      <label>Introducción</label>
      <textarea id="nl-intro" rows="2" oninput="updatePreview()" placeholder="Descubre las últimas tendencias..."></textarea>
    </div>
    <div class="form-group">
      <label>Cuerpo del mensaje</label>
      <textarea id="nl-body" rows="4" oninput="updatePreview()" placeholder="Texto adicional del email..."></textarea>
    </div>

    <!-- Product picker -->
    <div class="form-group">
      <label>Productos destacados</label>
      <div style="position:relative">
        <input type="text" id="prod-search" autocomplete="off" placeholder="Buscar producto..." oninput="filterProducts(this.value)" onfocus="filterProducts(this.value)">
        <div id="prod-suggestions" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid var(--color-gray-300);border-radius:0 0 4px 4px;max-height:220px;overflow-y:auto;z-index:200;margin-top:-1px;box-shadow:0 4px 12px rgba(0,0,0,0.08)"></div>
      </div>
      <div id="prod-selected-chips" style="display:flex;flex-wrap:wrap;gap:0.375rem;margin-top:0.5rem"></div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
      <div class="form-group">
        <label>Texto del botón</label>
        <input type="text" id="nl-btn-text" oninput="updatePreview()" value="Ver colección">
      </div>
      <div class="form-group">
        <label>Enlace del botón</label>
        <input type="text" id="nl-btn-link" oninput="updatePreview()" value="https://www.kokett.ad/shop">
      </div>
    </div>

    <div style="display:flex;gap:0.75rem;margin-top:1rem;align-items:center">
      <button onclick="sendNewsletter('test')" class="btn btn-secondary">✉ Prueba</button>
      <button onclick="sendNewsletter('all')" class="btn btn-primary">Enviar a todos (<?= count($subscribers) ?>)</button>
    </div>
    <p id="nl-msg" style="margin-top:0.75rem;font-size:0.875rem"></p>

    <details style="margin-top:1.5rem">
      <summary style="cursor:pointer;font-size:0.875rem;font-weight:600;color:var(--color-gray-500)">Suscriptores (<?= count($subscribers) ?>)</summary>
      <div style="margin-top:0.75rem;background:var(--color-gray-50);border-radius:6px;max-height:250px;overflow-y:auto;border:1px solid var(--color-gray-200)">
        <?php foreach ($subscribers as $sub): ?>
        <div style="padding:0.4rem 0.75rem;border-bottom:1px solid var(--color-gray-100);font-size:0.8rem;display:flex;justify-content:space-between;align-items:center">
          <span><?= e($sub['email']) ?></span>
          <button onclick="unsubscribe(<?= $sub['id'] ?>)" style="border:none;background:none;color:var(--color-gray-400);cursor:pointer">&times;</button>
        </div>
        <?php endforeach; ?>
        <?php if (empty($subscribers)): ?><p style="padding:1rem;color:var(--color-gray-500);font-size:0.875rem">Sin suscriptores.</p><?php endif; ?>
      </div>
    </details>
  </div>

  <!-- Right: Preview -->
  <div>
    <div style="font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-gray-500);margin-bottom:0.5rem">Vista previa del email</div>
    <div id="email-preview" style="border:1px solid var(--color-gray-200);border-radius:8px;overflow:hidden;background:#f9f9f9;padding:1rem;font-size:0.85rem">
      <div id="preview-inner" style="background:#fff;max-width:500px;margin:0 auto;font-family:sans-serif"></div>
    </div>
  </div>

</div>

<script>
const allProducts = <?= json_encode(array_values($allProducts), JSON_HEX_TAG) ?>;
const SITE_PRIMARY    = '<?= e($_acPri) ?>';
const SITE_ON_PRIMARY = '<?= e($_acOnPri) ?>';
let selectedProducts = [];

// ── Product picker ────────────────────────────────────────────────────────────
function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function filterProducts(query) {
  const q = query.toLowerCase().trim();
  const box = document.getElementById('prod-suggestions');
  const matches = q
    ? allProducts.filter(p => p.name.toLowerCase().includes(q)).slice(0, 8)
    : allProducts.slice(0, 6);
  if (!matches.length) { box.style.display = 'none'; return; }
  box.innerHTML = matches.map(p => {
    const isSelected = selectedProducts.some(s => s.id == p.id);
    return `<div onmousedown="selectProduct(${p.id})" style="display:flex;align-items:center;gap:0.5rem;padding:0.4rem 0.625rem;cursor:pointer;font-size:0.82rem;border-bottom:1px solid var(--color-gray-100);${isSelected ? 'background:#f0fdf4;' : ''}" onmouseover="this.style.background='var(--color-gray-50)'" onmouseout="this.style.background='${isSelected ? '#f0fdf4' : ''}'">
      ${p.firstImage ? `<img src="${escHtml(p.firstImage)}" style="width:30px;height:30px;object-fit:cover;border-radius:3px;flex-shrink:0">` : '<div style="width:30px;height:30px;background:var(--color-gray-100);border-radius:3px;flex-shrink:0"></div>'}
      <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(p.name)}</span>
      ${isSelected ? '<span style="color:var(--color-green);flex-shrink:0">✓</span>' : ''}
    </div>`;
  }).join('');
  box.style.display = 'block';
}

function selectProduct(id) {
  const p = allProducts.find(p => p.id == id);
  if (!p || selectedProducts.some(s => s.id == id)) {
    document.getElementById('prod-search').value = '';
    document.getElementById('prod-suggestions').style.display = 'none';
    return;
  }
  selectedProducts.push(p);
  document.getElementById('prod-search').value = '';
  document.getElementById('prod-suggestions').style.display = 'none';
  renderSelectedChips();
  updatePreview();
}

function removeProduct(id) {
  selectedProducts = selectedProducts.filter(p => p.id != id);
  renderSelectedChips();
  updatePreview();
}

function renderSelectedChips() {
  const container = document.getElementById('prod-selected-chips');
  container.innerHTML = selectedProducts.map(p => `
    <div style="display:inline-flex;align-items:center;gap:0.3rem;background:var(--color-gray-100);border:1px solid var(--color-gray-200);border-radius:4px;padding:0.2rem 0.35rem;font-size:0.75rem;max-width:140px">
      ${p.firstImage ? `<img src="${escHtml(p.firstImage)}" style="width:18px;height:18px;object-fit:cover;border-radius:2px;flex-shrink:0">` : ''}
      <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1">${escHtml(p.name)}</span>
      <button onmousedown="removeProduct(${p.id})" style="border:none;background:none;cursor:pointer;color:var(--color-gray-500);line-height:1;padding:0;font-size:0.9rem;flex-shrink:0">&times;</button>
    </div>
  `).join('');
}

document.addEventListener('click', e => {
  if (!e.target.closest('#prod-search') && !e.target.closest('#prod-suggestions')) {
    document.getElementById('prod-suggestions').style.display = 'none';
  }
});

// ── Email builder ─────────────────────────────────────────────────────────────
function buildEmailHtml() {
  const title   = document.getElementById('nl-title').value || '';
  const intro   = document.getElementById('nl-intro').value || '';
  const body    = document.getElementById('nl-body').value || '';
  const btnText = document.getElementById('nl-btn-text').value || 'Ver colección';
  const btnLink = document.getElementById('nl-btn-link').value || '#';

  const productsSection = selectedProducts.length > 0 ? `
    <div style="padding:0 24px 8px">
      <p style="margin:0 0 12px;font-size:14px;font-weight:600;color:#111">Productos destacados</p>
      <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px">
        ${selectedProducts.map(p => `
          <a href="https://www.kokett.ad/product/${p.id}" style="text-decoration:none;color:#111;display:block">
            ${p.firstImage ? `<img src="${escHtml(p.firstImage)}" style="width:100%;aspect-ratio:3/4;object-fit:cover;border-radius:4px;display:block">` : '<div style="width:100%;aspect-ratio:3/4;background:#f3f4f6;border-radius:4px"></div>'}
            <div style="margin-top:6px;font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(p.name)}</div>
            <div style="font-size:12px;color:#666">${parseFloat(p.price).toFixed(2)} €</div>
          </a>`).join('')}
      </div>
    </div>` : '';

  return `<div style="font-family:system-ui,sans-serif;color:#111">
    <div style="background:${SITE_PRIMARY};padding:20px 24px;text-align:center">
      <span style="color:${SITE_ON_PRIMARY};font-size:20px;font-weight:700;letter-spacing:0.1em">KOKETT</span>
    </div>
    ${title ? `<div style="padding:24px 24px 0"><h2 style="font-size:22px;font-weight:700;margin:0">${esc(title)}</h2></div>` : ''}
    ${intro ? `<div style="padding:16px 24px 0"><p style="margin:0;color:#555;line-height:1.6">${esc(intro)}</p></div>` : ''}
    ${body  ? `<div style="padding:16px 24px 0"><p style="margin:0;line-height:1.6">${esc(body).replace(/\n/g,'<br>')}</p></div>` : ''}
    ${productsSection ? `<div style="padding:24px 0 0">${productsSection}</div>` : ''}
    <div style="padding:24px;text-align:center">
      <a href="${esc(btnLink)}" style="display:inline-block;background:${SITE_PRIMARY};color:${SITE_ON_PRIMARY};padding:12px 32px;text-decoration:none;border-radius:4px;font-weight:600">${esc(btnText)}</a>
    </div>
    <div style="padding:16px 24px;border-top:1px solid #eee;text-align:center;color:#999;font-size:12px">
      Kokett · Andorra · <a href="#" style="color:#999">Darse de baja</a>
    </div>
  </div>`;
}

function esc(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function updatePreview() {
  document.getElementById('preview-inner').innerHTML = buildEmailHtml();
}

function sendNewsletter(type) {
  const subject = document.getElementById('nl-subject').value.trim();
  if (!subject) { setMsg('El asunto es obligatorio.', false); return; }
  const body = buildEmailHtml();
  fetch('/admin/api/marketing.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action: type==='test' ? 'send-test' : 'send-all', subject, body})
  }).then(r=>r.json()).then(d => setMsg(d.message || (d.success ? 'Enviado.' : 'Error.'), d.success));
}

function setMsg(text, ok) {
  const m = document.getElementById('nl-msg');
  m.textContent = text;
  m.style.color = ok ? 'var(--color-green)' : 'var(--color-red)';
}

function unsubscribe(id) {
  if (!confirm('¿Dar de baja?')) return;
  fetch('/admin/api/marketing.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'unsubscribe',id})})
    .then(()=>location.reload());
}

updatePreview();
</script>

<?php require __DIR__ . '/layout-footer.php'; ?>
