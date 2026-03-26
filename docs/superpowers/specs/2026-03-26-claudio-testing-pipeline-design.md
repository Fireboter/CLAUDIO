# Claudio — Automated Testing Pipeline
## Subsystem 5 Design Spec

**Goal:** A config-driven, project-agnostic test runner that fires after task completion, executes health checks and Playwright browser tests against any local or remote target, sends screenshots to Telegram on every run, and auto-queues a fix task when something fails.

**Chosen approach:** `scripts/run-tests.py` (Python + Playwright) reads `.claudio/agents/{name}/tests.json` per project. Each config defines stack type, optional local server startup, health commands, and a list of URL checks with expected status codes, text assertions, and screenshot labels. `agent-checkin.ps1` gains a `-RunTests` switch. New projects automatically get a starter `tests.json` scaffolded during the Project Import Procedure.

---

## Scope

**In scope:**
- `scripts/run-tests.py` — main test runner
- `scripts/requirements-tests.txt` — `playwright` dependency declaration
- `scripts/start-tests.ps1` — manual launcher (all projects or one)
- `.claudio/agents/{ClaudeTrader,WebsMami,ClaudeSEO}/tests.json` — initial configs
- `scripts/agent-checkin.ps1` — add `-RunTests` switch (+5 lines)
- `CLAUDE.md` — add step 9 to Project Import Procedure (scaffold `tests.json`)

**Out of scope:**
- CI/CD integration (GitHub Actions)
- Test history dashboards or trend graphs
- Parallel multi-project test runs
- Video recording
- Performance/load testing

---

## Architecture

### File Map

| Action | Path | Role |
|---|---|---|
| Create | `scripts/run-tests.py` | Test runner: health checks + Playwright browser checks + Telegram report |
| Create | `scripts/requirements-tests.txt` | `playwright` + `python-dotenv` + `python-telegram-bot` |
| Create | `scripts/start-tests.ps1` | Manual wt.exe launcher for test runs |
| Create | `.claudio/agents/ClaudeTrader/tests.json` | ClaudeTrader test config (local Next.js) |
| Create | `.claudio/agents/WebsMami/tests.json` | WebsMami test config (remote URL) |
| Create | `.claudio/agents/ClaudeSEO/tests.json` | ClaudeSEO test config (remote URL) |
| Modify | `scripts/agent-checkin.ps1` | Add `-RunTests` switch |
| Modify | `CLAUDE.md` | Add step 9 to Project Import Procedure |

### Data Flow

```
agent-checkin.ps1 -Project ClaudeTrader -Status idle -CompleteTask task-001 -RunTests
  └─► python scripts/run-tests.py --project ClaudeTrader
        ├─ reads .claudio/agents/ClaudeTrader/tests.json
        ├─ runs health[] commands (must exit 0)
        ├─ starts local dev server (if startup defined), polls until ready
        ├─ Playwright (headless Chromium):
        │     for each check: goto url, assert status + text, screenshot
        ├─ stops local dev server
        ├─ writes .claudio/results/test-ClaudeTrader-<ts>.json
        ├─ if FAIL:
        │     creates .claudio/tasks/ClaudeTrader/pending/test-fix-<ts>.json
        │     sends Telegram failure report + all screenshots
        └─ if PASS:
              sends Telegram success line + all screenshots

Manual run:
  pwsh scripts/start-tests.ps1 [-Project ClaudeTrader]
  └─► same as above, new Windows Terminal tab
```

---

## `tests.json` Schema

Every project declares its test config at `.claudio/agents/{name}/tests.json`.

```json
{
  "enabled": true,
  "stack": "nextjs",
  "base_url": "http://localhost:3000",
  "startup": {
    "cmd": "npm run dev",
    "cwd": "Projects/ClaudeTrader",
    "ready_timeout": 30
  },
  "health": [
    { "cmd": "npm run build", "cwd": "Projects/ClaudeTrader" }
  ],
  "checks": [
    { "path": "/",          "expect_status": 200, "expect_text": "ClaudeTrader", "screenshot": "home" },
    { "path": "/dashboard", "expect_status": 200, "screenshot": "dashboard" }
  ]
}
```

Remote/deployed project (no local server):

