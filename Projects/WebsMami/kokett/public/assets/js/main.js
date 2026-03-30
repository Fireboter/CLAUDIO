// Update cart count in header
function updateCartCount() {
  fetch('/api/cart.php?action=count')
    .then(r => r.json())
    .then(data => {
      const el = document.getElementById('cart-count');
      if (el) {
        el.textContent = data.count > 0 ? data.count : '';
        el.style.display = data.count > 0 ? 'flex' : 'none';
      }
    })
    .catch(() => {});
}

// Newsletter form
const newsletterForm = document.getElementById('newsletter-form');
if (newsletterForm) {
  newsletterForm.addEventListener('submit', function(e) {
    e.preventDefault();
    const email = this.querySelector('input[name="email"]').value;
    fetch('/api/newsletter.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email })
    })
    .then(r => r.json())
    .then(data => {
      const msg = document.getElementById('newsletter-msg');
      if (msg) {
        if (data.success) {
          msg.textContent = '¡Gracias por suscribirte!';
        } else if (data.message === 'already_subscribed') {
          msg.textContent = 'Ya estás suscrito.';
        } else {
          msg.textContent = 'Error al suscribirse.';
        }
        msg.classList.remove('hidden');
        newsletterForm.classList.add('hidden');
      }
    })
    .catch(() => {});
  });
}
