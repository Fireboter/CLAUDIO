<?php
$pageTitle = 'Contacto';
require dirname(__DIR__) . '/pages/layout-header.php';
?>

<div class="container py-8" style="max-width:600px">
  <h1 style="font-size:1.875rem;font-weight:700;margin-bottom:0.5rem">Contacto</h1>
  <p style="color:var(--color-gray-500);margin-bottom:2rem">¿Tienes alguna pregunta? Escríbenos y te responderemos en breve.</p>

  <form id="contact-form">
    <div class="form-group">
      <label>Nombre *</label>
      <input type="text" name="name" required>
    </div>
    <div class="form-group">
      <label>Email *</label>
      <input type="email" name="email" required>
    </div>
    <div class="form-group">
      <label>Tipo de consulta</label>
      <select name="formType">
        <option value="contact">Consulta general</option>
        <option value="order">Pedido</option>
        <option value="return">Devolución</option>
        <option value="other">Otro</option>
      </select>
    </div>
    <div class="form-group">
      <label>Mensaje *</label>
      <textarea name="message" rows="5" required style="resize:vertical"></textarea>
    </div>
    <p id="contact-error" class="text-red" style="margin-bottom:0.5rem"></p>
    <p id="contact-success" class="text-green hidden" style="margin-bottom:0.5rem">¡Mensaje enviado! Te responderemos pronto.</p>
    <button type="submit" class="btn btn-black" style="width:100%" id="contact-btn">Enviar mensaje</button>
  </form>
</div>

<script>
document.getElementById('contact-form').addEventListener('submit', function(e) {
  e.preventDefault();
  const btn = document.getElementById('contact-btn');
  btn.disabled = true; btn.textContent = 'Enviando...';
  const data = {
    name:     this.querySelector('[name="name"]').value,
    email:    this.querySelector('[name="email"]').value,
    message:  this.querySelector('[name="message"]').value,
    formType: this.querySelector('[name="formType"]').value,
  };
  fetch('/api/contact.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(data) })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      document.getElementById('contact-success').classList.remove('hidden');
      this.reset();
    } else {
      document.getElementById('contact-error').textContent = res.message || 'Error al enviar.';
    }
    btn.disabled = false; btn.textContent = 'Enviar mensaje';
  });
});
</script>

<?php require dirname(__DIR__) . '/pages/layout-footer.php'; ?>
