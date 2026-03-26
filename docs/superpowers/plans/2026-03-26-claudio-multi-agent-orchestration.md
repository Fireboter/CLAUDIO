# Claudio Multi-Agent Orchestration — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the `.claudio/` message bus, PowerShell agent scripts, per-project learnings journals, and update all CLAUDE.md files so the Claudio Queen can spawn and coordinate terminal-based project agents with Telegram notifications.

**Architecture:** Structured Agent Protocol (B) — shared `.claudio/` directory at `D:\CLAUDIO` root is the message bus. Queen writes tasks to `tasks/<Project>/pending/`. Agents move them through `active/` → `done/` or `failed/`, write results, update `registry.json`, and notify via Telegram. All scripts use `$PSScriptRoot` for cross-device portability — no hardcoded `D:/CLAUDIO` paths in scripts.

**Tech Stack:** PowerShell 7 (`pwsh`), Windows Terminal (`wt.exe`), Telegram Bot API, Claude Code, git worktrees, JSON config files.

**Architecture note:** The spec shows `project_path`, `worktree_root`, and `learnings_journal` in agent config. These are omitted from the actual JSON files — scripts compute them from `$PSScriptRoot` for cross-device portability. Only behavioral config lives in `agents/<Project>/config.json`.

---

## File Map

| Action | Path | Responsibility |
|---|---|---|
| Create | `.claudio/tasks/{ClaudeTrader,WebsMami,ClaudeSEO}/{pending,active,done,failed}/.gitkeep` | Task queue dirs — pending/active gitignored; done/failed tracked |
| Create | `.claudio/results/{ClaudeTrader,WebsMami,ClaudeSEO}/.gitkeep` | Completed task result JSON files |
| Create | `.claudio/archive/tasks/.gitkeep` | Rotation target for old done/ tasks |
| Create | `.claudio/registry.json` | Live agent state snapshot (gitignored) |
| Create | `.claudio/agents/ClaudeTrader/config.json` | ClaudeTrader behavioral config |
| Create | `.claudio/agents/WebsMami/config.json` | WebsMami behavioral config |
| Create | `.claudio/agents/ClaudeSEO/config.json` | ClaudeSEO behavioral config |
| Create | `.claudio/agents/ClaudeTrader/learnings.md` | Hard-won ClaudeTrader insights (never deleted) |
| Create | `.claudio/agents/WebsMami/learnings.md` | Hard-won WebsMami insights |
| Create | `.claudio/agents/ClaudeSEO/learnings.md` | Hard-won ClaudeSEO insights |
| Create | `scripts/telegram-notify.ps1` | Send one-way Telegram message; reads .env for credentials |
| Create | `scripts/agent-checkin.ps1` | Agent writes heartbeat + status to registry.json |
| Create | `scripts/spawn-agent.ps1` | Open Windows Terminal tab for a project; guards against double-spawn |
| Create | `scripts/queue-task.ps1` | Queen writes a task JSON to pending/ |
| Create | `scripts/archive-tasks.ps1` | Move done/ tasks older than N days to archive/ |
| Create | `.env.example` | Documents required env vars; committed (not .env itself) |
| Extend | `.gitignore` | Add .claudio/registry.json + pending/ + active/ patterns |
| Extend | `Projects/ClaudeTrader/CLAUDE.md` | Append agent startup checklist |
| Extend | `Projects/WebsMami/CLAUDE.md` | Append agent startup checklist |
| Extend | `Work (Rechtecheck)/ClaudeSEO/CLAUDE.md` | Append agent startup checklist |
| Extend | `CLAUDE.md` | Replace step 5 of session startup with bus-reading steps 5-8 |

---

## Task 1: `.claudio/` Bus Directory Scaffold

**Files:**
- Create: `.claudio/tasks/ClaudeTrader/pending/.gitkeep`
- Create: `.claudio/tasks/ClaudeTrader/active/.gitkeep`
- Create: `.claudio/tasks/ClaudeTrader/done/.gitkeep`
- Create: `.claudio/tasks/ClaudeTrader/failed/.gitkeep`
- Create: `.claudio/tasks/WebsMami/{pending,active,done,failed}/.gitkeep`
- Create: `.claudio/tasks/ClaudeSEO/{pending,active,done,failed}/.gitkeep`
- Create: `.claudio/results/{ClaudeTrader,WebsMami,ClaudeSEO}/.gitkeep`
- Create: `.claudio/archive/tasks/.gitkeep`
- Modify: `.gitignore`

- [ ] **Step 1: Verify the bus directory does not exist yet**

```bash
test -d "D:/CLAUDIO/.claudio" && echo "EXISTS" || echo "OK — does not exist yet"
```

Expected: `OK — does not exist yet`

- [ ] **Step 2: Create all task queue directories with .gitkeep placeholders**

```bash
cd "D:/CLAUDIO"
for project in ClaudeTrader WebsMami ClaudeSEO; do
  for state in pending active done failed; do
    mkdir -p ".claudio/tasks/$project/$state"
    touch ".claudio/tasks/$project/$state/.gitkeep"
  done
done
```

- [ ] **Step 3: Create results and archive directories**

```bash
cd "D:/CLAUDIO"
for project in ClaudeTrader WebsMami ClaudeSEO; do
  mkdir -p ".claudio/results/$project"
  touch ".claudio/results/$project/.gitkeep"
done
mkdir -p ".claudio/archive/tasks"
touch ".claudio/archive/tasks/.gitkeep"
```

- [ ] **Step 4: Verify structure**

```bash
find "D:/CLAUDIO/.claudio" -name ".gitkeep" | sort
```

Expected output (15 lines):
```
.claudio/archive/tasks/.gitkeep
.claudio/results/ClaudeSEO/.gitkeep
.claudio/results/ClaudeTrader/.gitkeep
.claudio/results/WebsMami/.gitkeep
.claudio/tasks/ClaudeSEO/active/.gitkeep
.claudio/tasks/ClaudeSEO/done/.gitkeep
.claudio/tasks/ClaudeSEO/failed/.gitkeep
.claudio/tasks/ClaudeSEO/pending/.gitkeep
.claudio/tasks/ClaudeTrader/active/.gitkeep
.claudio/tasks/ClaudeTrader/done/.gitkeep
.claudio/tasks/ClaudeTrader/failed/.gitkeep
.claudio/tasks/ClaudeTrader/pending/.gitkeep
.claudio/tasks/WebsMami/active/.gitkeep
.claudio/tasks/WebsMami/done/.gitkeep
.claudio/tasks/WebsMami/failed/.gitkeep
```

