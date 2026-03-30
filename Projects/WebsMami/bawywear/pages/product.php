<?php
if (!isset($productId)) { http_response_code(404); require __DIR__ . '/404.php'; exit; }

$products = db_query('SELECT p.*, c.name as collectionName, c.image as collectionImage FROM Product p LEFT JOIN Collection c ON p.collectionId = c.id WHERE p.id = ?', [$productId]);
if (empty($products)) { http_response_code(404); require __DIR__ . '/404.php'; exit; }
$product = $products[0];

$variants = db_query('SELECT *, (stock - reserved) as availableStock FROM ProductVariant WHERE productId = ?', [$productId]);
foreach ($variants as &$v) {
    $v['stock'] = max(0, (int)$v['availableStock']);
    $cv = $v['customValues'];
    $v['customValues'] = !empty($cv) ? (is_array($cv) ? $cv : (json_decode($cv, true) ?? [])) : [];
}
unset($v);

// Build dimensions from customValues first, then standard columns
$dimensions = []; // [name => [value, ...]]
foreach ($variants as $v) {
    foreach ($v['customValues'] as $k => $val) {
        if (!isset($dimensions[$k])) $dimensions[$k] = [];
        if (!in_array($val, $dimensions[$k])) $dimensions[$k][] = $val;
    }
}
// Standard columns as fallback only for variants that have no customValues
foreach ([['Talla','size'],['Color','color'],['Material','material']] as [$dim,$col]) {
    foreach ($variants as $v) {
        if (!empty($v['customValues'])) continue; // already handled via customValues
        if (!empty($v[$col])) {
            if (!isset($dimensions[$dim])) $dimensions[$dim] = [];
            if (!in_array($v[$col], $dimensions[$dim])) $dimensions[$dim][] = $v[$col];
        }
    }
}

$images = db_query('SELECT * FROM ProductImage WHERE productId = ? ORDER BY displayOrder', [$productId]);
// Only keep entries that point to local uploads (not external/stale URLs)
$images = array_values(array_filter($images, fn($img) => preg_match('#/p_[^/]+$#', $img['url'] ?? '')));
if (empty($images) && $product['images']) {
    $legacy = json_decode($product['images'], true) ?? [];
    foreach ($legacy as $i => $url) {
        $images[] = ['id' => -$i, 'url' => $url, 'displayOrder' => $i];
    }
}

$related = [];
if ($product['collectionId']) {
    $related = db_query('SELECT id, name, price, discount, images FROM Product WHERE collectionId = ? AND id != ? LIMIT 4', [$product['collectionId'], $productId]);
}

// Fetch first image for each related product from ProductImage, fallback to legacy JSON
$relatedImages = [];
if (!empty($related)) {
    $relIds = array_column($related, 'id');
    $placeholders = implode(',', array_fill(0, count($relIds), '?'));
    $piRows = db_query("SELECT productId, url FROM ProductImage WHERE productId IN ($placeholders) ORDER BY displayOrder", $relIds);
    foreach ($piRows as $pi) {
        // Only use admin-uploaded images (p_ prefix); skip migration-generated entries with wrong paths
        if (!isset($relatedImages[$pi['productId']]) && !empty($pi['url']) && preg_match('#/p_[^/]+$#', $pi['url'])) {
            $url = $pi['url'];
            if ($url[0] !== '/' && strpos($url, 'http') !== 0) $url = '/' . $url;
            $relatedImages[$pi['productId']] = $url;
        }
    }
}

$pageTitle = $product['name'];
require dirname(__DIR__) . '/pages/layout-header.php';
?>