```json
{
  "enabled": true,
  "stack": "php",
  "base_url": "https://websmami.example.com",
  "startup": null,
  "health": [],
  "checks": [
    { "path": "/",     "expect_status": 200, "expect_text": "Kokett", "screenshot": "home" },
    { "path": "/shop", "expect_status": 200, "screenshot": "shop" }
  ]
}
```

### Field Reference

| Field | Type | Required | Description |
|---|---|---|---|
| `enabled` | bool | yes | `false` = skip this project entirely |
| `stack` | string\|null | no | Stack hint: `"nextjs"`, `"php"`, `"python-django"`, `"static"`, `"custom"`, `null`. Used to apply stack-specific startup defaults. |
| `base_url` | string | yes | Base URL for all checks (local or remote) |
| `startup` | object\|null | yes | `null` for remote targets. Object for local servers. |
| `startup.cmd` | string | yes | Shell command to start the server |
| `startup.cwd` | string | yes | Working directory for the startup command (relative to `CLAUDIO_ROOT`) |
| `startup.ready_timeout` | int | no | Seconds to poll `base_url` before giving up (default: 30) |
| `health[]` | array | no | Commands that must exit 0 before browser tests run |
| `health[].cmd` | string | yes | Shell command |
| `health[].cwd` | string | no | Working directory (relative to `CLAUDIO_ROOT`, default: project root) |
| `checks[]` | array | yes | Browser checks to execute in order |
| `checks[].path` | string | yes | URL path appended to `base_url` |
| `checks[].expect_status` | int | no | Expected HTTP status code (default: 200) |
| `checks[].expect_text` | string | no | String that must appear in `page.content()` (case-sensitive) |
| `checks[].screenshot` | string | yes | Filename stem; saved to `.claudio/screenshots/test-{project}-{stem}.png` |

### Stack hints

| Stack value | Startup behaviour |
|---|---|
| `"nextjs"` | Waits for "Ready" in stdout before URL polling begins |
| `"php"` | Generic URL poll only |
| `"python-django"` | Generic URL poll only |
| `"static"` | Generic URL poll only |
| `"custom"` / `null` | Generic URL poll only |

Adding new stack types only requires adding a branch in `run-tests.py`'s `_wait_for_ready()` helper.

---

## `scripts/run-tests.py`

Single file, six logical sections:

### 1. Configuration and setup

```python
CLAUDIO_ROOT    = Path(__file__).parent.parent
SCREENSHOTS_DIR = CLAUDIO_ROOT / '.claudio' / 'screenshots'
RESULTS_DIR     = CLAUDIO_ROOT / '.claudio' / 'results'
TASKS_DIR       = CLAUDIO_ROOT / '.claudio' / 'tasks'

load_dotenv(CLAUDIO_ROOT / '.env')
TELEGRAM_TOKEN  = os.environ['TELEGRAM_BOT_TOKEN']
TELEGRAM_CHAT   = os.environ['TELEGRAM_CHAT_ID']
```

CLI: `python run-tests.py --project ClaudeTrader` or `--all`

### 2. Config loader

```python
def load_config(project: str) -> dict | None:
    path = CLAUDIO_ROOT / '.claudio' / 'agents' / project / 'tests.json'
    if not path.exists():
        return None
    with open(path) as f:
        return json.load(f)
```

Returns `None` if no config. Returns config dict. Caller checks `enabled`.

### 3. Health checks

```python
def run_health_checks(health: list, claudio_root: Path) -> list[dict]:
    results = []
    for h in health:
        cwd = claudio_root / h.get('cwd', '.')
        proc = subprocess.run(h['cmd'], shell=True, cwd=cwd, capture_output=True, text=True)
        results.append({
            'cmd': h['cmd'],
            'passed': proc.returncode == 0,
            'output': proc.stdout + proc.stderr
        })
    return results
```

### 4. Server startup / teardown

