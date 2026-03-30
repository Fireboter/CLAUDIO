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

# Seconds to wait between terminal spawn attempts (avoid spawning floods)
SPAWN_COOLDOWN = 45.0
CLAUDIO_DIR = str(CLAUDIO_ROOT)


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
    Return (hwnd, title) of the Windows Terminal hosting a live Claudio/Claude session.

    Strategy:
    1. Find a process where name OR cmdline contains 'claude' AND
       (cwd OR cmdline) contains 'CLAUDIO'.
       Checks both because Windows may deny cwd access, and claude can be node-hosted.
    2. Walk the process tree up to WindowsTerminal.exe.
    3. Return the hwnd of that WT window.

    Returns None if no live Claude session is found — caller must spawn a new terminal.
    """
    try:
        import win32gui
        import win32process
        import psutil

        target_wt_pid = None

        for proc in psutil.process_iter(['pid', 'name', 'cwd', 'cmdline']):
            try:
                name    = (proc.info.get('name') or '').lower()
                cwd     = (proc.info.get('cwd') or '').upper()
                cmdline = ' '.join(proc.info.get('cmdline') or []).upper()

                # Only match the actual Claude Code process (node or claude binary),
                # NOT launcher processes like cmd.exe / powershell that have 'claude'
                # in their arguments but aren't the REPL itself.
                # cmd.exe running "wt ... claude ..." would otherwise be matched,
                # causing injection into a bare shell that executes the text as commands.
                _is_launcher = any(x in name for x in ('cmd', 'powershell', 'wt', 'python', 'sh'))
                is_claude = (not _is_launcher) and (
                    'claude' in name
                    or ('node' in name and 'claude' in cmdline.lower())
                )
                in_claudio  = 'CLAUDIO' in cwd or 'CLAUDIO' in cmdline

                if is_claude and in_claudio:
                    # Walk up the process tree to find WindowsTerminal
                    p = proc
                    while p:
                        try:
                            if 'windowsterminal' in p.name().lower():
                                target_wt_pid = p.pid
                                break
                            p = p.parent()
                        except Exception:
                            break
            except (psutil.NoSuchProcess, psutil.AccessDenied):
                pass

            if target_wt_pid:
                break

        if not target_wt_pid:
            return None  # No live Claude session — caller should spawn a new terminal

        # --- Find the hwnd owned by that WindowsTerminal PID ---
        found = []

        def cb(hwnd, _):
            if not win32gui.IsWindowVisible(hwnd):
                return
            title = win32gui.GetWindowText(hwnd)
            if not title:
                return
            try:
                _, pid = win32process.GetWindowThreadProcessId(hwnd)
                if pid == target_wt_pid:
                    found.append((hwnd, title))
            except Exception:
                pass

        win32gui.EnumWindows(cb, None)
        if found:
            return found[0]

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


def _force_foreground(hwnd):
    """
    Bring hwnd to foreground from a background process.
    Uses the AttachThreadInput trick, which is the standard Windows workaround
    for SetForegroundWindow's focus-steal restriction.
    Returns True on success.
    """
    import ctypes
    import win32process
    import win32gui

    SW_RESTORE = 9
    ctypes.windll.user32.ShowWindow(hwnd, SW_RESTORE)
    time.sleep(0.1)

    # Get thread IDs
    fg_hwnd = ctypes.windll.user32.GetForegroundWindow()
    fg_thread, _ = win32process.GetWindowThreadProcessId(fg_hwnd) if fg_hwnd else (0, 0)
    target_thread, _ = win32process.GetWindowThreadProcessId(hwnd)

    attached = False
    if fg_thread and fg_thread != target_thread:
        attached = bool(ctypes.windll.user32.AttachThreadInput(fg_thread, target_thread, True))

    ctypes.windll.user32.BringWindowToTop(hwnd)
    result = win32gui.SetForegroundWindow(hwnd)

    if attached:
        ctypes.windll.user32.AttachThreadInput(fg_thread, target_thread, False)

    return result is not None


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

        # Bring to foreground using AttachThreadInput trick
        if not _force_foreground(hwnd):
            log("SetForegroundWindow failed — retrying with keybd_event unlock")
            # Alternative: simulate Alt key press to unlock foreground lock
            ctypes.windll.user32.keybd_event(0x12, 0, 0, 0)       # VK_MENU down
            ctypes.windll.user32.keybd_event(0x12, 0, 0x0002, 0)   # VK_MENU up (KEYEVENTF_KEYUP)
            win32gui.SetForegroundWindow(hwnd)

        time.sleep(0.5)

        # Press Escape to dismiss any autocomplete/menu — safe alternative to ctrl+c
        # (ctrl+c sends SIGINT which can kill Claude Code entirely)
        pyautogui.press('escape')
        time.sleep(0.2)

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
    """
    Check if ANY other telegram-daemon instance is running by scanning all processes.
    More robust than PID-file-only check: avoids race conditions when multiple
    sessions start simultaneously, each spawning a daemon.
    """
    my_pid = os.getpid()
    try:
        result = subprocess.run(
            ['powershell', '-NoProfile', '-Command',
             'Get-CimInstance Win32_Process'
             ' | Where-Object {$_.Name -like "python*" -and $_.CommandLine -like "*telegram-daemon.py*"}'
             ' | Select-Object ProcessId | ConvertTo-Json -Compress'],
            capture_output=True, text=True, timeout=10
        )
        raw = result.stdout.strip()
        if raw:
            data = json.loads(raw)
            if isinstance(data, dict):
                data = [data]
            other = [int(d['ProcessId']) for d in data
                     if d.get('ProcessId') and int(d['ProcessId']) != my_pid]
            if other:
                return True
    except Exception:
        pass
    return False


def spawn_claudio_terminal():
    """
    Open a new Windows Terminal tab in D:\\CLAUDIO running claude --dangerously-skip-permissions
    directly (no cmd wrapper). When Claude exits, the tab closes automatically — preventing a
    bare shell from being mistaken for a live Claudio session on the next poll cycle.
    """
    # Run claude directly; WT closes the tab when the process exits
    cmd = [
        'wt', '-w', '0', 'new-tab',
        '--startingDirectory', CLAUDIO_DIR,
        '--', 'claude', '--dangerously-skip-permissions'
    ]
    try:
        subprocess.Popen(cmd, creationflags=subprocess.CREATE_NO_WINDOW)
        log(f"Spawned new Windows Terminal tab in {CLAUDIO_DIR}")
        return True
    except FileNotFoundError:
        pass
    except Exception as e:
        log(f"spawn_claudio_terminal failed: {e}")
        return False

    # wt not in PATH — try well-known location
    try:
        wt_path = os.path.expandvars(r'%LOCALAPPDATA%\Microsoft\WindowsApps\wt.exe')
        cmd[0] = wt_path
        subprocess.Popen(cmd, creationflags=subprocess.CREATE_NO_WINDOW)
        log(f"Spawned new Windows Terminal tab (via full path) in {CLAUDIO_DIR}")
        return True
    except Exception as e2:
        log(f"spawn_claudio_terminal fallback failed: {e2}")
    return False


def main():
    if already_running():
        log("Daemon already running — exiting")
        sys.exit(0)

    write_pid()
    log(f"Telegram daemon started (PID {os.getpid()}). Polling every {POLL_SECONDS}s.")
    log(f"Inbox: {INBOX_FILE}")

    last_inject = 0.0
    last_spawn  = 0.0
    SPAWN_LOAD_WAIT = 12.0  # seconds to wait after spawning before trying to inject

    while True:
        try:
            pending = read_pending()
            if pending:
                idle = get_last_input_idle()
                now = time.time()
                # Don't inject more often than every 5 seconds,
                # and don't inject right after a spawn (Claude needs time to load)
                recently_spawned = (now - last_spawn) < SPAWN_LOAD_WAIT
                if idle >= IDLE_THRESHOLD and (now - last_inject) >= 5.0 and not recently_spawned:
                    win = find_claudio_window()
                    if win:
                        hwnd, title = win
                        # Inject ONE message at a time to avoid concatenation in prompt
                        single = pending[:1]
                        log(f"Found Claudio window  ({len(pending)} pending, injecting 1)")
                        if inject_trigger(hwnd, title, single):
                            last_inject = now
                            # Mark ALL pending as processed immediately so
                            # they are not re-injected if Claude is interrupted.
                            # The hook will surface remaining ones via TELEGRAM_CONTEXT.
                            _mark_injected(pending)
                    else:
                        # No live Claude session — spawn one if cooldown allows.
                        # The newly spawned session will pick up pending messages
                        # via the CLAUDE.md startup inbox check.
                        if (now - last_spawn) >= SPAWN_COOLDOWN:
                            log("No active Claudio terminal — spawning a new one")
                            if spawn_claudio_terminal():
                                last_spawn = now
                        else:
                            log("Claudio window not found — messages queued, will surface on next prompt")
                elif idle < IDLE_THRESHOLD:
                    log(f"User active ({idle:.1f}s idle) — waiting before inject")
        except Exception as e:
            log(f"Poll error: {e}")

        time.sleep(POLL_SECONDS)


if __name__ == '__main__':
    main()