<div class="container py-8">
  <div class="product-layout">
    <!-- Image Gallery -->
    <div>
      <div style="aspect-ratio:3/4;position:relative;overflow:hidden;border-radius:8px;background:var(--color-gray-100)">
        <?php if (!empty($images)): ?>
          <img id="main-image" src="<?= e($images[0]['url']) ?>" alt="<?= e($product['name']) ?>" style="width:100%;height:100%;object-fit:cover">
        <?php endif; ?>
      </div>
      <?php if (count($images) > 1): ?>
      <div class="thumbnail-grid">
        <?php foreach ($images as $img): ?>
          <button onclick="document.getElementById('main-image').src='<?= e($img['url']) ?>'" style="border:2px solid transparent;border-radius:4px;overflow:hidden;padding:0;cursor:pointer;aspect-ratio:1;background:none">
            <img src="<?= e($img['url']) ?>" alt="" style="width:100%;height:100%;object-fit:cover">
          </button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Product Info -->
    <div>
      <p style="color:var(--color-gray-500);margin-bottom:0.5rem;font-size:0.875rem"><?= e($product['collectionName'] ?? '') ?></p>
      <h1 style="font-size:1.75rem;font-weight:700;margin-bottom:1rem"><?= e($product['name']) ?></h1>

      <div class="product-price" style="font-size:1.5rem;margin-bottom:1.5rem">
        <?php if ($product['discount'] && (float)$product['discount'] < (float)$product['price']): ?>
          <span class="original" style="font-size:1.125rem"><?= number_format($product['price'], 2) ?> €</span>
          <span class="discounted"><?= number_format($product['discount'], 2) ?> €</span>
        <?php else: ?>
          <span><?= number_format($product['price'], 2) ?> €</span>
        <?php endif; ?>
      </div>

      <?php if ($product['description']): ?>
      <div style="color:var(--color-gray-700);margin-bottom:1.5rem;line-height:1.75;font-size:0.95rem">
        <?= nl2br(e($product['description'])) ?>
      </div>
      <?php endif; ?>

      <!-- Variant selectors — built from dimensions -->
      <?php foreach ($dimensions as $dimName => $dimVals): ?>
      <div style="margin-bottom:1rem">
        <label style="font-weight:600;display:block;margin-bottom:0.5rem"><?= e($dimName) ?></label>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
          <?php foreach ($dimVals as $val): ?>
            <button class="opt-btn" data-dim="<?= e($dimName) ?>" data-val="<?= e($val) ?>"
                    style="padding:0.5rem 1rem;border:2px solid var(--c-border);border-radius:0;cursor:pointer;background:var(--c-surface);color:var(--c-text);font-family:inherit;font-size:0.875rem">
              <?= e($val) ?>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <p id="stock-status" style="color:var(--color-gray-500);font-size:0.875rem;margin-bottom:1rem;min-height:1.25rem"></p>

      <button id="add-to-cart-btn" onclick="addToCart()" style="width:100%;padding:1rem;background:var(--c-primary);color:var(--c-text-on-primary);border:none;border-radius:0;font-size:1rem;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;cursor:pointer;font-family:inherit" disabled>
        Selecciona una opción
      </button>
      <p id="add-to-cart-msg" class="hidden text-green" style="margin-top:0.5rem;text-align:center"></p>
    </div>
  </div>

  <!-- Related Products -->
  <?php if (!empty($related)): ?>
  <div style="margin-top:4rem">
    <h2 style="font-size:1.5rem;font-weight:700;margin-bottom:1.5rem">Productos relacionados</h2>
    <div class="product-grid">
      <?php foreach ($related as $rel): ?>
        <?php
        $relImg = $relatedImages[$rel['id']] ?? null;
        if (!$relImg) {
            foreach (json_decode($rel['images'] ?? '[]', true) ?? [] as $url) {
                if (!empty($url)) {
                    if ($url[0] !== '/' && strpos($url, 'http') !== 0) $url = '/' . $url;
                    $relImg = $url; break;
                }
            }
        }
        ?>
        <a href="/product/<?= $rel['id'] ?>" class="product-card">
          <?php if ($relImg): ?><img src="<?= e($relImg) ?>" alt="<?= e($rel['name']) ?>" loading="lazy"><?php else: ?><div style="aspect-ratio:3/4;background:var(--color-gray-100)"></div><?php endif; ?>
          <div class="product-card-body">
            <h3><?= e($rel['name']) ?></h3>
            <div class="product-price">
              <?php if ($rel['discount'] && (float)$rel['discount'] < (float)$rel['price']): ?>
                <span class="original"><?= number_format($rel['price'], 2) ?> €</span>
                <span class="discounted"><?= number_format($rel['discount'], 2) ?> €</span>
              <?php else: ?>
                <span><?= number_format($rel['price'], 2) ?> €</span>
              <?php endif; ?>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
