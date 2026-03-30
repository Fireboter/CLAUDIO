<?php
$pageTitle = 'Política de Envíos';
$settingsRows = db_query('SELECT `key`, `value` FROM SiteSettings');
$settings = [];
foreach ($settingsRows as $r) $settings[$r['key']] = $r['value'];
require dirname(__DIR__) . '/pages/layout-header.php';
?>
<div class="container py-8" style="max-width:800px">
  <h1 style="font-size:2rem;font-weight:700;margin-bottom:2rem">Política de Envíos</h1>
  <div style="line-height:1.8;color:var(--color-gray-700)">
    <h2 style="font-size:1.125rem;font-weight:600;margin-bottom:0.75rem">Recogida en tienda</h2>
    <p style="margin-bottom:1.5rem">Gratuita. Disponible en nuestro punto de venta en Andorra la Vella.</p>
    <h2 style="font-size:1.125rem;font-weight:600;margin-bottom:0.75rem">España, Canarias, Ceuta y Melilla</h2>
    <p style="margin-bottom:1.5rem"><?= number_format((float)($settings['shipping_spain_price'] ?? 7.50), 2) ?> €. Gratuito para pedidos superiores a <?= number_format((float)($settings['shipping_spain_free_threshold'] ?? 80), 0) ?> €.</p>
    <h2 style="font-size:1.125rem;font-weight:600;margin-bottom:0.75rem">Portugal, Alemania y Francia</h2>
    <p style="margin-bottom:1.5rem"><?= number_format((float)($settings['shipping_europe_price'] ?? 12.00), 2) ?> €. Precio reducido de <?= number_format((float)($settings['shipping_europe_discounted_price'] ?? 4.50), 2) ?> € para pedidos superiores a <?= number_format((float)($settings['shipping_europe_discount_threshold'] ?? 80), 0) ?> €.</p>
    <h2 style="font-size:1.125rem;font-weight:600;margin-bottom:0.75rem">Plazos</h2>
    <p>Los pedidos se procesan en 1–2 días laborables. El tiempo de entrega depende del destino.</p>
  </div>
</div>
<?php require dirname(__DIR__) . '/pages/layout-footer.php'; ?>