```python
def start_server(startup: dict, claudio_root: Path, stack: str | None, base_url: str) -> subprocess.Popen | None:
    cwd = claudio_root / startup['cwd']
    proc = subprocess.Popen(startup['cmd'], shell=True, cwd=cwd,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    timeout = startup.get('ready_timeout', 30)
    _wait_for_ready(base_url, proc, timeout, stack)
    return proc

def _wait_for_ready(url: str, proc: subprocess.Popen, timeout: int, stack: str | None):
    # For nextjs: scan stdout for "Ready" line (up to timeout seconds)
    # For all stacks: poll url every 2s until HTTP 200 or timeout
    ...

def stop_server(proc: subprocess.Popen | None):
    if proc:
        proc.terminate()
        try: proc.wait(timeout=5)
        except subprocess.TimeoutExpired: proc.kill()
```

### 5. Browser checks (Playwright)

```python
def run_browser_checks(base_url: str, checks: list, project: str,
                       screenshots_dir: Path) -> list[dict]:
    results = []
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page()
        for check in checks:
            url = base_url.rstrip('/') + check['path']
            stem = check['screenshot']
            screenshot_path = screenshots_dir / f'test-{project}-{stem}.png'
            try:
                response = page.goto(url, wait_until='networkidle', timeout=15000)
                status = response.status if response else 0
                content = page.content()
                page.screenshot(path=str(screenshot_path), full_page=True)

                status_ok = (status == check.get('expect_status', 200))
                text_ok   = (check['expect_text'] in content) if 'expect_text' in check else True
                passed    = status_ok and text_ok

                reason = None
                if not status_ok: reason = f'HTTP {status} (expected {check.get("expect_status", 200)})'
                if not text_ok:   reason = f'expect_text "{check["expect_text"]}" not found'

                results.append({'path': check['path'], 'passed': passed,
                                 'reason': reason, 'screenshot': str(screenshot_path)})
            except Exception as e:
                page.screenshot(path=str(screenshot_path), full_page=True)
                results.append({'path': check['path'], 'passed': False,
                                 'reason': str(e), 'screenshot': str(screenshot_path)})
        browser.close()
    return results
```

### 6. Reporting + task creation

**Telegram report:**

On success:
```
✅ ClaudeTrader — 5/5 checks passed (14s)
```
+ all screenshots sent as photos

On failure:
```
🔴 ClaudeTrader — 3/5 checks FAILED

✗ /dashboard — expected text "Portfolio" not found
✗ /api/health — HTTP 500 (expected 200)
✓ / — OK
✓ /login — OK
✓ /settings — OK

Fix task queued: test-fix-20260326-143022
```
+ all screenshots sent as photos

Screenshots sent via `bot.send_photo()` using the same Telegram credentials as the bot.

**Auto-queued fix task** (written to `.claudio/tasks/{project}/pending/`):

```json
{
  "id": "test-fix-20260326-143022",
  "type": "test_fix",
  "priority": "high",
  "description": "Fix 2 failing checks: /dashboard (text 'Portfolio' not found), /api/health (HTTP 500)",
  "created_at": "2026-03-26T14:30:22Z",
  "context": {
    "failed_checks": [
      { "path": "/dashboard", "reason": "expect_text 'Portfolio' not found" },
      { "path": "/api/health", "reason": "HTTP 500" }
    ],
    "screenshot_paths": [
      ".claudio/screenshots/test-ClaudeTrader-dashboard.png",
      ".claudio/screenshots/test-ClaudeTrader-api-health.png"
    ]
  }
}
```

---

## `scripts/agent-checkin.ps1` — Addition

Add `-RunTests` switch to the param block:

```powershell
param(
  ...existing params...
  [switch]$RunTests   # when set, run tests after task completion
)
```

Append after the tasks-summary.json block (end of file):

```powershell
# Run tests if requested (agent passes this flag when code changes warrant verification)
if ($RunTests) {
  Write-Host "[$Project] running tests..."
  $testScript = Join-Path $claudioRoot "scripts\run-tests.py"
  python $testScript --project $Project
}
```

Usage by an agent:
```powershell
pwsh scripts/agent-checkin.ps1 -Project ClaudeTrader -Status idle -CompleteTask task-001 -RunTests
```

---

## `scripts/start-tests.ps1`

Manual launcher for interactive test runs:

