<?php
$cartItems = array_values(cart_get());
if (empty($cartItems)) { redirect('/cart'); }

$cartTotal = cart_total();
$settingsRows = db_query('SELECT `key`, `value` FROM SiteSettings');
$settings = [];
foreach ($settingsRows as $r) $settings[$r['key']] = $r['value'];

$spainPrice        = (float)($settings['shipping_spain_price'] ?? 7.50);
$spainThreshold    = (float)($settings['shipping_spain_free_threshold'] ?? 80);
$europePrice       = (float)($settings['shipping_europe_price'] ?? 12.00);
$europeDiscounted  = (float)($settings['shipping_europe_discounted_price'] ?? 4.50);
$europeThreshold   = (float)($settings['shipping_europe_discount_threshold'] ?? 80);

$pageTitle = 'Checkout';
require dirname(__DIR__) . '/pages/layout-header.php';
?>

<div class="container py-8" style="max-width:1000px">
  <h1 style="font-size:1.875rem;font-weight:700;margin-bottom:2rem">Checkout</h1>

  <div class="checkout-layout">
    <!-- Form -->
    <div>
      <form id="checkout-form">
        <h2 style="font-weight:700;margin-bottom:1rem;font-size:1.125rem">Datos de envío</h2>
        <div class="form-group">
          <label>Nombre Completo *</label>
          <input type="text" name="name" required>
        </div>
        <div class="form-group">
          <label>Email *</label>
          <input type="email" name="email" required>
        </div>
        <div class="form-group">
          <label>Teléfono *</label>
          <input type="tel" name="phone" required>
        </div>
        <div class="form-group">
          <label>Dirección *</label>
          <input type="text" name="address" required>
        </div>
        <div class="form-row-2">
          <div class="form-group">
            <label>Ciudad *</label>
            <input type="text" name="city" required>
          </div>
          <div class="form-group">
            <label>Código Postal *</label>
            <input type="text" name="postalCode" required>
          </div>
        </div>

        <h2 style="font-weight:700;margin:1.5rem 0 1rem;font-size:1.125rem">Método de envío</h2>
        <div style="border:1px solid var(--color-gray-200);border-radius:8px;overflow:hidden">
          <label style="display:flex;align-items:center;gap:1rem;padding:1rem;cursor:pointer;border-bottom:1px solid var(--color-gray-200)">
            <input type="radio" name="shippingMethod" value="pickup" checked onchange="updateShipping()">
            <div style="flex:1">
              <p style="font-weight:500">Recogida en tienda</p>
              <p style="font-size:0.875rem;color:var(--color-gray-500)">Gratis</p>
            </div>
            <span style="font-weight:600">Gratis</span>
          </label>
          <label style="display:flex;align-items:center;gap:1rem;padding:1rem;cursor:pointer;border-bottom:1px solid var(--color-gray-200)">
            <input type="radio" name="shippingMethod" value="spain" onchange="updateShipping()">
            <div style="flex:1">
              <p style="font-weight:500">España, Canarias, Ceuta y Melilla</p>
              <p style="font-size:0.875rem;color:var(--color-gray-500)">Gratis a partir de <?= number_format($spainThreshold, 0) ?> €</p>
            </div>
            <span style="font-weight:600"><?= $cartTotal >= $spainThreshold ? 'Gratis' : number_format($spainPrice, 2) . ' €' ?></span>
          </label>
          <label style="display:flex;align-items:center;gap:1rem;padding:1rem;cursor:pointer">
            <input type="radio" name="shippingMethod" value="europe" onchange="updateShipping()">
            <div style="flex:1">
              <p style="font-weight:500">Portugal, Alemania y Francia</p>
              <p style="font-size:0.875rem;color:var(--color-gray-500)"><?= number_format($europeDiscounted, 2) ?> € a partir de <?= number_format($europeThreshold, 0) ?> €</p>
            </div>
            <span style="font-weight:600"><?= $cartTotal >= $europeThreshold ? number_format($europeDiscounted, 2) . ' €' : number_format($europePrice, 2) . ' €' ?></span>
          </label>
        </div>

        <h2 style="font-weight:700;margin:1.5rem 0 1rem;font-size:1.125rem">Código de descuento</h2>
        <div style="display:flex;gap:0.5rem">
          <input type="text" id="discount-input" placeholder="GIFT-XXXX-XXXX" style="flex:1;padding:0.625rem 0.875rem;border:1px solid var(--color-gray-300);border-radius:4px;font-size:1rem;text-transform:uppercase">
          <button type="button" onclick="applyDiscount()" style="padding:0.625rem 1.25rem;background:var(--c-primary);color:var(--c-text-on-primary);border:none;border-radius:0;cursor:pointer;font-family:inherit;font-weight:700;text-transform:uppercase;letter-spacing:0.05em">Aplicar</button>
        </div>
        <p id="discount-msg" style="font-size:0.875rem;margin-top:0.5rem"></p>

        <p id="error-msg" class="text-red" style="margin-top:1rem"></p>
      </form>
    </div>

    <!-- Order Summary -->
    <div style="position:sticky;top:80px;background:var(--color-gray-50);padding:1.5rem;border-radius:8px">
      <h2 style="font-weight:700;margin-bottom:1rem;font-size:1.125rem">Resumen del pedido</h2>
      <?php foreach ($cartItems as $item): ?>
      <div style="display:flex;justify-content:space-between;margin-bottom:0.75rem;font-size:0.9rem">
        <span><?= e($item['name']) ?> <?php if($item['size']): ?>(<?= e($item['size']) ?>)<?php endif; ?> × <?= $item['quantity'] ?></span>
        <span><?= number_format($item['price'] * $item['quantity'], 2) ?> €</span>
      </div>
      <?php endforeach; ?>
      <hr style="margin:1rem 0;border:none;border-top:1px solid var(--color-gray-200)">
      <div style="display:flex;justify-content:space-between;margin-bottom:0.5rem">
        <span>Subtotal</span><span><?= number_format($cartTotal, 2) ?> €</span>
      </div>
      <div id="shipping-line" style="display:flex;justify-content:space-between;margin-bottom:0.5rem">
        <span>Envío</span><span id="shipping-cost">Gratis</span>
      </div>
      <div id="discount-line" style="display:none;justify-content:space-between;margin-bottom:0.5rem;color:var(--color-green)">
        <span>Descuento</span><span id="discount-amount">-0.00 €</span>
      </div>
      <hr style="margin:1rem 0;border:none;border-top:1px solid var(--color-gray-200)">
      <div style="display:flex;justify-content:space-between;font-weight:700;font-size:1.125rem;margin-bottom:1.5rem">
        <span>Total</span><span id="total-display"><?= number_format($cartTotal, 2) ?> €</span>
      </div>
      <button onclick="submitCheckout()" style="width:100%;padding:1rem;background:var(--c-primary);color:var(--c-text-on-primary);border:none;border-radius:0;font-size:1rem;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;cursor:pointer;font-family:inherit" id="pay-btn">
        Pagar ahora
      </button>
    </div>
  </div>
