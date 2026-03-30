<?php
$settings = [];
$rows = db_query('SELECT `key`, `value` FROM SiteSettings');
foreach ($rows as $row) $settings[$row['key']] = $row['value'];

$allGroups = db_query('SELECT * FROM CollectionGroup ORDER BY id');
$collections = db_query('SELECT c.*, COUNT(p.id) as productCount FROM Collection c LEFT JOIN Product p ON c.id = p.collectionId GROUP BY c.id HAVING productCount > 0');

$collectionsByGroup = [];
foreach ($collections as $c) {
    if ($c['groupId']) $collectionsByGroup[$c['groupId']][] = $c;
}

$groups = array_values(array_filter(array_map(function($g) use ($collectionsByGroup) {
    $g['collections'] = $collectionsByGroup[$g['id']] ?? [];
    return $g;
}, $allGroups), fn($g) => count($g['collections']) > 0));

// Fetch up to 5 product images per collection for slideshows
$collectionIds = array_column($collections, 'id');
$collectionProductImages = [];
if ($collectionIds) {
    $placeholders = implode(',', array_fill(0, count($collectionIds), '?'));
    $products = db_query("SELECT collectionId, images FROM Product WHERE collectionId IN ($placeholders) AND images IS NOT NULL AND images != '[]' ORDER BY id DESC", $collectionIds);
    foreach ($products as $p) {
        $cid = $p['collectionId'];
        if (isset($collectionProductImages[$cid]) && count($collectionProductImages[$cid]) >= 5) continue;
        $imgs = json_decode($p['images'], true);
        if (!empty($imgs[0])) $collectionProductImages[$cid][] = $imgs[0];
    }
}

// Attach images array to each collection
foreach ($groups as &$group) {
    foreach ($group['collections'] as &$col) {
        $imgs = $collectionProductImages[$col['id']] ?? [];
        if (empty($imgs) && $col['image']) $imgs = [$col['image']];
        $col['slideImages'] = $imgs;
    }
}
unset($group, $col);

require dirname(__DIR__) . '/pages/layout-header.php';
?>

<!-- Hero Section -->
<section class="hero">
  <?php
    $heroPosRaw = $settings['hero_image_position'] ?? '';
    $heroPosArr = $heroPosRaw ? json_decode($heroPosRaw, true) : null;
    $heroPosCSS = $heroPosArr ? (int)($heroPosArr['x']??50).'% '.(int)($heroPosArr['y']??50).'%' : '50% 50%';
  ?>
  <?php
    $heroOverlay  = ($settings['hero_overlay_enabled'] ?? '1') === '1';
    $heroImgOpacity = $heroOverlay ? max(0.1, 1 - (int)($settings['hero_overlay_opacity'] ?? 50) / 100) : 1;
  ?>
  <?php if (!empty($settings['hero_image'])): ?>
    <img src="<?= e($settings['hero_image']) ?>" alt="Hero" class="hero-bg"
         style="object-position:<?= $heroPosCSS ?>;opacity:<?= $heroImgOpacity ?>">
  <?php else: ?>
    <div style="position:absolute;inset:0;background:linear-gradient(to right,#1f2937,#111827);opacity:0.5"></div>
  <?php endif; ?>
  <div class="hero-content">
    <h1><?= e($settings['hero_title'] ?? t('landing.hero.title')) ?></h1>
    <p><?= e($settings['hero_subtitle'] ?? t('landing.hero.subtitle')) ?></p>
    <a href="<?= e($settings['hero_button_link'] ?? '/shop') ?>" class="btn btn-white">
      <?= e($settings['hero_button_text'] ?? t('landing.hero.cta')) ?>
    </a>
  </div>
</section>

<!-- Marquee Strip -->
<div class="marquee-strip">
  <div class="marquee-track">
    <?php $items = ['NEW ARRIVALS', 'STREETWEAR', 'LIMITED DROPS', 'FREE SHIPPING OVER 80€', 'NEW ARRIVALS', 'STREETWEAR', 'LIMITED DROPS', 'FREE SHIPPING OVER 80€', 'NEW ARRIVALS', 'STREETWEAR', 'LIMITED DROPS', 'FREE SHIPPING OVER 80€', 'NEW ARRIVALS', 'STREETWEAR', 'LIMITED DROPS', 'FREE SHIPPING OVER 80€']; foreach ($items as $i => $item): ?>
    <span class="marquee-item"><?= $item ?></span><?php if ($i < count($items)-1): ?><span class="marquee-sep">✦</span><?php endif; ?>
    <?php endforeach; ?>
  </div>
</div>

