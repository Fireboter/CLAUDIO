<?php
$pageTitle = 'Ajustes';
try { $settingsRows = db_query('SELECT `key`, `value` FROM SiteSettings WHERE site = ?', [SITE_ID]); } catch (Throwable $_e) { $settingsRows = []; }
$s = [];
foreach ($settingsRows as $r) $s[$r['key']] = $r['value'];
$heroPos = json_decode($s['hero_image_position'] ?? '{"x":50,"y":50}', true);
require __DIR__ . '/layout-header.php';
?>

<div class="page-header">
  <h1>Ajustes del sitio</h1>
</div>

<form id="settings-form" class="admin-form" style="max-width:720px">

  <h2 style="font-weight:600;margin-bottom:1rem;font-size:1rem;border-bottom:1px solid var(--color-gray-200);padding-bottom:0.5rem">🖼 Hero</h2>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
    <div>
      <div class="form-group">
        <label>Título</label>
        <input type="text" name="hero_title" value="<?= e($s['hero_title'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Subtítulo</label>
        <input type="text" name="hero_subtitle" value="<?= e($s['hero_subtitle'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Texto del botón</label>
        <input type="text" name="hero_button_text" value="<?= e($s['hero_button_text'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Enlace del botón</label>
        <input type="text" name="hero_button_link" value="<?= e($s['hero_button_link'] ?? '/shop') ?>">
      </div>
      <?php
      $heroTextColorRaw = $s['hero_text_color'] ?? 'white';
      $heroTextColorMap = ['white'=>'#ffffff','black'=>'#111111','beige'=>'#f5f0e8','blue'=>'#1e40af'];
      $heroTextColor = isset($heroTextColorMap[$heroTextColorRaw]) ? $heroTextColorMap[$heroTextColorRaw] : (preg_match('/^#[0-9a-fA-F]{6}$/i', $heroTextColorRaw) ? $heroTextColorRaw : '#ffffff');
      ?>
      <div class="form-group" style="display:flex;align-items:center;gap:0.75rem">
        <input type="color" name="hero_text_color" id="cp-hero_text_color" value="<?= e($heroTextColor) ?>"
               style="width:44px;height:36px;padding:2px;border:2px solid var(--color-gray-300);border-radius:4px;cursor:pointer;flex-shrink:0"
               oninput="syncHeroTextColor()">
        <input type="text" id="ct-hero_text_color" value="<?= e($heroTextColor) ?>"
               style="width:84px;font-family:monospace;font-size:0.8rem;padding:0.3rem 0.4rem;border:1px solid var(--color-gray-300);border-radius:4px;flex-shrink:0"
               oninput="syncHeroTextColorText()">
        <div>
          <div style="font-weight:600;font-size:0.85rem">Color del texto</div>
          <div style="font-size:0.72rem;color:var(--color-gray-500)">Texto superpuesto en el hero</div>
        </div>
      </div>

      <?php $overlayEnabled = ($s['hero_overlay_enabled'] ?? '1') === '1';
            $overlayOpacity = (int)($s['hero_overlay_opacity'] ?? 50); ?>
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:0.6rem;cursor:pointer;user-select:none;font-weight:600;font-size:0.85rem">
          <input type="checkbox" id="hero-overlay-chk" name="hero_overlay_enabled" value="1"
                 <?= $overlayEnabled ? 'checked' : '' ?>
                 onchange="updateOverlayPreview()">
          Máscara de color sobre imagen
        </label>
        <div style="font-size:0.72rem;color:var(--color-gray-500);margin-top:0.2rem">Aplica el color de superficie del tema sobre la imagen del hero</div>
      </div>
      <div class="form-group" id="overlay-opacity-row" style="display:<?= $overlayEnabled ? 'block' : 'none' ?>">
        <label style="font-size:0.85rem;font-weight:600">Intensidad: <span id="overlay-opacity-lbl"><?= $overlayOpacity ?>%</span></label>
        <input type="range" name="hero_overlay_opacity" id="hero-overlay-range"
               min="5" max="90" value="<?= $overlayOpacity ?>"
               style="width:100%;margin-top:0.3rem"
               oninput="updateOverlayPreview()">
      </div>
    </div>

    <div>
      <div class="form-group">
        <label>Imagen hero <small style="color:var(--color-gray-500)">(clic para enfocar)</small></label>
        <div id="hero-container" onclick="setFocus(event)" style="position:relative;width:100%;aspect-ratio:16/9;background:var(--color-gray-200);border-radius:6px;overflow:hidden;cursor:crosshair;border:2px solid var(--color-gray-300)">
          <?php if (!empty($s['hero_image'])): ?>
          <img id="hero-img" src="<?= e($s['hero_image']) ?>"
               style="width:100%;height:100%;object-fit:cover;object-position:<?= (int)($heroPos['x']??50) ?>% <?= (int)($heroPos['y']??50) ?>%;pointer-events:none;position:absolute;inset:0">
          <?php endif; ?>
          <div id="hero-overlay-preview" style="position:absolute;inset:0;pointer-events:none;transition:opacity 0.2s"></div>
          <div id="hero-overlay" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:0.3rem;padding:1rem;pointer-events:none;text-align:center">
            <p id="prev-title" style="font-size:1rem;font-weight:700;margin:0;color:var(--hero-text,#fff);text-shadow:0 1px 4px rgba(0,0,0,0.5)"><?= e($s['hero_title'] ?? 'Título') ?></p>
            <p id="prev-subtitle" style="font-size:0.65rem;margin:0;color:var(--hero-text,#fff);text-shadow:0 1px 3px rgba(0,0,0,0.5)"><?= e($s['hero_subtitle'] ?? 'Subtítulo') ?></p>
            <span id="prev-btn" style="margin-top:0.25rem;font-size:0.6rem;padding:0.2rem 0.6rem;background:rgba(255,255,255,0.25);border:1px solid rgba(255,255,255,0.7);border-radius:9999px;color:var(--hero-text,#fff)"><?= e($s['hero_button_text'] ?? 'Ver colección') ?></span>
          </div>
          <div id="hero-dot" style="position:absolute;width:16px;height:16px;border-radius:50%;background:rgba(255,255,0,0.9);border:2px solid #000;transform:translate(-50%,-50%);pointer-events:none;left:<?= (int)($heroPos['x']??50) ?>%;top:<?= (int)($heroPos['y']??50) ?>%"></div>
        </div>
        <input type="file" name="hero_image_file" accept="image/*" style="margin-top:0.5rem" onchange="previewHero(this)">
        <input type="hidden" name="hero_image" value="<?= e($s['hero_image'] ?? '') ?>">
        <input type="hidden" name="hero_image_position" id="hero-pos-input" value="<?= e($s['hero_image_position'] ?? '{"x":50,"y":50,"scale":1}') ?>">
      </div>
    </div>
  </div>

  <h2 style="font-weight:600;margin:1.5rem 0 1rem;font-size:1rem;border-bottom:1px solid var(--color-gray-200);padding-bottom:0.5rem">📦 Envíos</h2>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
    <div class="form-group">
      <label>Precio España (€)</label>
      <input type="number" step="0.01" name="shipping_spain_price" value="<?= $s['shipping_spain_price'] ?? '7.50' ?>">
    </div>
    <div class="form-group">
      <label>Gratis España a partir de (€)</label>
      <input type="number" step="0.01" name="shipping_spain_free_threshold" value="<?= $s['shipping_spain_free_threshold'] ?? '80' ?>">
    </div>
    <div class="form-group">
      <label>Precio Europa (€)</label>
      <input type="number" step="0.01" name="shipping_europe_price" value="<?= $s['shipping_europe_price'] ?? '12.00' ?>">
    </div>
    <div class="form-group">
      <label>Precio Europa con descuento (€)</label>
      <input type="number" step="0.01" name="shipping_europe_discounted_price" value="<?= $s['shipping_europe_discounted_price'] ?? '4.50' ?>">
    </div>
    <div class="form-group">
      <label>Descuento Europa a partir de (€)</label>
      <input type="number" step="0.01" name="shipping_europe_discount_threshold" value="<?= $s['shipping_europe_discount_threshold'] ?? '80' ?>">
    </div>
  </div>

  <h2 style="font-weight:600;margin:1.5rem 0 1rem;font-size:1rem;border-bottom:1px solid var(--color-gray-200);padding-bottom:0.5rem">💰 Impuestos</h2>
  <div class="form-group" style="max-width:200px">
    <label>IVA / Tax (%)</label>
    <input type="number" step="0.01" name="tax_percentage" value="<?= $s['tax_percentage'] ?? '4.5' ?>">
  </div>

  <h2 style="font-weight:600;margin:1.5rem 0 1rem;font-size:1rem;border-bottom:1px solid var(--color-gray-200);padding-bottom:0.5rem">🎨 Colores del sitio</h2>

  <div style="margin-bottom:1.5rem">
    <div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-gray-500);margin-bottom:0.5rem">Paletas predefinidas</div>
    <div id="palette-grid" style="display:flex;flex-wrap:wrap;gap:0.35rem;max-height:180px;overflow-y:auto;padding:0.25rem 0"></div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:2rem;align-items:start">
    <div>
      <?php
      $colorDefs = [
        'color_bg'             => ['Fondo',               'Página y header',                  '#ffffff'],
        'color_surface'        => ['Superficie',          'Tarjetas, inputs, footer',         '#f9fafb'],
        'color_primary'        => ['Primario',            'Botones, badges, acentos',         '#111111'],
        'color_text_on_primary'=> ['Texto sobre primario','Texto en botones con color primario','#ffffff'],
        'color_text'           => ['Texto',               'Cuerpo y navegación',              '#111827'],
        'color_border'         => ['Borde',               'Separadores y contornos',          '#e5e7eb'],
      ];
      foreach ($colorDefs as $key => [$label, $desc, $default]):
        $val = $s[$key] ?? $default;
      ?>
      <div class="form-group" style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.75rem">
        <?php if ($key === 'color_text_on_primary'): ?>
        <div style="position:relative;width:160px;height:36px;border-radius:4px;overflow:hidden;cursor:pointer;flex-shrink:0;border:2px solid var(--color-gray-300)"
             id="tone-bar" onmousedown="toneDragStart(event)" ontouchstart="toneTouchStart(event)">
          <div style="position:absolute;inset:0;background:linear-gradient(to right,#000,#fff)"></div>
          <div id="tone-thumb" style="position:absolute;top:50%;width:16px;height:16px;border-radius:50%;border:2px solid rgba(128,128,128,0.5);pointer-events:none;transform:translate(-50%,-50%);box-shadow:0 1px 4px rgba(0,0,0,0.4)"></div>
        </div>
        <input type="color" name="<?= $key ?>" id="cp-<?= $key ?>" value="<?= e($val) ?>" style="display:none" oninput="syncColor('<?= $key ?>')">
        <?php else: ?>
        <input type="color" name="<?= $key ?>" id="cp-<?= $key ?>" value="<?= e($val) ?>"
               style="width:44px;height:36px;padding:2px;border:2px solid var(--color-gray-300);border-radius:4px;cursor:pointer;flex-shrink:0"
               oninput="syncColor('<?= $key ?>')">
        <?php endif; ?>
        <input type="text" id="ct-<?= $key ?>" value="<?= e($val) ?>"
               style="width:84px;font-family:monospace;font-size:0.8rem;padding:0.3rem 0.4rem;border:1px solid var(--color-gray-300);border-radius:4px;flex-shrink:0"
               oninput="syncColorText('<?= $key ?>')">
        <div>
          <div style="font-weight:600;font-size:0.85rem"><?= $label ?></div>
          <div style="font-size:0.72rem;color:var(--color-gray-500)"><?= $desc ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div>
      <div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--color-gray-500);margin-bottom:0.5rem">Vista previa</div>
      <div id="clr-preview" style="border:2px solid var(--color-gray-200);border-radius:6px;overflow:hidden;font-family:system-ui,sans-serif;user-select:none">
        <!-- Header -->
        <div id="pv-head" style="padding:0.5rem 0.9rem;display:flex;align-items:center;gap:0.75rem;border-bottom:2px solid">
          <span style="font-weight:900;font-size:0.85rem;letter-spacing:0.05em"><?= e(SITE_NAME) ?></span>
          <span style="display:flex;gap:0.6rem;font-size:0.6rem;font-weight:700;opacity:0.65"><span>SHOP</span><span>CONTACT</span></span>
          <span style="margin-left:auto;font-size:0.7rem">🛒</span>
        </div>
        <!-- Hero -->
        <div id="pv-hero" style="padding:1.4rem 1rem;text-align:center;position:relative;overflow:hidden">
          <div style="position:absolute;inset:0;background:rgba(0,0,0,0.4);pointer-events:none"></div>
          <div style="position:relative;z-index:1">
            <div id="pv-htitle" style="font-size:1rem;font-weight:900;text-transform:uppercase;letter-spacing:-0.02em;margin-bottom:0.25rem">NEW COLLECTION</div>
            <div id="pv-hsub" style="font-size:0.55rem;letter-spacing:0.15em;text-transform:uppercase;margin-bottom:0.65rem;opacity:0.7">SHOP THE LATEST DROPS</div>
            <span id="pv-hbtn" style="display:inline-block;padding:0.25rem 0.85rem;font-size:0.6rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase">VER COLECCIÓN</span>
          </div>
        </div>
        <!-- Products -->
        <div id="pv-products" style="padding:0.6rem;display:grid;grid-template-columns:repeat(3,1fr);gap:0.4rem">
          <?php for($i=0;$i<3;$i++): ?>
          <div id="pv-card-<?= $i ?>" style="overflow:hidden;border-width:1px;border-style:solid;border-radius:3px">
            <div style="aspect-ratio:3/4;background:#aaa"></div>
            <div style="padding:0.3rem">
              <div id="pv-ct-<?= $i ?>" style="font-size:0.55rem;font-weight:700;text-transform:uppercase;margin-bottom:0.1rem">PRODUCTO</div>
              <div id="pv-cp-<?= $i ?>" style="font-size:0.55rem">39.99€</div>
            </div>
          </div>
          <?php endfor; ?>
        </div>
        <!-- Input row -->
        <div id="pv-fwrap" style="padding:0.45rem 0.6rem;display:flex;gap:0.35rem">
          <div id="pv-inp" style="flex:1;padding:0.3rem 0.45rem;font-size:0.55rem;border-width:2px;border-style:solid;border-radius:2px">Email...</div>
          <div id="pv-ibtn" style="padding:0.3rem 0.7rem;font-size:0.55rem;font-weight:700;white-space:nowrap;text-transform:uppercase;border-radius:2px">Enviar</div>
        </div>
        <!-- Footer -->
        <div id="pv-foot" style="padding:0.5rem 0.9rem;text-align:center;font-size:0.55rem;border-top-width:1px;border-top-style:solid;opacity:0.7">
          © 2025 <?= e(SITE_NAME) ?>
        </div>
      </div>
    </div>
  </div>

  <h2 style="font-weight:600;margin:1.5rem 0 1rem;font-size:1rem;border-bottom:1px solid var(--color-gray-200);padding-bottom:0.5rem">📄 Páginas del sitio</h2>
  <p style="font-size:0.8rem;color:var(--color-gray-500);margin-bottom:1rem">El contenido se guarda como HTML. Puedes usar etiquetas como &lt;h2&gt;, &lt;p&gt;, &lt;ul&gt;, etc.</p>
  <?php
  $pageDefs = [
    'page_legal_notice'   => 'Aviso Legal',
    'page_privacy_policy' => 'Política de Privacidad',
    'page_quienes_somos'  => 'Quiénes Somos',
    'page_contact_info'   => 'Información de Contacto',
    'page_garantia'       => 'Garantía',
  ];
  $pageDefaults = [
    'page_legal_notice'   => '<h2 style="font-size:1.25rem;font-weight:600;margin-bottom:0.75rem">Datos del titular</h2>
<p style="margin-bottom:1.5rem">Nuestra tienda · Andorra la Vella, Andorra</p>
<h2 style="font-size:1.25rem;font-weight:600;margin-bottom:0.75rem">Propiedad intelectual</h2>
<p style="margin-bottom:1.5rem">Todos los contenidos de este sitio web son propiedad de nuestra tienda. Queda prohibida su reproducción total o parcial sin autorización expresa.</p>
<h2 style="font-size:1.25rem;font-weight:600;margin-bottom:0.75rem">Responsabilidad</h2>
<p>No nos hacemos responsables de los daños que pudieran derivarse del uso de la información contenida en este sitio web.</p>',
    'page_privacy_policy' => '<p style="margin-bottom:1.5rem">En cumplimiento del RGPD (UE) 2016/679, te informamos de cómo tratamos tus datos personales.</p>
<h2 style="font-size:1.125rem;font-weight:600;margin-bottom:0.75rem">Responsable del tratamiento</h2>
<p style="margin-bottom:1.5rem">Nuestra tienda · Andorra la Vella, Andorra</p>
<h2 style="font-size:1.125rem;font-weight:600;margin-bottom:0.75rem">Finalidad</h2>
<p style="margin-bottom:1.5rem">Gestión de pedidos, envío de newsletters (solo con consentimiento), atención al cliente.</p>
<h2 style="font-size:1.125rem;font-weight:600;margin-bottom:0.75rem">Derechos</h2>
<p>Puedes ejercer tus derechos de acceso, rectificación, supresión y portabilidad contactándonos a través del formulario de contacto.</p>',
    'page_quienes_somos'  => '<p style="margin-bottom:1.5rem">Somos una tienda de moda independiente comprometida con la calidad, el estilo y la identidad propia.</p>
<p style="margin-bottom:1.5rem">Seleccionamos cada pieza con cuidado para ofrecerte una colección que combina tendencias actuales con prendas atemporales.</p>
<p>Creemos en la moda como forma de expresión personal, y trabajamos para que cada cliente encuentre algo que realmente le represente.</p>',
    'page_contact_info'   => '<p><strong>Email:</strong> contacto@tienda.com</p>
<p><strong>Ubicación:</strong> Andorra la Vella, Andorra</p>
<p style="margin-top:1.5rem"><a href="/pages/contact" style="font-weight:600;text-decoration:underline">Ir al formulario de contacto →</a></p>',
    'page_garantia'       => '<p style="margin-bottom:1.5rem">Todos nuestros productos están garantizados contra defectos de fabricación durante 2 años desde la fecha de compra, conforme a la normativa vigente de la Unión Europea.</p>
<p style="margin-bottom:1.5rem">Si recibes un producto defectuoso, contacta con nosotros en los 30 días siguientes a la recepción y te ofreceremos un cambio o reembolso completo.</p>
<p>Para ejercer la garantía, contacta con nuestro equipo a través del formulario de contacto indicando tu número de pedido y una descripción del problema.</p>',
  ];
  foreach ($pageDefs as $key => $label):
  ?>
  <div class="form-group">
    <label><?= $label ?></label>
    <textarea name="<?= $key ?>" rows="6" style="font-family:monospace;font-size:0.8rem;resize:vertical"><?= e($s[$key] ?: $pageDefaults[$key]) ?></textarea>
  </div>
  <?php endforeach; ?>

  <div style="margin-top:1.5rem;display:flex;gap:0.75rem;align-items:center">
    <button type="button" onclick="saveSettings()" class="btn btn-primary">Guardar ajustes</button>
    <p id="settings-msg" style="font-size:0.875rem;margin:0"></p>
  </div>