</div>

<!-- Hidden Redsys form -->
<form id="redsys-form" method="POST" action="" style="display:none">
  <input type="hidden" name="Ds_SignatureVersion" id="Ds_SignatureVersion">
  <input type="hidden" name="Ds_MerchantParameters" id="Ds_MerchantParameters">
  <input type="hidden" name="Ds_Signature" id="Ds_Signature">
</form>

<script>
const cartTotal = <?= $cartTotal ?>;
const shipping = {
  pickup:  0,
  spain:   <?= $cartTotal >= $spainThreshold ? 0 : $spainPrice ?>,
  europe:  <?= $cartTotal >= $europeThreshold ? $europeDiscounted : $europePrice ?>
};
let discountAmount = 0;

function getShippingMethod() {
  const el = document.querySelector('input[name="shippingMethod"]:checked');
  return el ? el.value : 'pickup';
}

function updateShipping() {
  const method = getShippingMethod();
  const cost = shipping[method];
  document.getElementById('shipping-cost').textContent = cost === 0 ? 'Gratis' : cost.toFixed(2) + ' €';
  updateTotal();
}

function updateTotal() {
  const method = getShippingMethod();
  const total = Math.max(0, cartTotal + shipping[method] - discountAmount);
  document.getElementById('total-display').textContent = total.toFixed(2) + ' €';
}

function applyDiscount() {
  const code = document.getElementById('discount-input').value.trim().toUpperCase();
  if (!code) return;
  fetch('/api/discount.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ code })
  })
  .then(r => r.json())
  .then(data => {
    const msg = document.getElementById('discount-msg');
    if (data.valid) {
      discountAmount = data.amount;
      msg.textContent = 'Código aplicado: -' + data.amount.toFixed(2) + ' €';
      msg.style.color = 'var(--color-green)';
      document.getElementById('discount-line').style.display = 'flex';
      document.getElementById('discount-amount').textContent = '-' + data.amount.toFixed(2) + ' €';
      updateTotal();
    } else {
      msg.textContent = data.message || 'Código no válido';
      msg.style.color = 'var(--color-red)';
    }
  });
}

function submitCheckout() {
  const form = document.getElementById('checkout-form');
  if (!form.checkValidity()) { form.reportValidity(); return; }

  const btn = document.getElementById('pay-btn');
  btn.disabled = true;
  btn.textContent = 'Procesando...';

  const data = {
    name:           form.querySelector('[name="name"]').value,
    email:          form.querySelector('[name="email"]').value,
    phone:          form.querySelector('[name="phone"]').value,
    address:        form.querySelector('[name="address"]').value,
    city:           form.querySelector('[name="city"]').value,
    postalCode:     form.querySelector('[name="postalCode"]').value,
    shippingMethod: getShippingMethod(),
    discountCode:   document.getElementById('discount-input').value.trim().toUpperCase() || null,
  };

  fetch('/api/checkout-submit.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data)
  })
  .then(r => r.json())
  .then(res => {
    if (res.success && res.payment) {
      const redsysForm = document.getElementById('redsys-form');
      redsysForm.action = res.payment.url;
      document.getElementById('Ds_SignatureVersion').value = res.payment.params.Ds_SignatureVersion;
      document.getElementById('Ds_MerchantParameters').value = res.payment.params.Ds_MerchantParameters;
      document.getElementById('Ds_Signature').value = res.payment.params.Ds_Signature;
      redsysForm.submit();
    } else {
      document.getElementById('error-msg').textContent = res.message || 'Error al procesar el pedido';
      btn.disabled = false;
      btn.textContent = 'Pagar ahora';
    }
  })
  .catch(() => {
    document.getElementById('error-msg').textContent = 'Error de conexión. Inténtalo de nuevo.';
    btn.disabled = false;
    btn.textContent = 'Pagar ahora';
  });
}
</script>

<?php require dirname(__DIR__) . '/pages/layout-footer.php'; ?>
