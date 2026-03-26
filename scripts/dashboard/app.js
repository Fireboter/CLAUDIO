// app.js — Claudio Dashboard polling and rendering

const POLL_INTERVAL_MS = 5000;
const MAX_FAILURES     = 3;
const AGENT_NAMES      = ['ClaudeTrader', 'WebsMami', 'ClaudeSEO'];
const CLAUDE_MD_PATHS  = {
  ClaudeTrader: 'Projects/ClaudeTrader/CLAUDE.md',
  WebsMami:     'Projects/WebsMami/CLAUDE.md',
  ClaudeSEO:    'Work%20(Rechtecheck)/ClaudeSEO/CLAUDE.md'
};

let lastRegistry    = null;
let lastTaskSummary = null;
let lastUpdated     = null;
let failureCount    = 0;
let openAgent       = null;   // name of agent whose modal is open
let activeTab       = null;   // currently selected modal tab key

// ── Utilities ────────────────────────────────────────────────────────────────

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

function timeAgo(isoString) {
  if (!isoString) return 'never';
  const diff = Math.floor((Date.now() - new Date(isoString).getTime()) / 1000);
  if (diff < 60)   return 'just now';
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  return `${Math.floor(diff / 3600)}h ago`;
}

async function fetchJSON(url) {
  const res = await fetch(url);
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  return res.json();
}

async function fetchText(url) {
  const res = await fetch(url);
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  return res.text();
}

// ── Connection indicator ──────────────────────────────────────────────────────

function setConnected(connected) {
  document.getElementById('status-dot').className = 'dot ' + (connected ? 'connected' : 'disconnected');
  document.getElementById('offline-banner').style.display = connected ? 'none' : 'block';
}

// ── Render helpers ────────────────────────────────────────────────────────────

function statusClass(status) {
  if (status === 'active') return 'status-active';
  if (status === 'idle')   return 'status-idle';
  return 'status-offline';
}

function statusDot(status) {
  return (status === 'active' || status === 'idle') ? '●' : '○';
}

// ── Agent cards ───────────────────────────────────────────────────────────────

function renderAgentCards() {
  const container = document.getElementById('agent-cards');
  if (!lastRegistry) {
    container.innerHTML = '<p class="empty">No registry data.</p>';
    return;
  }
  container.innerHTML = AGENT_NAMES.map(name => {
    const a      = lastRegistry.agents?.[name] ?? {};
    const status = a.status ?? 'offline';
    const sc     = statusClass(status);
    const sd     = statusDot(status);
    const task   = a.current_task   ?? '—';
    const branch = a.current_branch ?? '—';
    const beat   = timeAgo(a.last_heartbeat);
    const done   = a.tasks_completed_today ?? 0;
    return `
      <div class="agent-card">
        <div class="agent-header">
          <span class="agent-name">${escapeHtml(name)}</span>
          <span class="${sc}">${sd} ${escapeHtml(status.toUpperCase())}</span>
        </div>
        <div class="agent-meta">
          <div><span class="label">Task:</span>   <span class="mono">${escapeHtml(task)}</span></div>
          <div><span class="label">Branch:</span> <span class="mono">${escapeHtml(branch)}</span></div>
          <div><span class="label">Beat:</span>   ${escapeHtml(beat)}</div>
          <div><span class="label">Done today:</span> ${escapeHtml(String(done))}</div>
        </div>
        <button class="btn-detail" onclick="openModal('${escapeHtml(name)}')">View Details &#x25b8;</button>
      </div>`;
  }).join('');
}

// ── Task table ────────────────────────────────────────────────────────────────

function renderTaskTable() {
  const tbody = document.getElementById('task-table-body');
  tbody.innerHTML = AGENT_NAMES.map(name => {
    const ag = lastTaskSummary?.agents?.[name];
    const c  = ag?.counts ?? {};
    const p  = c.pending != null ? c.pending : '—';
    const a  = c.active  != null ? c.active  : '—';
    const d  = c.done    != null ? c.done    : '—';
    const f  = c.failed  != null ? c.failed  : '—';
    const dClass = (typeof d === 'number' && d > 0) ? 'count-done'   : '';
    const fClass = (typeof f === 'number' && f > 0) ? 'count-failed' : '';
    return `<tr>
      <td>${escapeHtml(name)}</td>
      <td>${p}</td>
      <td>${a}</td>
      <td class="${dClass}">${d}</td>
      <td class="${fClass}">${f}</td>
    </tr>`;
  }).join('');
}

// ── Recent completions ────────────────────────────────────────────────────────

function renderRecentCompletions() {
  const list = document.getElementById('completions-list');
  if (!lastRegistry) {
    list.innerHTML = '<li class="empty">—</li>';
    return;
  }
  const items = [];
  for (const name of AGENT_NAMES) {
    const completions = lastRegistry.agents?.[name]?.recent_completions ?? [];
    for (const c of completions) {
      // recent_completions items are plain task ID strings per agent-checkin.ps1
      items.push(`<li><span class="agent-tag">${escapeHtml(name)}</span> <span class="mono">${escapeHtml(String(c))}</span></li>`);
    }
  }
  list.innerHTML = items.length ? items.join('') : '<li class="empty">No completions yet.</li>';
}

