<?php
require_once dirname(__DIR__) . '/lang/lang.php';
$cartCount = cart_item_count();
$currentLang = $_SESSION['lang'] ?? 'es';

// Load theme colors from DB (single-quoted LIKE to avoid ANSI_QUOTES issues)
$_cr = db_query("SELECT `key`,`value` FROM SiteSettings WHERE `key` LIKE 'color_%'");
$_c  = []; foreach ($_cr as $_r) $_c[$_r['key']] = $_r['value'];
$_cBg  = $_c['color_bg']      ?? '#000000';
$_cSrf = $_c['color_surface'] ?? '#1a1a1a';
$_cPri = $_c['color_primary'] ?? '#FFB800';
$_cTxt = $_c['color_text']    ?? '#888888';
$_cBdr = $_c['color_border']  ?? '#333333';

// Luminance → decide contrast text color
if (!function_exists('_lum')) {
    function _lum(string $hex): float {
        $hex = ltrim($hex, '#');
        [$r,$g,$b] = [hexdec(substr($hex,0,2))/255, hexdec(substr($hex,2,2))/255, hexdec(substr($hex,4,2))/255];
        return 0.2126*$r + 0.7152*$g + 0.0722*$b;
    }
}
$_cOnPri = _lum($_cPri) > 0.35 ? '#000000' : '#ffffff';

// Compute CSS filter to colorize a black logo PNG to --c-primary
// Chain: brightness(0)→black, invert(1)→white, sepia(1)→H≈60°S≈100%L≈97%, then rotate/darken
if (!function_exists('_logo_filter')) {
    function _logo_filter(string $hex): string {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) return 'brightness(0) invert(1)';
        $r = hexdec(substr($hex,0,2))/255;
        $g = hexdec(substr($hex,2,2))/255;
        $b = hexdec(substr($hex,4,2))/255;
        $max = max($r,$g,$b); $min = min($r,$g,$b);
        $l = ($max+$min)/2;
        $d = $max - $min;
        if ($d < 0.001) {
            // Achromatic: just brightness
            $br = round($l * 100);
            return $br === 0 ? 'brightness(0)' : "brightness(0) invert(1) grayscale(1) brightness({$br}%)";
        }
        $s = $l > 0.5 ? $d/(2-$max-$min) : $d/($max+$min);
        if ($max == $r) $h = (($g-$b)/$d + ($g<$b ? 6 : 0));
        elseif ($max == $g) $h = (($b-$r)/$d + 2);
        else $h = (($r-$g)/$d + 4);
        $hDeg = round($h * 60); // 0–360
        // After sepia(1) from white, hue≈60°. Rotate to target.
        $hRot  = (($hDeg - 60) % 360 + 360) % 360;
        $satPc = round($s * 100);
        $brPc  = round($l / 0.97 * 100); // scale lightness from sepia's ~97%
        $f = "brightness(0) invert(1) sepia(1)";
        if ($hRot > 1)   $f .= " hue-rotate({$hRot}deg)";
        if ($satPc < 90) $f .= " saturate({$satPc}%)";
        if ($brPc  !== 100) $f .= " brightness({$brPc}%)";
        return $f;
    }
}
$_cLogoFilter = _logo_filter($_cPri);
?>
<!DOCTYPE html>
<html lang="<?= $currentLang ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?><?= e(SITE_NAME) ?></title>
  <link rel="stylesheet" href="/assets/css/main.css?v=4">
  <style>:root{--c-bg:<?= $_cBg ?>;--c-surface:<?= $_cSrf ?>;--c-primary:<?= $_cPri ?>;--c-text:<?= $_cTxt ?>;--c-border:<?= $_cBdr ?>;--c-text-on-primary:<?= $_cOnPri ?>}.logo-img{filter:<?= $_cLogoFilter ?>}</style>
  <link rel="manifest" href="/manifest.json">
  <link rel="icon" type="image/png" href="/assets/icon.png">
  <link rel="apple-touch-icon" href="/assets/icon.png">
  <meta name="theme-color" content="<?= $_cPri ?>">
</head>
<body>
<header class="site-header">
  <div class="header-inner">
    <a href="/" class="logo"><img src="/assets/logo.png" alt="<?= e(SITE_NAME) ?>" class="logo-img"></a>
    <nav class="main-nav">
      <a href="/shop"><?= t('nav.shop') ?></a>
      <a href="/pages/contact"><?= t('nav.contact') ?></a>
    </nav>
    <div class="header-actions">
      <a href="/cart" class="cart-link" aria-label="Cesta">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
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
