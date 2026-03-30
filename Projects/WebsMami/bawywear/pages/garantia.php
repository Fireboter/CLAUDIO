<?php
$pageTitle = 'Garantía';
require dirname(__DIR__) . '/pages/layout-header.php';
$rows = db_query("SELECT `value` FROM SiteSettings WHERE `key` = 'page_garantia'");
$content = $rows[0]['value'] ?? null;
$defaultContent = '<p style="margin-bottom:1.5rem">Todos nuestros productos están garantizados contra defectos de fabricación durante 2 años desde la fecha de compra, conforme a la normativa vigente de la Unión Europea.</p>
<p style="margin-bottom:1.5rem">Si recibes un producto defectuoso, contacta con nosotros en los 30 días siguientes a la recepción y te ofreceremos un cambio o reembolso completo.</p>
<p>Para ejercer la garantía, contacta con nuestro equipo a través del formulario de contacto indicando tu número de pedido y una descripción del problema.</p>';
?>
<div class="container py-8" style="max-width:800px">
  <h1 style="font-size:2rem;font-weight:700;margin-bottom:2rem">Garantía</h1>
  <div style="line-height:1.8;color:var(--c-text)">
    <?= $content ?: $defaultContent ?>
  </div>
</div>
<?php require dirname(__DIR__) . '/pages/layout-footer.php'; ?>