</form>

<script>
function setFocus(e) {
  const rect = e.currentTarget.getBoundingClientRect();
  const x = ((e.clientX - rect.left) / rect.width * 100).toFixed(1);
  const y = ((e.clientY - rect.top)  / rect.height * 100).toFixed(1);
  document.getElementById('hero-pos-input').value = JSON.stringify({x:parseFloat(x), y:parseFloat(y), scale:1});
  document.getElementById('hero-dot').style.left = x + '%';
  document.getElementById('hero-dot').style.top  = y + '%';
  const img = document.getElementById('hero-img');
  if (img) img.style.objectPosition = x + '% ' + y + '%';
}

function previewHero(input) {
  if (!input.files[0]) return;
  const url = URL.createObjectURL(input.files[0]);
  let img = document.getElementById('hero-img');
  const cont = document.getElementById('hero-container');
  if (!img) {
    img = document.createElement('img');
    img.id = 'hero-img';
    img.style = 'width:100%;height:100%;object-fit:cover;pointer-events:none';
    cont.insertBefore(img, cont.firstChild);
  }
  img.src = url;
}

// ===== Color palette preview =====
function getC() {
  return {
    bg:  document.getElementById('cp-color_bg').value,
    sf:  document.getElementById('cp-color_surface').value,
    pr:  document.getElementById('cp-color_primary').value,
    top: document.getElementById('cp-color_text_on_primary').value,
    tx:  document.getElementById('cp-color_text').value,
    bd:  document.getElementById('cp-color_border').value,
  };
}
function lum(hex) {
  hex = hex.replace('#','');
  const r=parseInt(hex.slice(0,2),16)/255, g=parseInt(hex.slice(2,4),16)/255, b=parseInt(hex.slice(4,6),16)/255;
  return 0.2126*r + 0.7152*g + 0.0722*b;
}
function onPrimary(pr) { return lum(pr) > 0.35 ? '#000' : '#fff'; }

