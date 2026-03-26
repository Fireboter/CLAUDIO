# Claudio Dashboard (Subsystem 4) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a live read-only HTML/CSS/JS dashboard served by `python -m http.server` that shows the status of all three Claudio agents, their task queues, and full agent detail modals.

**Architecture:** Three static files (`index.html`, `style.css`, `app.js`) served from `scripts/dashboard/` by Python's built-in HTTP server. JavaScript polls `/.claudio/registry.json` and `/.claudio/tasks-summary.json` every 5 seconds; `agent-checkin.ps1` is extended to write `tasks-summary.json` on every call. A `start-dashboard.ps1` script launches the server in a new Windows Terminal tab and opens the browser.

**Tech Stack:** Vanilla HTML/CSS/ES2020 (no build tools, no npm), Python 3.x `http.server`, PowerShell 5.1+

---

## File Map

| Action | Path | Role |
|---|---|---|
| Modify | `.gitignore` | Add `.claudio/tasks-summary.json` |
| Create | `scripts/dashboard/index.html` | HTML shell: header, agent grid, task table, completions, modal |
| Create | `scripts/dashboard/style.css` | Dark theme, status colours, card/modal layout |
| Create | `scripts/dashboard/app.js` | Polling loop, render functions, modal logic |
| Modify | `scripts/agent-checkin.ps1` | Append ~20 lines to write `.claudio/tasks-summary.json` |
| Create | `scripts/start-dashboard.ps1` | wt.exe launcher; opens browser at dashboard URL |

---

## Task 1: .gitignore + dashboard directory

**Files:**
- Modify: `.gitignore`
- Create: `scripts/dashboard/.gitkeep`

- [ ] **Step 1: Add tasks-summary.json to .gitignore**

Open `.gitignore` and add after the `# Telegram bot ephemeral state` block:

```
# Dashboard generated state
.claudio/tasks-summary.json
```

The end of `.gitignore` should now look like:

```
# Telegram bot ephemeral state
.claudio/telegram-session.json
.claudio/screenshots/

# Dashboard generated state
.claudio/tasks-summary.json
```

- [ ] **Step 2: Create the dashboard directory**

```bash
mkdir -p scripts/dashboard
touch scripts/dashboard/.gitkeep
```

- [ ] **Step 3: Verify .gitignore works**

```bash
echo "test" > .claudio/tasks-summary.json
git status
```

Expected: `.claudio/tasks-summary.json` does NOT appear in untracked files. Then clean up:

```bash
rm .claudio/tasks-summary.json
```

- [ ] **Step 4: Commit**

```bash
git add .gitignore scripts/dashboard/.gitkeep
git commit -m "feat: scaffold dashboard directory, gitignore tasks-summary.json"
```

---

## Task 2: style.css — dark theme

**Files:**
- Create: `scripts/dashboard/style.css`

- [ ] **Step 1: Create style.css with full dark theme**