<!-- Featured Collections -->
<section class="py-20" style="background:var(--c-surface)">
  <div class="container">
    <?php if (empty($groups)): ?>
      <div style="text-align:center;padding:5rem 0;color:var(--color-gray-500)">No collections available</div>
    <?php else: ?>
    <div class="group-carousel" id="groupCarousel"
         data-groups="<?= e(json_encode(array_map(fn($g) => [
           'id'   => $g['id'],
           'name' => $g['name'],
           'collections' => array_map(fn($c) => [
             'id'     => $c['id'],
             'name'   => $c['name'],
             'images' => $c['slideImages'],
           ], $g['collections']),
         ], $groups))) ?>">

      <!-- Group Title Navigation -->
      <div class="group-title-nav">
        <button class="group-title-side" id="prevGroupTitle" onclick="carousel.prev()"></button>
        <h2 class="group-title-current" id="currentGroupTitle"></h2>
        <button class="group-title-side" id="nextGroupTitle" onclick="carousel.next()"></button>
      </div>

      <!-- Desktop Arrow Buttons -->
      <button class="carousel-nav-btn carousel-prev" id="carouselPrev" onclick="carousel.prev()" aria-label="Previous group">
        <svg width="28" height="28" fill="none" stroke="#1f2937" stroke-width="2" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
      </button>
      <button class="carousel-nav-btn carousel-next" id="carouselNext" onclick="carousel.next()" aria-label="Next group">
        <svg width="28" height="28" fill="none" stroke="#1f2937" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
      </button>

      <!-- Collections Grid -->
      <div id="grid-stage">
        <div class="collections-grid" id="collectionsGrid"
             ontouchstart="carousel.touchStart(event)"
             ontouchmove="carousel.touchMove(event)"
             ontouchend="carousel.touchEnd()">
        </div>
      </div>

      <!-- Expand/Collapse Button -->
      <div style="display:flex;justify-content:center;margin-top:2rem" id="expandWrap">
        <button class="expand-btn" id="expandBtn" onclick="carousel.toggleExpand()">
          <div class="expand-btn-icon">
            <svg id="expandIcon" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
        </button>
      </div>

      <!-- Mobile Indicators -->
      <div class="group-indicators" id="groupIndicators"></div>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- About Section -->
<section class="py-20" style="background:var(--c-surface)">
  <div class="container text-center" style="max-width:48rem;margin:0 auto">
    <h2 style="font-size:1.875rem;font-weight:700;margin-bottom:1.5rem"><?= t('landing.about.title') ?></h2>
    <p style="color:var(--color-gray-500);font-size:1.125rem;line-height:1.75;margin-bottom:2rem"><?= t('landing.about.description') ?></p>
    <a href="/pages/quienes-somos" style="font-weight:700"><?= t('landing.about.readMore') ?> &rarr;</a>
  </div>
</section>