function updatePreview() {
  const c = getC(), oP = c.top;
  const $ = id => document.getElementById(id);

  $('pv-head').style.cssText += `;background:${c.bg};color:${c.tx};border-bottom-color:${c.bd}`;
  $('pv-hero').style.background = c.sf;
  $('pv-htitle').style.color = c.pr;
  $('pv-hsub').style.color = c.tx;
  $('pv-hbtn').style.cssText += `;background:${c.pr};color:${oP}`;
  $('pv-products').style.background = c.bg;
  for (let i=0;i<3;i++) {
    $('pv-card-'+i).style.cssText += `;background:${c.sf};border-color:${c.bd}`;
    $('pv-ct-'+i).style.color = c.pr;
    $('pv-cp-'+i).style.color = c.tx;
  }
  $('pv-fwrap').style.background = c.bg;
  $('pv-inp').style.cssText += `;background:${c.sf};border-color:${c.bd};color:${c.tx}`;
  $('pv-ibtn').style.cssText += `;background:${c.pr};color:${oP}`;
  $('pv-foot').style.cssText += `;background:${c.sf};color:${c.tx};border-top-color:${c.bd}`;
}

function syncColor(key) {
  const v = document.getElementById('cp-'+key).value;
  document.getElementById('ct-'+key).value = v;
  if (key === 'color_text_on_primary') updateToneThumb(v);
  updatePreview();
}
function syncColorText(key) {
  const v = document.getElementById('ct-'+key).value;
  if (/^#[0-9a-fA-F]{6}$/.test(v)) {
    document.getElementById('cp-'+key).value = v;
    if (key === 'color_text_on_primary') updateToneThumb(v);
    updatePreview();
  }
}