```css
/* style.css — Claudio Dashboard dark theme */

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:       #0d1117;
  --card-bg:  #161b22;
  --border:   #30363d;
  --text:     #e6edf3;
  --subtext:  #8b949e;
  --active:   #3fb950;
  --idle:     #d29922;
  --offline:  #8b949e;
  --failed:   #f85149;
  --done:     #3fb950;
  --font:     system-ui, -apple-system, sans-serif;
  --mono:     'Courier New', Consolas, monospace;
}

body {
  background: var(--bg);
  color: var(--text);
  font-family: var(--font);
  font-size: 14px;
  line-height: 1.6;
}

header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 24px;
  border-bottom: 1px solid var(--border);
}

header h1 { font-size: 18px; letter-spacing: 2px; }

.header-right {
  display: flex;
  align-items: center;
  gap: 10px;
  color: var(--subtext);
  font-size: 12px;
}

.dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  display: inline-block;
}
.dot.connected    { background: var(--active); animation: pulse 2s infinite; }
.dot.disconnected { background: var(--failed); }

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50%       { opacity: 0.4; }
}

#offline-banner {
  background: var(--failed);
  color: #fff;
  text-align: center;
  padding: 8px;
  font-size: 13px;
}

#offline-banner code {
  font-family: var(--mono);
  background: rgba(0,0,0,0.3);
  padding: 1px 4px;
  border-radius: 3px;
}

main {
  padding: 24px;
  display: flex;
  flex-direction: column;
  gap: 32px;
}

section h2 {
  font-size: 11px;
  letter-spacing: 1.5px;
  color: var(--subtext);
  text-transform: uppercase;
  margin-bottom: 12px;
}

/* Agent cards */
.agent-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
}

.agent-card {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.agent-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.agent-name { font-weight: 600; font-size: 15px; }

.agent-meta {
  display: flex;
  flex-direction: column;
  gap: 4px;
  color: var(--subtext);
  font-size: 12px;
}

.agent-meta .label {
  color: var(--subtext);
  min-width: 60px;
  display: inline-block;
}

.mono { font-family: var(--mono); color: var(--text); }

.status-active  { color: var(--active);  font-size: 12px; }
.status-idle    { color: var(--idle);    font-size: 12px; }
.status-offline { color: var(--offline); font-size: 12px; }

.btn-detail {
  background: none;
  border: 1px solid var(--border);
  color: var(--subtext);
  padding: 6px 12px;
  border-radius: 4px;
  cursor: pointer;
  font-size: 12px;
  text-align: left;
  transition: border-color 0.15s, color 0.15s;
}
.btn-detail:hover { border-color: var(--text); color: var(--text); }

/* Task table */
#task-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

#task-table th, #task-table td {
  padding: 8px 12px;
  text-align: left;
  border-bottom: 1px solid var(--border);
}

#task-table th {
  color: var(--subtext);
  font-size: 11px;
  letter-spacing: 0.5px;
  text-transform: uppercase;
}

.count-done   { color: var(--done); }
.count-failed { color: var(--failed); }

/* Completions list */
#completions-list {
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 13px;
}

#completions-list li { color: var(--subtext); }

.agent-tag {
  color: var(--text);
  font-weight: 500;
  min-width: 120px;
  display: inline-block;
}

.empty   { color: var(--subtext); font-style: italic; }
.loading { color: var(--subtext); }

/* Modal overlay */
#modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
}

#modal {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 8px;
  width: 700px;
  max-width: 90vw;
  max-height: 80vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

#modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 20px;
  border-bottom: 1px solid var(--border);
}

#modal-title { font-size: 16px; font-weight: 600; margin-right: 10px; }

#modal-close {
  background: none;
  border: none;
  color: var(--subtext);
  cursor: pointer;
  font-size: 16px;
  padding: 4px 8px;
  border-radius: 4px;
}
#modal-close:hover { color: var(--text); }

#modal-tabs {
  display: flex;
  padding: 0 20px;
  border-bottom: 1px solid var(--border);
}

.tab-btn {
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  color: var(--subtext);
  padding: 10px 14px;
  cursor: pointer;
  font-size: 13px;
  transition: color 0.15s;
}
.tab-btn:hover  { color: var(--text); }
.tab-btn.active { color: var(--text); border-bottom-color: var(--active); }

#modal-content {
  flex: 1;
  overflow-y: auto;
  padding: 20px;
}

#modal-content pre {
  font-family: var(--mono);
  font-size: 12px;
  white-space: pre-wrap;
  word-break: break-word;
  color: var(--text);
}

.kv-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.kv-table td {
  padding: 6px 10px;
  border-bottom: 1px solid var(--border);
  vertical-align: top;
}
.kv-table td.key { color: var(--subtext); width: 200px; font-family: var(--mono); }

/* Modal tasks tab */
.task-section { margin-bottom: 20px; }

.task-section-title {
  font-size: 11px;
  letter-spacing: 1px;
  margin-bottom: 8px;
}
.task-section-title.pending { color: var(--idle); }
.task-section-title.active  { color: var(--active); }
.task-section-title.done    { color: var(--done); }
.task-section-title.failed  { color: var(--failed); }

.task-section ul { list-style: none; display: flex; flex-direction: column; gap: 4px; }
.task-section li { font-size: 12px; color: var(--subtext); }
.task-section li.mono { color: var(--text); }
```

- [ ] **Step 2: Verify the CSS file exists and has content**

```bash
wc -l scripts/dashboard/style.css
```

Expected: 200+ lines.

- [ ] **Step 3: Commit**

```bash
git add scripts/dashboard/style.css
git commit -m "feat: dashboard dark theme CSS"
```

---

## Task 3: index.html — static shell

**Files:**
- Create: `scripts/dashboard/index.html`

