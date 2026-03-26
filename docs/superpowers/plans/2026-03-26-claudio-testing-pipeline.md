# Subsystem 5 — Automated Testing Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a config-driven, project-agnostic test runner that fires on agent task completion, executes health checks and Playwright browser tests against local or remote targets, sends screenshots to Telegram on every run (pass and fail), and auto-queues a fix task when checks fail.

**Architecture:** `scripts/run-tests.py` reads per-project `.claudio/agents/{name}/tests.json`, runs health commands then Playwright browser checks, writes result JSON to `.claudio/results/`, queues a fix task to `.claudio/tasks/{project}/pending/` on failure, and sends a Telegram report with all screenshots. `agent-checkin.ps1` gains a `-RunTests` switch. Unit + integration tests live in `scripts/tests/test_run_tests.py` using pytest; a `conftest.py` loads the hyphen-named module.

**Tech Stack:** Python 3.11+, Playwright (sync API, headless Chromium), python-telegram-bot 20+, python-dotenv, pytest 8+. PowerShell for launchers.

---

## File Map

| Action | Path | Responsibility |
|---|---|---|
| Create | `scripts/requirements-tests.txt` | Python deps: playwright, python-dotenv, python-telegram-bot, pytest |
| Create | `scripts/run-tests.py` | Main runner: load_config, health checks, server start/stop, browser checks, result writing, fix task, Telegram, orchestration |
| Create | `scripts/start-tests.ps1` | Manual wt.exe launcher |
| Create | `scripts/tests/__init__.py` | Empty — makes tests/ a Python package |
| Create | `scripts/tests/conftest.py` | Loads `run-tests.py` as `run_tests` module (hyphenated filename workaround) |
| Create | `scripts/tests/test_run_tests.py` | All tests (added task by task) |
| Create | `.claudio/agents/ClaudeTrader/tests.json` | ClaudeTrader config (local Next.js) |
| Create | `.claudio/agents/WebsMami/tests.json` | WebsMami config (remote PHP, placeholder URL) |
| Create | `.claudio/agents/ClaudeSEO/tests.json` | ClaudeSEO config (remote PHP, placeholder URL) |
| Modify | `scripts/agent-checkin.ps1` | Add `[switch]$RunTests` to param block + if block at end of file |
| Modify | `CLAUDE.md` | Add step 9 to Project Import Procedure |

---

### Task 1: Requirements, Test Infrastructure, and `load_config()`

**Files:**
- Create: `scripts/requirements-tests.txt`
- Create: `scripts/tests/__init__.py`
- Create: `scripts/tests/conftest.py`
- Create: `scripts/tests/test_run_tests.py`
- Create: `scripts/run-tests.py`

- [ ] **Step 1: Create `scripts/requirements-tests.txt`**

```
playwright>=1.40.0
python-dotenv>=1.0.0
python-telegram-bot>=20.0
pytest>=8.0.0
```

- [ ] **Step 2: Install dependencies**

Run:
```
pip install -r scripts/requirements-tests.txt && playwright install chromium
```
Expected: All packages installed, Chromium browser downloaded (printed to stdout).

- [ ] **Step 3: Create `scripts/tests/__init__.py`**

Empty file. Creates `scripts/tests/` as a Python package.

- [ ] **Step 4: Create `scripts/tests/conftest.py`**

`run-tests.py` cannot be imported with `import` directly because of the hyphen. This file loads it via `importlib` and registers it under the name `run_tests` in `sys.modules` so test files can do `import run_tests`.

```python
# scripts/tests/conftest.py
import importlib.util
import sys
from pathlib import Path


def _load_run_tests():
    scripts_dir = Path(__file__).parent.parent  # D:\CLAUDIO\scripts
    spec = importlib.util.spec_from_file_location(
        'run_tests', scripts_dir / 'run-tests.py'
    )
    mod = importlib.util.module_from_spec(spec)
    sys.modules['run_tests'] = mod
    spec.loader.exec_module(mod)
    return mod


_load_run_tests()
```

- [ ] **Step 5: Write failing tests for `load_config()`**

Create `scripts/tests/test_run_tests.py` with ALL imports for the entire test file (added here once so later tasks only append test functions):

```python
# scripts/tests/test_run_tests.py
import http.server
import json
import subprocess
import threading
from pathlib import Path
from unittest.mock import MagicMock

import pytest
import run_tests  # loaded by conftest.py


# ──────────────────────────────────────────────
# Fixtures
# ──────────────────────────────────────────────

@pytest.fixture(scope='module')
def local_server():
    """Serve a minimal HTML page on a random free port (reused across all browser tests)."""
    html = b'<html><body><h1>TestApp Hello</h1></body></html>'

    class Handler(http.server.BaseHTTPRequestHandler):
        def do_GET(self):
            self.send_response(200)
            self.send_header('Content-Type', 'text/html')
            self.end_headers()
            self.wfile.write(html)

        def log_message(self, *args):
            pass

    srv = http.server.HTTPServer(('127.0.0.1', 0), Handler)
    port = srv.server_address[1]
    t = threading.Thread(target=srv.serve_forever)
    t.daemon = True
    t.start()
    yield f'http://127.0.0.1:{port}'
    srv.shutdown()


# ──────────────────────────────────────────────
# load_config
# ──────────────────────────────────────────────

def test_load_config_missing(tmp_path):
    assert run_tests.load_config('NoProject', claudio_root=tmp_path) is None


def test_load_config_returns_dict(tmp_path):
    config_dir = tmp_path / '.claudio' / 'agents' / 'TestProject'
    config_dir.mkdir(parents=True)
    config = {
        'enabled': True, 'stack': 'php',
        'base_url': 'http://example.com',
        'startup': None, 'health': [], 'checks': [],
    }
    (config_dir / 'tests.json').write_text(json.dumps(config))

    result = run_tests.load_config('TestProject', claudio_root=tmp_path)
    assert result['enabled'] is True
    assert result['base_url'] == 'http://example.com'
```