updatePreview();

// ===== Black↔White tone slider for text-on-primary =====
function toneHexToPos(hex) {
  hex = hex.replace('#','');
  const r=parseInt(hex.slice(0,2),16), g=parseInt(hex.slice(2,4),16), b=parseInt(hex.slice(4,6),16);
  return ((r+g+b)/3) / 255;
}
function tonePosToHex(pct) {
  const v = Math.round(pct * 255).toString(16).padStart(2,'0');
  return '#' + v + v + v;
}
function updateToneThumb(hex) {
  const thumb = document.getElementById('tone-thumb');
  if (!thumb) return;
  const pct = toneHexToPos(hex);
  thumb.style.left = (pct * 100) + '%';
  thumb.style.background = hex;
  thumb.style.borderColor = pct > 0.5 ? 'rgba(0,0,0,0.35)' : 'rgba(255,255,255,0.6)';
}
function toneApply(pct) {
  const hex = tonePosToHex(pct);
  document.getElementById('cp-color_text_on_primary').value = hex;
  document.getElementById('ct-color_text_on_primary').value = hex;
  updateToneThumb(hex);
  updatePreview();
}
function toneClientX(e) { return e.touches ? e.touches[0].clientX : e.clientX; }
let _toneDrag = false;
function toneDragStart(e) {
  _toneDrag = true;
  const bar = document.getElementById('tone-bar');
  const rect = bar.getBoundingClientRect();
  toneApply(Math.max(0, Math.min(1, (toneClientX(e) - rect.left) / rect.width)));
  document.addEventListener('mousemove', _toneMoveH);
  document.addEventListener('mouseup', _toneEndH);
}
function _toneMoveH(e) {
  if (!_toneDrag) return;
  const bar = document.getElementById('tone-bar');
  const rect = bar.getBoundingClientRect();
  toneApply(Math.max(0, Math.min(1, (toneClientX(e) - rect.left) / rect.width)));
}
function _toneEndH() { _toneDrag = false; document.removeEventListener('mousemove', _toneMoveH); document.removeEventListener('mouseup', _toneEndH); }
function toneTouchStart(e) {
  e.preventDefault();
  const bar = document.getElementById('tone-bar');
  const rect = bar.getBoundingClientRect();
  toneApply(Math.max(0, Math.min(1, (toneClientX(e) - rect.left) / rect.width)));
  document.addEventListener('touchmove', _toneTouchMoveH, {passive:false});
  document.addEventListener('touchend', _toneTouchEndH);
}
function _toneTouchMoveH(e) { e.preventDefault(); const bar=document.getElementById('tone-bar'); const rect=bar.getBoundingClientRect(); toneApply(Math.max(0,Math.min(1,(toneClientX(e)-rect.left)/rect.width))); }
function _toneTouchEndH() { document.removeEventListener('touchmove',_toneTouchMoveH); document.removeEventListener('touchend',_toneTouchEndH); }
(function(){ const el=document.getElementById('cp-color_text_on_primary'); if(el) updateToneThumb(el.value); })();

