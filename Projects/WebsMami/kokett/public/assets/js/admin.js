// Admin JS utilities

function confirmDelete(url, message) {
  if (confirm(message || '¿Estás seguro?')) {
    fetch(url, { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({action:'delete'}) })
      .then(r => r.json())
      .then(d => { if (d.success) location.reload(); else alert(d.message || 'Error'); });
  }
}

function apiPost(url, data) {
  return fetch(url, { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(data) }).then(r => r.json());
}

// Image preview
document.querySelectorAll('input[type="file"][data-preview]').forEach(input => {
  input.addEventListener('change', function() {
    const previewId = this.dataset.preview;
    const preview = document.getElementById(previewId);
    if (preview && this.files[0]) {
      const reader = new FileReader();
      reader.onload = e => { preview.src = e.target.result; preview.classList.remove('hidden'); };
      reader.readAsDataURL(this.files[0]);
    }
  });
});