- [ ] **Step 6: Run tests — expect failure**

Run:
```
python -m pytest scripts/tests/test_run_tests.py -v
```
Expected: ERROR — `ModuleNotFoundError: No module named 'run_tests'` (run-tests.py doesn't exist yet).

- [ ] **Step 7: Create `scripts/run-tests.py` with imports and `load_config()`**

```python
#!/usr/bin/env python3
"""Claudio test runner — health checks + Playwright browser tests per project."""

import argparse
import datetime
import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

from dotenv import load_dotenv

CLAUDIO_ROOT    = Path(__file__).parent.parent
SCREENSHOTS_DIR = CLAUDIO_ROOT / '.claudio' / 'screenshots'
RESULTS_DIR     = CLAUDIO_ROOT / '.claudio' / 'results'
TASKS_DIR       = CLAUDIO_ROOT / '.claudio' / 'tasks'

load_dotenv(CLAUDIO_ROOT / '.env')
TELEGRAM_TOKEN = os.environ.get('TELEGRAM_BOT_TOKEN', '')
TELEGRAM_CHAT  = os.environ.get('TELEGRAM_CHAT_ID', '')


def load_config(project: str, claudio_root: Path | None = None) -> dict | None:
    """Load .claudio/agents/{project}/tests.json. Returns None if missing."""
    root = claudio_root if claudio_root is not None else CLAUDIO_ROOT
    path = root / '.claudio' / 'agents' / project / 'tests.json'
    if not path.exists():
        return None
    with open(path) as f:
        return json.load(f)
```

Note: `claudio_root=None` with lazy resolution at call time lets tests monkeypatch `run_tests.CLAUDIO_ROOT` without fighting Python's default-parameter capture semantics.

- [ ] **Step 8: Run tests — expect pass**

Run:
```
python -m pytest scripts/tests/test_run_tests.py -v
```
Expected:
```
PASSED test_load_config_missing
PASSED test_load_config_returns_dict
2 passed
```

- [ ] **Step 9: Commit**

```bash
git add scripts/requirements-tests.txt scripts/run-tests.py scripts/tests/
git commit -m "feat(tests): add test infrastructure and load_config"
```

---

### Task 2: Health Checks

**Files:**
- Modify: `scripts/tests/test_run_tests.py` (append tests)
- Modify: `scripts/run-tests.py` (append function)

- [ ] **Step 1: Append health check tests to `scripts/tests/test_run_tests.py`**

```python
# ──────────────────────────────────────────────
# run_health_checks
# ──────────────────────────────────────────────

def test_run_health_checks_empty(tmp_path):
    assert run_tests.run_health_checks([], tmp_path) == []


def test_run_health_checks_pass(tmp_path):
    health = [{'cmd': 'python -c "import sys; sys.exit(0)"', 'cwd': '.'}]
    results = run_tests.run_health_checks(health, tmp_path)
    assert len(results) == 1
    assert results[0]['passed'] is True
    assert results[0]['cmd'] == health[0]['cmd']


def test_run_health_checks_fail(tmp_path):
    health = [{'cmd': 'python -c "import sys; sys.exit(1)"', 'cwd': '.'}]
    results = run_tests.run_health_checks(health, tmp_path)
    assert results[0]['passed'] is False


def test_run_health_checks_captures_output(tmp_path):
    health = [{'cmd': 'python -c "print(\'hello health\')"', 'cwd': '.'}]
    results = run_tests.run_health_checks(health, tmp_path)
    assert 'hello health' in results[0]['output']
```

- [ ] **Step 2: Run new tests — expect failure**

Run:
```
python -m pytest scripts/tests/test_run_tests.py::test_run_health_checks_empty -v
```
Expected: `AttributeError: module 'run_tests' has no attribute 'run_health_checks'`

- [ ] **Step 3: Append `run_health_checks` to `scripts/run-tests.py`**

```python
def run_health_checks(health: list, claudio_root: Path) -> list[dict]:
    """Run shell commands that must exit 0. Returns list of {cmd, passed, output}."""
    results = []
    for h in health:
        cwd = claudio_root / h.get('cwd', '.')
        proc = subprocess.run(
            h['cmd'], shell=True, cwd=cwd, capture_output=True, text=True
        )
        results.append({
            'cmd':    h['cmd'],
            'passed': proc.returncode == 0,
            'output': proc.stdout + proc.stderr,
        })
    return results
```

- [ ] **Step 4: Run all tests — expect pass**

Run:
```
python -m pytest scripts/tests/test_run_tests.py -v
```
Expected: All 6 tests pass.

- [ ] **Step 5: Commit**

```bash
git add scripts/tests/test_run_tests.py scripts/run-tests.py
git commit -m "feat(tests): add run_health_checks"
```

---

### Task 3: Server Startup, Teardown, and Ready Detection

**Files:**
- Modify: `scripts/tests/test_run_tests.py`
- Modify: `scripts/run-tests.py`

- [ ] **Step 1: Append server tests to `scripts/tests/test_run_tests.py`**

```python
# ──────────────────────────────────────────────
# _wait_for_ready / start_server / stop_server
# ──────────────────────────────────────────────

def test_wait_for_ready_nextjs_reads_ready():
    """Real subprocess that echoes 'Ready' — should return without raising."""
    proc = subprocess.Popen(
        'echo Ready',
        shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
    )
    run_tests._wait_for_ready('http://localhost:9999', proc, timeout=10, stack='nextjs')
    proc.wait()


def test_wait_for_ready_nextjs_process_exits_early():
    """Process exits without printing 'Ready' — should raise RuntimeError."""
    proc = subprocess.Popen(
        'python -c "import sys; sys.exit(0)"',
        shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
    )
    with pytest.raises(RuntimeError, match='exited unexpectedly'):
        run_tests._wait_for_ready('http://localhost:9999', proc, timeout=5, stack='nextjs')
    proc.wait()


def test_wait_for_ready_url_polling_succeeds(monkeypatch):
    """URL polling returns after one successful urlopen call."""
    call_count = [0]

    def mock_urlopen(url, timeout=None):
        call_count[0] += 1
        return MagicMock()

    monkeypatch.setattr(run_tests.urllib.request, 'urlopen', mock_urlopen)

    proc = MagicMock()
    proc.poll.return_value = None

    run_tests._wait_for_ready('http://localhost:9999', proc, timeout=5, stack='php')
    assert call_count[0] == 1


def test_wait_for_ready_http_error_means_up(monkeypatch):
    """An HTTPError (e.g. 404) still means the server is responding — should return."""
    def mock_urlopen(url, timeout=None):
        raise run_tests.urllib.error.HTTPError(url, 404, 'Not Found', {}, None)

    monkeypatch.setattr(run_tests.urllib.request, 'urlopen', mock_urlopen)

    proc = MagicMock()
    run_tests._wait_for_ready('http://localhost:9999', proc, timeout=5, stack='php')


def test_stop_server_terminates():
    proc = subprocess.Popen(
        'python -c "import time; time.sleep(30)"',
        shell=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )
    assert proc.poll() is None
    run_tests.stop_server(proc)
    assert proc.poll() is not None


def test_stop_server_noop_on_none():
    run_tests.stop_server(None)  # must not raise
```

- [ ] **Step 2: Run new tests — expect failure**

Run:
```
python -m pytest scripts/tests/test_run_tests.py::test_wait_for_ready_nextjs_reads_ready -v
```
Expected: `AttributeError: module 'run_tests' has no attribute '_wait_for_ready'`

- [ ] **Step 3: Append server functions to `scripts/run-tests.py`**

```python
def _wait_for_ready(base_url: str, proc: subprocess.Popen, timeout: int, stack: str | None):
    """Block until server is ready. Raises RuntimeError on timeout or unexpected exit."""
    deadline = time.time() + timeout

    if stack == 'nextjs':
        # Scan stdout for Next.js "✓ Ready" signal
        while time.time() < deadline:
            line = proc.stdout.readline()
            if not line:
                if proc.poll() is not None:
                    raise RuntimeError('Server process exited unexpectedly')
                time.sleep(0.1)
                continue
            if 'Ready' in line or 'ready' in line or '\u2713' in line:
                return
        raise RuntimeError(f'Server did not signal Ready within {timeout}s')

    # Generic: poll base_url until any HTTP response (even 4xx means server is up)
    while time.time() < deadline:
        try:
            urllib.request.urlopen(base_url, timeout=2)
            return
        except urllib.error.HTTPError:
            return
        except Exception:
            time.sleep(2)
    raise RuntimeError(f'Server did not become ready within {timeout}s')


def start_server(
    startup: dict, claudio_root: Path, stack: str | None, base_url: str
) -> subprocess.Popen:
    """Start local dev server; block until ready. Returns Popen handle."""
    cwd  = claudio_root / startup['cwd']
    proc = subprocess.Popen(
        startup['cmd'], shell=True, cwd=cwd,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
    )
    _wait_for_ready(base_url, proc, startup.get('ready_timeout', 30), stack)
    return proc


def stop_server(proc: subprocess.Popen | None):
    """Terminate server process. No-op if proc is None."""
    if proc:
        proc.terminate()
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            proc.kill()
```

- [ ] **Step 4: Run all tests — expect pass**

Run:
```
python -m pytest scripts/tests/test_run_tests.py -v
```
Expected: All 12 tests pass.

- [ ] **Step 5: Commit**

```bash
git add scripts/tests/test_run_tests.py scripts/run-tests.py
git commit -m "feat(tests): add server startup, teardown, and ready detection"
```

---

### Task 4: Browser Checks (Playwright)

**Files:**
- Modify: `scripts/tests/test_run_tests.py`
- Modify: `scripts/run-tests.py`

- [ ] **Step 1: Append browser check tests to `scripts/tests/test_run_tests.py`**

The `local_server` fixture is already defined at the top of the test file (Task 1). These tests use it.

```python
# ──────────────────────────────────────────────
# run_browser_checks
# ──────────────────────────────────────────────

def test_browser_check_pass(local_server, tmp_path):
    checks = [
        {'path': '/', 'expect_status': 200, 'expect_text': 'TestApp Hello', 'screenshot': 'home'}
    ]
    results = run_tests.run_browser_checks(local_server, checks, 'TestProject', tmp_path)

    assert len(results) == 1
    assert results[0]['passed'] is True
    assert results[0]['reason'] is None
    assert (tmp_path / 'test-TestProject-home.png').exists()


def test_browser_check_wrong_status(local_server, tmp_path):
    checks = [{'path': '/', 'expect_status': 404, 'screenshot': 'home'}]
    results = run_tests.run_browser_checks(local_server, checks, 'TestProject', tmp_path)

    assert results[0]['passed'] is False
    assert 'HTTP 200' in results[0]['reason']
    assert 'expected 404' in results[0]['reason']


def test_browser_check_missing_text(local_server, tmp_path):
    checks = [{'path': '/', 'expect_text': 'NotPresent', 'screenshot': 'home'}]
    results = run_tests.run_browser_checks(local_server, checks, 'TestProject', tmp_path)

    assert results[0]['passed'] is False
    assert 'NotPresent' in results[0]['reason']


def test_browser_check_creates_screenshot_dir(local_server, tmp_path):
    """screenshots_dir is created automatically when it doesn't exist."""
    screenshots_dir = tmp_path / 'nested' / 'screenshots'
    assert not screenshots_dir.exists()

    checks = [{'path': '/', 'screenshot': 'home'}]
    run_tests.run_browser_checks(local_server, checks, 'T', screenshots_dir)

    assert screenshots_dir.exists()
    assert (screenshots_dir / 'test-T-home.png').exists()
```

- [ ] **Step 2: Run new tests — expect failure**

Run:
```
python -m pytest scripts/tests/test_run_tests.py::test_browser_check_pass -v
```
Expected: `AttributeError: module 'run_tests' has no attribute 'run_browser_checks'`

- [ ] **Step 3: Append `run_browser_checks` to `scripts/run-tests.py`**

```python
def run_browser_checks(
    base_url: str, checks: list, project: str, screenshots_dir: Path
) -> list[dict]:
    """Navigate to each URL, assert status + text, take screenshot. Returns result list."""
    from playwright.sync_api import sync_playwright

    screenshots_dir.mkdir(parents=True, exist_ok=True)
    results = []

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page()

        for check in checks:
            url             = base_url.rstrip('/') + check['path']
            stem            = check['screenshot']
            screenshot_path = screenshots_dir / f'test-{project}-{stem}.png'

            try:
                response = page.goto(url, wait_until='networkidle', timeout=15000)
                status   = response.status if response else 0
                content  = page.content()
                page.screenshot(path=str(screenshot_path), full_page=True)

                status_ok = (status == check.get('expect_status', 200))
                text_ok   = (check['expect_text'] in content) if 'expect_text' in check else True
                passed    = status_ok and text_ok

                reason = None
                if not status_ok:
                    reason = f'HTTP {status} (expected {check.get("expect_status", 200)})'
                if not text_ok:
                    reason = f'expect_text "{check["expect_text"]}" not found'

                results.append({
                    'path':       check['path'],
                    'passed':     passed,
                    'reason':     reason,
                    'screenshot': str(screenshot_path),
                })

            except Exception as e:
                try:
                    page.screenshot(path=str(screenshot_path), full_page=True)
                except Exception:
                    pass
                results.append({
                    'path':       check['path'],
                    'passed':     False,
                    'reason':     str(e),
                    'screenshot': str(screenshot_path),
                })

        browser.close()

    return results
```

- [ ] **Step 4: Run all tests — expect pass**

Run:
```
python -m pytest scripts/tests/test_run_tests.py -v
```
Expected: All 16 tests pass. Playwright tests spin up headless Chromium; the `local_server` fixture serves the test page.

- [ ] **Step 5: Commit**

```bash
git add scripts/tests/test_run_tests.py scripts/run-tests.py
git commit -m "feat(tests): add Playwright browser checks"
```

---

### Task 5: Result Writing and Fix Task Creation

**Files:**
- Modify: `scripts/tests/test_run_tests.py`
- Modify: `scripts/run-tests.py`

- [ ] **Step 1: Append result and fix task tests**

```python
# ──────────────────────────────────────────────
# write_result
# ──────────────────────────────────────────────

def test_write_result_creates_json(tmp_path):
    health = [{'cmd': 'test', 'passed': True, 'output': ''}]
    checks = [{'path': '/', 'passed': True, 'reason': None, 'screenshot': 'home.png'}]

    path = run_tests.write_result('TestProject', health, checks, 14.3, tmp_path / 'results')

    assert path.exists()
    assert path.suffix == '.json'
    assert 'TestProject' in path.name

    data = json.loads(path.read_text())
    assert data['project'] == 'TestProject'
    assert data['passed'] is True
    assert data['elapsed_seconds'] == 14.3
    assert len(data['health']) == 1
    assert len(data['checks']) == 1


def test_write_result_passed_false_when_any_fail(tmp_path):
    checks = [
        {'path': '/',    'passed': True,  'reason': None,       'screenshot': ''},
        {'path': '/api', 'passed': False, 'reason': 'HTTP 500', 'screenshot': ''},
    ]
    path = run_tests.write_result('TestProject', [], checks, 2.0, tmp_path / 'results')
    assert json.loads(path.read_text())['passed'] is False


# ──────────────────────────────────────────────
# create_fix_task
# ──────────────────────────────────────────────

def test_create_fix_task_writes_json(tmp_path):
    failed = [
        {'path': '/dashboard', 'reason': "text 'Portfolio' not found", 'screenshot': 'dash.png'},
        {'path': '/api',       'reason': 'HTTP 500',                   'screenshot': 'api.png'},
    ]
    pending_dir = tmp_path / 'pending'
    task_id = run_tests.create_fix_task('TestProject', failed, pending_dir)

    task_files = list(pending_dir.glob('*.json'))
    assert len(task_files) == 1

    task = json.loads(task_files[0].read_text())
    assert task['id'] == task_id
    assert task['id'].startswith('test-fix-')
    assert task['type'] == 'test_fix'
    assert task['priority'] == 'high'
    assert '2' in task['description']
    assert len(task['context']['failed_checks']) == 2
    assert task['context']['failed_checks'][0]['path'] == '/dashboard'
    assert 'dash.png' in task['context']['screenshot_paths']
```

- [ ] **Step 2: Run new tests — expect failure**

Run:
```
python -m pytest scripts/tests/test_run_tests.py::test_write_result_creates_json -v
```
Expected: `AttributeError: module 'run_tests' has no attribute 'write_result'`

- [ ] **Step 3: Append `write_result` and `create_fix_task` to `scripts/run-tests.py`**

```python
def write_result(
    project: str,
    health_results: list,
    check_results: list,
    elapsed: float,
    results_dir: Path,
) -> Path:
    """Write test result JSON. Creates results_dir if missing. Returns path to file."""
    results_dir.mkdir(parents=True, exist_ok=True)
    ts = datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S')
    result = {
        'project':         project,
        'timestamp':       datetime.datetime.utcnow().isoformat() + 'Z',
        'elapsed_seconds': round(elapsed, 1),
        'health':          health_results,
        'checks':          check_results,
        'passed':          all(r['passed'] for r in health_results + check_results),
    }
    path = results_dir / f'test-{project}-{ts}.json'
    path.write_text(json.dumps(result, indent=2))
    return path


def create_fix_task(project: str, failed_checks: list, tasks_dir: Path) -> str:
    """Write a test_fix task JSON to tasks_dir. Returns the task ID string."""
    tasks_dir.mkdir(parents=True, exist_ok=True)
    ts      = datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S')
    task_id = f'test-fix-{ts}'
    desc_parts = [f'{c["path"]} ({c["reason"]})' for c in failed_checks]
    task = {
        'id':          task_id,
        'type':        'test_fix',
        'priority':    'high',
        'description': f'Fix {len(failed_checks)} failing check(s): {", ".join(desc_parts)}',
        'created_at':  datetime.datetime.utcnow().isoformat() + 'Z',
        'context': {
            'failed_checks':    [{'path': c['path'], 'reason': c['reason']} for c in failed_checks],
            'screenshot_paths': [c['screenshot'] for c in failed_checks if c.get('screenshot')],
        },
    }
    (tasks_dir / f'{task_id}.json').write_text(json.dumps(task, indent=2))
    return task_id
```

- [ ] **Step 4: Run all tests — expect pass**

Run:
```
python -m pytest scripts/tests/test_run_tests.py -v
```
Expected: All 20 tests pass.

- [ ] **Step 5: Commit**

```bash
git add scripts/tests/test_run_tests.py scripts/run-tests.py
git commit -m "feat(tests): add result writing and fix task creation"
```

---

### Task 6: Telegram Message Builder and Sender

**Files:**
- Modify: `scripts/tests/test_run_tests.py`
- Modify: `scripts/run-tests.py`

- [ ] **Step 1: Append Telegram tests**

```python
# ──────────────────────────────────────────────
# build_telegram_message
# ──────────────────────────────────────────────

def test_telegram_message_all_pass():
    checks = [
        {'path': '/',          'passed': True, 'reason': None},
        {'path': '/dashboard', 'passed': True, 'reason': None},
    ]
    msg = run_tests.build_telegram_message('TestProject', checks, [], 14.0, None)
    assert msg.startswith('✅')
    assert 'TestProject' in msg
    assert '2/2' in msg
    assert '14s' in msg


def test_telegram_message_with_failures():
    checks = [
        {'path': '/dashboard', 'passed': False, 'reason': "text 'Portfolio' not found"},
        {'path': '/',          'passed': True,  'reason': None},
    ]
    msg = run_tests.build_telegram_message('TestProject', checks, [], 5.0, 'test-fix-123')
    assert msg.startswith('🔴')
    assert '1/2' in msg
    assert '/dashboard' in msg
    assert "text 'Portfolio' not found" in msg
    assert 'test-fix-123' in msg


def test_telegram_message_health_failure():
    health = [{'cmd': 'npm run build', 'passed': False, 'reason': 'exit 1'}]
    msg = run_tests.build_telegram_message('TestProject', [], health, 3.0, 'test-fix-456')
    assert '🔴' in msg
    assert 'test-fix-456' in msg


# ──────────────────────────────────────────────
# send_telegram
# ──────────────────────────────────────────────

def test_send_telegram_skips_when_no_credentials(monkeypatch, capsys):
    monkeypatch.setattr(run_tests, 'TELEGRAM_TOKEN', '')
    monkeypatch.setattr(run_tests, 'TELEGRAM_CHAT', '')
    run_tests.send_telegram('hello', [])
    out = capsys.readouterr().out.lower()
    assert 'missing' in out or 'skip' in out
```

- [ ] **Step 2: Run new tests — expect failure**

Run:
```
python -m pytest scripts/tests/test_run_tests.py::test_telegram_message_all_pass -v
```
Expected: `AttributeError: module 'run_tests' has no attribute 'build_telegram_message'`

- [ ] **Step 3: Append `build_telegram_message` and `send_telegram` to `scripts/run-tests.py`**

```python
def build_telegram_message(
    project: str,
    check_results: list,
    health_results: list,
    elapsed: float,
    task_id: str | None,
) -> str:
    """Return a human-readable Telegram message for pass or fail outcome."""
    all_results = health_results + check_results
    total    = len(all_results)
    failed   = [r for r in all_results if not r['passed']]
    n_failed = len(failed)

    if n_failed == 0:
        return f'✅ {project} — {total}/{total} checks passed ({round(elapsed)}s)'

    lines = [f'🔴 {project} — {n_failed}/{total} checks FAILED', '']
    for r in all_results:
        mark   = '✗' if not r['passed'] else '✓'
        path   = r.get('path') or r.get('cmd', '?')
        reason = f' — {r["reason"]}' if r.get('reason') else ''
        lines.append(f'{mark} {path}{reason}')

    if task_id:
        lines.extend(['', f'Fix task queued: {task_id}'])

    return '\n'.join(lines)


def send_telegram(message: str, screenshot_paths: list):
    """Send Telegram message + photo attachments. Skips silently if credentials missing."""
    if not TELEGRAM_TOKEN or not TELEGRAM_CHAT:
        print('[telegram] TELEGRAM_BOT_TOKEN or TELEGRAM_CHAT_ID missing — skipping')
        return

    import asyncio
    import telegram

    async def _send():
        bot = telegram.Bot(token=TELEGRAM_TOKEN)
        await bot.send_message(chat_id=TELEGRAM_CHAT, text=message)
        for path in screenshot_paths:
            p = Path(path)
            if p.exists():
                with open(p, 'rb') as f:
                    await bot.send_photo(chat_id=TELEGRAM_CHAT, photo=f)

    try:
        asyncio.run(_send())
    except Exception as e:
        print(f'[telegram] send failed: {e}')
```

- [ ] **Step 4: Run all tests — expect pass**

Run:
```
python -m pytest scripts/tests/test_run_tests.py -v
```
Expected: All 24 tests pass.

- [ ] **Step 5: Commit**

```bash
git add scripts/tests/test_run_tests.py scripts/run-tests.py
git commit -m "feat(tests): add Telegram message builder and sender"
```

---

### Task 7: `run_project()` Orchestration and `main()` CLI

**Files:**
- Modify: `scripts/tests/test_run_tests.py`
- Modify: `scripts/run-tests.py`

- [ ] **Step 1: Append orchestration tests**

```python
# ──────────────────────────────────────────────
# run_project (orchestration)
# ──────────────────────────────────────────────

def test_run_project_no_config(tmp_path, monkeypatch, capsys):
    monkeypatch.setattr(run_tests, 'CLAUDIO_ROOT', tmp_path)
    result = run_tests.run_project('MissingProject')
    assert result is None
    assert 'no tests.json' in capsys.readouterr().out


def test_run_project_disabled(tmp_path, monkeypatch):
    monkeypatch.setattr(run_tests, 'CLAUDIO_ROOT', tmp_path)
    config_dir = tmp_path / '.claudio' / 'agents' / 'DisabledProj'
    config_dir.mkdir(parents=True)
    (config_dir / 'tests.json').write_text(json.dumps({
        'enabled': False, 'stack': 'php',
        'base_url': 'http://example.com',
        'startup': None, 'health': [], 'checks': [],
    }))
    assert run_tests.run_project('DisabledProj') is None


def test_run_project_placeholder_url(tmp_path, monkeypatch, capsys):
    monkeypatch.setattr(run_tests, 'CLAUDIO_ROOT', tmp_path)
    config_dir = tmp_path / '.claudio' / 'agents' / 'PlaceholderProj'
    config_dir.mkdir(parents=True)
    (config_dir / 'tests.json').write_text(json.dumps({
        'enabled': True, 'stack': 'php',
        'base_url': 'REPLACE_WITH_URL',
        'startup': None, 'health': [], 'checks': [],
    }))
    assert run_tests.run_project('PlaceholderProj') is None
    assert 'placeholder' in capsys.readouterr().out.lower()


def test_run_project_all_pass(local_server, tmp_path, monkeypatch):
    monkeypatch.setattr(run_tests, 'CLAUDIO_ROOT',    tmp_path)
    monkeypatch.setattr(run_tests, 'SCREENSHOTS_DIR', tmp_path / 'screenshots')
    monkeypatch.setattr(run_tests, 'RESULTS_DIR',     tmp_path / 'results')
    monkeypatch.setattr(run_tests, 'TASKS_DIR',       tmp_path / 'tasks')
    monkeypatch.setattr(run_tests, 'TELEGRAM_TOKEN',  '')   # disable Telegram

    config_dir = tmp_path / '.claudio' / 'agents' / 'LiveProj'
    config_dir.mkdir(parents=True)
    (config_dir / 'tests.json').write_text(json.dumps({
        'enabled': True, 'stack': 'php',
        'base_url': local_server,
        'startup': None, 'health': [],
        'checks': [
            {'path': '/', 'expect_status': 200,
             'expect_text': 'TestApp Hello', 'screenshot': 'home'}
        ],
    }))

    result = run_tests.run_project('LiveProj')
    assert result is True

    result_files = list((tmp_path / 'results').glob('*.json'))
    assert len(result_files) == 1
    assert json.loads(result_files[0].read_text())['passed'] is True

    # No fix task queued when all pass
    pending_dir = tmp_path / 'tasks' / 'LiveProj' / 'pending'
    assert not pending_dir.exists() or not list(pending_dir.glob('*.json'))
```

- [ ] **Step 2: Run new tests — expect failure**

Run:
```
python -m pytest scripts/tests/test_run_tests.py::test_run_project_no_config -v
```
Expected: `AttributeError: module 'run_tests' has no attribute 'run_project'`

- [ ] **Step 3: Append `run_project()` and `main()` to `scripts/run-tests.py`**

```python
def run_project(project: str) -> bool | None:
    """Orchestrate a full test run for one project. Returns True/False/None (skipped)."""
    config = load_config(project)
    if config is None:
        print(f'[{project}] no tests.json — skipping')
        return None
    if not config.get('enabled', True):
        print(f'[{project}] disabled — skipping')
        return None

    base_url = config['base_url']
    if 'REPLACE_WITH_' in base_url:
        print(f'[{project}] base_url is placeholder — skipping')
        return None

    start_time     = time.time()
    health_results = []
    check_results  = []
    server_proc    = None

    try:
        health_results = run_health_checks(config.get('health', []), CLAUDIO_ROOT)
        health_failed  = [r for r in health_results if not r['passed']]

        if health_failed:
            print(f'[{project}] health checks failed — skipping browser checks')
        else:
            if config.get('startup'):
                server_proc = start_server(
                    config['startup'], CLAUDIO_ROOT, config.get('stack'), base_url
                )
            check_results = run_browser_checks(
                base_url, config.get('checks', []), project, SCREENSHOTS_DIR
            )

    except Exception as e:
        print(f'[{project}] error: {e}')
        for check in config.get('checks', []):
            stem = check.get('screenshot', 'error')
            check_results.append({
                'path':       check['path'],
                'passed':     False,
                'reason':     str(e),
                'screenshot': str(SCREENSHOTS_DIR / f'test-{project}-{stem}.png'),
            })

    finally:
        stop_server(server_proc)

    elapsed     = time.time() - start_time
    result_path = write_result(project, health_results, check_results, elapsed, RESULTS_DIR)
    print(f'[{project}] result written to {result_path}')

    all_failed = [r for r in health_results + check_results if not r['passed']]

    task_id = None
    if all_failed:
        pending_dir = TASKS_DIR / project / 'pending'
        task_id     = create_fix_task(project, all_failed, pending_dir)
        print(f'[{project}] fix task queued: {task_id}')

    message         = build_telegram_message(project, check_results, health_results, elapsed, task_id)
    all_screenshots = [r['screenshot'] for r in health_results + check_results if r.get('screenshot')]
    send_telegram(message, all_screenshots)

    passed = not bool(all_failed)
    print(f'[{project}] {"PASS" if passed else "FAIL"} ({round(elapsed)}s)')
    return passed


def main():
    parser = argparse.ArgumentParser(description='Claudio test runner')
    group  = parser.add_mutually_exclusive_group(required=True)
    group.add_argument('--project', help='Run tests for one project')
    group.add_argument('--all', action='store_true', dest='run_all',
                       help='Run tests for all projects that have a tests.json')
    args = parser.parse_args()

    agents_dir = CLAUDIO_ROOT / '.claudio' / 'agents'
    if args.run_all:
        projects = [
            d.name for d in agents_dir.iterdir()
            if d.is_dir() and (d / 'tests.json').exists()
        ]
    else:
        projects = [args.project]

    results      = {proj: run_project(proj) for proj in sorted(projects)}
    failed_projs = [p for p, ok in results.items() if ok is False]

    if failed_projs:
        print(f'\nFAILED: {", ".join(failed_projs)}')
        sys.exit(1)
    print('\nAll tests passed (or skipped).')


if __name__ == '__main__':
    main()
```

Note: `dest='run_all'` avoids shadowing Python's built-in `all`.

- [ ] **Step 4: Run all tests — expect pass**

Run:
```
python -m pytest scripts/tests/test_run_tests.py -v
```
Expected: All 28 tests pass.

- [ ] **Step 5: Smoke-test the CLI**

Run:
```
python scripts/run-tests.py --project WebsMami
```
Expected (WebsMami has placeholder URL):
```
[WebsMami] base_url is placeholder — skipping
All tests passed (or skipped).
```

- [ ] **Step 6: Commit**

```bash
git add scripts/tests/test_run_tests.py scripts/run-tests.py
git commit -m "feat(tests): add run_project orchestration and main CLI"
```

---

### Task 8: Initial `tests.json` Configs

**Files:**
- Create: `.claudio/agents/ClaudeTrader/tests.json`
- Create: `.claudio/agents/WebsMami/tests.json`
- Create: `.claudio/agents/ClaudeSEO/tests.json`

No tests needed — data files. Verified via the CLI smoke test.

- [ ] **Step 1: Create `.claudio/agents/ClaudeTrader/tests.json`**

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

- [ ] **Step 2: Create `.claudio/agents/WebsMami/tests.json`**

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

- [ ] **Step 3: Create `.claudio/agents/ClaudeSEO/tests.json`**

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

- [ ] **Step 4: Verify `--all` skips placeholder projects**

Run:
```
python scripts/run-tests.py --all
```
Expected (ClaudeTrader starts npm run build which takes time — if you want a fast check, run with just WebsMami):
```
[ClaudeSEO]  base_url is placeholder — skipping
[WebsMami]   base_url is placeholder — skipping
[ClaudeTrader] ...
All tests passed (or skipped).
```

- [ ] **Step 5: Commit**

```bash
git add .claudio/agents/ClaudeTrader/tests.json .claudio/agents/WebsMami/tests.json .claudio/agents/ClaudeSEO/tests.json
git commit -m "feat(tests): add initial tests.json configs for all three projects"
```

---

### Task 9: `start-tests.ps1` Manual Launcher

**Files:**
- Create: `scripts/start-tests.ps1`

- [ ] **Step 1: Create `scripts/start-tests.ps1`**

```powershell
# start-tests.ps1 — Run Claudio tests in a new Windows Terminal tab
# Usage:
#   pwsh scripts/start-tests.ps1                         # run all projects
#   pwsh scripts/start-tests.ps1 -Project ClaudeTrader   # run one project

param([string]$Project = '')

$claudioRoot = Split-Path $PSScriptRoot -Parent
$testArgs    = if ($Project) { "--project $Project" } else { "--all" }
$startCmd    = "Set-Location '$claudioRoot'; python scripts/run-tests.py $testArgs"
$encodedCmd  = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($startCmd))

wt.exe new-tab --title "Claudio: Tests" -- pwsh -NoExit -EncodedCommand $encodedCmd
```

- [ ] **Step 2: Verify script syntax is valid**

Run:
```
pwsh -NonInteractive -Command "Get-Content scripts/start-tests.ps1 | Out-Null; Write-Host OK"
```
Expected: `OK`

- [ ] **Step 3: Commit**

```bash
git add scripts/start-tests.ps1
git commit -m "feat(tests): add start-tests.ps1 manual launcher"
```

---

### Task 10: `agent-checkin.ps1` `-RunTests` Switch and `CLAUDE.md` Step 9

**Files:**
- Modify: `scripts/agent-checkin.ps1` (param block lines 21–24; append 5 lines at end)
- Modify: `CLAUDE.md` (Project Import Procedure)

- [ ] **Step 1: Read `scripts/agent-checkin.ps1` lines 12–24 to confirm param block**

Open `scripts/agent-checkin.ps1`. The param block currently ends with:

```powershell
  [string]$CompleteTask   = $null   # task ID just completed — prepended to recent_completions
)
```

- [ ] **Step 2: Add `-RunTests` switch to the param block**

Find (exact text):
```powershell
  [string]$CompleteTask   = $null   # task ID just completed — prepended to recent_completions
)
```

Replace with:
```powershell
  [string]$CompleteTask   = $null,  # task ID just completed — prepended to recent_completions
  [switch]$RunTests                 # when set, run tests after status update
)
```

Important: the existing `= $null` gains a comma (`,`) because a new param follows it.

- [ ] **Step 3: Append `-RunTests` handler at the very end of `scripts/agent-checkin.ps1`**

After the final `Set-Content $taskSummaryPath -Encoding UTF8` line, append:

```powershell

# Run tests if requested (agent passes this when code changes warrant verification)
if ($RunTests) {
  Write-Host "[$Project] running tests..."
  $testScript = Join-Path $claudioRoot "scripts\run-tests.py"
  python $testScript --project $Project
}
```

- [ ] **Step 4: Add step 9 to `CLAUDE.md` Project Import Procedure**

Find (exact text, at the end of the Project Import Procedure section):
```
8. Commit all changes: `feat: onboard [project name] into Claudio`
```

Replace with:
```
8. Commit all changes: `feat: onboard [project name] into Claudio`
9. Scaffold `.claudio/agents/{project}/tests.json` — detect stack and `base_url`,
   create starter config with `"enabled": true`, one check for `/`, and
   `startup` set if a local dev server command was detected. Fill `REPLACE_WITH_URL`
   if the live URL is unknown. Commit with the onboarding commit.
```

- [ ] **Step 5: Smoke-test the `-RunTests` switch**

Run:
```
pwsh scripts/agent-checkin.ps1 -Project WebsMami -Status idle -RunTests
```
Expected:
```
[WebsMami] status=idle heartbeat=...
[WebsMami] running tests...
[WebsMami] base_url is placeholder — skipping
All tests passed (or skipped).
```

- [ ] **Step 6: Commit**

```bash
git add scripts/agent-checkin.ps1 CLAUDE.md
git commit -m "feat(tests): add -RunTests switch to agent-checkin and CLAUDE.md step 9"
```

---

## Post-Implementation

After all 10 tasks are complete, run the full test suite one final time:

```
python -m pytest scripts/tests/test_run_tests.py -v
```

Expected: All 28 tests pass.

Run a manual end-to-end check against one real project (replace when WebsMami URL is known):
```
# Fill in real URL first:
# edit .claudio/agents/WebsMami/tests.json -> set "base_url": "https://your-url.com"
pwsh scripts/start-tests.ps1 -Project WebsMami
```
