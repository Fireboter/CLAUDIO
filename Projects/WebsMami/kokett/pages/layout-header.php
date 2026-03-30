<?php
require_once dirname(__DIR__) . '/lang/lang.php';
$cartCount = cart_item_count();
$currentLang = $_SESSION['lang'] ?? 'es';

// Load theme colors from DB
$_cr = db_query("SELECT `key`,`value` FROM SiteSettings WHERE `key` LIKE 'color_%'");
$_c  = []; foreach ($_cr as $_r) $_c[$_r['key']] = $_r['value'];
$_cBg  = $_c['color_bg']      ?? '#ffffff';
$_cSrf = $_c['color_surface'] ?? '#f9fafb';
$_cPri = $_c['color_primary'] ?? '#111111';
$_cTxt = $_c['color_text']    ?? '#111827';
$_cBdr = $_c['color_border']  ?? '#e5e7eb';
if (!function_exists('_lum')) {
    function _lum(string $hex): float {
        $hex = ltrim($hex, '#');
        [$r,$g,$b] = [hexdec(substr($hex,0,2))/255, hexdec(substr($hex,2,2))/255, hexdec(substr($hex,4,2))/255];
        return 0.2126*$r + 0.7152*$g + 0.0722*$b;
    }
}
$_cOnPri = $_c['color_text_on_primary'] ?? (_lum($_cPri) > 0.35 ? '#000000' : '#ffffff');
$_currentPath = strtok($_SERVER['REQUEST_URI'], '?');
?>
<!DOCTYPE html>
<html lang="<?= $currentLang ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?><?= e(SITE_NAME) ?></title>
  <link rel="icon" type="image/png" href="/assets/icon-black.png">
  <link rel="apple-touch-icon" href="/assets/icon-black.png">
  <link rel="manifest" href="/manifest.json">
  <link rel="stylesheet" href="/assets/css/main.css?v=4">
  <style>:root{--c-bg:<?= $_cBg ?>;--c-surface:<?= $_cSrf ?>;--c-primary:<?= $_cPri ?>;--c-text:<?= $_cTxt ?>;--c-border:<?= $_cBdr ?>;--c-text-on-primary:<?= $_cOnPri ?>}</style>
  <meta name="theme-color" content="<?= $_cBg ?>">
</head>
<body>
<svg width="0" height="0" style="position:absolute;overflow:hidden" aria-hidden="true">
  <defs>
    <filter id="logo-color" x="0%" y="0%" width="100%" height="100%" color-interpolation-filters="sRGB">
      <feFlood flood-color="<?= $_cTxt ?>" result="color"/>
      <feComposite in="color" in2="SourceGraphic" operator="in"/>
    </filter>
  </defs>
</svg>
<header class="site-header">
  <div class="header-inner">
    <a href="/" class="logo"><img src="/assets/logo.png" alt="<?= e(SITE_NAME) ?>" class="logo-img" style="height:40px;width:auto;display:block;filter:url(#logo-color)"></a>
    <nav class="main-nav">
      <a href="/shop" <?= $_currentPath === '/shop' ? 'class="active"' : '' ?>><?= t('nav.shop') ?></a>
      <a href="/pages/contact" <?= $_currentPath === '/pages/contact' ? 'class="active"' : '' ?>><?= t('nav.contact') ?></a>
    </nav>
    <div class="header-actions">
      <a href="/shop" class="shop-icon-link <?= $_currentPath === '/shop' ? 'active' : '' ?>" aria-label="Tienda">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
      </a>
      <a href="/cart" class="cart-link" aria-label="Cesta">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
        <?php if ($cartCount > 0): ?>
        <span class="cart-count" id="cart-count"><?= $cartCount ?></span>
        <?php else: ?>
        <span class="cart-count" id="cart-count" style="display:none"></span>
        <?php endif; ?>
      </a>
      <div class="lang-switcher">
        <?php if ($currentLang === 'es'): ?>
          <a href="?lang=ca">CA</a>
        <?php else: ?>
          <a href="?lang=es">ES</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</header>
<main>
