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

# Per-agent topic thread IDs (Forum Group routing)
TELEGRAM_THREADS = {
    'ClaudeTrader': os.environ.get('TELEGRAM_THREAD_CLAUDETRADER', ''),
    'WebsMami':     os.environ.get('TELEGRAM_THREAD_WEBSMAMI', ''),
    'ClaudeSEO':    os.environ.get('TELEGRAM_THREAD_CLAUDESEO', ''),
}


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
    # Merge stderr into stdout so Next.js startup messages (written to stderr) are readable
    proc = subprocess.Popen(
        startup['cmd'], shell=True, cwd=cwd,
        stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True,
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
    base_url: str, checks: list, project: str, screenshots_dir: Path,
    auth_config: dict | None = None,
) -> list[dict]:
    """Navigate to each URL, assert status + text, take screenshot. Returns result list.

    auth_config keys:
      login_path  — path appended to each check's base_url to reach the login page
      fields      — {css_selector: value} mapping of form fields to fill
      submit      — CSS selector of the submit button
    """
    from playwright.sync_api import sync_playwright

    screenshots_dir.mkdir(parents=True, exist_ok=True)
    results = []

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page()

        authenticated_bases: set = set()

        for check in checks:
            check_base      = (check.get('base_url') or base_url).rstrip('/')
            url             = check_base + check['path']
            stem            = check['screenshot']
            screenshot_path = screenshots_dir / f'test-{project}-{stem}.png'

            # Perform login for this base_url if auth is required and not yet done
            if check.get('requires_auth') and auth_config and check_base not in authenticated_bases:
                login_url = check_base + auth_config['login_path']
                try:
                    page.goto(login_url, wait_until='networkidle', timeout=15000)
                    for selector, value in auth_config.get('fields', {}).items():
                        page.fill(selector, value)
                    page.click(auth_config['submit'])
                    page.wait_for_load_state('networkidle')
                    authenticated_bases.add(check_base)
                    print(f'[auth] logged in at {login_url}')
                except Exception as e:
                    print(f'[auth] login failed for {check_base}: {e}')

            try:
                response = page.goto(url, wait_until='networkidle', timeout=check.get('timeout', 30000))
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
    def _label(c):
        return c.get('path') or c.get('cmd', '?')

    def _reason(c):
        return c.get('reason') or c.get('output', '')

    desc_parts = [f'{_label(c)} ({_reason(c)})' for c in failed_checks]
    task = {
        'id':          task_id,
        'type':        'test_fix',
        'priority':    'high',
        'description': f'Fix {len(failed_checks)} failing check(s): {", ".join(desc_parts)}',
        'created_at':  datetime.datetime.utcnow().isoformat() + 'Z',
        'context': {
            'failed_checks':    [{'path': _label(c), 'reason': _reason(c)} for c in failed_checks],
            'screenshot_paths': [c['screenshot'] for c in failed_checks if c.get('screenshot')],
        },
    }
    (tasks_dir / f'{task_id}.json').write_text(json.dumps(task, indent=2))
    return task_id


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


def send_telegram(message: str, screenshot_paths: list, project: str | None = None):
    """Send Telegram message + photos to the project's topic thread (or main chat)."""
    if not TELEGRAM_TOKEN or not TELEGRAM_CHAT:
        print('[telegram] TELEGRAM_BOT_TOKEN or TELEGRAM_CHAT_ID missing — skipping')
        return

    import json as _json
    import urllib.request as _req

    thread_str = TELEGRAM_THREADS.get(project or '', '') if project else ''
    thread_id  = int(thread_str) if thread_str else None

    def _post(endpoint: str, payload: dict):
        data = _json.dumps(payload).encode('utf-8')
        request = _req.Request(
            f'https://api.telegram.org/bot{TELEGRAM_TOKEN}/{endpoint}',
            data=data, headers={'Content-Type': 'application/json'}
        )
        _req.urlopen(request, timeout=10)

    def _post_photo(path: Path):
        import mimetypes, email.mime.multipart, email.mime.base, email.encoders
        from urllib.request import Request as R
        boundary = b'----TGBoundary'
        body = (
            b'--' + boundary + b'\r\n'
            b'Content-Disposition: form-data; name="chat_id"\r\n\r\n' +
            TELEGRAM_CHAT.encode() + b'\r\n'
        )
        if thread_id:
            body += (
                b'--' + boundary + b'\r\n'
                b'Content-Disposition: form-data; name="message_thread_id"\r\n\r\n' +
                str(thread_id).encode() + b'\r\n'
            )
        photo_bytes = path.read_bytes()
        body += (
            b'--' + boundary + b'\r\n'
            b'Content-Disposition: form-data; name="photo"; filename="' + path.name.encode() + b'"\r\n'
            b'Content-Type: image/png\r\n\r\n' +
            photo_bytes + b'\r\n'
            b'--' + boundary + b'--\r\n'
        )
        request = R(
            f'https://api.telegram.org/bot{TELEGRAM_TOKEN}/sendPhoto',
            data=body,
            headers={'Content-Type': f'multipart/form-data; boundary={boundary.decode()}'}
        )
        _req.urlopen(request, timeout=30)

    try:
        payload = {'chat_id': TELEGRAM_CHAT, 'text': message}
        if thread_id:
            payload['message_thread_id'] = thread_id
        _post('sendMessage', payload)
        import time as _time
        for path_str in screenshot_paths:
            p = Path(path_str)
            if p.exists():
                try:
                    _post_photo(p)
                    _time.sleep(0.5)   # avoid Telegram photo rate-limit
                except Exception as e:
                    print(f'[telegram] photo send failed: {e}')
    except Exception as e:
        print(f'[telegram] send failed: {e}')


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
    extra_procs: list[subprocess.Popen] = []

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
            for es in config.get('extra_startups', []):
                ready_url = es.get('ready_url', base_url)
                p = start_server(es, CLAUDIO_ROOT, es.get('stack'), ready_url)
                extra_procs.append(p)
            check_results = run_browser_checks(
                base_url, config.get('checks', []), project, SCREENSHOTS_DIR,
                auth_config=config.get('auth'),
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
        for ep in extra_procs:
            stop_server(ep)

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
    send_telegram(message, all_screenshots, project=project)

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
