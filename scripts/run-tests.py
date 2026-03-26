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
