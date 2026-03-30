<?php
$pageTitle = 'Información de Contacto';
require dirname(__DIR__) . '/pages/layout-header.php';
$rows = db_query("SELECT `value` FROM SiteSettings WHERE `key` = 'page_contact_info'");
$content = $rows[0]['value'] ?? null;
$defaultContent = '<p><strong>Email:</strong> contacto@tienda.com</p>
<p><strong>Ubicación:</strong> Andorra la Vella, Andorra</p>
<p style="margin-top:1.5rem"><a href="/pages/contact" style="font-weight:600;text-decoration:underline">Ir al formulario de contacto →</a></p>';
?>
<div class="container py-8" style="max-width:600px">
  <h1 style="font-size:2rem;font-weight:700;margin-bottom:2rem">Información de Contacto</h1>
  <div style="line-height:2;color:var(--c-text)">
    <?= $content ?: $defaultContent ?>
  </div>
</div>
<?php require dirname(__DIR__) . '/pages/layout-footer.php'; ?>
