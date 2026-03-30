#!/usr/bin/env python3
"""
telegram-approve.py — PreToolUse hook: forwards risky Bash commands to Telegram for y/n approval.

When a command matches risky patterns ($(  ), kill, rm -rf, etc.), this hook:
1. Sends the command to Telegram asking for approval
2. Polls the inbox for a reply (60s timeout)
3. Exits 0 = allow, exits 2 = block

Claude Code wires this as the first entry in PreToolUse[Bash] hooks (see settings.json).
Stdin receives the hook JSON: {"tool_name": "Bash", "tool_input": {"command": "..."}}
"""

import json
import os
import sys
import time
import subprocess
from pathlib import Path
from datetime import datetime, timezone

CLAUDIO_ROOT = Path(__file__).parent.parent
INBOX_FILE   = CLAUDIO_ROOT / '.claudio' / 'telegram-inbox.json'
BOT_PID_FILE = CLAUDIO_ROOT / '.claudio' / 'telegram-bot.pid'
TIMEOUT      = 60   # seconds to wait for a reply before defaulting to allow
POLL_INTERVAL = 1.5

# Patterns that trigger Claude Code's "Do you want to proceed?" dialog.
# Kept conservative — only what typically causes the interactive prompt.
RISKY_PATTERNS = [
    '$(',           # command substitution
    '`',            # backtick execution
    'kill ',
    'kill\t',
    'pkill',
    'taskkill',
    'rm -rf',
    'rm -r ',
    'rmdir /s',
    'del /s',
    'git push --force',
    'git push -f',
    'git reset --hard',
    'DROP TABLE',
    'DROP DATABASE',
    'truncate table',
]


def is_risky(cmd: str) -> bool:
    cmd_lower = cmd.lower()
    for p in RISKY_PATTERNS:
        if p.lower() in cmd_lower:
            return True
    return False


def bot_is_running() -> bool:
    """Only send Telegram messages if the bot process is up."""
    try:
        if not BOT_PID_FILE.exists():
            return False
        pid = int(BOT_PID_FILE.read_text().strip())
        result = subprocess.run(
            ['powershell', '-NoProfile', '-Command',
             f'(Get-Process -Id {pid} -ErrorAction SilentlyContinue).Id'],
            capture_output=True, text=True, timeout=3
        )
        return result.stdout.strip() == str(pid)
    except Exception:
        return False


def send_telegram(text: str):
    try:
        subprocess.run(
            ['python', str(CLAUDIO_ROOT / 'scripts' / '_tg.py'), 'claudio', text],
            capture_output=True, timeout=12
        )
    except Exception:
        pass


def mark_processed(msg_id: str):
    try:
        data = json.loads(INBOX_FILE.read_text(encoding='utf-8'))
        for m in data.get('messages', []):
            if m['id'] == msg_id:
                m['processed'] = True
        INBOX_FILE.write_text(json.dumps(data, indent=2, ensure_ascii=False), encoding='utf-8')
    except Exception:
        pass


def wait_for_reply(after_ts: str) -> str | None:
    """
    Poll the inbox for the first unprocessed message received after after_ts.
    Returns the message text (lowercased), or None on timeout.
    """
    deadline = time.time() + TIMEOUT
    while time.time() < deadline:
        try:
            if INBOX_FILE.exists():
                data = json.loads(INBOX_FILE.read_text(encoding='utf-8'))
                for msg in data.get('messages', []):
                    if msg.get('processed'):
                        continue
                    if msg.get('timestamp', '') > after_ts:
                        mark_processed(msg['id'])
                        return msg.get('text', '').strip().lower()
        except Exception:
            pass
        time.sleep(POLL_INTERVAL)
    return None


def main():
    # --- Read hook input from stdin ---
    cmd = ''
    description = ''
    try:
        raw = sys.stdin.read()
        if raw.strip():
            hi = json.loads(raw)
            ti = hi.get('tool_input', hi)
            cmd = ti.get('command', '')
            description = ti.get('description', '')
    except Exception:
        pass

    # Only intercept if the command looks risky and the bot is reachable
    if not cmd or not is_risky(cmd) or not bot_is_running():
        sys.exit(0)

    # Build the Telegram approval request
    display = cmd[:300] + ('…' if len(cmd) > 300 else '')
    lines = [
        '🔐 *Permission needed*',
        f'```\n{display}\n```',
    ]
    if description:
        lines.append(f'_{description}_')
    lines.append('\nReply *y* to allow or *n* to block  _(60 s timeout → auto-allow)_')
    tg_msg = '\n'.join(lines)

    now_ts = datetime.now(timezone.utc).isoformat()
    send_telegram(tg_msg)

    reply = wait_for_reply(now_ts)

    if reply is None:
        # Timeout — allow; terminal dialog will appear as normal fallback
        send_telegram('⏱️ No reply in 60 s — allowing (answer the terminal dialog manually)')
        sys.exit(0)

    if reply.startswith('y') or reply == '1':
        send_telegram('✅ Approved — auto-confirming terminal dialog')
        # Claude Code shows its own permission dialog AFTER the hook exits.
        # Spawn a background process that waits ~2 s then presses "1" + Enter
        # to auto-confirm it — so the terminal doesn't freeze waiting for input.
        injector = (
            'import time, sys\n'
            'time.sleep(2)\n'
            'try:\n'
            '    import pyautogui\n'
            '    pyautogui.FAILSAFE = False\n'
            '    pyautogui.press("1")\n'
            '    import time as t; t.sleep(0.1)\n'
            '    pyautogui.press("enter")\n'
            'except Exception as e:\n'
            '    sys.exit(1)\n'
        )
        subprocess.Popen(
            [sys.executable, '-c', injector],
            creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0),
        )
        sys.exit(0)
    else:
        send_telegram('❌ Blocked')
        print('Command blocked via Telegram', file=sys.stderr)
        sys.exit(2)


if __name__ == '__main__':
    main()
