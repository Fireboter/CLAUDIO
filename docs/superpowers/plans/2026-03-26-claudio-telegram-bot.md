# Claudio Telegram Bot Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a bidirectional Telegram bot that bridges any natural-language instruction to a `claude -p` subprocess, supporting multi-turn conversation, screenshot forwarding, and reliable session state.

**Architecture:** Python async long-polling bot (`python-telegram-bot>=20.0`) wraps each incoming message in a system prompt with full conversation history, spawns `claude -p` as a subprocess from `D:\CLAUDIO`, and dispatches the output (text splits, photos, state updates) back to Telegram. No keyword routing — every message is reasoned by Claude. Multi-turn state is held in-memory; history is re-injected on each call rather than using `--continue` to avoid contaminating the Queen terminal session.

**Tech Stack:** Python 3.10+, `python-telegram-bot>=20.0`, `python-dotenv>=1.0`, `asyncio`, `re`, `pathlib`, `subprocess via asyncio`

---

## File Map

| Action | Path | Responsibility |
|---|---|---|
| Create | `scripts/requirements-bot.txt` | Pip dependency list for the bot |
| Create | `scripts/telegram-bot.py` | Full bot service: poller, Claude bridge, dispatcher |
| Create | `scripts/start-telegram-bot.ps1` | Windows Terminal launcher using EncodedCommand |
| Create | `.claudio/screenshots/.gitkeep` | Ensures screenshots dir is tracked in structure, gitignored content |
| Modify | `.gitignore` | Add ephemeral bot state paths |
| Modify | `CLAUDE.md` | Add TELEGRAM_CONTEXT note to Session Startup |

---

## Task 1: Gitignore and directory setup

**Files:**
- Modify: `D:\CLAUDIO\.gitignore`
- Create: `D:\CLAUDIO\.claudio\screenshots\.gitkeep`

- [ ] **Step 1: Read current .gitignore**

```bash
cat D:\CLAUDIO\.gitignore
```

- [ ] **Step 2: Add telegram bot entries to .gitignore**

Append these two lines to `.gitignore`:
```
# Telegram bot ephemeral state
.claudio/telegram-session.json
.claudio/screenshots/
```

- [ ] **Step 3: Create screenshots directory with gitkeep**

Create an empty file at `.claudio/screenshots/.gitkeep` (empty content).

- [ ] **Step 4: Verify screenshots dir is gitignored**

Run:
```bash
git check-ignore -v .claudio/screenshots/something.png
```
Expected: `.gitignore:N:.claudio/screenshots/` (where N is the line number)

- [ ] **Step 5: Commit**

```bash
git add .gitignore .claudio/screenshots/.gitkeep
git commit -m "chore: add telegram bot gitignore entries and screenshots dir"
```

---

## Task 2: Requirements file

**Files:**
- Create: `scripts/requirements-bot.txt`

- [ ] **Step 1: Create requirements-bot.txt**

```
python-telegram-bot>=20.0
python-dotenv>=1.0
```

- [ ] **Step 2: Verify packages resolve**

Run:
```bash
pip install -r scripts/requirements-bot.txt --dry-run
```
Expected: Both packages listed with resolved versions, no errors.

- [ ] **Step 3: Commit**

```bash
git add scripts/requirements-bot.txt
git commit -m "chore: add telegram bot requirements file"
```

---

## Task 3: Core bot service — configuration and state

**Files:**
- Create: `scripts/telegram-bot.py` (first section)

This task creates the file with imports, constants, state management, and helper functions only. The message handler and Claude bridge are in later tasks.

- [ ] **Step 1: Create telegram-bot.py with config and state section**

