#!/usr/bin/env python3
"""
telegram-stop-hook.py — Forward Claude's response to Telegram on Stop event.

Architecture:
- hook-handler.cjs writes .claudio/telegram-session.json when a Telegram message
  is detected in UserPromptSubmit. This script checks that flag.
- Reads the JSONL transcript to collect ALL text blocks from the last assistant
  turn (multiple streaming entries per turn, we union their text content).
- Sends to Telegram. Also screenshots any running localhost visualization.

Transcript format (Claude Code 2.x):
  Each JSONL line: {type:"user"|"assistant", message:{role, content:[{type,text}]}, ...}
  Multiple entries share the same message["id"] (streaming chunks).
  We collect ALL text blocks after the last user entry to get the full response.
"""
import json
import os
import socket
import sys
from datetime import datetime, timezone
from pathlib import Path

CLAUDIO_ROOT = Path(__file__).parent.parent
TRANSCRIPT_BASE = Path.home() / '.claude' / 'projects' / 'D--CLAUDIO'
FLAG_FILE = CLAUDIO_ROOT / '.claudio' / 'telegram-session.json'
LOG_FILE  = CLAUDIO_ROOT / '.claudio' / 'telegram-stop-hook.log'
FLAG_MAX_AGE_SECONDS = 1800  # 30 minutes

LOCALHOST_PORTS = [3000, 3001, 4000, 5000, 5173, 7860, 8080, 8000]


def log(msg: str):
    ts = datetime.now(timezone.utc).strftime('%H:%M:%S')
    line = f"[{ts}] {msg}"
    try:
        with open(LOG_FILE, 'a', encoding='utf-8') as f:
            f.write(line + '\n')
    except Exception:
        pass


def read_stdin() -> dict:
    try:
        raw = sys.stdin.read().strip()
        return json.loads(raw) if raw else {}
    except Exception:
        return {}


def is_telegram_session(session_id: str) -> bool:
    """Check the flag file written by hook-handler.cjs when Telegram messages arrive."""
    try:
        if not FLAG_FILE.exists():
            return False
        flag = json.loads(FLAG_FILE.read_text(encoding='utf-8'))
        # Check age — flag older than FLAG_MAX_AGE_SECONDS means stale
        ts = datetime.fromisoformat(flag.get('timestamp', '1970-01-01'))
        if ts.tzinfo is None:
            ts = ts.replace(tzinfo=timezone.utc)
        age = (datetime.now(timezone.utc) - ts).total_seconds()
        if age > FLAG_MAX_AGE_SECONDS:
            return False
        # Accept if session matches OR if flag is very recent (< 5 min) regardless
        flag_session = flag.get('session_id', '')
        if flag_session == session_id:
            return True
        if age < 300:  # same machine, recent flag → same user context
            return True
    except Exception as e:
        log(f"flag check error: {e}")
    return False


def read_transcript(session_id: str, transcript_path: str = '') -> list:
    if transcript_path:
        path = Path(transcript_path)
    else:
        path = TRANSCRIPT_BASE / f'{session_id}.jsonl'
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
    log(f"transcript entries: {len(entries)}")
    return entries


def extract_text_blocks(content) -> list:
    """Extract all text strings from a content value (str, list, or dict)."""
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


def last_assistant_text(entries: list) -> str:
    """
    Collect all unique text blocks from the last assistant turn.

    The transcript stores streaming updates: multiple entries share the same
    message["id"]. The last entry for a turn might be a tool_use block with
    no text. We scan ALL entries after the last user entry and union text.
    """
    # Find index of the last user entry
    last_user_idx = -1
    for i, e in enumerate(entries):
        typ = e.get('type', '')
        msg = e.get('message') or {}
        role = msg.get('role', '') if isinstance(msg, dict) else ''
        if typ == 'user' or role == 'user':
            last_user_idx = i

    if last_user_idx == -1:
        log("no user entry found in transcript")
        return ''

    log(f"last user entry at index {last_user_idx} of {len(entries)}")

    # Collect unique texts from all assistant entries after last user
    seen: set = set()
    texts: list = []

    for e in entries[last_user_idx + 1:]:
        typ = e.get('type', '')
        msg = e.get('message') or {}
        role = msg.get('role', '') if isinstance(msg, dict) else ''

        if typ == 'assistant' or role == 'assistant':
            content = msg.get('content', []) if isinstance(msg, dict) else []
            for t in extract_text_blocks(content):
                if len(t) > 15 and t not in seen:
                    seen.add(t)
                    texts.append(t)

    result = '\n\n'.join(texts)
    log(f"collected text length: {len(result)} chars, {len(texts)} blocks")
    return result


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


def load_tg():
    import importlib.util
    spec = importlib.util.spec_from_file_location('_tg', CLAUDIO_ROOT / 'scripts' / '_tg.py')
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def main():
    data = read_stdin()
    session_id = data.get('session_id', os.environ.get('CLAUDE_SESSION_ID', ''))
    transcript_path = data.get('transcript_path', '')
    log(f"Stop hook fired — session: {session_id!r}, transcript: {transcript_path!r}")

    # On Windows the Stop hook sometimes receives empty stdin — fall back to the
    # current-session.json file written by the UserPromptSubmit (route) hook.
    if not session_id:
        cur = CLAUDIO_ROOT / '.claudio' / 'current-session.json'
        try:
            if cur.exists():
                cs = json.loads(cur.read_text(encoding='utf-8'))
                session_id = cs.get('session_id', '')
                transcript_path = transcript_path or cs.get('transcript_path', '')
                log(f"session_id from current-session.json: {session_id!r}")
        except Exception as e:
            log(f"current-session.json read error: {e}")

    if not session_id:
        log("no session_id — aborting")
        return

    # Always forward — user wants all Claude responses in Telegram regardless of
    # whether the turn was triggered from Telegram or typed directly in the terminal.
    # is_telegram_session() is kept for logging only.
    tg_flag = is_telegram_session(session_id)
    log(f"telegram_session flag: {tg_flag} (ignored — always forward)")

    # Prefer last_assistant_message from hook stdin (always available, no timing issues)
    msg = (data.get('last_assistant_message') or '').strip()
    if msg:
        log(f"using last_assistant_message from hook stdin ({len(msg)} chars)")
    else:
        # Fall back to transcript (may not be flushed yet on fast responses)
        entries = read_transcript(session_id, transcript_path)
        if not entries:
            return
        msg = last_assistant_text(entries)
        if not msg:
            log("no assistant text found — skipping")
            return

    MAX = 3800
    if len(msg) > MAX:
        msg = msg[:MAX] + '\n\u2026[truncated]'

    # Check for localhost visualization
    screenshot = ''
    for port in LOCALHOST_PORTS:
        if port_open(port):
            log(f"localhost:{port} is open — attempting screenshot")
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
    except Exception as e:
        log(f"Telegram send error: {e}")


if __name__ == '__main__':
    main()
