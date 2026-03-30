<?php
$PRODUCTS_PER_PAGE = 24;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $PRODUCTS_PER_PAGE;

$collectionId    = isset($_GET['collection']) ? (int)$_GET['collection'] : null;
$groupId         = isset($_GET['group']) ? (int)$_GET['group'] : null;
$searchQuery     = trim($_GET['search'] ?? '');
$sizeFilter      = trim($_GET['size'] ?? '');
$colorFilter     = trim($_GET['color'] ?? '');
$materialFilter  = trim($_GET['material'] ?? '');
$novedadesFilter = isset($_GET['novedades']) && $_GET['novedades'] === 'true';

// Exclude gift card collection from regular shop
$tarjetaRegalo = db_query("SELECT id FROM Collection WHERE name = 'Tarjeta Regalo'");
$tarjetaRegaloId = $tarjetaRegalo[0]['id'] ?? null;

// Build product query
$where = ['1=1'];
$params = [];

if ($tarjetaRegaloId && !$collectionId) {
    $where[] = '(p.collectionId IS NULL OR p.collectionId != ?)';
    $params[] = $tarjetaRegaloId;
}
if ($collectionId) { $where[] = 'p.collectionId = ?'; $params[] = $collectionId; }
if ($novedadesFilter) { $where[] = 'p.id IN (SELECT productId FROM ProductNovedades)'; }
if ($groupId) { $where[] = 'c.groupId = ?'; $params[] = $groupId; }
if ($searchQuery) {
    $where[] = '(p.name LIKE ? OR p.description LIKE ?)';
    $term = "%$searchQuery%";
    $params[] = $term; $params[] = $term;
}
if ($sizeFilter)     { $where[] = 'EXISTS (SELECT 1 FROM ProductVariant WHERE productId = p.id AND size = ?)';     $params[] = $sizeFilter; }
if ($colorFilter)    { $where[] = 'EXISTS (SELECT 1 FROM ProductVariant WHERE productId = p.id AND color = ?)';    $params[] = $colorFilter; }
if ($materialFilter) { $where[] = 'EXISTS (SELECT 1 FROM ProductVariant WHERE productId = p.id AND material = ?)'; $params[] = $materialFilter; }

$whereClause = implode(' AND ', $where);