The HTML provides the skeleton that `app.js` populates via DOM manipulation. All IDs here must match the ones referenced in `app.js` (Task 4).

- [ ] **Step 1: Create index.html**

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Claudio Dashboard</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <header>
    <h1>CLAUDIO</h1>
    <div class="header-right">
      <span id="last-updated">Not yet updated</span>
      <span id="status-dot" class="dot disconnected"></span>
    </div>
  </header>

  <div id="offline-banner" style="display:none">
    Dashboard server offline — run <code>pwsh scripts/start-dashboard.ps1</code>
  </div>

  <main>
    <section>
      <h2>Agents</h2>
      <div id="agent-cards" class="agent-grid"></div>
    </section>

    <section>
      <h2>Task Queues</h2>
      <table id="task-table">
        <thead>
          <tr>
            <th>Agent</th>
            <th>Pending</th>
            <th>Active</th>
            <th>Done</th>
            <th>Failed</th>
          </tr>
        </thead>
        <tbody id="task-table-body"></tbody>
      </table>
    </section>

    <section>
      <h2>Recent Completions</h2>
      <ul id="completions-list"></ul>
    </section>
  </main>

  <!-- Modal -->
  <div id="modal-overlay" style="display:none">
    <div id="modal">
      <div id="modal-header">
        <div>
          <span id="modal-title"></span>
          <span id="modal-status"></span>
        </div>
        <button id="modal-close" onclick="closeModal()">&#x2715;</button>
      </div>
      <div id="modal-tabs">
        <button class="tab-btn" data-tab="config">Config</button>
        <button class="tab-btn" data-tab="learnings">Learnings</button>
        <button class="tab-btn" data-tab="claude-md">CLAUDE.md</button>
        <button class="tab-btn" data-tab="tasks">Tasks</button>
      </div>
      <div id="modal-content"></div>
    </div>
  </div>

  <script src="app.js"></script>
</body>
</html>
```

- [ ] **Step 2: Open file in browser to confirm structure renders without JS errors**

Open `scripts/dashboard/index.html` directly (as `file://`) — the page should show the CLAUDIO header with a red dot, empty sections, no JS errors in console. The offline banner stays hidden because no fetch runs without HTTP.

- [ ] **Step 3: Commit**

```bash
git add scripts/dashboard/index.html
git commit -m "feat: dashboard HTML shell"
```

---

## Task 4: app.js — polling, rendering, modal

**Files:**
- Create: `scripts/dashboard/app.js`

This is the entire frontend logic: polling, all render functions, modal lifecycle, tab loading, utilities.

- [ ] **Step 1: Create app.js**

```javascript
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
          <div><span class="label">Done today:</span> ${done}</div>
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
```

- [ ] **Step 2: Start Python HTTP server and verify no JS errors**

```bash
cd D:/CLAUDIO
python -m http.server 8765 --bind 127.0.0.1
```

Open `http://localhost:8765/scripts/dashboard/` in a browser. Open DevTools → Console.

Expected:
- No JS errors
- "status-dot" flickers red (no data yet — 3 failed polls, then red dot + offline banner appears)
- Agent cards section shows "No registry data." initially

Stop the server with Ctrl+C.

- [ ] **Step 3: Verify poll cycle with mock data**

Start server again. In browser DevTools Console, paste:

```javascript
lastRegistry = {
  schema_version: 1,
  updated_at: new Date().toISOString(),
  agents: {
    ClaudeTrader: { status: 'active',  last_heartbeat: new Date().toISOString(), current_task: 'task-001', current_branch: 'main', tasks_completed_today: 2, recent_completions: ['task-001', 'task-000'] },
    WebsMami:     { status: 'idle',    last_heartbeat: new Date(Date.now() - 120000).toISOString(), current_task: null, current_branch: 'main', tasks_completed_today: 1, recent_completions: ['task-web-001'] },
    ClaudeSEO:    { status: 'offline', last_heartbeat: null, current_task: null, current_branch: null, tasks_completed_today: 0, recent_completions: [] }
  }
};
lastUpdated = new Date();
renderDashboard();
```

Expected: Three agent cards appear with correct status colours (green/amber/grey), task info, and "View Details ▸" buttons.

- [ ] **Step 4: Verify modal opens and closes**

In Console, paste:

```javascript
openModal('ClaudeTrader');
```

