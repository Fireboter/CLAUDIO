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