$products = db_query("SELECT DISTINCT p.id, p.name, p.price, p.discount,
        COALESCE(
            (SELECT url FROM ProductImage WHERE productId = p.id AND url REGEXP 'p_[^/]+$' ORDER BY displayOrder ASC LIMIT 1),
            JSON_UNQUOTE(JSON_EXTRACT(p.images, '\$[0]'))
        ) as coverImage
    FROM Product p LEFT JOIN Collection c ON p.collectionId = c.id WHERE $whereClause ORDER BY p.id DESC LIMIT $PRODUCTS_PER_PAGE OFFSET $offset", $params);
$total = db_query("SELECT COUNT(DISTINCT p.id) as total FROM Product p LEFT JOIN Collection c ON p.collectionId = c.id WHERE $whereClause", $params)[0]['total'] ?? 0;
$totalPages = (int)ceil($total / $PRODUCTS_PER_PAGE);

// Fetch filter options
$groups = db_query('SELECT * FROM CollectionGroup ORDER BY id');
$allCollections = db_query('SELECT c.*, COUNT(p.id) as productCount FROM Collection c LEFT JOIN Product p ON c.id = p.collectionId GROUP BY c.id');
$nonEmpty = array_filter($allCollections, fn($c) => $c['productCount'] > 0 && $c['id'] != $tarjetaRegaloId);

$groupsWithCollections = array_values(array_filter(array_map(function($g) use ($nonEmpty) {
    $g['collections'] = array_values(array_filter($nonEmpty, fn($c) => $c['groupId'] == $g['id']));
    return $g;
}, $groups), fn($g) => count($g['collections']) > 0));

$novedadesCount = db_query('SELECT COUNT(*) as c FROM ProductNovedades')[0]['c'] ?? 0;

$variantRows = db_query("SELECT DISTINCT v.size, v.color, v.material FROM ProductVariant v JOIN Product p ON v.productId = p.id LEFT JOIN Collection c ON p.collectionId = c.id WHERE $whereClause", $params);
$sizes     = array_values(array_unique(array_filter(array_column($variantRows, 'size'))));
$colors    = array_values(array_unique(array_filter(array_column($variantRows, 'color'))));
$materials = array_values(array_unique(array_filter(array_column($variantRows, 'material'))));
sort($sizes); sort($colors); sort($materials);

require dirname(__DIR__) . '/pages/layout-header.php';
?>

<div class="filter-overlay" id="filter-overlay" onclick="toggleFilters()"></div>

<div class="shop-layout">
  <!-- Filters Sidebar -->
  <aside class="shop-filters" id="shop-filters">
    <h2 style="font-weight:700;margin-bottom:1.5rem">Filtros</h2>

    <?php if ($novedadesCount > 0): ?>
    <a href="<?= $novedadesFilter ? '/shop' : '/shop?novedades=true' ?>" class="filter-novedades <?= $novedadesFilter ? 'active' : '' ?>">Novedades</a>
    <?php endif; ?>

    <?php foreach ($groupsWithCollections as $group):
      $groupOpen = $collectionId && in_array($collectionId, array_column($group['collections'], 'id'));
    ?>
    <details class="filter-card" <?= $groupOpen ? 'open' : '' ?>>
      <summary><?= e($group['name']) ?></summary>
      <div class="filter-card-body">
        <?php foreach ($group['collections'] as $col):
          $isActive = $collectionId == $col['id'];
          $href = $isActive ? '/shop' : '/shop?collection=' . $col['id'];
        ?>
          <a href="<?= $href ?>" class="filter-col-item <?= $isActive ? 'active' : '' ?>"><?= e($col['name']) ?></a>
        <?php endforeach; ?>
      </div>
    </details>
    <?php endforeach; ?>

    <?php if (!empty($sizes) || !empty($colors) || !empty($materials)):
      $filtersOpen = $sizeFilter || $colorFilter || $materialFilter;
    ?>
    <details class="filter-card" <?= $filtersOpen ? 'open' : '' ?>>
      <summary>Filtros</summary>
      <div class="filter-card-body">
        <?php if (!empty($sizes)): ?>
        <details class="filter-subcard" <?= $sizeFilter ? 'open' : '' ?>>
          <summary>Talla</summary>
          <div class="filter-subcard-body">
            <?php foreach ($sizes as $size): ?>
            <label class="filter-checkbox-item">
              <input type="checkbox" onchange="toggleFilter('size','<?= e($size) ?>',this.checked)" <?= $sizeFilter === $size ? 'checked' : '' ?>>
              <?= e(strtoupper($size)) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </details>
        <?php endif; ?>
        <?php if (!empty($colors)): ?>
        <details class="filter-subcard" <?= $colorFilter ? 'open' : '' ?>>
          <summary>Color</summary>
          <div class="filter-subcard-body">
            <?php foreach ($colors as $color): ?>
            <label class="filter-checkbox-item">
              <input type="checkbox" onchange="toggleFilter('color','<?= e($color) ?>',this.checked)" <?= $colorFilter === $color ? 'checked' : '' ?>>
              <?= e($color) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </details>
        <?php endif; ?>
        <?php if (!empty($materials)): ?>
        <details class="filter-subcard" <?= $materialFilter ? 'open' : '' ?>>
          <summary>Material</summary>
          <div class="filter-subcard-body">
            <?php foreach ($materials as $material): ?>
            <label class="filter-checkbox-item">
              <input type="checkbox" onchange="toggleFilter('material','<?= e($material) ?>',this.checked)" <?= $materialFilter === $material ? 'checked' : '' ?>>
              <?= e($material) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </details>
        <?php endif; ?>
      </div>
    </details>
    <?php endif; ?>

    <?php if ($collectionId || $groupId || $sizeFilter || $colorFilter || $materialFilter || $novedadesFilter || $searchQuery): ?>
      <a href="/shop" class="filter-clear">Limpiar filtros</a>
    <?php endif; ?>
  </aside>

  <!-- Products -->
  <div>
    <form method="GET" action="/shop" class="shop-search-row">
      <input type="text" name="search" value="<?= e($searchQuery) ?>" placeholder="Buscar productos..." style="flex:1;min-width:0;padding:0.5rem 1rem;border:2px solid var(--c-border);border-radius:0;font-size:1rem;background:var(--c-surface);color:var(--c-text);font-family:inherit">
      <button type="button" class="shop-filter-btn" onclick="toggleFilters()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
        Filtros
      </button>
    </form>

    <p style="color:var(--color-gray-500);font-size:0.875rem;margin-bottom:1rem"><?= $total ?> producto<?= $total !== 1 ? 's' : '' ?></p>

    <?php if (empty($products)): ?>
      <p style="color:var(--color-gray-500)">No se encontraron productos.</p>
    <?php else: ?>
      <div class="product-grid">
        <?php foreach ($products as $product): ?>
          <?php
          $firstImage = $product['coverImage'] ?? null;
          $hasDiscount = $product['discount'] && (float)$product['discount'] < (float)$product['price'];
          ?>
          <a href="/product/<?= (int)$product['id'] ?>" class="product-card">
            <?php if ($firstImage): ?>
              <img src="<?= e($firstImage) ?>" alt="<?= e($product['name']) ?>" loading="lazy">
            <?php else: ?>
              <div style="aspect-ratio:3/4;background:var(--color-gray-100)"></div>
            <?php endif; ?>
            <div class="product-card-body">
              <h3><?= e($product['name']) ?></h3>
              <div class="product-price">
                <?php if ($hasDiscount): ?>
                  <span class="original"><?= number_format($product['price'], 2) ?> €</span>
                  <span class="discounted"><?= number_format($product['discount'], 2) ?> €</span>
                <?php else: ?>
                  <span><?= number_format($product['price'], 2) ?> €</span>
                <?php endif; ?>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>

      <?php if ($totalPages > 1): ?>
      <div style="display:flex;justify-content:center;gap:0.5rem;margin-top:2rem;flex-wrap:wrap">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"
             style="padding:0.5rem 1rem;border:2px solid <?= $i === $page ? 'var(--c-primary)' : 'var(--c-border)' ?>;border-radius:0;background:<?= $i === $page ? 'var(--c-primary)' : 'var(--c-surface)' ?>;color:<?= $i === $page ? 'var(--c-text-on-primary)' : 'var(--c-text)' ?>;text-decoration:none;font-weight:700">
            <?= $i ?>
          </a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<script>
function toggleFilters() {
  const sidebar = document.getElementById('shop-filters');
  const overlay = document.getElementById('filter-overlay');
  const icon = document.getElementById('filter-toggle-icon');
  const open = sidebar.classList.toggle('open');
  overlay.classList.toggle('open');
  icon.style.transform = open ? 'rotate(180deg)' : '';
  document.body.style.overflow = open ? 'hidden' : '';
}
function toggleFilter(key, value, checked) {
  const params = new URLSearchParams(window.location.search);
  if (checked) { params.set(key, value); } else { params.delete(key); }
  params.delete('page');
  window.location.href = '/shop?' + params.toString();
}
</script>
<?php require dirname(__DIR__) . '/pages/layout-footer.php'; ?>