```python
#!/usr/bin/env python3
"""
telegram-bot.py — Claudio bidirectional Telegram bot
Bridges Telegram messages to `claude -p` subprocess calls.
Run via: python scripts/telegram-bot.py
"""

import asyncio
import json
import os
import re
import sys
from datetime import datetime
from pathlib import Path

from dotenv import load_dotenv
from telegram import Update
from telegram.ext import Application, MessageHandler, filters, ContextTypes

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

CLAUDIO_ROOT = Path(__file__).parent.parent
SCREENSHOTS_DIR = CLAUDIO_ROOT / ".claudio" / "screenshots"
SESSION_FILE = CLAUDIO_ROOT / ".claudio" / "telegram-session.json"
REGISTRY_FILE = CLAUDIO_ROOT / ".claudio" / "registry.json"

load_dotenv(CLAUDIO_ROOT / ".env")

TELEGRAM_BOT_TOKEN = os.getenv("TELEGRAM_BOT_TOKEN", "")
TELEGRAM_CHAT_ID = os.getenv("TELEGRAM_CHAT_ID", "")

if not TELEGRAM_BOT_TOKEN or not TELEGRAM_CHAT_ID:
    print("ERROR: TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID must be set in .env", file=sys.stderr)
    sys.exit(1)

SCREENSHOTS_DIR.mkdir(parents=True, exist_ok=True)

# ---------------------------------------------------------------------------
# Conversation state (in-memory, cleared on bot restart)
# ---------------------------------------------------------------------------

def reset_state() -> dict:
    return {
        "active": False,
        "history": [],
        "waiting_since": None,
        "processing": False,
    }

state = reset_state()
pending_messages: list[str] = []

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def read_registry_safe() -> str:
    """Read .claudio/registry.json and return a compact summary string."""
    if not REGISTRY_FILE.exists():
        return "(registry not found)"
    try:
        with open(REGISTRY_FILE, "r", encoding="utf-8") as f:
            data = json.load(f)
        agents = data.get("agents", {})
        if not agents:
            return "(no agents registered)"
        parts = []
        for name, info in agents.items():
            status = info.get("status", "unknown")
            parts.append(f"{name}: {status}")
        return ", ".join(parts)
    except Exception as e:
        return f"(registry read error: {e})"


def write_session_file():
    """Write current state summary to SESSION_FILE for crash inspection."""
    try:
        summary = {
            "active": state["active"],
            "waiting_since": state["waiting_since"].isoformat() if state["waiting_since"] else None,
            "processing": state["processing"],
            "turn_count": len(state["history"]),
            "last_updated": datetime.utcnow().isoformat() + "Z",
        }
        with open(SESSION_FILE, "w", encoding="utf-8") as f:
            json.dump(summary, f, indent=2)
    except Exception:
        pass  # session file is informational only — never fatal
```

- [ ] **Step 2: Verify syntax**

```bash
python -c "import ast; ast.parse(open('scripts/telegram-bot.py').read()); print('OK')"
```
Expected: `OK`

---

## Task 4: Claude subprocess bridge and prompt builder

**Files:**
- Modify: `scripts/telegram-bot.py` (append sections)

- [ ] **Step 1: Append SYSTEM_WRAPPER and build_prompt to telegram-bot.py**

```python
# ---------------------------------------------------------------------------
# Prompt builder
# ---------------------------------------------------------------------------

SYSTEM_WRAPPER = """\
TELEGRAM_CONTEXT: You are Claudio operating via Telegram remote control.
This is turn {turn} of a multi-turn conversation. Full history is below.

MARKER PROTOCOL (follow exactly):
- When you need the user's input, end your response with <<<WAITING>>> on its own line
- When you are fully done (no more questions), end with <<<DONE>>> on its own line
- To send a screenshot, include <<<SCREENSHOT: /absolute/path/to/file.png>>> on its own line
  (Claude saves screenshots to D:/CLAUDIO/.claudio/screenshots/ via Playwright MCP)
- Keep responses concise — they arrive via Telegram on a phone

CAPABILITIES: You have full Queen capabilities. You can queue tasks, spawn agents, create
projects, onboard codebases, run scripts, use Playwright MCP, and invoke superpowers skills
(brainstorming -> writing-plans -> executing-plans). Always follow the Memory-First Rule.

CURRENT AGENT STATE:
{registry_state}

CONVERSATION HISTORY:
{history}

LATEST INSTRUCTION: {latest_message}"""


def build_prompt(history: list) -> str:
    registry = read_registry_safe()
    formatted_history = "\n".join(
        f"{'User' if h['role'] == 'user' else 'Claudio'}: {h['content']}"
        for h in history[:-1]  # all but the latest
    )
    return SYSTEM_WRAPPER.format(
        turn=len(history),
        registry_state=registry,
        history=formatted_history or "(none — this is the first turn)",
        latest_message=history[-1]["content"],
    )


# ---------------------------------------------------------------------------
# Claude subprocess invocation
# ---------------------------------------------------------------------------

async def run_claude(history: list) -> str:
    """Invoke `claude -p <prompt>` as a subprocess and return stdout."""
    prompt = build_prompt(history)
    proc = await asyncio.create_subprocess_exec(
        "claude", "-p", prompt,
        cwd=str(CLAUDIO_ROOT),
        stdout=asyncio.subprocess.PIPE,
        stderr=asyncio.subprocess.PIPE,
    )
    try:
        stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=300)
    except asyncio.TimeoutError:
        proc.kill()
        await proc.communicate()  # reap the process
        return "⏰ Claude timed out after 5 minutes. Please try again or simplify the request."
    if proc.returncode != 0:
        err = stderr.decode(errors="replace").strip()
        return f"❌ Error (exit {proc.returncode}): {err[:500]}"
    return stdout.decode(errors="replace").strip()
```