- [ ] **Step 5: Append .claudio/ gitignore rules**

Open `.gitignore` and append the following block after the existing content (`.worktrees/` is already present — do NOT duplicate it):

```
# .claudio ephemeral state (pending/ and active/ are not committed; done/, failed/, results/, agents/, archive/ are)
.claudio/registry.json
.claudio/tasks/*/pending/
.claudio/tasks/*/active/
```

- [ ] **Step 6: Verify .gitignore is not gitignoring tracked dirs**

```bash
cd "D:/CLAUDIO"
git check-ignore -v .claudio/tasks/ClaudeTrader/done/.gitkeep || echo "NOT ignored — OK"
git check-ignore -v .claudio/tasks/ClaudeTrader/pending/.gitkeep && echo "IGNORED — OK"
```

Expected: first command prints `NOT ignored — OK`, second prints the gitignore rule that ignores it.

- [ ] **Step 7: Commit**

```bash
cd "D:/CLAUDIO"
git add .claudio/ .gitignore
git status  # verify no pending/ or active/ dirs are staged
git commit -m "feat: add .claudio/ message bus scaffold and gitignore rules"
```

---

## Task 2: Registry.json and Agent Configs

**Files:**
- Create: `.claudio/registry.json`
- Create: `.claudio/agents/ClaudeTrader/config.json`
- Create: `.claudio/agents/WebsMami/config.json`
- Create: `.claudio/agents/ClaudeSEO/config.json`

- [ ] **Step 1: Verify agent config dirs do not exist**

```bash
test -d "D:/CLAUDIO/.claudio/agents" && echo "EXISTS" || echo "OK"
```

Expected: `OK`

- [ ] **Step 2: Create registry.json (initial state — all agents offline)**

Create `.claudio/registry.json`:

```json
{
  "updated_at": null,
  "agents": {
    "ClaudeTrader": {
      "status": "offline",
      "last_heartbeat": null,
      "current_task": null,
      "current_branch": null,
      "tasks_completed_today": 0,
      "recent_completions": []
    },
    "WebsMami": {
      "status": "offline",
      "last_heartbeat": null,
      "current_task": null,
      "current_branch": null,
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

- [ ] **Step 3: Verify registry.json is valid JSON and gitignored**

```bash
cd "D:/CLAUDIO"
pwsh -c "Get-Content .claudio/registry.json | ConvertFrom-Json | Select-Object -ExpandProperty agents | Get-Member -MemberType NoteProperty | Select-Object Name"
git check-ignore -v .claudio/registry.json
```

Expected: three rows (ClaudeSEO, ClaudeTrader, WebsMami) + gitignore rule printed.

- [ ] **Step 4: Create ClaudeTrader agent config**

Create `.claudio/agents/ClaudeTrader/config.json`:

```json
{
  "project": "ClaudeTrader",
  "branch_prefix": "feature",
  "base_branch": "main",
  "polling_interval_seconds": 30,
  "auto_merge": true,
  "auto_push": true,
  "require_tests_pass": true,
  "compact_after_each_task": true
}
```

- [ ] **Step 5: Create WebsMami agent config**

Create `.claudio/agents/WebsMami/config.json`:

```json
{
  "project": "WebsMami",
  "branch_prefix": "feature",
  "base_branch": "main",
  "polling_interval_seconds": 30,
  "auto_merge": true,
  "auto_push": true,
  "require_tests_pass": false,
  "compact_after_each_task": true
}
```

Note: `require_tests_pass: false` — WebsMami is a PHP/Python project with no test runner currently configured.

- [ ] **Step 6: Create ClaudeSEO agent config**

Create `.claudio/agents/ClaudeSEO/config.json`:

```json
{
  "project": "ClaudeSEO",
  "branch_prefix": "feature",
  "base_branch": "main",
  "polling_interval_seconds": 30,
  "auto_merge": false,
  "auto_push": false,
  "require_tests_pass": false,
  "compact_after_each_task": true
}
```

Note: `auto_merge: false, auto_push: false` — ClaudeSEO is a Work project with extra caution required before any deploy.

- [ ] **Step 7: Verify all three configs parse as valid JSON**

```bash
cd "D:/CLAUDIO"
for f in .claudio/agents/*/config.json; do
  pwsh -c "Get-Content '$f' | ConvertFrom-Json | Select-Object project, auto_merge" && echo "$f OK"
done
```

Expected: three `OK` lines.

- [ ] **Step 8: Commit**

```bash
cd "D:/CLAUDIO"
git add .claudio/agents/
git commit -m "feat: add agent configs for ClaudeTrader, WebsMami, ClaudeSEO"
```

---

## Task 3: Learnings Journals

**Files:**
- Create: `.claudio/agents/ClaudeTrader/learnings.md`
- Create: `.claudio/agents/WebsMami/learnings.md`
- Create: `.claudio/agents/ClaudeSEO/learnings.md`

- [ ] **Step 1: Create ClaudeTrader learnings journal**

Create `.claudio/agents/ClaudeTrader/learnings.md`:

```markdown
# ClaudeTrader — Agent Learnings Journal

This file accumulates hard-won knowledge that is NOT documented in CLAUDE.md, code comments,
or git history. Agents MUST read this at session start (step 2 of startup checklist).
Agents MUST append to this after each task when they discover something non-obvious.

## Format

Each entry: date, task ID, and the insight. One bullet per insight.

---

## Entries

_No entries yet. First agent to run will add discoveries here._
```

- [ ] **Step 2: Create WebsMami learnings journal**

Create `.claudio/agents/WebsMami/learnings.md`:

```markdown
# WebsMami — Agent Learnings Journal

This file accumulates hard-won knowledge that is NOT documented in CLAUDE.md, code comments,
or git history. Agents MUST read this at session start (step 2 of startup checklist).
Agents MUST append to this after each task when they discover something non-obvious.

## Format

Each entry: date, task ID, and the insight. One bullet per insight.

## CRITICAL: Credentials

config.php files in kokett/ and bawywear/ contain DB credentials and Redsys keys.
NEVER commit any file that contains plain-text credentials. Verify before every commit.

---

## Entries

_No entries yet. First agent to run will add discoveries here._
```

- [ ] **Step 3: Create ClaudeSEO learnings journal**

Create `.claudio/agents/ClaudeSEO/learnings.md`:

```markdown
# ClaudeSEO — Agent Learnings Journal

This file accumulates hard-won knowledge that is NOT documented in CLAUDE.md, code comments,
or git history. Agents MUST read this at session start (step 2 of startup checklist).
Agents MUST append to this after each task when they discover something non-obvious.

## Format

Each entry: date, task ID, and the insight. One bullet per insight.

## CRITICAL: Work Project

ClaudeSEO is a Work project (Rechtecheck). auto_merge and auto_push are disabled in config.json.
Always get explicit Queen approval before merging or pushing. Extra caution on all changes.

---

## Entries

_No entries yet. First agent to run will add discoveries here._
```

- [ ] **Step 4: Verify journals exist and are tracked**

```bash
cd "D:/CLAUDIO"
for f in .claudio/agents/*/learnings.md; do
  git check-ignore -v "$f" && echo "IGNORED — BAD" || echo "$f tracked — OK"
