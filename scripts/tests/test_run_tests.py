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