- [ ] **Step 2: Verify syntax**

```bash
python -c "import ast; ast.parse(open('scripts/telegram-bot.py').read()); print('OK')"
```
Expected: `OK`

---

## Task 5: Screenshot handler and long-message splitter

**Files:**
- Modify: `scripts/telegram-bot.py` (append sections)

- [ ] **Step 1: Append screenshot handler and send_long_message to telegram-bot.py**

```python
# ---------------------------------------------------------------------------
# Screenshot handler
# ---------------------------------------------------------------------------

SCREENSHOT_RE = re.compile(r"<<<SCREENSHOT:\s*(.+?)>>>")
MAX_PHOTO_BYTES = 10 * 1024 * 1024  # 10 MB Telegram limit for photos


async def handle_screenshots(text: str, update: Update, context: ContextTypes.DEFAULT_TYPE) -> str:
    """Send each <<<SCREENSHOT: path>>> as a Telegram photo/document, remove markers from text."""
    for match in SCREENSHOT_RE.finditer(text):
        path = Path(match.group(1).strip())
        if path.exists():
            try:
                file_size = path.stat().st_size
                with open(path, "rb") as f:
                    if file_size > MAX_PHOTO_BYTES:
                        await context.bot.send_document(
                            chat_id=update.effective_chat.id,
                            document=f,
                            filename=path.name,
                        )
                    else:
                        await context.bot.send_photo(
                            chat_id=update.effective_chat.id,
                            photo=f,
                        )
            except Exception as e:
                await update.message.reply_text(f"⚠️ Screenshot found but failed to send: {e}")
        else:
            await update.message.reply_text(f"⚠️ Screenshot not found at: {path}")
        text = text.replace(match.group(0), "")
    return text


# ---------------------------------------------------------------------------
# Long message splitter
# ---------------------------------------------------------------------------

TELEGRAM_MAX_LEN = 4096


async def send_long_message(text: str, update: Update):
    """Send text, splitting at paragraph boundaries if >4096 chars."""
    text = text.strip()
    if not text:
        return
    if len(text) <= TELEGRAM_MAX_LEN:
        await update.message.reply_text(text)
        return
    # Split at double-newline paragraph boundaries
    paragraphs = text.split("\n\n")
    chunk = ""
    for para in paragraphs:
        candidate = (chunk + "\n\n" + para).strip() if chunk else para
        if len(candidate) <= TELEGRAM_MAX_LEN:
            chunk = candidate
        else:
            if chunk:
                await update.message.reply_text(chunk)
            # If a single paragraph exceeds limit, hard-split it
            while len(para) > TELEGRAM_MAX_LEN:
                await update.message.reply_text(para[:TELEGRAM_MAX_LEN])
                para = para[TELEGRAM_MAX_LEN:]
            chunk = para
    if chunk:
        await update.message.reply_text(chunk)
```

- [ ] **Step 2: Verify syntax**

```bash
python -c "import ast; ast.parse(open('scripts/telegram-bot.py').read()); print('OK')"
```
Expected: `OK`

---

## Task 6: Output dispatcher and message handler

**Files:**
- Modify: `scripts/telegram-bot.py` (append sections)

- [ ] **Step 1: Append dispatch_output and handle_message to telegram-bot.py**