done
```

Expected: three `tracked — OK` lines (learnings.md is NOT gitignored).

- [ ] **Step 5: Commit**

```bash
cd "D:/CLAUDIO"
git add .claudio/agents/
git commit -m "feat: add learnings journals for all three project agents"
```

---

## Task 4: `scripts/telegram-notify.ps1` and `.env.example`

**Files:**
- Create: `scripts/telegram-notify.ps1`
- Create: `.env.example`

- [ ] **Step 1: Verify scripts/ directory does not exist**

```bash
test -d "D:/CLAUDIO/scripts" && echo "EXISTS" || echo "OK — does not exist"
```

Expected: `OK — does not exist`

- [ ] **Step 2: Create `.env.example`**

Create `.env.example` at repo root:

```bash
# Claudio environment variables — copy this file to .env and fill in values
# .env is gitignored and must NEVER be committed

# Telegram Bot credentials (required for agent notifications)
# Create a bot via @BotFather on Telegram to get the token
# Get your chat ID by messaging @userinfobot
TELEGRAM_BOT_TOKEN=your_bot_token_here
TELEGRAM_CHAT_ID=your_chat_id_here
```

- [ ] **Step 3: Create `scripts/telegram-notify.ps1`**

Create `scripts/telegram-notify.ps1`:

```powershell
# telegram-notify.ps1 — Send a one-way message to the configured Telegram chat
# Usage: pwsh scripts/telegram-notify.ps1 "*ClaudeTrader* Done: task-001 ✓"
# Reads credentials from .env at repo root (TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID)

param(
  [Parameter(Mandatory=$true)]
  [string]$Message
)

$claudioRoot = Split-Path $PSScriptRoot -Parent
$envPath = Join-Path $claudioRoot ".env"

if (-not (Test-Path $envPath)) {
  Write-Error ".env not found at $envPath — copy .env.example to .env and fill in credentials"
  exit 1
}

# Parse .env — no external module required
Get-Content $envPath | ForEach-Object {
  if ($_ -match '^([^#\s][^=]*)=(.*)$') {
    [System.Environment]::SetEnvironmentVariable($matches[1].Trim(), $matches[2].Trim(), 'Process')
  }
}

$token  = $env:TELEGRAM_BOT_TOKEN
$chatId = $env:TELEGRAM_CHAT_ID

if (-not $token -or -not $chatId) {
  Write-Error "TELEGRAM_BOT_TOKEN or TELEGRAM_CHAT_ID not set in .env"
  exit 1
}

try {
  $body = @{
    chat_id    = $chatId
    text       = $Message
    parse_mode = "Markdown"
  }
  $response = Invoke-RestMethod `
    -Uri "https://api.telegram.org/bot$token/sendMessage" `
    -Method Post `
    -Body ($body | ConvertTo-Json) `
    -ContentType "application/json"
  Write-Host "Telegram sent: $Message"
} catch {
  Write-Warning "Telegram send failed: $($_.Exception.Message)"
  # Do not exit 1 — notification failure must never block agent work
}
```

- [ ] **Step 4: Verify the script parses without syntax errors**

```bash
pwsh -c "& { . 'D:/CLAUDIO/scripts/telegram-notify.ps1' -Message 'test' -WhatIf }" 2>&1 | head -5 || \
pwsh -NoProfile -Command "
  \$null = Get-Command -Syntax 'D:/CLAUDIO/scripts/telegram-notify.ps1' -ErrorAction SilentlyContinue
  [System.Management.Automation.Language.Parser]::ParseFile('D:/CLAUDIO/scripts/telegram-notify.ps1', [ref]\$null, [ref]\$null) | Out-Null
  Write-Host 'Parse OK'
"
```

Expected: `Parse OK` (no syntax errors).

- [ ] **Step 5: Commit**

```bash
cd "D:/CLAUDIO"
git add scripts/ .env.example
git status  # verify .env is NOT staged (only .env.example)
git commit -m "feat: add telegram-notify.ps1 and .env.example"
```

---

## Task 5: `scripts/agent-checkin.ps1`

**Files:**
- Create: `scripts/agent-checkin.ps1`

- [ ] **Step 1: Create `scripts/agent-checkin.ps1`**

Create `scripts/agent-checkin.ps1`:

```powershell
# agent-checkin.ps1 — Agent writes its current status to .claudio/registry.json
# Called by project agents to register heartbeats, task start, task complete, and idle/offline states
#
# Usage examples:
#   pwsh scripts/agent-checkin.ps1 -Project ClaudeTrader -Status active -CurrentTask task-20260326-001
#   pwsh scripts/agent-checkin.ps1 -Project ClaudeTrader -Status idle
#   pwsh scripts/agent-checkin.ps1 -Project ClaudeTrader -Status active -CompleteTask task-20260326-001

param(
  [Parameter(Mandatory=$true)]
  [ValidateSet('ClaudeTrader','WebsMami','ClaudeSEO')]
  [string]$Project,

  [Parameter(Mandatory=$true)]
  [ValidateSet('active','idle','offline')]
  [string]$Status,

  [string]$CurrentTask    = $null,
  [string]$CurrentBranch  = $null,
  [string]$CompleteTask   = $null   # task ID just completed — prepended to recent_completions
)

$claudioRoot  = Split-Path $PSScriptRoot -Parent
$registryPath = Join-Path $claudioRoot ".claudio\registry.json"