<script>
(function() {
  var el = document.getElementById('groupCarousel');
  if (!el) return;

  var groups       = JSON.parse(el.dataset.groups);
  var currentIndex = 0;
  var isExpanded   = false;
  var isAnimating  = false;
  var autoTimer    = null;
  var touchStart   = null;
  var touchEnd     = null;
  var cardTimers   = [];

  var EASE = 'cubic-bezier(0.4, 0, 0.2, 1)';
  var DUR  = 420; // ms

  // ── Build grid HTML ──────────────────────────────────────────────────────────

  function buildGridHTML(group) {
    var cols = isExpanded ? group.collections : group.collections.slice(0, 3);
    return cols.map(function(col, i) {
      var imgs = col.images && col.images.length ? col.images : [];
      var imgHtml = imgs.map(function(src, j) {
        return '<img src="' + escHtml(src) + '" alt="' + escHtml(col.name) + '"' +
          ' style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;transition:opacity 1.2s ease-in-out;opacity:' + (j === 0 ? '1' : '0') + '"' +
          ' data-img-index="' + j + '">';
      }).join('');
      return '<a href="/shop?collection=' + col.id + '" class="col-card" data-card-index="' + i + '" data-total="' + cols.length + '">' +
        '<div class="col-card-bg">' + (imgHtml || '<div style="width:100%;height:100%;background:var(--color-gray-200)"></div>') + '</div>' +
        '<div class="col-card-name"><h3>' + escHtml(col.name) + '</h3></div>' +
        '</a>';
    }).join('');
  }

  // ── Card image rotators ──────────────────────────────────────────────────────

  function startCardRotators(grid) {
    grid.querySelectorAll('.col-card').forEach(function(card) {
      var cardIdx  = parseInt(card.dataset.cardIndex);
      var total    = parseInt(card.dataset.total);
      var imgs     = card.querySelectorAll('img');
      if (imgs.length < 2) return;
      var cur = 0;
      var t = setTimeout(function() {
        var iv = setInterval(function() {
          imgs[cur].style.opacity = '0';
          cur = (cur + 1) % imgs.length;
          imgs[cur].style.opacity = '1';
        }, total * 3000);
        cardTimers.push(iv);
      }, cardIdx * 3000);
      cardTimers.push(t);
    });
  }

  function clearCardTimers() {
    cardTimers.forEach(function(t) { clearTimeout(t); clearInterval(t); });
    cardTimers = [];
  }

  // ── Update UI chrome ─────────────────────────────────────────────────────────

  function updateChrome(group) {
    var prev = groups[(currentIndex - 1 + groups.length) % groups.length];
    var next = groups[(currentIndex + 1) % groups.length];
    var showNav = groups.length > 1;
    document.getElementById('prevGroupTitle').textContent = showNav ? prev.name : '';
    document.getElementById('currentGroupTitle').textContent = group.name;
    document.getElementById('nextGroupTitle').textContent = showNav ? next.name : '';
    document.getElementById('carouselPrev').style.display = showNav ? '' : 'none';
    document.getElementById('carouselNext').style.display = showNav ? '' : 'none';

    var expandIcon = document.getElementById('expandIcon');
    expandIcon.style.transform = isExpanded ? 'rotate(180deg)' : '';
    document.getElementById('expandWrap').style.display = group.collections.length > 3 ? 'flex' : 'none';

    var wrap = document.getElementById('groupIndicators');
    if (groups.length <= 1) { wrap.style.display = 'none'; return; }
    wrap.innerHTML = groups.map(function(_, i) {
      return '<button class="group-dot' + (i === currentIndex ? ' active' : '') + '" onclick="carousel.goTo(' + i + ')" aria-label="Group ' + (i+1) + '"></button>';
    }).join('');
  }

  // ── Render with slide animation ──────────────────────────────────────────────

  function render(dir) {
    var group = groups[currentIndex];
    var grid  = document.getElementById('collectionsGrid');
    var stage = document.getElementById('grid-stage');

    updateChrome(group);

    if (dir && grid.innerHTML.trim() !== '') {
      // Snapshot outgoing content into an absolute clone
      var out = document.createElement('div');
      out.className = grid.className;
      out.innerHTML = grid.innerHTML;
      out.style.cssText = 'position:absolute;top:0;left:0;width:100%;pointer-events:none;will-change:transform,opacity;';
      stage.style.minHeight = grid.offsetHeight + 'px';
      stage.insertBefore(out, grid);

      // Place new grid off-screen (no transition yet)
      var fromX = dir === 'left' ? '100%' : '-100%';
      var toX   = dir === 'left' ? '-100%' : '100%';
      grid.style.cssText = 'transform:translateX(' + fromX + ');opacity:0;will-change:transform,opacity;';
      clearCardTimers();
      grid.innerHTML = buildGridHTML(group);

      // Trigger simultaneous slide
      requestAnimationFrame(function() {
        requestAnimationFrame(function() {
          var t = 'transform ' + DUR + 'ms ' + EASE + ', opacity ' + DUR + 'ms ease';
          out.style.transition = t;
          out.style.transform  = 'translateX(' + toX + ')';
          out.style.opacity    = '0.25';

          grid.style.transition = t;
          grid.style.transform  = 'translateX(0)';
          grid.style.opacity    = '1';
        });
      });

      setTimeout(function() {
        if (out.parentNode) out.parentNode.removeChild(out);
        grid.style.cssText = '';
        stage.style.minHeight = '';
        startCardRotators(grid);
        isAnimating = false;
      }, DUR + 30);

    } else {
      // First render or expand toggle — no animation
      clearCardTimers();
      grid.style.cssText = '';
      grid.innerHTML = buildGridHTML(group);
      startCardRotators(grid);
      isAnimating = false;
    }
  }

  // ── Auto-rotate ──────────────────────────────────────────────────────────────

  function startAuto() {
    stopAuto();
    if (groups.length <= 1) return;
    autoTimer = setInterval(function() { goTo((currentIndex + 1) % groups.length, 'left'); }, 20000);
  }
  function stopAuto() { if (autoTimer) { clearInterval(autoTimer); autoTimer = null; } }

  // ── Navigation ───────────────────────────────────────────────────────────────

  function goTo(index, dir) {
    if (isAnimating) return;
    isAnimating  = true;
    currentIndex = index;
    isExpanded   = false;
    render(dir || (index > currentIndex ? 'left' : 'right'));
    startAuto();
  }

  // ── Public API ───────────────────────────────────────────────────────────────

  window.carousel = {
    prev: function() { goTo((currentIndex - 1 + groups.length) % groups.length, 'right'); },
    next: function() { goTo((currentIndex + 1) % groups.length, 'left'); },
    goTo: goTo,
    toggleExpand: function() {
      isExpanded = !isExpanded;
      if (isExpanded) stopAuto(); else startAuto();
      isAnimating = false;
      render(null);
    },
    touchStart: function(e) { touchStart = e.targetTouches[0].clientX; touchEnd = null; },
    touchMove:  function(e) { touchEnd = e.targetTouches[0].clientX; },
    touchEnd:   function() {
      if (!touchStart || !touchEnd) return;
      var d = touchStart - touchEnd;
      if (d > 50)  carousel.next();
      if (d < -50) carousel.prev();
    },
  };

  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  render(null);
  startAuto();
})();
</script>

<?php require dirname(__DIR__) . '/pages/layout-footer.php'; ?>
