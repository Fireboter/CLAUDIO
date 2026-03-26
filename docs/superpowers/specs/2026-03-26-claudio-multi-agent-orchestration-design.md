# Claudio — Multi-Agent Orchestration
## Subsystem 2 Design Spec

**Goal:** Enable the Claudio Queen to spawn and manage per-project Claude Code agents that each load their own CLAUDE.md and .mcp.json, communicate via a file-based task bus, and notify the user via Telegram.

**Chosen approach:** Structured Agent Protocol (B) — shared `.claudio/` message bus with task schema, agent registry, and per-project config. Designed for zero-rewrite upgrade to hive-mind (C) when task volume justifies it.

---

## Scope

**In scope:**
- `.claudio/` message bus directory structure and schemas
- PowerShell spawn script (`spawn-agent.ps1`)
- Agent startup behavior (encoded in each project's CLAUDE.md)
- Queen session startup enhanced to read the bus
- Git worktree pattern for feature/bugfix tasks
- One-way Telegram notifications (agents → user)
- Context compaction strategy for long-running agent sessions
- Per-project agent config files

**Out of scope (handled by later subsystems):**
- Bidirectional Telegram (you → Claudio) → Subsystem 3
- Visual agent network dashboard → Subsystem 4
- Playwright screenshot pipeline → Subsystem 5
- University project agents (no projects yet)

---

## Architecture

### Three Layers

| Layer | What | Where |
|---|---|---|
| Queen | Claudio root session — receives tasks, spawns agents, briefs user | `D:\CLAUDIO` |
| Agent | One Claude Code terminal per project — loads CLAUDE.md + .mcp.json natively | `D:\CLAUDIO\Projects\*` or `Work\*` |
| Bus | Shared `.claudio\` directory — file-based task queue + registry | `D:\CLAUDIO\.claudio\` |

**Cardinal rule:** Queen never does project-level work directly. It routes to the right project agent.

### Data Flow

```
You (Telegram/terminal) → Queen writes task → .claudio/tasks/<Project>/pending/<id>.json
Queen runs spawn-agent.ps1 <Project> → Windows Terminal opens → Claude loads CLAUDE.md + .mcp.json
Agent reads task → moves to active/ → writes registry heartbeat
Agent → Telegram: "Starting: <task title>"
Agent creates git worktree → feature/<name>-<date> branch
Agent codes / tests / reviews (using superpowers skills per CLAUDE.md)
Agent commits → merges to main → pushes
Agent writes result JSON → moves task to done/
Agent → Telegram: "Done: <task title> ✓ merged to main"
Agent compacts context → polls for next task (or restarts if task limit reached)
```

---

## Directory Structure

```
D:\CLAUDIO\
  .claudio\
    registry.json                    # Live agent state — GITIGNORED (changes every heartbeat)
    tasks\
      ClaudeTrader\
        pending\                     # Queen drops tasks here — GITIGNORED
        active\                      # Agent moves task here on pickup — GITIGNORED
        done\                        # Completed tasks — TRACKED (history)
        failed\                      # Failed tasks — TRACKED (for debugging)
      WebsMami\  (same structure)
      ClaudeSEO\ (same structure)
    results\
      ClaudeTrader\                  # Rich result JSON per completed task — TRACKED
      WebsMami\
      ClaudeSEO\
    archive\
      tasks\                         # Tasks/done/ older than 30 days rotate here — TRACKED
    agents\
      ClaudeTrader\
        config.json                  # Agent config — TRACKED
        learnings.md                 # Accumulated project insights — TRACKED (never deleted)
      WebsMami\
        config.json
        learnings.md
      ClaudeSEO\
        config.json
        learnings.md
  scripts\
    spawn-agent.ps1                  # Open Windows Terminal for a project
    queue-task.ps1                   # Queen helper: write a task file
    telegram-notify.ps1              # One-way Telegram send (bot webhook)
    agent-checkin.ps1                # Agent updates its registry heartbeat
    archive-tasks.ps1                # Rotate done/ tasks older than 30 days
  .worktrees\                        # Git worktrees for active feature work — GITIGNORED
    ClaudeTrader\
    WebsMami\
    ClaudeSEO\
```

### .gitignore additions

```
# .claudio ephemeral state
.claudio/registry.json
.claudio/tasks/*/pending/
.claudio/tasks/*/active/

# Git worktrees (project-level work isolation)
.worktrees/
```

---

## Schemas

### Task (Queen writes → Agent reads)

```json
{
  "id": "task-20260326-001",
  "project": "ClaudeTrader",
  "type": "feature",
  "title": "Add 20-period moving average indicator",
  "description": "Full description of requirements...",
  "priority": "high",
  "depends_on": [],
  "created_at": "2026-03-26T02:00:00Z",
  "created_by": "queen",
  "branch_prefix": "feature",
  "telegram_notify": true
}
```

**Task types:** `feature` | `bugfix` | `review` | `research` | `deploy` | `maintenance`

**Priority:** `high` | `normal` | `low` — agent always picks highest priority pending task first.

**ruv-swarm compatibility note:** This schema is intentionally compatible with ruv-swarm's task format. Upgrading from file queue to hive-mind queue is additive — no schema changes required.

### Result (Agent writes)

```json
{
  "task_id": "task-20260326-001",
  "project": "ClaudeTrader",
  "status": "done",
  "completed_at": "2026-03-26T02:45:00Z",
  "duration_minutes": 45,
  "branch": "feature/moving-average-20260326",
  "commits": ["abc1234", "def5678"],
  "merged_to": "main",
  "pushed": true,
  "tests_passed": true,
  "summary": "Added 20-period MA indicator to chart. Created calculation utility, integrated with data pipeline, added toggle button.",
  "issues": [],
  "context_compacted": true
}
```

**Status:** `done` | `failed` | `partial` (partial = blocked, waiting for input)

### Registry (Agent maintains, Queen reads)

```json
{
  "updated_at": "2026-03-26T02:40:00Z",
  "agents": {
    "ClaudeTrader": {
      "status": "active",
      "last_heartbeat": "2026-03-26T02:40:00Z",
      "current_task": "task-20260326-001",
      "current_branch": "feature/moving-average-20260326",
      "tasks_completed_today": 2,
      "recent_completions": ["task-20260325-003", "task-20260325-002"]
    },
    "WebsMami": {
      "status": "idle",
      "last_heartbeat": "2026-03-26T01:00:00Z",
      "current_task": null,
      "current_branch": "main",
      "tasks_completed_today": 0,
      "recent_completions": []
    },
    "ClaudeSEO": {
      "status": "offline",
      "last_heartbeat": null,
      "current_task": null,
      "current_branch": null,
      "tasks_completed_today": 0,
      "recent_completions": []
    }
  }
}
```

**Agent statuses:** `active` (working) | `idle` (open, no tasks) | `offline` (terminal not running)

Registry stores only `recent_completions` (last 5) — full history is in `tasks/done/` and `results/`.

### Agent Config (per-project, tracked in git)

```json
{
  "project": "ClaudeTrader",
  "project_path": "D:/CLAUDIO/Projects/ClaudeTrader",
  "branch_prefix": "feature",
  "base_branch": "main",
  "polling_interval_seconds": 30,
  "worktree_root": "D:/CLAUDIO/.worktrees/ClaudeTrader",
  "auto_merge": true,
  "auto_push": true,
  "require_tests_pass": true,
  "compact_after_each_task": true,
  "learnings_journal": "D:/CLAUDIO/.claudio/agents/ClaudeTrader/learnings.md"
}
```

---

## Spawn Script (scripts/spawn-agent.ps1)

```powershell
param(
  [Parameter(Mandatory=$true)]
  [string]$ProjectName
)

$projects = @{
  "ClaudeTrader" = "D:/CLAUDIO/Projects/ClaudeTrader"
  "WebsMami"     = "D:/CLAUDIO/Projects/WebsMami"
  "ClaudeSEO"    = "D:/CLAUDIO/Work (Rechtecheck)/ClaudeSEO"
}

if (-not $projects.ContainsKey($ProjectName)) {
  Write-Error "Unknown project: $ProjectName. Valid: $($projects.Keys -join ', ')"; exit 1
}

# Guard: already active?
$registryPath = "D:/CLAUDIO/.claudio/registry.json"
if (Test-Path $registryPath) {
  $reg = Get-Content $registryPath | ConvertFrom-Json
  if ($reg.agents.$ProjectName.status -eq "active") {
    Write-Host "$ProjectName agent already active — skipping"; exit 0
  }
}

$projectPath = $projects[$ProjectName]
wt.exe new-tab --title "Claudio: $ProjectName" -- pwsh -NoExit -Command "Set-Location '$projectPath'; claude"

# First-time note: Claude Code will prompt to trust the folder.
# Confirm once — trust is remembered permanently in ~/.claude/settings.json.
Write-Host "Spawned $ProjectName agent. If first time in this directory, approve the trust prompt."
```

---

## Agent Startup Behavior (added to each project's CLAUDE.md)

This section is appended to the `## Claudio — Project Context` block of each project's CLAUDE.md. The agent reads it automatically on session start.

```markdown
### Claudio Agent Startup Checklist

When starting as a Claudio project agent (opened by spawn-agent.ps1):

1. **Register:** Write `status: "active"` and current timestamp to `D:/CLAUDIO/.claudio/registry.json`
2. **Read learnings journal:** Read `D:/CLAUDIO/.claudio/agents/<Project>/learnings.md` — this contains hard-won project insights from past tasks. Do this before starting any work.
3. **Check tasks:** Scan `D:/CLAUDIO/.claudio/tasks/<Project>/pending/` — pick highest-priority task
4. **If task found:**
   - Move task JSON to `active/`
   - Send Telegram: "Starting: <task title>"
   - For `feature`/`bugfix`: create git worktree → branch → code → test → merge → push
   - For `review`/`research`: work in project dir, write report to `results/`
   - Write result JSON to `D:/CLAUDIO/.claudio/results/<Project>/`
   - Move task to `done/`
   - **Write learnings:** Append any non-obvious discoveries to `learnings.md` (codebase quirks, patterns, anti-patterns, environment facts not in any doc)
   - **Record to claude-mem:** Store key decisions or discoveries via claude-mem MCP tools
   - Send Telegram: "Done: <task title> ✓"
   - Run `/compact` to summarize context (keeps window lean without losing continuity)
5. **If no tasks:** Write `status: "idle"` to registry, send Telegram: "<Project> agent idle"
6. **Poll:** Use the `/loop 30s` skill to check for new tasks periodically while session is open. Alternatively, Queen sends a "check tasks" prompt when new work arrives — the agent need not loop if Queen is active.
7. **On failure:** Move task to `failed/` with error details, send Telegram alert, escalate per Memory-First Rule
8. **Session continues indefinitely.** No scheduled restart. Compaction + learnings journal + claude-mem preserve all knowledge across many tasks.

### Escalation trigger (from this project agent)
Before escalating to Queen: exhausted (1) CLAUDE.md rules (2) claude-mem search (3) project docs.
Only then: write to `failed/` with `"blocked": true` and send Telegram: "<Project> needs decision: <question>"
```

---

## Queen Session Startup (root CLAUDE.md addition)

Replace step 5 of the existing startup sequence with:

```bash
# 5. Read agent registry
cat D:/CLAUDIO/.claudio/registry.json
# → Report: which agents are active / idle / offline

# 6. Check pending work
ls D:/CLAUDIO/.claudio/tasks/*/pending/
# → Report: tasks waiting per project

# 7. Surface completed work since last session
# List result files newer than 24h
# → Summarize: what each agent accomplished

# 8. Brief user
# "ClaudeTrader: active on task-001. WebsMami: idle. ClaudeSEO: offline.
#  1 pending task (WebsMami). 3 tasks completed yesterday."
```

---

## Git Worktree Pattern

Used for `feature`, `bugfix`, and `maintenance` task types.

```bash
# Create isolated worktree (project dir stays on main)
git worktree add D:/CLAUDIO/.worktrees/<Project>/task-<id> feature/<name>-<date>
cd D:/CLAUDIO/.worktrees/<Project>/task-<id>

# ... agent works here, commits to feature/<name>-<date> ...

# Completion — merge must happen from the MAIN project directory, not the worktree
# (worktrees are locked to their branch; can't checkout main inside them)
cd <project-dir>                           # e.g. D:/CLAUDIO/Projects/ClaudeTrader
git merge --no-ff feature/<name>-<date>
git push
git worktree remove D:/CLAUDIO/.worktrees/<Project>/task-<id>
git branch -d feature/<name>-<date>
```

`.worktrees/` is gitignored at root. The merged feature branch history lives in `main`.

---

## Context Compaction Strategy

Long-running agent sessions accumulate context. The goal is to keep the context window lean **without losing hard-won working knowledge**. Session restarts are avoided — they discard conversational working memory (codebase quirks, non-obvious patterns, environment facts) that aren't written anywhere else.

### What survives session boundaries (never lost)
- CLAUDE.md — always reloaded fresh
- All files on disk, git history
- claude-mem — cross-session semantic memory
- `.claudio/results/` — completed task summaries
- `.claudio/agents/<Project>/learnings.md` — accumulated project insights (see below)

### What dies with a restart (the real cost)
Conversational working memory: codebase quirks discovered mid-task, subtle patterns, environment facts not yet documented. This is why session restart is **not used** as a compaction strategy.

### Layer 1 — Per-task compaction (`/compact`)
After completing each task, the agent runs `/compact`. This replaces the detailed conversation with a compact summary (~2-5k tokens), keeping the window lean. Because the summary includes what was done and decided, the agent retains functional continuity across many tasks without bloat.

### Layer 2 — Learnings Journal
After each task, the agent writes any non-obvious discoveries to:
```
.claudio/agents/<Project>/learnings.md
```

This file is **tracked in git** and read at agent startup (step 1.5 in the startup checklist). It externalizes working memory so it survives compaction and session gaps.

**What goes in the learnings journal:**
- Codebase quirks (e.g., "auth middleware requires X header, not documented anywhere")
- Non-obvious patterns to follow (e.g., "all DB queries use the QueryBuilder wrapper, never raw SQL")
- Anti-patterns discovered (e.g., "don't use Y component — deprecated, causes Z")
- Environment peculiarities (e.g., "npm run dev fails if port 9000 is in use — check first")
- Decisions that weren't obvious from code/docs

**What does NOT go in the learnings journal:** anything already in CLAUDE.md, task results, or git history.

### Layer 3 — Claude-mem recording
After each significant decision or discovery, the agent stores a record via `mcp__plugin_claude-mem_mcp-search__` tools. Fresh sessions or Queen can semantic-search these.

### Emergency exit (edge case only)
If the context window becomes genuinely unusable despite compaction (very rare — large file reads on multiple tasks):
- Agent writes current state + remaining work to the learnings journal
- Agent writes a `partial` result entry for the current task
- Agent sends Telegram: "<Project> session exiting — context limits reached, state saved to learnings journal"
- Agent exits. Queen re-spawns when ready; fresh session reads learnings journal and continues.

This is **not scheduled** — only happens if the agent detects degraded reasoning.

### Registry and file compaction
`recent_completions` keeps last 5 entries. Full history is in `tasks/done/` and `results/`.
`scripts/archive-tasks.ps1` moves `tasks/done/` files older than 30 days to `tasks/archive/`. Run by Queen once a week.

---

## Telegram Notifications (one-way, Subsystem 2 scope)

Agents and Queen call `scripts/telegram-notify.ps1 "<message>"`.

Bot token and chat ID are stored in `D:/CLAUDIO/.env` (gitignored). The script reads them as environment variables.

```powershell
# scripts/telegram-notify.ps1
param([Parameter(Mandatory=$true)][string]$Message)
# Parse .env manually (no external module required)
Get-Content "D:/CLAUDIO/.env" | ForEach-Object {
  if ($_ -match '^([^#][^=]*)=(.*)$') {
    [System.Environment]::SetEnvironmentVariable($matches[1].Trim(), $matches[2].Trim())
  }
}
$token  = $env:TELEGRAM_BOT_TOKEN
$chatId = $env:TELEGRAM_CHAT_ID
Invoke-RestMethod -Uri "https://api.telegram.org/bot$token/sendMessage" `
  -Body @{ chat_id = $chatId; text = $Message; parse_mode = "Markdown" }
```

**Notification events:**
| Event | Message format |
|---|---|
| Task started | `*ClaudeTrader* Starting: Add MA indicator` |
| Progress update | `*ClaudeTrader* Branch created. Implementing calculation.` |
| Task done | `*ClaudeTrader* ✓ Done: Add MA indicator — merged to main (3 commits)` |
| Task failed | `*ClaudeTrader* ✗ Failed: Add MA indicator — needs decision: <question>` |
| Agent idle | `*ClaudeTrader* Agent idle — no pending tasks` |
| Session restarting | `*ClaudeTrader* Restarting session (context compaction after 5 tasks)` |

Bidirectional Telegram (you → Queen) is Subsystem 3.

---

## Upgrade Path to Hive-Mind (Approach C)

When task volume grows and file polling becomes a bottleneck:

1. Replace `queue-task.ps1` with `mcp__ruv-swarm__task_orchestrate` calls
2. Replace agent task polling with `mcp__ruv-swarm__task_status` subscription
3. Task schema is already compatible — no changes needed
4. File-based `results/` stays as the persistent store (hive-mind gets the routing layer on top)
5. `registry.json` can be replaced by `mcp__ruv-swarm__agent_metrics`

This is additive. The project CLAUDE.md sections and spawn scripts remain unchanged.

---

## Implementation Checklist

- [ ] Create `.claudio/` directory structure with all subdirs + placeholder `.gitkeep` files
- [ ] Write `registry.json` initial state (all agents offline)
- [ ] Write `agents/ClaudeTrader/config.json`, `agents/WebsMami/config.json`, `agents/ClaudeSEO/config.json`
- [ ] Create `agents/ClaudeTrader/learnings.md`, `agents/WebsMami/learnings.md`, `agents/ClaudeSEO/learnings.md` (empty initial files with header)
- [ ] Write `scripts/spawn-agent.ps1`
- [ ] Write `scripts/queue-task.ps1`
- [ ] Write `scripts/telegram-notify.ps1`
- [ ] Write `scripts/agent-checkin.ps1`
- [ ] Write `scripts/archive-tasks.ps1`
- [ ] Append "Claudio Agent Startup Checklist" to `Projects/ClaudeTrader/CLAUDE.md`
- [ ] Append "Claudio Agent Startup Checklist" to `Projects/WebsMami/CLAUDE.md`
- [ ] Append "Claudio Agent Startup Checklist" to `Work (Rechtecheck)/ClaudeSEO/CLAUDE.md`
- [ ] Update root `CLAUDE.md` session startup (steps 5-8) to read `.claudio/` bus
- [ ] Update root `.gitignore` with `.claudio/` selective rules and `.worktrees/`
- [ ] Create `.env.example` documenting required env vars (`TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID`)
- [ ] Commit: `feat: subsystem 2 — multi-agent orchestration protocol`

---

## Files Created/Modified

| Action | Path |
|---|---|
| Create | `.claudio/` full directory tree |
| Create | `.claudio/registry.json` |
| Create | `.claudio/agents/ClaudeTrader/config.json` |
| Create | `.claudio/agents/ClaudeTrader/learnings.md` |
| Create | `.claudio/agents/WebsMami/config.json` |
| Create | `.claudio/agents/WebsMami/learnings.md` |
| Create | `.claudio/agents/ClaudeSEO/config.json` |
| Create | `.claudio/agents/ClaudeSEO/learnings.md` |
| Create | `scripts/spawn-agent.ps1` |
| Create | `scripts/queue-task.ps1` |
| Create | `scripts/telegram-notify.ps1` |
| Create | `scripts/agent-checkin.ps1` |
| Create | `scripts/archive-tasks.ps1` |
| Create | `.env.example` |
| Extend | `Projects/ClaudeTrader/CLAUDE.md` |
| Extend | `Projects/WebsMami/CLAUDE.md` |
| Extend | `Work (Rechtecheck)/ClaudeSEO/CLAUDE.md` |
| Extend | `CLAUDE.md` (root session startup) |
| Extend | `.gitignore` |