# Load or initialise registry
if (Test-Path $registryPath) {
  $reg = Get-Content $registryPath -Raw | ConvertFrom-Json
} else {
  # Bootstrap empty registry
  $reg = [PSCustomObject]@{
    updated_at = $null
    agents = [PSCustomObject]@{
      ClaudeTrader = [PSCustomObject]@{ status='offline'; last_heartbeat=$null; current_task=$null; current_branch=$null; tasks_completed_today=0; recent_completions=@() }
      WebsMami     = [PSCustomObject]@{ status='offline'; last_heartbeat=$null; current_task=$null; current_branch=$null; tasks_completed_today=0; recent_completions=@() }
      ClaudeSEO    = [PSCustomObject]@{ status='offline'; last_heartbeat=$null; current_task=$null; current_branch=$null; tasks_completed_today=0; recent_completions=@() }
    }
  }
}

$now    = (Get-Date -Format 'yyyy-MM-ddTHH:mm:ssZ')
$agent  = $reg.agents.$Project

$agent.status         = $Status
$agent.last_heartbeat = $now

if ($PSBoundParameters.ContainsKey('CurrentTask')) {
  $agent.current_task = $CurrentTask
}
if ($PSBoundParameters.ContainsKey('CurrentBranch')) {
  $agent.current_branch = $CurrentBranch
}

# Handle task completion: prepend to recent_completions, keep last 5
if ($CompleteTask) {
  $list = @($CompleteTask) + @($agent.recent_completions | Select-Object -First 4)
  $agent.recent_completions   = $list
  $agent.tasks_completed_today = [int]$agent.tasks_completed_today + 1
  $agent.current_task          = $null
}

$reg.updated_at = $now
$reg | ConvertTo-Json -Depth 5 | Set-Content $registryPath -Encoding UTF8

Write-Host "[$Project] status=$Status heartbeat=$now"
```

- [ ] **Step 2: Smoke-test the script (writes to a temp copy of registry)**

```bash
cd "D:/CLAUDIO"
# Copy registry to temp, run checkin, verify output, restore
cp .claudio/registry.json .claudio/registry.json.bak
pwsh -Command "scripts/agent-checkin.ps1 -Project ClaudeTrader -Status active -CurrentTask task-test-001"
pwsh -Command "
  \$r = Get-Content '.claudio/registry.json' | ConvertFrom-Json
  if (\$r.agents.ClaudeTrader.status -eq 'active' -and \$r.agents.ClaudeTrader.current_task -eq 'task-test-001') {
    Write-Host 'agent-checkin.ps1 OK'
  } else {
    Write-Error 'FAIL: unexpected registry state'
  }
"
# Reset registry back to offline state
pwsh -Command "scripts/agent-checkin.ps1 -Project ClaudeTrader -Status offline"
rm .claudio/registry.json.bak
```

Expected: `agent-checkin.ps1 OK`

- [ ] **Step 3: Commit**

```bash
cd "D:/CLAUDIO"
git add scripts/agent-checkin.ps1
git commit -m "feat: add agent-checkin.ps1 for registry heartbeat management"
```

---

## Task 6: `scripts/spawn-agent.ps1`

**Files:**
- Create: `scripts/spawn-agent.ps1`

- [ ] **Step 1: Create `scripts/spawn-agent.ps1`**

Create `scripts/spawn-agent.ps1`:

```powershell
# spawn-agent.ps1 — Open a new Windows Terminal tab for a project agent
# The terminal starts Claude Code in the project directory, which auto-loads:
#   - The project's CLAUDE.md (hierarchical, picked up by Claude automatically)
#   - The project's .mcp.json (picked up by Claude Code automatically)
#
# Usage: pwsh scripts/spawn-agent.ps1 -ProjectName ClaudeTrader
# First-time: Claude Code will prompt to trust the directory — confirm once.
# Subsequent runs: trust is remembered in ~/.claude/settings.json permanently.

param(
  [Parameter(Mandatory=$true)]
  [ValidateSet('ClaudeTrader','WebsMami','ClaudeSEO')]
  [string]$ProjectName
)

$claudioRoot = Split-Path $PSScriptRoot -Parent

$projectPaths = @{
  'ClaudeTrader' = Join-Path $claudioRoot 'Projects\ClaudeTrader'
  'WebsMami'     = Join-Path $claudioRoot 'Projects\WebsMami'
  'ClaudeSEO'    = Join-Path $claudioRoot 'Work (Rechtecheck)\ClaudeSEO'
}

$projectPath = $projectPaths[$ProjectName]

if (-not (Test-Path $projectPath)) {
  Write-Error "Project directory not found: $projectPath"
  exit 1
}

# Guard: do not double-spawn an already active agent
$registryPath = Join-Path $claudioRoot '.claudio\registry.json'
if (Test-Path $registryPath) {
  $reg = Get-Content $registryPath -Raw | ConvertFrom-Json
  if ($reg.agents.$ProjectName.status -eq 'active') {
    Write-Host "[$ProjectName] Agent already active — skipping spawn. Terminal should already be open."
    exit 0
  }
}

Write-Host "Spawning agent for $ProjectName at $projectPath"
Write-Host "Windows Terminal will open a new tab. Claude Code will start in the project directory."
Write-Host "If this is the first time opening this directory, approve the trust prompt once."

# Open new Windows Terminal tab, cd to project, launch claude
# Claude Code auto-loads CLAUDE.md and .mcp.json from the project directory
wt.exe new-tab `
  --title "Claudio: $ProjectName" `
  -- pwsh -NoExit -Command "Set-Location '$projectPath'; Write-Host 'Claudio Agent: $ProjectName — ready'; claude"
```

- [ ] **Step 2: Test parameter validation**

```bash
pwsh -Command "
  try {
    & 'D:/CLAUDIO/scripts/spawn-agent.ps1' -ProjectName 'InvalidProject'
    Write-Error 'FAIL: should have rejected invalid project'
  } catch {
    Write-Host 'Parameter validation OK — rejected InvalidProject'
  }
" 2>&1
```

Expected: `Parameter validation OK — rejected InvalidProject`

- [ ] **Step 3: Test project path resolution**

```bash
pwsh -Command "
  # Simulate $PSScriptRoot without actually running wt.exe
  \$claudioRoot = 'D:/CLAUDIO'
  \$path = Join-Path \$claudioRoot 'Projects\ClaudeTrader'
  if (Test-Path \$path) { Write-Host 'ClaudeTrader path resolves OK: ' + \$path }
  else { Write-Error 'Path not found: ' + \$path }
