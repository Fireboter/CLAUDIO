<?php
$pageTitle = 'Quiénes Somos';
require dirname(__DIR__) . '/pages/layout-header.php';
$rows = db_query("SELECT `value` FROM SiteSettings WHERE `key` = 'page_quienes_somos'");
$content = $rows[0]['value'] ?? null;
$defaultContent = '<p style="margin-bottom:1.5rem">Somos una tienda de moda independiente comprometida con la calidad, el estilo y la identidad propia.</p>
<p style="margin-bottom:1.5rem">Seleccionamos cada pieza con cuidado para ofrecerte una colección que combina tendencias actuales con prendas atemporales.</p>
<p>Creemos en la moda como forma de expresión personal, y trabajamos para que cada cliente encuentre algo que realmente le represente.</p>';
?>
<div class="container py-8" style="max-width:800px">
  <h1 style="font-size:2rem;font-weight:700;margin-bottom:2rem">Quiénes Somos</h1>
  <div style="line-height:1.8;color:var(--c-text)">
    <?= $content ?: $defaultContent ?>
  </div>
</div>
<?php require dirname(__DIR__) . '/pages/layout-footer.php'; ?>
