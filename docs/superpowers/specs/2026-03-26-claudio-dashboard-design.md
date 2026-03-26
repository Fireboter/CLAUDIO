# Claudio — Agent Network Dashboard
## Subsystem 4 Design Spec

**Goal:** A live, read-only HTML dashboard that shows the full state of all Claudio agents — status, task queues, config, learnings, and CLAUDE.md — served by `python -m http.server` with no custom server code.

**Chosen approach:** `python -m http.server 8765 --bind 127.0.0.1` from `D:\CLAUDIO`. JavaScript fetches `.claudio/registry.json` and `.claudio/tasks-summary.json` every 5 seconds. Agent detail files (config.json, learnings.md, CLAUDE.md) are fetched lazily on demand. `agent-checkin.ps1` is extended to write `tasks-summary.json` after every status update.

---

## Scope

**In scope:**
- HTML/CSS/JS dashboard (`scripts/dashboard/`)
- Live polling of `registry.json` + `tasks-summary.json` every 5s
- Agent cards with status, task, branch, heartbeat, completions
- Task queue summary table (pending/active/done/failed counts)
- Recent completions list
- Agent detail modal with 4 tabs: Config, Learnings, CLAUDE.md, Tasks
- `scripts/start-dashboard.ps1` — wt.exe launcher + opens browser
- `scripts/agent-checkin.ps1` — append tasks-summary.json write (~20 lines)
- `.gitignore` — add `tasks-summary.json`

**Out of scope:**
- Write actions (queueing tasks, spawning agents) — read-only for now
- Authentication — localhost-only
- Mobile layout
- Historical charts or time-series data
- Subsystem 5 (Playwright pipeline)

---

## Architecture

### File Map

| Action | Path | Role |
|---|---|---|
| Create | `scripts/dashboard/index.html` | HTML shell: header, agent cards grid, task table, modal |
| Create | `scripts/dashboard/style.css` | Dark theme, status colours, card/modal layout |
| Create | `scripts/dashboard/app.js` | Polling loop, render functions, modal logic |
| Create | `scripts/start-dashboard.ps1` | wt.exe launcher; opens browser at dashboard URL |
| Modify | `scripts/agent-checkin.ps1` | +~20 lines: writes `.claudio/tasks-summary.json` after every call |
| Modify | `.gitignore` | Add `.claudio/tasks-summary.json` |
| Auto-created | `.claudio/tasks-summary.json` | Written by agent-checkin.ps1; read by dashboard JS |

### Data Flow

```
agent-checkin.ps1 (every heartbeat)
  └─► writes .claudio/registry.json        (existing)
  └─► writes .claudio/tasks-summary.json   (new)

browser (every 5s)
  └─► GET /.claudio/registry.json          → agent status, heartbeat, completions
  └─► GET /.claudio/tasks-summary.json     → task counts + task lists per agent

browser (on modal open, per tab click)
  └─► GET /.claudio/agents/{name}/config.json
  └─► GET /.claudio/agents/{name}/learnings.md
  └─► GET /{project_path}/CLAUDE.md
```

### CLAUDE.md Paths (hardcoded in app.js)

```javascript
const CLAUDE_MD_PATHS = {
  ClaudeTrader: 'Projects/ClaudeTrader/CLAUDE.md',
  WebsMami:     'Projects/WebsMami/CLAUDE.md',
  ClaudeSEO:    'Work%20(Rechtecheck)/ClaudeSEO/CLAUDE.md'
};
```

---

## Dashboard Layout

### Main view

```
┌──────────────────────────────────────────────────────────────┐
│  CLAUDIO                          Last updated: 3s ago  ●    │
├──────────────────────────────────────────────────────────────┤
│  AGENTS                                                      │
│  ┌──────────────────┐  ┌──────────────────┐  ┌───────────┐  │
│  │ ClaudeTrader     │  │ WebsMami         │  │ ClaudeSEO │  │
│  │ ● IDLE           │  │ ● IDLE           │  │ ○ OFFLINE │  │
│  │                  │  │                  │  │           │  │
│  │ Task:   —        │  │ Task:   —        │  │ Task: —   │  │
│  │ Branch: —        │  │ Branch: —        │  │ Branch: — │  │
│  │ Beat:   2m ago   │  │ Beat:   1m ago   │  │ Beat: —   │  │
│  │ Done today: 1    │  │ Done today: 1    │  │ Done: 0   │  │
│  │                  │  │                  │  │           │  │
│  │ [View Details ▸] │  │ [View Details ▸] │  │ [View ▸]  │  │
│  └──────────────────┘  └──────────────────┘  └───────────┘  │
│                                                              │
│  TASK QUEUES                                                 │
│  ┌──────────────┬─────────┬────────┬──────┬────────┐        │
│  │ Agent        │ Pending │ Active │ Done │ Failed │        │
│  ├──────────────┼─────────┼────────┼──────┼────────┤        │
│  │ ClaudeTrader │    0    │   0    │  1   │   0    │        │
│  │ WebsMami     │    0    │   0    │  1   │   0    │        │
│  │ ClaudeSEO    │    0    │   0    │  0   │   0    │        │
│  └──────────────┴─────────┴────────┴──────┴────────┘        │
│                                                              │
│  RECENT COMPLETIONS                                          │
│  · ClaudeTrader  task-test-001           2026-03-26 01:54   │
│  · WebsMami      task-20260326-020640    2026-03-26 02:07   │
└──────────────────────────────────────────────────────────────┘
```