Expected: Modal appears with title "ClaudeTrader", Config tab selected, loading spinner, then "(not found)" (no server files yet). Click ✕ or press Escape → modal closes cleanly.

- [ ] **Step 5: Commit**

```bash
git add scripts/dashboard/app.js
git commit -m "feat: dashboard polling, render, and modal logic"
```

---

## Task 5: agent-checkin.ps1 — write tasks-summary.json

**Files:**
- Modify: `scripts/agent-checkin.ps1` (append after line 80, the `Write-Host` line)

The `$now` variable (line 55) and `$claudioRoot` variable (line 26) are already defined in the script. The new code reads task files for all three agents and writes `.claudio/tasks-summary.json`.

- [ ] **Step 1: Append the tasks-summary block to agent-checkin.ps1**

Open `scripts/agent-checkin.ps1`. After the final line:

```powershell
Write-Host "[$Project] status=$Status heartbeat=$now"
```

Add a blank line, then append:

```powershell
# Write tasks-summary.json for all agents (consumed by the dashboard)
$taskSummaryPath = Join-Path $claudioRoot ".claudio\tasks-summary.json"
$summary = [PSCustomObject]@{ updated_at = $now; agents = [PSCustomObject]@{} }
foreach ($agentName in @('ClaudeTrader', 'WebsMami', 'ClaudeSEO')) {
  $tasksBase  = Join-Path $claudioRoot ".claudio\tasks\$agentName"
  $agentEntry = [PSCustomObject]@{
    counts = [PSCustomObject]@{}
    tasks  = [PSCustomObject]@{}
  }
  foreach ($s in @('pending', 'active', 'done', 'failed')) {
    $dir      = Join-Path $tasksBase $s
    $taskList = @()
    if (Test-Path $dir) {
      Get-ChildItem $dir -Filter '*.json' |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 20 |
        ForEach-Object {
          try { $taskList += (Get-Content $_.FullName -Raw | ConvertFrom-Json) } catch {}
        }
    }
    $agentEntry.counts | Add-Member -NotePropertyName $s -NotePropertyValue $taskList.Count -Force
    $agentEntry.tasks  | Add-Member -NotePropertyName $s -NotePropertyValue $taskList       -Force
  }
  $summary.agents | Add-Member -NotePropertyName $agentName -NotePropertyValue $agentEntry -Force
}
$summary | ConvertTo-Json -Depth 8 | Set-Content $taskSummaryPath -Encoding UTF8
```

The complete end of the file should look like this (lines 80–):

```powershell
Write-Host "[$Project] status=$Status heartbeat=$now"

# Write tasks-summary.json for all agents (consumed by the dashboard)
$taskSummaryPath = Join-Path $claudioRoot ".claudio\tasks-summary.json"
$summary = [PSCustomObject]@{ updated_at = $now; agents = [PSCustomObject]@{} }
foreach ($agentName in @('ClaudeTrader', 'WebsMami', 'ClaudeSEO')) {
  $tasksBase  = Join-Path $claudioRoot ".claudio\tasks\$agentName"
  $agentEntry = [PSCustomObject]@{
    counts = [PSCustomObject]@{}
    tasks  = [PSCustomObject]@{}
  }
  foreach ($s in @('pending', 'active', 'done', 'failed')) {
    $dir      = Join-Path $tasksBase $s
    $taskList = @()
    if (Test-Path $dir) {
      Get-ChildItem $dir -Filter '*.json' |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 20 |
        ForEach-Object {
          try { $taskList += (Get-Content $_.FullName -Raw | ConvertFrom-Json) } catch {}
        }
    }
    $agentEntry.counts | Add-Member -NotePropertyName $s -NotePropertyValue $taskList.Count -Force
    $agentEntry.tasks  | Add-Member -NotePropertyName $s -NotePropertyValue $taskList       -Force
  }
  $summary.agents | Add-Member -NotePropertyName $agentName -NotePropertyValue $agentEntry -Force
}
$summary | ConvertTo-Json -Depth 8 | Set-Content $taskSummaryPath -Encoding UTF8
```

- [ ] **Step 2: Run the script and verify the output file**

```powershell
pwsh scripts/agent-checkin.ps1 -Project ClaudeTrader -Status idle
```

Expected output line: `[ClaudeTrader] status=idle heartbeat=<timestamp>`

Then verify the JSON was written:

```powershell
Get-Content .claudio/tasks-summary.json | ConvertFrom-Json | ConvertTo-Json -Depth 4
```