```python
# ---------------------------------------------------------------------------
# Output dispatcher
# ---------------------------------------------------------------------------

async def dispatch_output(raw_output: str, update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Parse Claude's output, send screenshots/text to Telegram, update state."""
    state["processing"] = False

    waiting = "<<<WAITING>>>" in raw_output
    done = "<<<DONE>>>" in raw_output

    # Handle and strip screenshot markers
    output = await handle_screenshots(raw_output, update, context)

    # Strip control markers
    output = output.replace("<<<WAITING>>>", "").replace("<<<DONE>>>", "").strip()

    # Send text
    await send_long_message(output, update)

    # Update conversation state
    state["history"].append({"role": "assistant", "content": raw_output})
    write_session_file()

    if waiting:
        state["active"] = True
        state["waiting_since"] = datetime.now()
    else:
        # <<<DONE>>> or no marker — clear conversation
        state.update(reset_state())
        write_session_file()
        # Process any queued messages
        if pending_messages:
            next_msg = pending_messages.pop(0)
            await _process_message(next_msg, update, context)


# ---------------------------------------------------------------------------
# Message processing (shared by handler and queue drain)
# ---------------------------------------------------------------------------

async def _process_message(user_text: str, update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Run Claude on user_text and dispatch the result."""
    await update.message.reply_text("⏳ Working on it...")
    state["processing"] = True
    state["history"].append({"role": "user", "content": user_text})
    write_session_file()
    result = await run_claude(state["history"])
    await dispatch_output(result, update, context)


# ---------------------------------------------------------------------------
# Message handler
# ---------------------------------------------------------------------------

async def handle_message(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Entry point for all incoming Telegram messages."""
    # Security: only accept messages from the configured chat
    if str(update.effective_chat.id) != TELEGRAM_CHAT_ID:
        return

    if update.message is None or update.message.text is None:
        return

    user_text = update.message.text.strip()
    if not user_text:
        return

    # If claude subprocess is still running, queue the message
    if state["processing"]:
        pending_messages.append(user_text)
        await update.message.reply_text("⏳ Still working on the previous task — will handle this next.")
        return

    await _process_message(user_text, update, context)
```

- [ ] **Step 2: Verify syntax**

```bash
python -c "import ast; ast.parse(open('scripts/telegram-bot.py').read()); print('OK')"
```
Expected: `OK`

---

## Task 7: Bot entrypoint (main)

**Files:**
- Modify: `scripts/telegram-bot.py` (append final section)

- [ ] **Step 1: Append main entrypoint to telegram-bot.py**

```python
# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main():
    print(f"Starting Claudio Telegram bot (root: {CLAUDIO_ROOT})")
    print(f"Screenshots dir: {SCREENSHOTS_DIR}")

    app = (
        Application.builder()
        .token(TELEGRAM_BOT_TOKEN)
        .build()
    )

    app.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, handle_message))

    print("Bot running — polling for messages. Press Ctrl+C to stop.")
    app.run_polling(allowed_updates=Update.ALL_TYPES)


if __name__ == "__main__":
    main()
```

- [ ] **Step 2: Verify full file syntax**

```bash
python -c "import ast; ast.parse(open('scripts/telegram-bot.py').read()); print('OK')"
```
Expected: `OK`

- [ ] **Step 3: Dry-run import check (no token needed)**

```bash
python -c "
import os; os.environ['TELEGRAM_BOT_TOKEN'] = 'x'; os.environ['TELEGRAM_CHAT_ID'] = '1'
# patch dotenv to not overwrite
import dotenv; dotenv.load_dotenv = lambda *a, **k: None
import importlib.util, sys
spec = importlib.util.spec_from_file_location('bot', 'scripts/telegram-bot.py')
# Just parse — don't run main()
import ast; ast.parse(open('scripts/telegram-bot.py').read()); print('Import check: OK')
"
```
Expected: `Import check: OK`

- [ ] **Step 4: Commit telegram-bot.py**

```bash
git add scripts/telegram-bot.py
git commit -m "feat: add Claudio Telegram bot service (scripts/telegram-bot.py)"
```

---

## Task 8: PowerShell launcher

**Files:**
- Create: `scripts/start-telegram-bot.ps1`

- [ ] **Step 1: Create start-telegram-bot.ps1**

```powershell
# start-telegram-bot.ps1 — Launch the Telegram bot in a new Windows Terminal tab
# Usage: pwsh scripts/start-telegram-bot.ps1

$claudioRoot = Split-Path $PSScriptRoot -Parent
$startCmd = "Set-Location '$claudioRoot'; python scripts/telegram-bot.py"
$encodedCmd = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($startCmd))
wt.exe new-tab --title "Claudio: Telegram Bot" -- pwsh -NoExit -EncodedCommand $encodedCmd
```

- [ ] **Step 2: Verify PowerShell syntax**

```powershell
$null = [System.Management.Automation.PSParser]::Tokenize(
    (Get-Content scripts/start-telegram-bot.ps1 -Raw), [ref]$null
)
Write-Host "Syntax OK"
```
Expected: `Syntax OK`

- [ ] **Step 3: Commit**

```bash
git add scripts/start-telegram-bot.ps1
git commit -m "feat: add Telegram bot PowerShell launcher"
```

---

## Task 9: CLAUDE.md — add TELEGRAM_CONTEXT note

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Read CLAUDE.md Session Startup section**

Read `D:\CLAUDIO\CLAUDE.md` to find the `## Session Startup` section and determine exact insertion point.

