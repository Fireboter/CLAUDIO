<?php
$pageTitle = 'Aviso Legal';
require dirname(__DIR__) . '/pages/layout-header.php';
$rows = db_query("SELECT `value` FROM SiteSettings WHERE `key` = 'page_legal_notice'");
$content = $rows[0]['value'] ?? null;
$defaultContent = '<h2 style="font-size:1.25rem;font-weight:600;margin-bottom:0.75rem">Datos del titular</h2>
<p style="margin-bottom:1.5rem">Nuestra tienda · Andorra la Vella, Andorra</p>
<h2 style="font-size:1.25rem;font-weight:600;margin-bottom:0.75rem">Propiedad intelectual</h2>
<p style="margin-bottom:1.5rem">Todos los contenidos de este sitio web son propiedad de nuestra tienda. Queda prohibida su reproducción total o parcial sin autorización expresa.</p>
<h2 style="font-size:1.25rem;font-weight:600;margin-bottom:0.75rem">Responsabilidad</h2>
<p>No nos hacemos responsables de los daños que pudieran derivarse del uso de la información contenida en este sitio web.</p>';
?>
<div class="container py-8" style="max-width:800px">
  <h1 style="font-size:2rem;font-weight:700;margin-bottom:2rem">Aviso Legal</h1>
  <div style="line-height:1.8;color:var(--c-text)">
    <?= $content ?: $defaultContent ?>
  </div>
</div>
<?php require dirname(__DIR__) . '/pages/layout-footer.php'; ?>
