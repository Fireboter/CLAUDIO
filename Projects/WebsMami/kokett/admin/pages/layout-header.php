<?php
$_acPri = '#111111'; $_acOnPriDb = null;
try {
    $_acr = db_query("SELECT `key`,`value` FROM SiteSettings WHERE `key` IN ('color_primary','color_text_on_primary')");
    foreach ($_acr as $_ar) {
        if ($_ar['key'] === 'color_primary') $_acPri = $_ar['value'];
        if ($_ar['key'] === 'color_text_on_primary') $_acOnPriDb = $_ar['value'];
    }
} catch (Throwable $_e) { /* SiteSettings table may not exist yet – use defaults */ }
if (!function_exists('_lum')) {
    function _lum(string $hex): float {
        $hex = ltrim($hex, '#');
        [$r,$g,$b] = [hexdec(substr($hex,0,2))/255, hexdec(substr($hex,2,2))/255, hexdec(substr($hex,4,2))/255];
        return 0.2126*$r + 0.7152*$g + 0.0722*$b;
    }
}
$_acOnPri = $_acOnPriDb ?? (_lum($_acPri) > 0.35 ? '#000000' : '#ffffff');
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $_acPri))  $_acPri  = '#111111';
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $_acOnPri)) $_acOnPri = '#ffffff';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?>Admin <?= e(SITE_NAME) ?></title>
  <link rel="stylesheet" href="/assets/css/admin.css?v=2">
  <style>:root{--c-primary:<?= $_acPri ?>;--c-text-on-primary:<?= $_acOnPri ?>}</style>
</head>
<body class="admin-body">
<svg width="0" height="0" style="position:absolute;overflow:hidden" aria-hidden="true">
  <defs>
    <filter id="admin-logo-color" x="0%" y="0%" width="100%" height="100%" color-interpolation-filters="sRGB">
      <feFlood flood-color="<?= $_acOnPri ?>" result="color"/>
      <feComposite in="color" in2="SourceGraphic" operator="in"/>
    </filter>
  </defs>
</svg>
<div class="admin-layout">
  <aside class="admin-sidebar" style="background:<?= $_acPri ?>;color:<?= $_acOnPri ?>;border-right:1px solid rgba(128,128,128,0.2)">
    <div class="sidebar-logo">
      <a href="/admin/"><img src="/assets/logo.png" alt="<?= e(SITE_NAME) ?>" style="height:28px;width:auto;display:block;filter:url(#admin-logo-color)"></a>
    </div>
    <?php
    $currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $navItems = [
        '/admin/'          => 'Dashboard',
        '/admin/products'  => 'Productos',
        '/admin/orders'    => 'Pedidos',
        '/admin/contacts'  => 'Contactos',
        '/admin/marketing' => 'Marketing',
        '/admin/settings'  => 'Ajustes',
    ];
    foreach ($navItems as $href => $label):
        $active = $currentPath === $href || str_starts_with($currentPath, $href . '/') ? 'active' : '';
    ?>
      <a href="<?= $href ?>" class="sidebar-link <?= $active ?>"><?= $label ?></a>
    <?php endforeach; ?>
    <div class="sidebar-footer">
      <a href="<?= e(PARTNER_ADMIN_URL) ?>" target="_blank" class="sidebar-link" style="font-size:0.75rem;opacity:0.6">Ir a otro admin</a>
      <a href="/admin/logout" class="sidebar-link" style="color:#dc2626">Cerrar sesión</a>
    </div>
  </aside>
  <main class="admin-main">
    <div class="admin-content">