// ── Timestamp ─────────────────────────────────────────────────────────────────

function updateTimestamp() {
  const el = document.getElementById('last-updated');
  el.textContent = lastUpdated
    ? `Last updated: ${timeAgo(lastUpdated.toISOString())}`
    : 'Not yet updated';
}

// ── Full dashboard render ─────────────────────────────────────────────────────

function renderDashboard() {
  renderAgentCards();
  renderTaskTable();
  renderRecentCompletions();
  updateTimestamp();
  // Re-render tasks tab in-place if modal is open on that tab
  if (openAgent && activeTab === 'tasks') {
    renderTasksTab(openAgent);
  }
}

// ── Polling loop ──────────────────────────────────────────────────────────────

async function poll() {
  try {
    const [reg, ts] = await Promise.all([
      fetchJSON('/.claudio/registry.json'),
      fetchJSON('/.claudio/tasks-summary.json').catch(() => null),
    ]);
    failureCount    = 0;
    lastRegistry    = reg;
    lastTaskSummary = ts;
    lastUpdated     = new Date();
    renderDashboard();
    setConnected(true);
  } catch {
    failureCount++;
    if (failureCount >= MAX_FAILURES) setConnected(false);
  }
}

poll();
setInterval(poll, POLL_INTERVAL_MS);
setInterval(updateTimestamp, 1000);  // keep "Xs ago" ticking

// ── Modal open/close ──────────────────────────────────────────────────────────

function openModal(agentName) {
  openAgent = agentName;
  document.getElementById('modal-overlay').style.display = 'flex';
  document.getElementById('modal-title').textContent = agentName;
  // Reflect current live status in modal header
  const a      = lastRegistry?.agents?.[agentName] ?? {};
  const status = a.status ?? 'offline';
  const el     = document.getElementById('modal-status');
  el.textContent = ` ${statusDot(status)} ${status.toUpperCase()}`;
  el.className   = statusClass(status);
  loadTab('config', agentName);
}

function closeModal() {
  openAgent = null;
  activeTab = null;
  document.getElementById('modal-overlay').style.display = 'none';
  document.getElementById('modal-content').innerHTML = '';
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
}

// ── Tab loading ───────────────────────────────────────────────────────────────

function loadTab(tab, agentName) {
  activeTab = tab;
  document.querySelectorAll('.tab-btn').forEach(b => {
    b.classList.toggle('active', b.dataset.tab === tab);
  });
  const content = document.getElementById('modal-content');
  content.innerHTML = '<p class="loading">Loading&#x2026;</p>';

  if (tab === 'config') {
    fetchJSON(`/.claudio/agents/${agentName}/config.json`)
      .then(data => {
        const rows = Object.entries(data).map(([k, v]) =>
          `<tr><td class="key">${escapeHtml(k)}</td><td>${escapeHtml(JSON.stringify(v))}</td></tr>`
        ).join('');
        content.innerHTML = `<table class="kv-table"><tbody>${rows}</tbody></table>`;
      })
      .catch(() => { content.innerHTML = '<p class="empty">(not found)</p>'; });

  } else if (tab === 'learnings') {
    fetchText(`/.claudio/agents/${agentName}/learnings.md`)
      .then(text => { content.innerHTML = `<pre>${escapeHtml(text)}</pre>`; })
      .catch(() => { content.innerHTML = '<p class="empty">(not found)</p>'; });

  } else if (tab === 'claude-md') {
    const path = CLAUDE_MD_PATHS[agentName];
    if (!path) { content.innerHTML = '<p class="empty">(path not configured)</p>'; return; }
    fetchText(`/${path}`)
      .then(text => { content.innerHTML = `<pre>${escapeHtml(text)}</pre>`; })
      .catch(() => { content.innerHTML = '<p class="empty">(not found)</p>'; });

  } else if (tab === 'tasks') {
    renderTasksTab(agentName);
  }
}

function renderTasksTab(agentName) {
  const content = document.getElementById('modal-content');
  const ag = lastTaskSummary?.agents?.[agentName];
  if (!ag) {
    content.innerHTML = '<p class="empty">(no task data — run agent-checkin.ps1 to generate tasks-summary.json)</p>';
    return;
  }
  content.innerHTML = ['pending', 'active', 'done', 'failed'].map(s => {
    const tasks = ag.tasks?.[s] ?? [];
    const items = tasks.length
      ? tasks.map(t => `<li class="mono">${escapeHtml(t.id ?? JSON.stringify(t))}</li>`).join('')
      : '<li class="empty">—</li>';
    return `<div class="task-section">
      <h4 class="task-section-title ${s}">${s.toUpperCase()} (${tasks.length})</h4>
      <ul>${items}</ul>
    </div>`;
  }).join('');
}

// ── Event wiring ──────────────────────────────────────────────────────────────

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

document.getElementById('modal-overlay').addEventListener('click', e => {
  if (e.target === document.getElementById('modal-overlay')) closeModal();
});

document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    if (openAgent) loadTab(btn.dataset.tab, openAgent);
  });
});
