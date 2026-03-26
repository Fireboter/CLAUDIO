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