### Agent detail modal (click "View Details ▸")

```
┌──────────────────────────────────────────────────────────────┐
│  ClaudeTrader  ● IDLE                                  [✕]  │
├──────────────────────────────────────────────────────────────┤
│  [Config]  [Learnings]  [CLAUDE.md]  [Tasks]                 │
├──────────────────────────────────────────────────────────────┤
│  (scrollable tab content)                                    │
│                                                              │
│  Config tab:     key/value table from config.json            │
│  Learnings tab:  preformatted text from learnings.md         │
│  CLAUDE.md tab:  full content, monospace, scrollable         │
│  Tasks tab:      four sections: Pending / Active / Done /    │
│                  Failed — each shows task list from          │
│                  tasks-summary.json                          │
└──────────────────────────────────────────────────────────────┘
```

---

## Components

### `scripts/dashboard/index.html`

Static shell. No data is baked in — all content is injected by `app.js`.

Key structure:
- `<header>`: title "CLAUDIO", refresh indicator dot, "Last updated" timestamp
- `<section id="agents-section">`: 3-column CSS grid for agent cards
- `<section id="tasks-section">`: `<table id="task-table">` for queue counts
- `<section id="completions-section">`: recent completions list
- Modal overlay: `<div id="modal-overlay">` with modal body, tab buttons, content pane
- `<script src="app.js">` at end of body

### `scripts/dashboard/style.css`

Dark theme. No external CDN or font imports.

```
Background:   #0d1117
Card bg:      #161b22
Border:       #30363d
Text:         #e6edf3
Subtext:      #8b949e

Status colours:
  active:  #3fb950  (green)
  idle:    #d29922  (amber)
  offline: #8b949e  (gray)

Failed count: #f85149 (red)
Done count:   #3fb950 (green)

Font: system-ui, -apple-system, sans-serif
Mono: 'Courier New', Consolas, monospace  (task IDs, code blocks)
```

Refresh indicator: `.dot.connected` pulses green; `.dot.disconnected` is red, no pulse.

### `scripts/dashboard/app.js`

Constants:
```javascript
const POLL_INTERVAL_MS = 5000;
const MAX_FAILURES     = 3;
const AGENT_NAMES      = ['ClaudeTrader', 'WebsMami', 'ClaudeSEO'];
const CLAUDE_MD_PATHS  = {
  ClaudeTrader: 'Projects/ClaudeTrader/CLAUDE.md',
  WebsMami:     'Projects/WebsMami/CLAUDE.md',
  ClaudeSEO:    'Work%20(Rechtecheck)/ClaudeSEO/CLAUDE.md'
};
```

Module-level state:
```javascript
let lastRegistry    = null;
let lastTaskSummary = null;
let lastUpdated     = null;
let failureCount    = 0;
let openAgent       = null;   // name of currently open modal agent
let activeTab       = null;   // currently selected modal tab
```

**Polling loop:**
```javascript
async function poll() {
  try {
    const [reg, ts] = await Promise.all([
      fetchJSON('/.claudio/registry.json'),
      fetchJSON('/.claudio/tasks-summary.json').catch(() => null),
    ]);
    failureCount = 0;
    lastRegistry = reg;
    lastTaskSummary = ts;
    lastUpdated = new Date();
    renderDashboard();
    setConnected(true);
  } catch {
    failureCount++;
    if (failureCount >= MAX_FAILURES) setConnected(false);
  }
}

poll();
setInterval(poll, POLL_INTERVAL_MS);
```

**Render functions:**
- `renderAgentCards()` — builds card HTML for each agent from `lastRegistry`
- `renderTaskTable()` — builds table rows from `lastTaskSummary`; shows `—` if null
- `renderRecentCompletions()` — reads `recent_completions` arrays from `lastRegistry`
- `updateTimestamp()` — shows "Xs ago" since `lastUpdated`