"
```

Expected: `ClaudeTrader path resolves OK: D:/CLAUDIO/Projects/ClaudeTrader`

- [ ] **Step 4: Commit**

```bash
cd "D:/CLAUDIO"
git add scripts/spawn-agent.ps1
git commit -m "feat: add spawn-agent.ps1 for Windows Terminal project agent launching"
```

---

## Task 7: `scripts/queue-task.ps1`

**Files:**
- Create: `scripts/queue-task.ps1`

- [ ] **Step 1: Create `scripts/queue-task.ps1`**

Create `scripts/queue-task.ps1`:

```powershell
# queue-task.ps1 — Queen writes a task to a project's pending/ queue
# Usage: pwsh scripts/queue-task.ps1 -Project ClaudeTrader -Title "Add MA indicator" -Type feature -Priority high
# The task JSON is written to .claudio/tasks/<Project>/pending/<id>.json
# The agent picks it up automatically on next poll or startup.

param(
  [Parameter(Mandatory=$true)]
  [ValidateSet('ClaudeTrader','WebsMami','ClaudeSEO')]
  [string]$Project,

  [Parameter(Mandatory=$true)]
  [string]$Title,

  [string]$Description = '',

  [ValidateSet('feature','bugfix','review','research','deploy','maintenance')]
  [string]$Type = 'feature',

  [ValidateSet('high','normal','low')]
  [string]$Priority = 'normal',

  [string]$BranchPrefix = 'feature',

  [switch]$NoTelegram
)

$claudioRoot = Split-Path $PSScriptRoot -Parent
$pendingDir  = Join-Path $claudioRoot ".claudio\tasks\$Project\pending"

if (-not (Test-Path $pendingDir)) {
  New-Item -ItemType Directory -Path $pendingDir -Force | Out-Null
}

# Task ID: timestamp-based for uniqueness and natural sort order
$taskId = "task-$(Get-Date -Format 'yyyyMMdd-HHmmss')"

$task = [ordered]@{
  id              = $taskId
  project         = $Project
  type            = $Type
  title           = $Title
  description     = $Description
  priority        = $Priority
  depends_on      = @()
  created_at      = (Get-Date -Format 'yyyy-MM-ddTHH:mm:ssZ')
  created_by      = 'queen'
  branch_prefix   = $BranchPrefix
  telegram_notify = (-not $NoTelegram.IsPresent)
}

$taskPath = Join-Path $pendingDir "$taskId.json"
$task | ConvertTo-Json | Set-Content $taskPath -Encoding UTF8

Write-Host ""
Write-Host "Task queued successfully:"
Write-Host "  ID:       $taskId"
Write-Host "  Project:  $Project"
Write-Host "  Title:    $Title"
Write-Host "  Type:     $Type | Priority: $Priority"
Write-Host "  File:     $taskPath"
Write-Host ""
Write-Host "Start the agent if not running: pwsh scripts/spawn-agent.ps1 -ProjectName $Project"
```

- [ ] **Step 2: Test: queue a task and verify the file is created**

```bash
cd "D:/CLAUDIO"
pwsh -Command "scripts/queue-task.ps1 -Project ClaudeTrader -Title 'Smoke test task' -Type research -Priority low -NoTelegram"
```

Expected output: task ID printed, file path shown.

- [ ] **Step 3: Verify the task file is valid JSON with correct structure**

```bash
pwsh -Command "
  \$files = Get-ChildItem '.claudio/tasks/ClaudeTrader/pending/*.json'
  if (\$files.Count -eq 0) { Write-Error 'No task files found'; exit 1 }
  \$task = Get-Content \$files[0] | ConvertFrom-Json
  if (\$task.project -eq 'ClaudeTrader' -and \$task.type -eq 'research') {
    Write-Host 'Task JSON OK — id:' \$task.id
  } else { Write-Error 'Unexpected task content' }
"
```

Expected: `Task JSON OK — id: task-<timestamp>`

- [ ] **Step 4: Clean up the test task**

```bash
rm D:/CLAUDIO/.claudio/tasks/ClaudeTrader/pending/task-*.json
```

- [ ] **Step 5: Commit**

```bash
cd "D:/CLAUDIO"
git add scripts/queue-task.ps1
git commit -m "feat: add queue-task.ps1 for writing tasks to the pending/ bus"
```

---

## Task 8: `scripts/archive-tasks.ps1`

**Files:**
- Create: `scripts/archive-tasks.ps1`

- [ ] **Step 1: Create `scripts/archive-tasks.ps1`**

Create `scripts/archive-tasks.ps1`:

```powershell
# archive-tasks.ps1 — Move done/ task files older than N days to archive/tasks/
# Run by Queen once a week to keep done/ dirs lean.
# Usage: pwsh scripts/archive-tasks.ps1
# Usage: pwsh scripts/archive-tasks.ps1 -DaysOld 60

param([int]$DaysOld = 30)

$claudioRoot = Split-Path $PSScriptRoot -Parent
$projects    = @('ClaudeTrader','WebsMami','ClaudeSEO')
$cutoff      = (Get-Date).AddDays(-$DaysOld)
$archived    = 0

foreach ($project in $projects) {
  $doneDir    = Join-Path $claudioRoot ".claudio\tasks\$project\done"
  $archiveDir = Join-Path $claudioRoot ".claudio\archive\tasks\$project"

  if (-not (Test-Path $doneDir)) { continue }

  New-Item -ItemType Directory -Path $archiveDir -Force | Out-Null

  Get-ChildItem (Join-Path $doneDir "*.json") |
    Where-Object { $_.LastWriteTime -lt $cutoff } |
    ForEach-Object {
      $dest = Join-Path $archiveDir $_.Name
      Move-Item $_.FullName $dest
      Write-Host "Archived: $($_.Name)"
      $archived++
    }
}

Write-Host ""
if ($archived -eq 0) {
  Write-Host "No tasks older than $DaysOld days found. Nothing to archive."
} else {
  Write-Host "Archived $archived task file(s) older than $DaysOld days to .claudio/archive/tasks/"
}
```

- [ ] **Step 2: Verify the script parses without errors**

```bash
pwsh -NoProfile -Command "
  [System.Management.Automation.Language.Parser]::ParseFile(
    'D:/CLAUDIO/scripts/archive-tasks.ps1',
    [ref]\$null, [ref]\$null
  ) | Out-Null
  Write-Host 'archive-tasks.ps1 parse OK'
