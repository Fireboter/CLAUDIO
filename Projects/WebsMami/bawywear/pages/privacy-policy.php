<?php
$pageTitle = 'Política de Privacidad';
require dirname(__DIR__) . '/pages/layout-header.php';
$rows = db_query("SELECT `value` FROM SiteSettings WHERE `key` = 'page_privacy_policy'");
$content = $rows[0]['value'] ?? null;
$defaultContent = '<p style="margin-bottom:1.5rem">En cumplimiento del RGPD (UE) 2016/679, te informamos de cómo tratamos tus datos personales.</p>
<h2 style="font-size:1.125rem;font-weight:600;margin-bottom:0.75rem">Responsable del tratamiento</h2>
<p style="margin-bottom:1.5rem">Bawywear · contacto@bawywear.com</p>
<h2 style="font-size:1.125rem;font-weight:600;margin-bottom:0.75rem">Finalidad</h2>
<p style="margin-bottom:1.5rem">Gestión de pedidos, envío de newsletters (solo con consentimiento), atención al cliente.</p>
<h2 style="font-size:1.125rem;font-weight:600;margin-bottom:0.75rem">Derechos</h2>
<p>Puedes ejercer tus derechos de acceso, rectificación, supresión y portabilidad contactando a contacto@bawywear.com.</p>';
?>
<div class="container py-8" style="max-width:800px">
  <h1 style="font-size:2rem;font-weight:700;margin-bottom:2rem">Política de Privacidad</h1>
  <div style="line-height:1.8;color:var(--c-text)">
    <?= $content ?: $defaultContent ?>
  </div>
</div>
<?php require dirname(__DIR__) . '/pages/layout-footer.php'; ?>