**Modal logic:**
- `openModal(agentName)` — shows overlay, sets title, loads Config tab by default
- `loadTab(tab, agentName)` — fetches relevant file(s), renders content
- `closeModal()` — hides overlay, clears `openAgent`/`activeTab`
- Tab click handlers — call `loadTab` with new tab name, update active class
- Pressing `Escape` closes modal

**`loadTab` per-tab fetch targets:**

| Tab | Fetch | Render |
|---|---|---|
| Config | `/.claudio/agents/{name}/config.json` | `<table>` of key/value pairs |
| Learnings | `/.claudio/agents/{name}/learnings.md` | `<pre>` with escaped text |
| CLAUDE.md | `/{CLAUDE_MD_PATHS[name]}` | `<pre>` with escaped text |
| Tasks | `lastTaskSummary.agents[name].tasks` (already in memory) | four labelled lists |

If a fetch fails or returns non-OK: render `<p class="empty">(not found)</p>` in the tab.

**`setConnected(bool)`:** toggles `.dot` class between `connected` and `disconnected`; shows/hides the "server offline" banner.

**`timeAgo(isoString)`:** converts ISO timestamp to human string ("just now", "2m ago", "1h ago", "never" for null).

**`escapeHtml(str)`:** replaces `&`, `<`, `>` with HTML entities — used before rendering .md content as `<pre>` text.

### `scripts/start-dashboard.ps1`

```powershell
# start-dashboard.ps1 — Launch the Claudio dashboard in a new Windows Terminal tab
# Usage: pwsh scripts/start-dashboard.ps1

$claudioRoot = Split-Path $PSScriptRoot -Parent
$startCmd = "Set-Location '$claudioRoot'; python -m http.server 8765 --bind 127.0.0.1"
$encodedCmd = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($startCmd))
wt.exe new-tab --title "Claudio: Dashboard" -- pwsh -NoExit -EncodedCommand $encodedCmd
Start-Sleep -Seconds 1
Start-Process "http://localhost:8765/scripts/dashboard/"
```

The 1-second sleep gives the server time to start before opening the browser.

### `scripts/agent-checkin.ps1` — Addition

Append after `Write-Host` (line 80). Scans all three agents' task directories and writes `.claudio/tasks-summary.json`:

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

### `.claudio/tasks-summary.json` (generated file)

```json
{
  "updated_at": "2026-03-26T04:00:00Z",
  "agents": {
    "ClaudeTrader": {
      "counts":  { "pending": 0, "active": 0, "done": 1, "failed": 0 },
      "tasks": {
        "pending": [],
        "active":  [],
        "done":    [{ "id": "task-test-001", "description": "Test task", "completed_at": "..." }],
        "failed":  []
      }
    },
    "WebsMami": { ... },
    "ClaudeSEO": { ... }
  }
}
```

---

## Error Handling Reference

| Situation | Behaviour |
|---|---|
| `python -m http.server` not started | After 3 poll failures → red dot + "Dashboard server offline — run start-dashboard.ps1" banner |
| `registry.json` missing | Cards show `—` for all fields; no crash |
| `tasks-summary.json` missing (no agent checkin yet) | Task table shows `—` counts; no crash |
| Modal file not found (config/learnings/CLAUDE.md) | Tab shows "(not found)" message |
| `tasks-summary.json` malformed JSON | Treat as missing; show `—` counts |
| Server reconnects after failure | failureCount resets to 0 on next success; green dot restores |
| Stale tab content after poll updates | Tasks tab re-reads from `lastTaskSummary` (in-memory); text tabs re-fetch from server |

---

## Security Model

| Concern | Mitigation |
|---|---|
| Network exposure | `--bind 127.0.0.1` — only accessible from localhost |
| `.env` accessible at `http://localhost:8765/.env` | Accepted risk — localhost only, no remote access |
| XSS via task content | `escapeHtml()` applied to all .md and task text before rendering |

---

## `.gitignore` Additions

```
# Dashboard generated state
.claudio/tasks-summary.json
```

---

## Installation

1. Ensure Python 3.x is available (`python --version`)
2. Run once to generate initial `tasks-summary.json`: `pwsh scripts/agent-checkin.ps1 -Project ClaudeTrader -Status idle`
3. Start dashboard: `pwsh scripts/start-dashboard.ps1`

Opens `http://localhost:8765/scripts/dashboard/` in the default browser. Dashboard auto-refreshes every 5 seconds. To stop: close the "Claudio: Dashboard" Windows Terminal tab.

---

## Upgrade Path

- **Write actions:** Add a POST handler to a custom Python server (replacing http.server) to queue tasks or spawn agents. Out of scope for Subsystem 4.
- **Historical charts:** Add a time-series log written by agent-checkin.ps1; render with Chart.js or SVG. Out of scope.
- **Auto-start on login:** Add to Windows Task Scheduler alongside the Telegram bot. Out of scope.