"
```

Expected: `archive-tasks.ps1 parse OK`

- [ ] **Step 3: Commit**

```bash
cd "D:/CLAUDIO"
git add scripts/archive-tasks.ps1
git commit -m "feat: add archive-tasks.ps1 for done/ task rotation"
```

---

## Task 9: Append Agent Startup Checklist to Project CLAUDE.md Files

**Files:**
- Modify: `Projects/ClaudeTrader/CLAUDE.md`
- Modify: `Projects/WebsMami/CLAUDE.md`
- Modify: `Work (Rechtecheck)/ClaudeSEO/CLAUDE.md`

- [ ] **Step 1: Verify none of the project CLAUDE.md files already have the checklist**

```bash
grep -l "Claudio Agent Startup Checklist" \
  "D:/CLAUDIO/Projects/ClaudeTrader/CLAUDE.md" \
  "D:/CLAUDIO/Projects/WebsMami/CLAUDE.md" \
  "D:/CLAUDIO/Work (Rechtecheck)/ClaudeSEO/CLAUDE.md" 2>/dev/null \
  && echo "ALREADY EXISTS — skip those files" || echo "None have it yet — OK to append"
```

Expected: `None have it yet — OK to append`

- [ ] **Step 2: Append agent startup checklist to ClaudeTrader/CLAUDE.md**

Open `Projects/ClaudeTrader/CLAUDE.md` and append the following block at the very end:

```markdown

---

### Claudio Agent Startup Checklist

When starting as a Claudio project agent (opened by `scripts/spawn-agent.ps1`):

1. **Register:** `pwsh D:/CLAUDIO/scripts/agent-checkin.ps1 -Project ClaudeTrader -Status active`
2. **Read learnings journal:** Read `D:/CLAUDIO/.claudio/agents/ClaudeTrader/learnings.md` before any work.
3. **Check tasks:** Scan `D:/CLAUDIO/.claudio/tasks/ClaudeTrader/pending/` — pick highest-priority task (high > normal > low).
4. **If task found:**
   - Move task JSON from `pending/` to `active/`
   - Update registry: `agent-checkin.ps1 -Project ClaudeTrader -Status active -CurrentTask <id>`
   - `pwsh D:/CLAUDIO/scripts/telegram-notify.ps1 "*ClaudeTrader* Starting: <title>"`
   - For `feature`/`bugfix`/`maintenance` → create git worktree (see Git Worktree Pattern below)
   - For `review`/`research` → work in project dir, write report to `D:/CLAUDIO/.claudio/results/ClaudeTrader/<id>.json`
   - Write result JSON to `D:/CLAUDIO/.claudio/results/ClaudeTrader/<task-id>.json`
   - Move task JSON from `active/` to `done/`
   - **Append learnings:** Add non-obvious discoveries to `D:/CLAUDIO/.claudio/agents/ClaudeTrader/learnings.md`
   - **Store in claude-mem:** Record key decisions via claude-mem MCP tools
   - `pwsh D:/CLAUDIO/scripts/telegram-notify.ps1 "*ClaudeTrader* Done: <title> ✓"`
   - `agent-checkin.ps1 -Project ClaudeTrader -Status idle -CompleteTask <id>`
   - Run `/compact` to keep context lean
5. **If no tasks:** `agent-checkin.ps1 -Project ClaudeTrader -Status idle` + send Telegram idle message
6. **Poll:** Use `/loop 30s` skill, or await Queen prompt when work arrives
7. **On failure:** Move task to `failed/` with error details → `telegram-notify.ps1 "*ClaudeTrader* ✗ Failed: <title>"` → escalate per Memory-First Rule in root CLAUDE.md
8. **Session runs indefinitely.** Compaction + learnings journal + claude-mem preserve all knowledge.

### Escalation from this agent

Before escalating: (1) CLAUDE.md rules → (2) claude-mem search → (3) project docs.
Only then: write to `failed/` with `"blocked": true` and send Telegram: `"*ClaudeTrader* needs decision: <question>"`

### Git Worktree Pattern (feature/bugfix/maintenance tasks)

```bash
# From project directory D:/CLAUDIO/Projects/ClaudeTrader
git worktree add "D:/CLAUDIO/.worktrees/ClaudeTrader/<task-id>" feature/<name>-$(date +%Y%m%d)
cd "D:/CLAUDIO/.worktrees/ClaudeTrader/<task-id>"

# ... implement, test, commit ...

# Merge from the project directory — worktrees are locked to their branch
cd "D:/CLAUDIO/Projects/ClaudeTrader"
git merge --no-ff feature/<name>-<date>
git push
git worktree remove "D:/CLAUDIO/.worktrees/ClaudeTrader/<task-id>"
git branch -d feature/<name>-<date>
```
```

- [ ] **Step 3: Verify ClaudeTrader CLAUDE.md has the section and existing rules are intact**

```bash
grep -c "Claudio Agent Startup Checklist" "D:/CLAUDIO/Projects/ClaudeTrader/CLAUDE.md"
grep -c "CRITICAL RULES" "D:/CLAUDIO/Projects/ClaudeTrader/CLAUDE.md"
grep -c "npm run dev" "D:/CLAUDIO/Projects/ClaudeTrader/CLAUDE.md"
```

Expected: all three print `1` (each appears exactly once).

- [ ] **Step 4: Append agent startup checklist to WebsMami/CLAUDE.md**

Open `Projects/WebsMami/CLAUDE.md` and append at the very end:

```markdown

---

### Claudio Agent Startup Checklist

When starting as a Claudio project agent (opened by `scripts/spawn-agent.ps1`):

1. **Register:** `pwsh D:/CLAUDIO/scripts/agent-checkin.ps1 -Project WebsMami -Status active`
2. **Read learnings journal:** Read `D:/CLAUDIO/.claudio/agents/WebsMami/learnings.md` before any work.
3. **CRITICAL — check credentials:** Before ANY file edit, verify no config.php or similar file containing DB/payment credentials will be modified accidentally.
4. **Check tasks:** Scan `D:/CLAUDIO/.claudio/tasks/WebsMami/pending/` — pick highest-priority task.
5. **If task found:**
   - Move task JSON from `pending/` to `active/`
   - Update registry: `agent-checkin.ps1 -Project WebsMami -Status active -CurrentTask <id>`
   - `pwsh D:/CLAUDIO/scripts/telegram-notify.ps1 "*WebsMami* Starting: <title>"`
   - For `feature`/`bugfix`/`maintenance` → create git worktree (see Git Worktree Pattern below)
   - For `review`/`research` → work in project dir, write report to `D:/CLAUDIO/.claudio/results/WebsMami/<id>.json`
   - Write result JSON to `D:/CLAUDIO/.claudio/results/WebsMami/<task-id>.json`
   - Move task JSON from `active/` to `done/`
   - **Append learnings:** Add non-obvious discoveries to `D:/CLAUDIO/.claudio/agents/WebsMami/learnings.md`
   - **Store in claude-mem:** Record key decisions via claude-mem MCP tools
   - `pwsh D:/CLAUDIO/scripts/telegram-notify.ps1 "*WebsMami* Done: <title> ✓"`
   - `agent-checkin.ps1 -Project WebsMami -Status idle -CompleteTask <id>`
   - Run `/compact` to keep context lean