- [ ] **Step 2: Add TELEGRAM_CONTEXT note**

In the `## Session Startup` section, after step 5 (the "Brief" step), add:

```markdown
> **Note:** If a Telegram bot session is active, instructions will arrive prefixed with
> `TELEGRAM_CONTEXT:`. Follow the marker protocol in the prompt exactly.
> Save any screenshots to `D:/CLAUDIO/.claudio/screenshots/` via Playwright MCP.
```

- [ ] **Step 3: Verify the addition looks correct**

Read the modified section of CLAUDE.md and confirm the note appears after the startup steps.

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: add TELEGRAM_CONTEXT note to CLAUDE.md Session Startup"
```

---

## Task 10: Smoke test and final commit

**Files:**
- No new files — integration verification

- [ ] **Step 1: Verify all required files exist**

```bash
test -f scripts/telegram-bot.py && echo "bot: OK" || echo "bot: MISSING"
test -f scripts/requirements-bot.txt && echo "requirements: OK" || echo "requirements: MISSING"
test -f scripts/start-telegram-bot.ps1 && echo "launcher: OK" || echo "launcher: MISSING"
test -f .claudio/screenshots/.gitkeep && echo "screenshots dir: OK" || echo "screenshots dir: MISSING"
grep -q "telegram-session.json" .gitignore && echo "gitignore entry: OK" || echo "gitignore entry: MISSING"
```
Expected: All lines print `OK`.

- [ ] **Step 2: Verify .env has required keys**

```bash
grep -q "TELEGRAM_BOT_TOKEN" .env && echo "token: found" || echo "token: MISSING"
grep -q "TELEGRAM_CHAT_ID" .env && echo "chat_id: found" || echo "chat_id: MISSING"
```
Expected: Both found.

- [ ] **Step 3: Full syntax check on bot file**

```bash
python -m py_compile scripts/telegram-bot.py && echo "Compile OK"
```
Expected: `Compile OK`

- [ ] **Step 4: Verify pip install works**

```bash
pip install -r scripts/requirements-bot.txt
```
Expected: Both packages install/confirm without errors.

- [ ] **Step 5: Verify git log shows all subsystem 3 commits**

```bash
git log --oneline -6
```
Expected: 5 commits visible for Subsystem 3 tasks (chore: gitignore, chore: requirements, feat: telegram-bot.py, feat: launcher, docs: CLAUDE.md note) plus prior work.

- [ ] **Step 6: Merge to main and push**

```bash
git checkout main
git merge --no-ff -m "feat: Subsystem 3 — bidirectional Telegram bot" HEAD
git push
```

---

## Self-Review

**Spec coverage check:**

| Spec requirement | Task |
|---|---|
| Python long-polling bot | Task 3 (Application.run_polling) |
| `claude -p` subprocess from CLAUDIO_ROOT | Task 4 (run_claude) |
| History re-injection (not --continue) | Task 4 (build_prompt injects full history) |
| `<<<WAITING>>>` / `<<<DONE>>>` markers | Tasks 5-6 (dispatch_output) |
| `<<<SCREENSHOT: path>>>` forwarding | Task 5 (handle_screenshots) |
| Screenshot >10MB as document | Task 5 (MAX_PHOTO_BYTES branch) |
| 5-minute subprocess timeout | Task 4 (asyncio.wait_for timeout=300) |
| Non-zero exit → error message to Telegram | Task 4 (returncode != 0 branch) |
| Security: reject non-TELEGRAM_CHAT_ID senders | Task 6 (handle_message guard) |
| Queue messages while processing | Task 6 (pending_messages list) |
| Silence until done (no keepalive) | Task 6 (only one "⏳ Working..." reply) |
| No session timeout | Task 6 (no timeout logic) |
| Response >4096 chars split | Task 5 (send_long_message) |
| CLAUDIO_ROOT via Path(__file__).parent.parent | Task 3 (config section) |
| read_registry_safe() | Task 3 |
| write_session_file() | Tasks 3, 6 |
| reset_state() | Task 3 |
| .claudio/screenshots/ created on startup | Task 3 (SCREENSHOTS_DIR.mkdir) |
| .gitignore additions | Task 1 |
| requirements-bot.txt | Task 2 |
| start-telegram-bot.ps1 with EncodedCommand | Task 8 |
| CLAUDE.md TELEGRAM_CONTEXT note | Task 9 |
| TELEGRAM_CONTEXT note in Session Startup | Task 9 |

All spec requirements covered. No placeholders.
