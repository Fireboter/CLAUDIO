#!/usr/bin/env python3
"""
telegram-forward-hook.py — Forward the previous assistant response on UserPromptSubmit.

When the user submits a new prompt, we read the transcript and forward the assistant's
LAST COMPLETE RESPONSE (the block between the second-to-last and last user messages).

This is the reliable Telegram forwarding mechanism — UserPromptSubmit fires on every
message, so we can always forward the previous response.

Also screenshots any running localhost visualization (visual companion etc).
"""
import json
import os
import socket
import sys
from datetime import datetime, timezone
from pathlib import Path

CLAUDIO_ROOT = Path(__file__).parent.parent
TRANSCRIPT_BASE = Path.home() / '.claude' / 'projects' / 'D--CLAUDIO'
LOG_FILE = CLAUDIO_ROOT / '.claudio' / 'telegram-forward-hook.log'
LAST_SENT_FILE = CLAUDIO_ROOT / '.claudio' / 'telegram-last-sent.json'
LOCALHOST_PORTS = [3000, 3001, 4000, 5000, 5173, 7860, 8080, 8000]


def log(msg: str):
    ts = datetime.now(timezone.utc).strftime('%H:%M:%S')
    try:
        with open(LOG_FILE, 'a', encoding='utf-8') as f:
            f.write(f'[{ts}] {msg}\n')
    except Exception:
        pass


def load_tg():
    import importlib.util
    spec = importlib.util.spec_from_file_location('_tg', CLAUDIO_ROOT / 'scripts' / '_tg.py')
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def read_stdin() -> dict:
    try:
        raw = sys.stdin.read().strip()
        return json.loads(raw) if raw else {}
    except Exception:
        return {}


def get_session(data: dict) -> tuple:
    session_id = data.get('session_id', os.environ.get('CLAUDE_SESSION_ID', ''))
    transcript_path = data.get('transcript_path', '')
    if not session_id:
        cur = CLAUDIO_ROOT / '.claudio' / 'current-session.json'
        try:
            if cur.exists():
                cs = json.loads(cur.read_text(encoding='utf-8'))
                session_id = cs.get('session_id', '')
                transcript_path = transcript_path or cs.get('transcript_path', '')
        except Exception:
            pass
    return session_id, transcript_path


def read_transcript(session_id: str, transcript_path: str = '') -> list:
    path = Path(transcript_path) if transcript_path else (TRANSCRIPT_BASE / f'{session_id}.jsonl')
    if not path.exists():
        log(f"transcript not found: {path}")
        return []
    entries = []
    try:
        for line in path.read_text(encoding='utf-8', errors='replace').splitlines():
            line = line.strip()
            if line:
                try:
                    entries.append(json.loads(line))
                except Exception:
                    pass
    except Exception as e:
        log(f"transcript read error: {e}")
    return entries


def is_user_entry(e: dict) -> bool:
    typ = e.get('type', '')
    msg = e.get('message') or {}
    role = msg.get('role', '') if isinstance(msg, dict) else ''
    return typ == 'user' or role == 'user'


def extract_text_blocks(content) -> list:
    if not content:
        return []
    if isinstance(content, str):
        return [content] if content.strip() else []
    if isinstance(content, dict):
        t = content.get('text', '')
        return [t] if t.strip() else []
    if isinstance(content, list):
        out = []
        for block in content:
            if isinstance(block, dict) and block.get('type') == 'text':
                t = block.get('text', '').strip()
                if t:
                    out.append(t)
        return out
    return []


def collect_assistant_text(entries: list) -> str:
    """Collect unique assistant text blocks from a slice of transcript entries."""
    seen: set = set()
    texts: list = []
    for e in entries:
        typ = e.get('type', '')
        msg = e.get('message') or {}
        role = msg.get('role', '') if isinstance(msg, dict) else ''
        if typ == 'assistant' or role == 'assistant':
            content = msg.get('content', []) if isinstance(msg, dict) else []
            for t in extract_text_blocks(content):
                if len(t) > 15 and t not in seen:
                    seen.add(t)
                    texts.append(t)
    return '\n\n'.join(texts)


def get_last_sent_idx() -> int:
    try:
        if LAST_SENT_FILE.exists():
            return json.loads(LAST_SENT_FILE.read_text(encoding='utf-8')).get('last_user_idx', -1)
    except Exception:
        pass
    return -1


def save_last_sent_idx(idx: int):
    try:
        LAST_SENT_FILE.write_text(
            json.dumps({'last_user_idx': idx, 'ts': datetime.now(timezone.utc).isoformat()}),
            encoding='utf-8'
        )
    except Exception:
        pass


def port_open(port: int) -> bool:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.settimeout(0.3)
        return s.connect_ex(('127.0.0.1', port)) == 0


def screenshot_port(port: int) -> str:
    d = CLAUDIO_ROOT / '.claudio' / 'screenshots'
    d.mkdir(parents=True, exist_ok=True)
    ts = datetime.now().strftime('%Y%m%d-%H%M%S')
    out = d / f'vis-{port}-{ts}.png'
    try:
        from playwright.sync_api import sync_playwright
        with sync_playwright() as pw:
            browser = pw.chromium.launch()
            page = browser.new_page(viewport={'width': 1280, 'height': 720})
            page.goto(f'http://localhost:{port}', timeout=5000)
            page.wait_for_load_state('networkidle', timeout=3000)
            page.screenshot(path=str(out))
            browser.close()
        return str(out)
    except Exception as e:
        log(f"screenshot error: {e}")
        return ''


def main():
    data = read_stdin()
    session_id, transcript_path = get_session(data)
    log(f"Forward hook — session: {session_id!r}")

    if not session_id:
        log("no session_id — aborting")
        return

    entries = read_transcript(session_id, transcript_path)
    if not entries:
        return

    user_indices = [i for i, e in enumerate(entries) if is_user_entry(e)]
    log(f"entries: {len(entries)}, user entries at: {user_indices}")

    if len(user_indices) < 2:
        log("only one user entry — no previous response to forward")
        return

    # Previous assistant response: between second-to-last and last user entries
    prev_user_idx = user_indices[-2]
    curr_user_idx = user_indices[-1]

    last_sent = get_last_sent_idx()
    log(f"prev_user_idx={prev_user_idx}, curr_user_idx={curr_user_idx}, last_sent={last_sent}")

    if last_sent >= curr_user_idx:
        log("already forwarded this response — skipping")
        return

    between = entries[prev_user_idx + 1:curr_user_idx]
    msg = collect_assistant_text(between)
    log(f"collected text: {len(msg)} chars")

    if not msg:
        log("no assistant text found — skipping")
        return

    MAX = 3800
    if len(msg) > MAX:
        msg = msg[:MAX] + '\n…[truncated]'

    # Screenshot localhost visualization if running
    screenshot = ''
    for port in LOCALHOST_PORTS:
        if port_open(port):
            log(f"localhost:{port} open — screenshotting")
            screenshot = screenshot_port(port)
            if screenshot:
                break

    try:
        tg = load_tg()
        tg.send('claudio', f'\U0001f4ac <b>Claudio:</b>\n\n{msg}')
        log("sent to Telegram OK")
        if screenshot:
            tg.send_photo('claudio', screenshot, 'localhost visualization')
            log(f"screenshot sent: {screenshot}")
        save_last_sent_idx(curr_user_idx)
    except Exception as e:
        log(f"Telegram send error: {e}")


if __name__ == '__main__':
    main()