6. **If no tasks:** `agent-checkin.ps1 -Project WebsMami -Status idle` + send Telegram idle message
7. **Poll:** Use `/loop 30s` skill, or await Queen prompt
8. **On failure:** Move task to `failed/` + `telegram-notify.ps1 "*WebsMami* ✗ Failed: <title>"` → escalate per Memory-First Rule

### Git Worktree Pattern

```bash
cd "D:/CLAUDIO/Projects/WebsMami"
git worktree add "D:/CLAUDIO/.worktrees/WebsMami/<task-id>" feature/<name>-$(date +%Y%m%d)
cd "D:/CLAUDIO/.worktrees/WebsMami/<task-id>"
# ... work ...
cd "D:/CLAUDIO/Projects/WebsMami"
git merge --no-ff feature/<name>-<date>
git push
git worktree remove "D:/CLAUDIO/.worktrees/WebsMami/<task-id>"
git branch -d feature/<name>-<date>
```
```

- [ ] **Step 5: Verify WebsMami CLAUDE.md**

```bash
grep -c "Claudio Agent Startup Checklist" "D:/CLAUDIO/Projects/WebsMami/CLAUDE.md"
grep -c "CRITICAL RULES" "D:/CLAUDIO/Projects/WebsMami/CLAUDE.md"
```

Expected: both print `1`.

- [ ] **Step 6: Append agent startup checklist to ClaudeSEO/CLAUDE.md**

Open `Work (Rechtecheck)/ClaudeSEO/CLAUDE.md` and append at the very end:

```markdown

---

### Claudio Agent Startup Checklist

When starting as a Claudio project agent (opened by `scripts/spawn-agent.ps1`):

**IMPORTANT:** ClaudeSEO is a Work project. `auto_merge` and `auto_push` are disabled in config.json.
Do NOT merge or push without explicit Queen approval via Telegram or terminal.

1. **Register:** `pwsh D:/CLAUDIO/scripts/agent-checkin.ps1 -Project ClaudeSEO -Status active`
2. **Read learnings journal:** Read `D:/CLAUDIO/.claudio/agents/ClaudeSEO/learnings.md` before any work.
3. **Check tasks:** Scan `D:/CLAUDIO/.claudio/tasks/ClaudeSEO/pending/` — pick highest-priority task.
4. **If task found:**
   - Move task JSON from `pending/` to `active/`
   - Update registry: `agent-checkin.ps1 -Project ClaudeSEO -Status active -CurrentTask <id>`
   - `pwsh D:/CLAUDIO/scripts/telegram-notify.ps1 "*ClaudeSEO* Starting: <title>"`
   - For `feature`/`bugfix`/`maintenance` → create git worktree (see pattern below). Do NOT auto-merge.
   - For `review`/`research` → work in project dir, write report to `D:/CLAUDIO/.claudio/results/ClaudeSEO/<id>.json`
   - Write result JSON to `D:/CLAUDIO/.claudio/results/ClaudeSEO/<task-id>.json`
   - Move task JSON from `active/` to `done/`
   - **Append learnings** to `D:/CLAUDIO/.claudio/agents/ClaudeSEO/learnings.md`
   - **Store in claude-mem** via claude-mem MCP tools
   - `pwsh D:/CLAUDIO/scripts/telegram-notify.ps1 "*ClaudeSEO* Done (awaiting merge approval): <title>"`
   - `agent-checkin.ps1 -Project ClaudeSEO -Status idle -CompleteTask <id>`
   - Run `/compact`
5. **If no tasks:** `agent-checkin.ps1 -Project ClaudeSEO -Status idle` + Telegram idle message
6. **Poll:** Use `/loop 30s` skill, or await Queen prompt
7. **On failure:** Move task to `failed/` + Telegram alert → escalate per Memory-First Rule

### Git Worktree Pattern

```bash
cd "D:/CLAUDIO/Work (Rechtecheck)/ClaudeSEO"
git worktree add "D:/CLAUDIO/.worktrees/ClaudeSEO/<task-id>" feature/<name>-$(date +%Y%m%d)
cd "D:/CLAUDIO/.worktrees/ClaudeSEO/<task-id>"
# ... work, commit — but DO NOT MERGE or PUSH without Queen approval ...
# Notify Queen: telegram-notify.ps1 "*ClaudeSEO* Branch ready for review: feature/<name>"
```
```

- [ ] **Step 7: Verify ClaudeSEO CLAUDE.md**

```bash
grep -c "Claudio Agent Startup Checklist" "D:/CLAUDIO/Work (Rechtecheck)/ClaudeSEO/CLAUDE.md"
grep -c "CRITICAL RULES" "D:/CLAUDIO/Work (Rechtecheck)/ClaudeSEO/CLAUDE.md"
```

Expected: both print `1`.

- [ ] **Step 8: Commit all three project CLAUDE.md updates**

```bash
cd "D:/CLAUDIO"
git add "Projects/ClaudeTrader/CLAUDE.md" "Projects/WebsMami/CLAUDE.md" "Work (Rechtecheck)/ClaudeSEO/CLAUDE.md"
git commit -m "feat: append Claudio agent startup checklist to all three project CLAUDE.md files"
```

---

## Task 10: Update Root CLAUDE.md Session Startup

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Verify the current step 5 in root CLAUDE.md**

```bash
grep -n "Brief\|brief\|pending tasks\|ruflo issues" "D:/CLAUDIO/CLAUDE.md"
```

Note the line number of the brief step — you will replace it.

- [ ] **Step 2: Replace step 5 of the session startup in root CLAUDE.md**

In `CLAUDE.md`, find the Session Startup section. Replace the current step 5 (brief) with these four steps:

```markdown
5. **Read agent registry**
   ```bash
   cat D:/CLAUDIO/.claudio/registry.json
   ```
   Report: which agents are `active` / `idle` / `offline` and current tasks.

6. **Check pending work**
   ```bash
   ls D:/CLAUDIO/.claudio/tasks/*/pending/
   ```
   Report: pending task count per project.

