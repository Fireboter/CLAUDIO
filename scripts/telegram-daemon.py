#!/usr/bin/env python3
"""
telegram-daemon.py — Real-time Telegram injection daemon for Claudio

Polls .claudio/telegram-inbox.json every 2 seconds for unprocessed messages.
When a new message arrives, finds the Claude Code / Windows Terminal window
running this CLAUDIO session and simulates Enter to trigger the UserPromptSubmit
hook — which reads the inbox and injects TELEGRAM_CONTEXT into Claude's context.

Run via:  python scripts/telegram-daemon.py
Auto-started by SessionStart hook alongside the Telegram bot.
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
PID_FILE     = CLAUDIO_ROOT / '.claudio' / 'telegram-daemon.pid'
LOG_FILE     = CLAUDIO_ROOT / '.claudio' / 'telegram-daemon.log'
POLL_SECONDS = 2

# Window title fragments that identify the Claudio terminal.
# Windows Terminal shows the cwd or process name in the tab/title bar.
WINDOW_HINTS = ['CLAUDIO', 'claudio', 'D:\\CLAUDIO']

# Minimum idle time (seconds) before injecting — avoids interrupting active typing
IDLE_THRESHOLD = 3.0


def log(msg: str):
    ts = datetime.now(timezone.utc).strftime('%H:%M:%S')
    line = f"[{ts}] {msg}"
    try:
        print(line, flush=True)
    except UnicodeEncodeError:
        print(line.encode('ascii', errors='replace').decode(), flush=True)
    try:
        with open(LOG_FILE, 'a', encoding='utf-8') as f:
            f.write(line + '\n')
    except Exception:
        pass


def _mark_injected(messages: list):
    """Mark a list of messages as processed in the inbox file."""
    try:
        ids = {m['id'] for m in messages}
        data = json.loads(INBOX_FILE.read_text(encoding='utf-8'))
        for m in data.get('messages', []):
            if m['id'] in ids:
                m['processed'] = True
        INBOX_FILE.write_text(json.dumps(data, indent=2, ensure_ascii=False), encoding='utf-8')
        log(f"Marked {len(ids)} message(s) as processed after inject")
    except Exception as e:
        log(f"_mark_injected error: {e}")


def read_pending() -> list:
    """Return list of unprocessed messages from the inbox."""
    try:
        if INBOX_FILE.exists():
            data = json.loads(INBOX_FILE.read_text(encoding='utf-8'))
            return [m for m in data.get('messages', []) if not m.get('processed')]
    except Exception:
        pass
    return []


def find_claudio_window():
    """
    Return (hwnd, title) of the Windows Terminal hosting this Claudio session.

    Strategy:
    1. Find a running claude.exe process with CLAUDIO in its cwd/cmdline.
    2. Walk the process tree up to WindowsTerminal.exe — get that PID.
    3. Find the hwnd owned by that WindowsTerminal PID.
    4. Fallback: any visible WindowsTerminal.exe window.
    """
    try:
        import win32gui
        import win32process
        import psutil

        # --- Step 1 & 2: find WindowsTerminal PID via claude.exe process tree ---
        target_wt_pid = None
        for proc in psutil.process_iter(['pid', 'name', 'cwd', 'cmdline']):
            try:
                if proc.info['name'] and 'claude' in proc.info['name'].lower():
                    cwd = proc.info['cwd'] or ''
                    if 'CLAUDIO' in cwd.upper():
                        # Walk up to WindowsTerminal
                        p = proc
                        while p:
                            if 'windowsterminal' in p.name().lower():
                                target_wt_pid = p.pid
                                break
                            try:
                                p = p.parent()
                            except Exception:
                                break
            except (psutil.NoSuchProcess, psutil.AccessDenied):
                pass
            if target_wt_pid:
                break

        # --- Step 3: get hwnd for that PID ---
        found = []

        def cb(hwnd, _):
            if not win32gui.IsWindowVisible(hwnd):
                return
            title = win32gui.GetWindowText(hwnd)
            if not title:
                return
            try:
                _, pid = win32process.GetWindowThreadProcessId(hwnd)
                if target_wt_pid and pid == target_wt_pid:
                    found.append((hwnd, title, True))
                elif 'windowsterminal' in (psutil.Process(pid).name() or '').lower():
                    found.append((hwnd, title, False))
            except Exception:
                pass

        win32gui.EnumWindows(cb, None)

        # Prefer exact PID match, then any WT window
        exact = [x for x in found if x[2]]
        if exact:
            return (exact[0][0], exact[0][1])
        if found:
            return (found[0][0], found[0][1])

    except ImportError:
        pass
    return None


def get_last_input_idle() -> float:
    """Return seconds since last mouse/keyboard input (Windows only)."""
    try:
        import ctypes
        class LASTINPUTINFO(ctypes.Structure):
            _fields_ = [('cbSize', ctypes.c_uint), ('dwTime', ctypes.c_uint)]
        li = LASTINPUTINFO()
        li.cbSize = ctypes.sizeof(li)
        ctypes.windll.user32.GetLastInputInfo(ctypes.byref(li))
        millis = ctypes.windll.kernel32.GetTickCount() - li.dwTime
        return millis / 1000.0
    except Exception:
        return 999.0


def inject_trigger(hwnd, title: str, messages: list):
    """
    Bring the window to foreground, type the Telegram message as a prompt,
    then press Enter to trigger the UserPromptSubmit hook.
    The hook will surface TELEGRAM_CONTEXT with full metadata.
    """
    try:
        import win32gui
        import ctypes
        import pyautogui

        # Build the prompt text from the first pending message
        msg = messages[0]
        from_name = msg.get('from', 'user')
        text = msg.get('text', '').strip()
        # Truncate long messages in the prompt (hook surfaces the full text)
        display = text[:120] + ('...' if len(text) > 120 else '')
        prompt = f"[telegram from {from_name}]: {display}"

        log(f"Injecting into window: {title!r}")
        log(f"Prompt: {prompt[:80]!r}")

        # Restore if minimized
        SW_RESTORE = 9
        ctypes.windll.user32.ShowWindow(hwnd, SW_RESTORE)
        time.sleep(0.15)

        # Bring to foreground
        win32gui.SetForegroundWindow(hwnd)
        time.sleep(0.5)

        # Clear any partial input on the current line
        pyautogui.hotkey('ctrl', 'c')
        time.sleep(0.3)

        # Use clipboard for reliable Unicode pasting
        import win32clipboard
        win32clipboard.OpenClipboard()
        win32clipboard.EmptyClipboard()
        win32clipboard.SetClipboardText(prompt, win32clipboard.CF_UNICODETEXT)
        win32clipboard.CloseClipboard()
        time.sleep(0.1)

        pyautogui.FAILSAFE = False
        pyautogui.hotkey('ctrl', 'v')
        time.sleep(0.15)

        # Submit
        pyautogui.press('enter')
        log("Trigger injected and submitted")
        return True
    except Exception as e:
        log(f"inject_trigger failed: {e}")
        return False


def write_pid():
    PID_FILE.write_text(str(os.getpid()))


def already_running() -> bool:
    if not PID_FILE.exists():
        return False
    try:
        pid = int(PID_FILE.read_text().strip())
        if pid == os.getpid():
            return False
        # Check if that PID is still a telegram-daemon process
        result = subprocess.run(
            ['powershell', '-NoProfile', '-Command',
             f'(Get-Process -Id {pid} -ErrorAction SilentlyContinue).CommandLine'],
            capture_output=True, text=True, timeout=5
        )
        if 'telegram-daemon' in result.stdout:
            return True
    except Exception:
        pass
    return False


def main():
    if already_running():
        log("Daemon already running — exiting")
        sys.exit(0)

    write_pid()
    log(f"Telegram daemon started (PID {os.getpid()}). Polling every {POLL_SECONDS}s.")
    log(f"Inbox: {INBOX_FILE}")

    last_inject = 0.0

    while True:
        try:
            pending = read_pending()
            if pending:
                idle = get_last_input_idle()
                now = time.time()
                # Don't inject more often than every 5 seconds
                if idle >= IDLE_THRESHOLD and (now - last_inject) >= 5.0:
                    win = find_claudio_window()
                    if win:
                        hwnd, title = win
                        log(f"Found Claudio window  ({len(pending)} pending message(s))")
                        if inject_trigger(hwnd, title, pending):
                            last_inject = now
                            # Mark injected messages as processed immediately so
                            # they are not re-injected if Claude is interrupted.
                            # The hook will ALSO mark them and output TELEGRAM_CONTEXT.
                            _mark_injected(pending)
                    else:
                        log("Claudio window not found — messages will be picked up on next prompt")
                elif idle < IDLE_THRESHOLD:
                    log(f"User active ({idle:.1f}s idle) — waiting before inject")
        except Exception as e:
            log(f"Poll error: {e}")

        time.sleep(POLL_SECONDS)


if __name__ == '__main__':
    main()