// ===== Hero overlay mask =====
function updateOverlayPreview() {
  const chk = document.getElementById('hero-overlay-chk');
  const range = document.getElementById('hero-overlay-range');
  const row = document.getElementById('overlay-opacity-row');
  const lbl = document.getElementById('overlay-opacity-lbl');
  const el = document.getElementById('hero-overlay-preview');
  const enabled = chk.checked;
  row.style.display = enabled ? 'block' : 'none';
  lbl.textContent = range.value + '%';
  const sf = document.getElementById('cp-color_surface')?.value || '#f9fafb';
  el.style.background = enabled ? sf : 'transparent';
  el.style.opacity = enabled ? (range.value / 100) : 0;
}
updateOverlayPreview();

// ===== Hero text color free picker =====
function syncHeroTextColor() {
  const v = document.getElementById('cp-hero_text_color').value;
  document.getElementById('ct-hero_text_color').value = v;
  document.querySelectorAll('#prev-title,#prev-subtitle,#prev-btn').forEach(el => el.style.color = v);
}
function syncHeroTextColorText() {
  const v = document.getElementById('ct-hero_text_color').value;
  if (/^#[0-9a-fA-F]{6}$/.test(v)) { document.getElementById('cp-hero_text_color').value = v; document.querySelectorAll('#prev-title,#prev-subtitle,#prev-btn').forEach(el => el.style.color = v); }
}
document.querySelectorAll('#prev-title,#prev-subtitle,#prev-btn').forEach(el => el.style.color = '<?= e($heroTextColor) ?>');

// ===== Palettes =====
const PALETTES = [
  ['Midnight','#0a0a0a','#141414','#ffffff','#a0a0a0','#2a2a2a'],
  ['Onyx','#0d0d0d','#1a1a1a','#FFB800','#888888','#333333'],
  ['Charcoal','#161616','#212121','#e0e0e0','#a0a0a0','#303030'],
  ['Obsidian','#111111','#1c1c1c','#e11d48','#9ca3af','#2d2d2d'],
  ['Carbon','#0f0f0f','#1a1a1a','#00d4ff','#a0a0a0','#2a2a2a'],
  ['Slate Night','#0f172a','#1e293b','#38bdf8','#94a3b8','#334155'],
  ['Deep Ocean','#0a1628','#0f2044','#3b82f6','#93c5fd','#1e3a5f'],
  ['Dark Forest','#0a1a0a','#0f2a0f','#22c55e','#86efac','#1a3a1a'],
  ['Crimson Night','#1a0a0a','#2a1010','#ef4444','#fca5a5','#3a1515'],
  ['Violet Dark','#0f0a1a','#1a1030','#8b5cf6','#c4b5fd','#2a1a4a'],
  ['Espresso','#1a1008','#2a1c10','#d97706','#fcd34d','#3a2818'],
  ['Whiskey','#1c1208','#2e1e0e','#f59e0b','#fde68a','#3d2a14'],
  ['Rust','#1a0f08','#2a180c','#ea580c','#fed7aa','#3a2010'],
  ['Amber','#1a1208','#2a1e0c','#f59e0b','#fde68a','#3a2a10'],
  ['Mocha','#1a1210','#2a1e1c','#c2410c','#fdba74','#3a2a28'],
  ['Snow','#ffffff','#f9fafb','#111111','#374151','#e5e7eb'],
  ['Cloud','#f8fafc','#f1f5f9','#0f172a','#475569','#e2e8f0'],
  ['Paper','#fafaf9','#f5f5f4','#1c1917','#57534e','#e7e5e4'],
  ['Ivory','#fffef7','#fef9e7','#111827','#6b7280','#f3f0d7'],
  ['Linen','#faf6f1','#f3ece3','#2d2015','#6b5a4a','#e0d0c0'],
  ['Milk','#fefefe','#f7f7f7','#1a1a1a','#555555','#e0e0e0'],
  ['Pearl','#fefefe','#f0f0f0','#2563eb','#374151','#d1d5db'],
  ['Chalk','#f9f9f9','#f0f0f0','#000000','#444444','#dddddd'],
  ['Cream','#fffdf7','#fdf9ee','#1d1d1d','#666666','#e8e0c0'],
  ['Vanilla','#fefce8','#fef9c3','#713f12','#854d0e','#fde68a'],
  ['Spring','#f0fdf4','#dcfce7','#15803d','#166534','#bbf7d0'],
  ['Azure','#eff6ff','#dbeafe','#1d4ed8','#1e40af','#bfdbfe'],
  ['Rose','#fff1f2','#ffe4e6','#be123c','#9f1239','#fecdd3'],
  ['Lavender','#faf5ff','#f3e8ff','#7c3aed','#6d28d9','#e9d5ff'],
  ['Peach','#fff7ed','#ffedd5','#c2410c','#9a3412','#fed7aa'],
  ['Mint','#f0fdfa','#ccfbf1','#0f766e','#115e59','#99f6e4'],
  ['Blossom','#fdf2f8','#fce7f3','#be185d','#9d174d','#fbcfe8'],
  ['Ocean Mist','#f0f9ff','#e0f2fe','#0369a1','#075985','#bae6fd'],
  ['Golden','#fffbeb','#fef3c7','#b45309','#92400e','#fde68a'],
  ['Sage','#f1f5f1','#e3ede3','#3d6b42','#2d5232','#b8d4bb'],
  ['Electric','#000000','#111111','#00ff88','#cccccc','#222222'],
  ['Neon Violet','#0a0010','#140020','#cc00ff','#cc99ff','#200030'],
  ['Cyberpunk','#0a0a00','#141400','#ffff00','#aaaa00','#202000'],
  ['Hot Pink','#1a0010','#2a0020','#ff0080','#ff99cc','#3a0030'],
  ['Acid','#001a00','#002a00','#00ff00','#66ff66','#003000'],
  ['Aurora','#050010','#0a0025','#6600ff','#cc88ff','#150035'],
  ['Lava','#1a0000','#2a0000','#ff3300','#ff9966','#3a0000'],
  ['Tropical','#001a10','#002a18','#00ff88','#66ffbb','#003020'],
  ['Magenta','#1a0015','#2a0025','#ff00aa','#ff88dd','#3a0030'],
  ['Pixel Blue','#000a1a','#00142a','#0088ff','#66bbff','#001a3a'],
  ['Zinc','#18181b','#27272a','#71717a','#a1a1aa','#3f3f46'],
  ['Stone','#1c1917','#292524','#78716c','#a8a29e','#44403c'],
  ['Slate','#0f172a','#1e293b','#64748b','#94a3b8','#334155'],
  ['Gray Pro','#111111','#1f1f1f','#6b7280','#9ca3af','#374151'],
  ['Warm Gray','#1c1b19','#2d2b27','#a08060','#c4a882','#3d3b37'],
  ['Cool Gray','#f8f9fa','#e9ecef','#495057','#6c757d','#dee2e6'],
  ['Neutral','#fafaf9','#f5f5f4','#737373','#a3a3a3','#e5e5e5'],
  ['Taupe','#f5f0eb','#ede5dc','#6b5b4b','#9b8b7b','#d0c4b8'],
  ['Greige','#f4f0ec','#ede8e2','#7a6a5a','#9a8a7a','#d4c8bc'],
  ['Driftwood','#f5f0e8','#ede5d8','#8b6914','#b8891c','#d8cbb8'],
  ['Midnight Blue','#003153','#004070','#0070cc','#5ba3e0','#005c99'],
  ['Forest','#1a2e1a','#233323','#2d6a2d','#5ea05e','#3a4d3a'],
  ['Burgundy','#2d1216','#3d181d','#8b1a2a','#c45060','#4d2030'],
  ['Navy','#0a1628','#102040','#1e3a8a','#3b82f6','#1e3a5f'],
  ['Teal Wave','#0a1f1f','#0f2a2a','#0d9488','#2dd4bf','#143535'],
  ['Sand Dune','#fdf6e8','#f5ead0','#c8840a','#a06808','#e8d5a8'],
  ['Cherry','#1a0808','#2d1010','#dc2626','#f87171','#3d1818'],
  ['Sage Green','#f0f5f0','#e2ece2','#4a7c59','#355a42','#c0d4c4'],
  ['Royal','#0f0a2a','#18103d','#6d28d9','#a78bfa','#2a1a5a'],
  ['Copper','#1a0f08','#2d1a0c','#b45309','#d97706','#3d2a18'],
  ['Summer','#fffbf0','#fff7e0','#ff6b00','#ff9a40','#ffe0b0'],
  ['Autumn','#1f1008','#321810','#c05010','#e08040','#4a2818'],
  ['Winter','#f0f5ff','#e0ebff','#1a50c0','#4080e0','#c0d4f8'],
  ['Spring Garden','#f0fff0','#e0ffe0','#2a8a2a','#4aaa4a','#b0e8b0'],
  ['Tropical Summer','#fff8f0','#fff0e0','#e05000','#ff8030','#ffd0b0'],
  ['Harvest','#fff8f0','#ffefd8','#c04800','#e07020','#f0c888'],
  ['Polar','#f5f8ff','#eaf0ff','#2c5282','#4a7ab5','#c0d4f0'],
  ['Rainforest','#0a1a08','#102a0e','#228b22','#44aa44','#1c3a1a'],
  ['Desert','#faf5e8','#f0e8d0','#c8820a','#a06808','#e0cc90'],
  ['Arctic','#f0f9ff','#e0f4ff','#0369a1','#0ea5e9','#b0dcf0'],
  ['Off White','#fafaf8','#f2f2f0','#1a1a1a','#444444','#d8d8d4'],
  ['Supreme','#ffffff','#f5f5f5','#cc0000','#333333','#e0e0e0'],
  ['Hypebeast','#0a0a0a','#151515','#ff2200','#888888','#252525'],
  ['Streetwear','#0f0f0f','#1a1a1a','#f0f0f0','#aaaaaa','#2a2a2a'],
  ['Skate','#1a1a2e','#16213e','#e94560','#a8dadc','#0f3460'],
  ['Underground','#0d0d0d','#161616','#ffffff','#888888','#2a2a2a'],
  ['Vintage Wash','#f0eae0','#e5ddd0','#5a4030','#8a7060','#d0c4b0'],
  ['Urban','#141414','#1e1e1e','#ff8c00','#aaaaaa','#282828'],
  ['Varsity','#0a1a3a','#102448','#c8a820','#e8c840','#1a3258'],
  ['Gold & Black','#0a0a0a','#141414','#c8a820','#a08010','#252525'],
  ['Champagne','#faf6ee','#f0e8d8','#c09040','#a07820','#e0d0b0'],
  ['Platinum','#f0f0f2','#e4e4e8','#6080a8','#8090a8','#ccccdc'],
  ['Rosé','#f8f0f2','#f0e4e8','#c06080','#a04060','#e8d4d8'],
  ['Onyx Gold','#111108','#1a1a08','#d4a017','#aa8010','#252510'],
  ['Midnight Silver','#0a0a10','#14141e','#8090b8','#a0b0cc','#20202a'],
  ['Deep Jade','#0a1a10','#10281a','#1e8a5a','#2aaa72','#182e20'],
  ['Black Diamond','#080808','#121212','#e8e8f0','#a0a0b0','#1c1c24'],
  ['Dark Ruby','#1a080a','#280c10','#cc1040','#e85070','#3a1020'],
  ['Velvet','#120a18','#1e1028','#9040c8','#c080f0','#2a1838'],
  ['Zero','#ffffff','#fafafa','#000000','#888888','#eeeeee'],
  ['Mono','#f5f5f5','#ebebeb','#111111','#555555','#d5d5d5'],
  ['Pure','#ffffff','#f0f0f0','#2563eb','#1e40af','#dbeafe'],
  ['Stark','#ffffff','#f8f8f8','#000000','#333333','#e5e5e5'],
  ['Blueprint','#0a1628','#102040','#2563eb','#93c5fd','#1e3a5f'],
];

function applyPalette(idx) {
  const [,bg,sf,pr,tx,bd] = PALETTES[idx];
  ['color_bg','color_surface','color_primary','color_text','color_border'].forEach((k,i)=>{
    const v=[bg,sf,pr,tx,bd][i];
    document.getElementById('cp-'+k).value=v;
    document.getElementById('ct-'+k).value=v;
  });
  const autoTop = lum(pr) > 0.35 ? '#000000' : '#ffffff';
  document.getElementById('cp-color_text_on_primary').value = autoTop;
  document.getElementById('ct-color_text_on_primary').value = autoTop;
  updateToneThumb(autoTop);
  updatePreview();
}

(function buildPaletteGrid(){
  const grid=document.getElementById('palette-grid');
  PALETTES.forEach(([name,bg,sf,pr],i)=>{
    const btn=document.createElement('button');
    btn.type='button';
    btn.title=name;
    btn.onclick=()=>applyPalette(i);
    btn.style.cssText='display:flex;align-items:center;gap:3px;padding:3px 7px;border:1px solid var(--color-gray-200);border-radius:4px;background:transparent;cursor:pointer;font-size:0.68rem;white-space:nowrap;color:inherit';
    const autoTop = lum(pr) > 0.35 ? '#000' : '#fff';
    btn.innerHTML=`<span style="display:inline-flex;gap:2px">`+
      [bg,sf,pr,autoTop].map(c=>`<span style="display:inline-block;width:9px;height:9px;background:${c};border-radius:2px"></span>`).join('')+
      `</span>${name}`;
    grid.appendChild(btn);
  });
})();

// Live-update preview text
document.querySelector('input[name="hero_title"]').addEventListener('input', e => {
  document.getElementById('prev-title').textContent = e.target.value || 'Título';
});
document.querySelector('input[name="hero_subtitle"]').addEventListener('input', e => {
  document.getElementById('prev-subtitle').textContent = e.target.value || 'Subtítulo';
});
document.querySelector('input[name="hero_button_text"]').addEventListener('input', e => {
  document.getElementById('prev-btn').textContent = e.target.value || 'Ver colección';
});

async function saveSettings() {
  const form = document.getElementById('settings-form');
  const msg  = document.getElementById('settings-msg');
  const btn  = form.querySelector('button');
  btn.disabled = true; btn.textContent = 'Guardando...';

  const fileInput = form.querySelector('input[name="hero_image_file"]');
  if (fileInput.files[0]) {
    const fd = new FormData(); fd.append('image', fileInput.files[0]);
    const d = await fetch('/admin/api/settings.php?action=upload-image', {method:'POST', body:fd}).then(r=>r.json());
    if (d.url) form.querySelector('input[name="hero_image"]').value = d.url;
  }

  const fd2 = new FormData(form);
  const settings = {};
  fd2.forEach((v, k) => { if (k !== 'hero_image_file') settings[k] = v; });
  settings.hero_overlay_enabled = document.getElementById('hero-overlay-chk').checked ? '1' : '0';

  const res = await fetch('/admin/api/settings.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'save', settings})
  }).then(r=>r.json());

  msg.textContent = res.success ? '✓ Guardado' : (res.message || 'Error');
  msg.style.color = res.success ? 'var(--color-green)' : 'var(--color-red)';
  btn.disabled = false; btn.textContent = 'Guardar ajustes';
}
</script>

<?php require __DIR__ . '/layout-footer.php'; ?>