```powershell
# start-tests.ps1 — Run Claudio tests in a new Windows Terminal tab
# Usage: pwsh scripts/start-tests.ps1 [-Project ClaudeTrader]
param([string]$Project = '')

$claudioRoot = Split-Path $PSScriptRoot -Parent
$args        = if ($Project) { "--project $Project" } else { "--all" }
$startCmd    = "Set-Location '$claudioRoot'; python scripts/run-tests.py $args"
$encodedCmd  = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($startCmd))
wt.exe new-tab --title "Claudio: Tests" -- pwsh -NoExit -EncodedCommand $encodedCmd
```

---

## Initial `tests.json` Files

### ClaudeTrader (local Next.js, stack: nextjs)

```json
{
  "enabled": true,
  "stack": "nextjs",
  "base_url": "http://localhost:3000",
  "startup": {
    "cmd": "npm run dev",
    "cwd": "Projects/ClaudeTrader",
    "ready_timeout": 60
  },
  "health": [
    { "cmd": "npm run build", "cwd": "Projects/ClaudeTrader" }
  ],
  "checks": [
    { "path": "/",      "expect_status": 200, "expect_text": "ClaudeTrader", "screenshot": "home" },
    { "path": "/login", "expect_status": 200, "screenshot": "login" }
  ]
}
```

### WebsMami (remote, stack: php)

```json
{
  "enabled": true,
  "stack": "php",
  "base_url": "REPLACE_WITH_WEBSMAMI_URL",
  "startup": null,
  "health": [],
  "checks": [
    { "path": "/", "expect_status": 200, "screenshot": "home" }
  ]
}
```

### ClaudeSEO (remote, stack: php)

```json
{
  "enabled": true,
  "stack": "php",
  "base_url": "REPLACE_WITH_CLAUDESEO_URL",
  "startup": null,
  "health": [],
  "checks": [
    { "path": "/", "expect_status": 200, "screenshot": "home" }
  ]
}
```

Remote configs use `REPLACE_WITH_*` placeholders so the agent fills in real URLs during first use without breaking any existing code.

---

## `CLAUDE.md` — Project Import Procedure Update

Add step 9 after step 8:

```markdown
9. Scaffold `.claudio/agents/{project}/tests.json` — detect stack and `base_url`,
   create starter config with `"enabled": true`, one check for `/`, and
   `startup` set if a local dev server command was detected. Fill `REPLACE_WITH_URL`
   if the live URL is unknown. Commit with the onboarding commit.
```

---

## Error Handling

| Situation | Behaviour |
|---|---|
| `tests.json` missing for a project | Skip silently — no test run, no error |
| `enabled: false` | Skip silently |
| Health command fails | Mark health check failed, skip browser checks, still report + queue task |
| Local server fails to start within `ready_timeout` | Mark all checks failed with "server did not start", still report + queue task |
| Playwright `page.goto` timeout (15s) | Mark that check failed, screenshot blank/partial, continue remaining checks |
| Screenshot directory missing | Create it before first screenshot |
| Telegram send fails | Log warning, continue — result JSON is written regardless |
| `TELEGRAM_BOT_TOKEN` missing from `.env` | Log warning, skip Telegram, still write result JSON and queue task |
| `REPLACE_WITH_URL` in `base_url` | Log warning, skip that project |

---

## Security Model

| Concern | Mitigation |
|---|---|
| Credentials | `TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID` from `.env` (gitignored) |
| Screenshot contents | Written to `.claudio/screenshots/` (gitignored) |
| Health command injection | `health[].cmd` values come from committed JSON in the repo — treat as trusted |
| Remote URL access | Tests only visit declared URLs; no crawling |

---

## Installation

1. `pip install -r scripts/requirements-tests.txt`
2. `playwright install chromium`
3. Fill in `base_url` in WebsMami and ClaudeSEO `tests.json`
4. Run manual test: `pwsh scripts/start-tests.ps1 -Project ClaudeTrader`

---

## Extensibility

Adding a new project to Claudio automatically scaffolds a `tests.json` (Project Import Procedure step 9). The agent fills in real URLs and adds project-specific checks. No changes to `run-tests.py` are needed unless a new stack type requires custom startup detection — add one branch to `_wait_for_ready()`.

New check types (e.g., form submission, authenticated pages) can be added to `checks[]` by extending the schema with optional fields (`click`, `fill`, `auth_header`) and handling them in `run_browser_checks()`.
