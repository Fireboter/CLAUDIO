<?php
$pageTitle = 'Contactos';
$typeFilter = $_GET['type'] ?? '';
$params = [];
$where = '1=1';
if ($typeFilter) { $where .= ' AND formType = ?'; $params[] = $typeFilter; }
$contacts = db_query("SELECT * FROM ContactSubmission WHERE $where ORDER BY createdAt DESC", $params);
require __DIR__ . '/layout-header.php';
?>

<div class="page-header">
  <h1>Formularios de contacto</h1>
</div>

<div style="display:flex;gap:0.5rem;margin-bottom:1.5rem">
  <a href="/admin/contacts" class="btn <?= !$typeFilter ? 'btn-primary' : 'btn-secondary' ?> btn-sm">Todos</a>
  <?php foreach (['contact','order','return','other'] as $t): ?>
    <a href="/admin/contacts?type=<?= $t ?>" class="btn <?= $typeFilter === $t ? 'btn-primary' : 'btn-secondary' ?> btn-sm"><?= $t ?></a>
  <?php endforeach; ?>
</div>

<table class="admin-table">
  <thead><tr><th>Nombre</th><th>Email</th><th>Tipo</th><th>Mensaje</th><th>Fecha</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($contacts as $c): ?>
    <tr style="<?= !$c['isRead'] ? 'font-weight:600' : '' ?>">
      <td><?= e($c['name']) ?></td>
      <td style="font-size:0.875rem"><?= e($c['email']) ?></td>
      <td><span class="badge badge-processing"><?= e($c['formType']) ?></span></td>
      <td style="max-width:300px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:0.875rem"><?= e(substr($c['message'], 0, 80)) ?><?= strlen($c['message']) > 80 ? '…' : '' ?></td>
      <td style="color:var(--color-gray-500);font-size:0.8rem"><?= date('d/m/Y H:i', strtotime($c['createdAt'])) ?></td>
      <td>
        <button onclick="viewContact(<?= $c['id'] ?>)" class="btn btn-secondary btn-sm">Ver</button>
        <button onclick="openReply(<?= $c['id'] ?>)" class="btn btn-primary btn-sm">Responder</button>
        <?php if (!$c['isRead']): ?>
          <button onclick="markRead(<?= $c['id'] ?>)" class="btn btn-secondary btn-sm">Leer</button>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<!-- View modal -->
<div id="contact-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:100;align-items:center;justify-content:center" onclick="this.style.display='none'">
  <div style="background:#fff;border-radius:8px;padding:2rem;max-width:560px;width:90%;max-height:80vh;overflow-y:auto" onclick="event.stopPropagation()">
    <h2 style="font-weight:700;margin-bottom:1rem" id="modal-name"></h2>
    <p style="font-size:0.875rem;color:var(--color-gray-500);margin-bottom:1rem" id="modal-email"></p>
    <p style="white-space:pre-wrap;margin-bottom:1.5rem" id="modal-message"></p>
    <button onclick="document.getElementById('contact-modal').style.display='none'" class="btn btn-secondary">Cerrar</button>
  </div>
</div>

<?php
$contactsJson = json_encode(array_column($contacts, null, 'id'));
?>
<script>
const contactsData = <?= $contactsJson ?>;

function viewContact(id) {
  const c = contactsData[id];
  if (!c) return;
  document.getElementById('modal-name').textContent = c.name + ' (' + c.formType + ')';
  document.getElementById('modal-email').textContent = c.email;
  document.getElementById('modal-message').textContent = c.message;
  document.getElementById('contact-modal').style.display = 'flex';
  if (!c.isRead) markRead(id);
}

function openReply(id) {
  const c = contactsData[id];
  if (!c) return;
  document.getElementById('reply-to').textContent = c.name + ' <' + c.email + '>';
  document.getElementById('reply-original').textContent = c.message;
  document.getElementById('reply-subject').value = 'Re: tu mensaje en Bawywear';
  document.getElementById('reply-body').value = '';
  document.getElementById('reply-msg').textContent = '';
  document.getElementById('reply-modal').dataset.contactId = id;
  document.getElementById('reply-modal').style.display = 'flex';
  setTimeout(() => document.getElementById('reply-body').focus(), 50);
  if (!c.isRead) markRead(id);
}

function sendReply() {
  const id      = parseInt(document.getElementById('reply-modal').dataset.contactId);
  const subject = document.getElementById('reply-subject').value.trim();
  const message = document.getElementById('reply-body').value.trim();
  if (!subject || !message) { setReplyMsg('Rellena el asunto y el mensaje.', false); return; }
  const btn = document.getElementById('reply-send-btn');
  btn.disabled = true; btn.textContent = 'Enviando...';
  fetch('/admin/api/contacts.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'reply', id, subject, message })
  }).then(r => r.json()).then(d => {
    setReplyMsg(d.message || (d.success ? 'Enviado.' : 'Error.'), d.success);
    btn.disabled = false; btn.textContent = 'Enviar';
    if (d.success) setTimeout(() => { document.getElementById('reply-modal').style.display = 'none'; location.reload(); }, 1200);
  }).catch(() => { setReplyMsg('Error de conexión.', false); btn.disabled = false; btn.textContent = 'Enviar'; });
}

function setReplyMsg(text, ok) {
  const m = document.getElementById('reply-msg');
  m.textContent = text;
  m.style.color = ok ? 'var(--color-green)' : 'var(--color-red)';
}

function markRead(id) {
  fetch('/admin/api/contacts.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'mark-read', id }) })
    .then(() => location.reload());
}
</script>

<!-- Reply modal -->
<div id="reply-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:200;align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:#fff;border-radius:8px;padding:2rem;max-width:560px;width:90%;max-height:90vh;overflow-y:auto" onclick="event.stopPropagation()">
    <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:1rem">Responder contacto</h2>
    <p style="font-size:0.8rem;color:var(--color-gray-500);margin-bottom:0.25rem">Para</p>
    <p id="reply-to" style="font-size:0.875rem;margin-bottom:1rem;font-weight:500"></p>
    <details style="margin-bottom:1rem">
      <summary style="font-size:0.8rem;color:var(--color-gray-500);cursor:pointer">Mensaje original</summary>
      <p id="reply-original" style="margin-top:0.5rem;font-size:0.85rem;color:var(--color-gray-700);white-space:pre-wrap;background:var(--color-gray-50);padding:0.75rem;border-radius:4px;border-left:3px solid var(--color-gray-300)"></p>
    </details>
    <div class="form-group">
      <label>Asunto</label>
      <input type="text" id="reply-subject">
    </div>
    <div class="form-group">
      <label>Mensaje</label>
      <textarea id="reply-body" rows="6" placeholder="Escribe tu respuesta..."></textarea>
    </div>
    <p id="reply-msg" style="font-size:0.875rem;margin-bottom:0.75rem;min-height:1.25rem"></p>
    <div style="display:flex;gap:0.5rem;justify-content:flex-end">
      <button class="btn btn-secondary" onclick="document.getElementById('reply-modal').style.display='none'">Cancelar</button>
      <button id="reply-send-btn" class="btn btn-primary" onclick="sendReply()">Enviar</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/layout-footer.php'; ?>