7. **Surface completed work since last session**
   ```bash
   # List result files newer than 24 hours
   find D:/CLAUDIO/.claudio/results -name "*.json" -newer D:/CLAUDIO/.claudio/registry.json
   ```
   Summarize: what each agent accomplished since last session (read the JSON files).

8. **Brief** — tell the user:
   - Which agents are active/idle/offline
   - What tasks are pending (and which project)
   - What was completed since last session
   - Any failed tasks needing attention
   - Example: "ClaudeTrader: active on task-001. WebsMami: idle. ClaudeSEO: offline. 1 pending task (WebsMami). 3 completed yesterday."
```

- [ ] **Step 3: Verify the update**

```bash
grep -c "Read agent registry" "D:/CLAUDIO/CLAUDE.md"
grep -c "Check pending work" "D:/CLAUDIO/CLAUDE.md"
grep -c "Surface completed work" "D:/CLAUDIO/CLAUDE.md"
```

Expected: all three print `1`.

- [ ] **Step 4: Verify the existing sections are still intact**

```bash
grep -c "Session Startup" "D:/CLAUDIO/CLAUDE.md"
grep -c "Orchestration Model" "D:/CLAUDIO/CLAUDE.md"
grep -c "Escalation Policy" "D:/CLAUDIO/CLAUDE.md"
```

Expected: each prints `1`.

- [ ] **Step 5: Commit**

```bash
cd "D:/CLAUDIO"
git add CLAUDE.md
git commit -m "feat: update Queen session startup to read .claudio/ bus (steps 5-8)"
```

---

## Task 11: Integration Smoke Test and Push

- [ ] **Step 1: Verify full directory structure exists**

```bash
cd "D:/CLAUDIO"
for path in \
  ".claudio/tasks/ClaudeTrader/done" \
  ".claudio/tasks/WebsMami/failed" \
  ".claudio/tasks/ClaudeSEO/done" \
  ".claudio/results/ClaudeTrader" \
  ".claudio/results/WebsMami" \
  ".claudio/results/ClaudeSEO" \
  ".claudio/archive/tasks" \
  ".claudio/agents/ClaudeTrader" \
  ".claudio/agents/WebsMami" \
  ".claudio/agents/ClaudeSEO" \
  "scripts"; do
  test -d "$path" && echo "OK: $path" || echo "MISSING: $path"
done
```

Expected: all lines print `OK`.

- [ ] **Step 2: Verify all scripts exist and parse cleanly**

```bash
pwsh -NoProfile -Command "
  \$scripts = Get-ChildItem 'D:/CLAUDIO/scripts/*.ps1'
  foreach (\$s in \$scripts) {
    \$errors = \$null
    [System.Management.Automation.Language.Parser]::ParseFile(\$s.FullName, [ref]\$null, [ref](\$errors)) | Out-Null
    if (\$errors.Count -eq 0) { Write-Host \"OK: \$(\$s.Name)\" }
    else { Write-Error \"PARSE ERROR: \$(\$s.Name) — \$(\$errors[0].Message)\" }
  }
"
```

Expected: 5 lines, all `OK`.

- [ ] **Step 3: Verify all JSON configs are valid**

```bash
pwsh -NoProfile -Command "
  \$configs = Get-ChildItem 'D:/CLAUDIO/.claudio/agents/*/config.json'
  foreach (\$c in \$configs) {
    try {
      Get-Content \$c | ConvertFrom-Json | Out-Null
      Write-Host \"OK: \$(\$c.FullName.Replace('D:/CLAUDIO/', ''))\"
    } catch {
      Write-Error \"INVALID JSON: \$(\$c.FullName) — \$_\"
    }
  }
"
```

Expected: 3 lines, all `OK`.

- [ ] **Step 4: Verify all CLAUDE.md files have the startup checklist**

```bash
for f in \
  "D:/CLAUDIO/Projects/ClaudeTrader/CLAUDE.md" \
  "D:/CLAUDIO/Projects/WebsMami/CLAUDE.md" \
  "D:/CLAUDIO/Work (Rechtecheck)/ClaudeSEO/CLAUDE.md"; do
  grep -q "Claudio Agent Startup Checklist" "$f" && echo "OK: $f" || echo "MISSING: $f"
done
```

Expected: all three `OK`.

- [ ] **Step 5: Verify root CLAUDE.md has bus-reading session startup**

```bash
grep -q "Read agent registry" "D:/CLAUDIO/CLAUDE.md" && echo "OK: Queen startup updated" || echo "MISSING"
```

Expected: `OK: Queen startup updated`

- [ ] **Step 6: Verify git status — nothing sensitive is untracked/staged**

```bash
cd "D:/CLAUDIO"
git status
```

Confirm: no `.env` file is staged or untracked. All new files are either staged (from previous commits) or properly gitignored.

- [ ] **Step 7: Final git push**

```bash
cd "D:/CLAUDIO"
git log --oneline | head -12
git push
```

Expected: push succeeds. Log shows ~10-12 commits from this subsystem.

---

## Self-Review Checklist

| Spec requirement | Task |
|---|---|
| `.claudio/` directory structure with all subdirs | Task 1 |
| `.gitignore` additions for ephemeral state | Task 1 |
| `registry.json` initial state | Task 2 |
| Agent configs for all 3 projects | Task 2 |
| Learnings journals (initial files, tracked) | Task 3 |
| `telegram-notify.ps1` (portable, .env parser, non-fatal on failure) | Task 4 |
| `.env.example` | Task 4 |
| `agent-checkin.ps1` (updates registry, handles completions list) | Task 5 |
| `spawn-agent.ps1` (Windows Terminal, guards double-spawn, portable paths) | Task 6 |
| `queue-task.ps1` (writes task JSON, validates project/type/priority) | Task 7 |
| `archive-tasks.ps1` (rotates done/ tasks older than 30 days) | Task 8 |
| Agent startup checklist in each project CLAUDE.md | Task 9 |
| ClaudeSEO auto_merge/auto_push disabled (Work project caution) | Task 2 + Task 9 |
| Root CLAUDE.md session startup steps 5-8 | Task 10 |
| Context compaction: `/compact` after each task | Task 9 (in checklist) |
| Learnings journal read at startup | Task 9 (in checklist) |
| claude-mem recording after each task | Task 9 (in checklist) |
| No session restart | Removed from design; not implemented |
| ruv-swarm upgrade path documented | In spec; no implementation action needed |
| Cross-device portability via `$PSScriptRoot` | Tasks 4-8 (all scripts) |