const variants = <?= json_encode($variants) ?>;
const dimensions = <?= json_encode(array_map('array_values', $dimensions)) ?>;
const dimensionNames = <?= json_encode(array_keys($dimensions)) ?>;
let selectedOptions = {}; // {dimName: value}

// Attach click handlers to option buttons
document.querySelectorAll('.opt-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const dim = this.dataset.dim;
    document.querySelectorAll(`.opt-btn[data-dim="${CSS.escape(dim)}"]`)
      .forEach(b => { b.style.borderColor = 'var(--c-border)'; b.style.background = 'var(--c-surface)'; b.style.color = 'var(--c-text)'; b.style.fontWeight = ''; });
    this.style.borderColor = 'var(--c-primary)';
    this.style.background = 'var(--c-primary)';
    this.style.color = 'var(--c-text-on-primary)';
    this.style.fontWeight = '700';
    selectedOptions[dim] = this.dataset.val;
    updateVariantState();
  });
});

function getMatchingVariant() {
  const dimCount = dimensionNames.length;
  return variants.find(v => {
    // Build this variant's option map
    const vOpts = Object.assign({}, v.customValues || {});
    if (v.size)     vOpts['Talla']    = vOpts['Talla']    || v.size;
    if (v.color)    vOpts['Color']    = vOpts['Color']    || v.color;
    if (v.material) vOpts['Material'] = vOpts['Material'] || v.material;
    // All selected dimensions must match
    return Object.entries(selectedOptions).every(([dim, val]) => vOpts[dim] === val);
  });
}

function updateVariantState() {
  const allSelected = dimensionNames.every(d => selectedOptions[d] !== undefined);
  const v = allSelected ? getMatchingVariant() : null;
  const btn = document.getElementById('add-to-cart-btn');
  const status = document.getElementById('stock-status');
  if (!allSelected || !v) {
    btn.disabled = true;
    btn.textContent = dimensionNames.length > 0 ? 'Selecciona una opción' : 'Sin stock';
    status.textContent = '';
    return;
  }
  if (v.stock <= 0 && !v.onDemand) {
    btn.disabled = true;
    btn.textContent = 'Sin stock';
    status.textContent = 'No disponible';
    status.style.color = 'var(--color-red)';
  } else {
    btn.disabled = false;
    btn.textContent = 'Añadir a la cesta';
    status.textContent = v.onDemand ? 'Bajo pedido' : (v.stock < 5 ? `Últimas ${v.stock} unidades` : '');
    status.style.color = 'var(--color-gray-500)';
  }
}

// Auto-select when only one variant or all dimensions have a single value
if (variants.length === 1 && dimensionNames.length === 0) {
  updateVariantState(); // no-option single variant
} else {
  dimensionNames.forEach((dim, di) => {
    const vals = Object.values(dimensions)[di] || [];
    if (vals.length === 1) {
      selectedOptions[dim] = vals[0];
      document.querySelector(`.opt-btn[data-dim="${CSS.escape(dim)}"]`)?.click();
    }
  });
  if (Object.keys(selectedOptions).length === dimensionNames.length && dimensionNames.length > 0) {
    updateVariantState();
  }
}

function addToCart() {
  const allSelected = dimensionNames.every(d => selectedOptions[d] !== undefined);
  const v = allSelected ? getMatchingVariant() : (variants.length === 1 ? variants[0] : null);
  if (!v) return;
  const btn = document.getElementById('add-to-cart-btn');
  btn.disabled = true;
  btn.textContent = 'Añadiendo...';
  fetch('/api/cart.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'add', variantId: v.id, quantity: 1 })
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      const msg = document.getElementById('add-to-cart-msg');
      msg.textContent = '¡Añadido a la cesta!';
      msg.classList.remove('hidden');
      updateCartCount();
      setTimeout(() => msg.classList.add('hidden'), 3000);
    } else {
      alert(data.message || 'Error al añadir a la cesta');
    }
    updateVariantState();
  })
  .catch(() => { updateVariantState(); });
}
</script>

<?php require dirname(__DIR__) . '/pages/layout-footer.php'; ?>
