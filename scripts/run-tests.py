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