Expected: JSON object with `updated_at`, and `agents` containing `ClaudeTrader`, `WebsMami`, `ClaudeSEO` each with `counts` (`pending`, `active`, `done`, `failed`) and `tasks`.

- [ ] **Step 3: Verify tasks-summary.json is NOT tracked by git**

```bash
git status
```

Expected: `.claudio/tasks-summary.json` does NOT appear in the output (it's gitignored from Task 1).

- [ ] **Step 4: Commit**

```bash
git add scripts/agent-checkin.ps1
git commit -m "feat: agent-checkin writes tasks-summary.json for dashboard"
```

---

## Task 6: start-dashboard.ps1

**Files:**
- Create: `scripts/start-dashboard.ps1`

- [ ] **Step 1: Create start-dashboard.ps1**

```powershell
# start-dashboard.ps1 — Launch the Claudio dashboard in a new Windows Terminal tab
# Usage: pwsh scripts/start-dashboard.ps1

$claudioRoot = Split-Path $PSScriptRoot -Parent
$startCmd    = "Set-Location '$claudioRoot'; python -m http.server 8765 --bind 127.0.0.1"
$encodedCmd  = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($startCmd))
wt.exe new-tab --title "Claudio: Dashboard" -- pwsh -NoExit -EncodedCommand $encodedCmd
Start-Sleep -Seconds 1
Start-Process "http://localhost:8765/scripts/dashboard/"
```

- [ ] **Step 2: Run the script and verify**

```powershell
pwsh scripts/start-dashboard.ps1
```

Expected:
- A new Windows Terminal tab titled "Claudio: Dashboard" opens, running `python -m http.server 8765 --bind 127.0.0.1` from `D:\CLAUDIO`
- The default browser opens `http://localhost:8765/scripts/dashboard/`
- The dashboard loads: green pulsing dot, agent cards rendered with real data (if registry.json exists), task table populated
- No errors in browser DevTools console

- [ ] **Step 3: Verify dashboard end-to-end with a live checkin**

With the server running, open a second terminal and run:

```powershell
pwsh scripts/agent-checkin.ps1 -Project WebsMami -Status idle
```

Expected: Within 5 seconds, the dashboard auto-refreshes. The WebsMami card shows status "IDLE" in amber. The task table row for WebsMami shows correct `done` count.

- [ ] **Step 4: Commit and push**

```bash
git add scripts/start-dashboard.ps1
git commit -m "feat: start-dashboard.ps1 launcher — Subsystem 4 complete"
git push
```

---

## Self-Review

**Spec coverage check:**

| Spec requirement | Task |
|---|---|
| HTML/CSS/JS dashboard in `scripts/dashboard/` | Tasks 2, 3, 4 |
| Live polling registry.json + tasks-summary.json every 5s | Task 4 (poll()) |
| Agent cards: status, task, branch, heartbeat, completions | Task 4 (renderAgentCards) |
| Task queue summary table | Task 4 (renderTaskTable) |
| Recent completions list | Task 4 (renderRecentCompletions) |
| Agent detail modal with 4 tabs | Task 4 (openModal, loadTab, renderTasksTab) |
| Config tab: key/value table from config.json | Task 4 (loadTab 'config') |
| Learnings tab: preformatted text from learnings.md | Task 4 (loadTab 'learnings') |
| CLAUDE.md tab: full monospace content | Task 4 (loadTab 'claude-md') |
| Tasks tab: four sections from tasks-summary.json | Task 4 (renderTasksTab) |
| start-dashboard.ps1 wt.exe launcher + opens browser | Task 6 |
| agent-checkin.ps1 extended to write tasks-summary.json | Task 5 |
| .gitignore: tasks-summary.json | Task 1 |
| After 3 poll failures → red dot + offline banner | Task 4 (setConnected, failureCount) |
| registry.json missing → cards show — | Task 4 (null guards in renderAgentCards) |
| tasks-summary.json missing → table shows — | Task 4 (null guards in renderTaskTable) |
| Modal file not found → "(not found)" | Task 4 (all .catch handlers) |
| escapeHtml on all user content | Task 4 (escapeHtml used throughout) |
| --bind 127.0.0.1 | Task 6 |
| CLAUDE_MD_PATHS with URL-encoded ClaudeSEO path | Task 4 (constants) |

All requirements covered. No gaps.
