# Claudio — Bidirectional Telegram Bot
## Subsystem 3 Design Spec

**Goal:** Give Adrian full remote control of Claudio from Telegram — send any natural-language instruction, get an immediate acknowledgment, receive progress questions and a final summary. Claude Code runs from `D:\CLAUDIO` with full Queen capabilities (superpowers, project agents, filesystem access).

**Chosen approach:** Python long-polling bot that bridges Telegram messages to `claude -p` subprocess calls. No keyword routing — every message goes through Claude's full reasoning. Multi-turn conversation history is re-injected on each turn (stateless, reliable). Screenshots sent via Playwright MCP are forwarded as Telegram photos.

---

## Scope

**In scope:**
- Python Telegram bot service (`scripts/telegram-bot.py`)
- Multi-turn conversation bridge (user ↔ Claude Code subprocess)
- Marker protocol for conversation control (`<<<WAITING>>>`, `<<<DONE>>>`, `<<<SCREENSHOT:>>>`)
- Screenshot forwarding via Playwright MCP
- Session state management and timeout
- Error handling (crash, timeout, long-running task)
- Launch script (`scripts/start-telegram-bot.ps1`)
- `.gitignore` additions for ephemeral bot state

**Out of scope:**
- Windows Service / Task Scheduler auto-start (can add later)
- Telegram group chats or multiple authorized users
- Voice messages or file uploads from Telegram
- Subsystem 4 (visual dashboard)
- Subsystem 5 (automated Playwright pipelines)

---

## Architecture

### Overview

```
Adrian (phone) ──Telegram──► Bot (scripts/telegram-bot.py)
                                │
                    ┌───────────┼───────────────────┐
                    │           │                   │
                "⏳ Working"   claude -p           send_photo
                 reply         subprocess          (screenshots)
                    │       (D:\CLAUDIO)
                    │           │
                    └───────────┼───────────────────┘
                                │
                         Telegram reply
                    (question, progress, done)
```

**Why Python:** `python-telegram-bot` provides reliable async long-polling and `send_photo`. The subprocess call to `claude` handles all reasoning — the bot itself has zero intelligence.

**Why re-inject history (not `--continue`):** `claude -p --continue` resumes the most recent Claude Code session on the machine. If a Queen terminal session is open, `--continue` would attach to it instead of the Telegram conversation. Re-injecting the full conversation history on each `claude -p` call is stateless and reliable regardless of what other sessions are open.

---

## Data Flow

### Simple request (no follow-up needed)

```
You: "what's the current agent status"
  → Bot: "⏳ Working on it..."
  → claude -p "TELEGRAM_CONTEXT... Instruction: what's the current agent status"
  → Claude reads registry.json, formats summary
  → Output: "ClaudeTrader: idle. WebsMami: offline. ClaudeSEO: offline. No pending tasks. <<<DONE>>>"
  → Bot strips <<<DONE>>>, sends to Telegram
  → Session state: cleared
```

### Multi-turn request (brainstorming questions)

```
You: "Create a new trading project at D:/Projects/TradingBot2 in TypeScript"
  → Bot: "⏳ Working on it..."
  → claude -p "TELEGRAM_CONTEXT... Instruction: Create a new trading..."
  → Claude activates brainstorming, asks first question
  → Output: "Before I start — should I scaffold from ClaudeTrader or start fresh? <<<WAITING>>>"
  → Bot strips <<<WAITING>>>, sends question to Telegram, saves to conversation history

You: "Start fresh, Next.js 15"
  → claude -p "TELEGRAM_CONTEXT... [full conversation history] ...Latest reply: Start fresh, Next.js 15"
  → Claude designs + creates project + Project Import Procedure
  → Output: "Done ✓ Created TradingBot2. Next.js 15 scaffold, CLAUDE.md written, ruflo initialized,
             onboarded as new Claudio project. 4 commits pushed. <<<DONE>>>"
  → Bot strips <<<DONE>>>, sends to Telegram
  → Session state: cleared
```

### Screenshot mid-task

```
  → Claude: "Here's the current design:"
             <<<SCREENSHOT: D:/CLAUDIO/.claudio/screenshots/design-v1.png>>>
             "The navbar needs work — should I adjust the spacing or color scheme first? <<<WAITING>>>"
  → Bot: sends text "Here's the current design:"
  → Bot: sends photo from path
  → Bot: sends question "The navbar needs work — should I ..."
  → Waits for reply
```

---

## Components

### `scripts/telegram-bot.py`

Single file, three logical sections:

**1. Configuration and setup**
- Read `TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID` from `.env` at repo root
- Define `CLAUDIO_ROOT = Path(__file__).parent.parent` (portable, no hardcoding)
- Define `SCREENSHOTS_DIR = CLAUDIO_ROOT / ".claudio" / "screenshots"`
- Define `SESSION_FILE = CLAUDIO_ROOT / ".claudio" / "telegram-session.json"`
- Session timeout: 30 minutes

**2. Conversation state**

In-memory state (not persisted between bot restarts):
```python
state = {
    "active": False,           # True while waiting for user reply
    "history": [],             # List of {"role": "user"|"assistant", "content": str}
    "waiting_since": None,     # datetime | None — for timeout check
    "processing": False,       # True while claude subprocess is running
}
```

On bot restart, state is cleared. User re-sends their instruction.

`reset_state()` returns: `{"active": False, "history": [], "waiting_since": None, "processing": False}`

`read_registry_safe()` reads `.claudio/registry.json` and returns a compact string summary, or `"(registry not found)"` if the file doesn't exist yet.

**3. Message handler flow**

```python
async def handle_message(update, context):
    # Security: reject any sender that is not TELEGRAM_CHAT_ID
    if str(update.effective_chat.id) != TELEGRAM_CHAT_ID:
        return

    # If claude subprocess is still running, queue the message
    if state["processing"]:
        await update.message.reply_text("⏳ Still working on the previous task — will handle this next.")
        # (queuing logic: append to pending_messages list)
        return

    # Check timeout: if waiting >30min, auto-expire
    if state["active"] and state["waiting_since"]:
        if (datetime.now() - state["waiting_since"]).seconds > 1800:
            state = reset_state()
            await update.message.reply_text("⏰ Previous conversation timed out. Starting fresh.")

    user_text = update.message.text
    await update.message.reply_text("⏳ Working on it...")

    state["processing"] = True
    state["history"].append({"role": "user", "content": user_text})

    result = await run_claude(state["history"])
    await dispatch_output(result, update, context)
```

**4. Claude subprocess invocation**

```python
async def run_claude(history: list) -> str:
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
        return "⏰ Claude timed out after 5 minutes. Please try again or simplify the request."
    if proc.returncode != 0:
        return f"❌ Error: {stderr.decode().strip()[:500]}"
    return stdout.decode().strip()
```

**5. Prompt builder**

```python
SYSTEM_WRAPPER = """TELEGRAM_CONTEXT: You are Claudio operating via Telegram remote control.
This is turn {turn} of a multi-turn conversation. Full history is below.

MARKER PROTOCOL (follow exactly):
- When you need the user's input, end your response with <<<WAITING>>> on its own line
- When you are fully done (no more questions), end with <<<DONE>>> on its own line
- To send a screenshot, include <<<SCREENSHOT: /absolute/path/to/file.png>>> on its own line
  (Claude saves screenshots to D:/CLAUDIO/.claudio/screenshots/ via Playwright MCP)
- Keep responses concise — they arrive via Telegram on a phone

CAPABILITIES: You have full Queen capabilities. You can queue tasks, spawn agents, create
projects, onboard codebases, run scripts, use Playwright MCP, and invoke superpowers skills
(brainstorming → writing-plans → executing-plans). Always follow the Memory-First Rule.

CURRENT AGENT STATE:
{registry_state}

CONVERSATION HISTORY:
{history}

LATEST INSTRUCTION: {latest_message}"""

def build_prompt(history: list) -> str:
    registry = read_registry_safe()
    formatted_history = "\n".join(
        f"{'User' if h['role']=='user' else 'Claudio'}: {h['content']}"
        for h in history[:-1]  # all but the latest
    )
    return SYSTEM_WRAPPER.format(
        turn=len(history),
        registry_state=registry,
        history=formatted_history or "(none — this is the first turn)",
        latest_message=history[-1]["content"],
    )
```

**6. Output dispatcher**

```python
async def dispatch_output(raw_output: str, update, context):
    state["processing"] = False

    # Detect terminal markers
    waiting = "<<<WAITING>>>" in raw_output
    done = "<<<DONE>>>" in raw_output

    # Extract and handle screenshots first
    output = await handle_screenshots(raw_output, update, context)

    # Strip all markers
    output = output.replace("<<<WAITING>>>", "").replace("<<<DONE>>>", "").strip()

    # Send text (split if >4096 chars)
    await send_long_message(output, update)

    # Update state
    state["history"].append({"role": "assistant", "content": raw_output})
    if waiting:
        state["active"] = True
        state["waiting_since"] = datetime.now()
    else:
        # done or fallback (no marker) — clear conversation
        state.update(reset_state())
```

**7. Screenshot handler**

```python
import re

SCREENSHOT_RE = re.compile(r"<<<SCREENSHOT:\s*(.+?)>>>")

async def handle_screenshots(text: str, update, context) -> str:
    for match in SCREENSHOT_RE.finditer(text):
        path = Path(match.group(1).strip())
        if path.exists():
            try:
                with open(path, "rb") as f:
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
```

**8. Long-running task keepalive**

A background asyncio task sends "⏳ Still working..." every 5 minutes while `state["processing"]` is True.

---

### `scripts/requirements-bot.txt`

```
python-telegram-bot>=20.0
python-dotenv>=1.0
```

`python-dotenv` loads `.env` at startup via `load_dotenv(CLAUDIO_ROOT / ".env")`. No additional API keys needed — uses only `TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID` already present from Subsystem 2.

---

### `scripts/start-telegram-bot.ps1`

```powershell
# start-telegram-bot.ps1 — Launch the Telegram bot in a new Windows Terminal tab
# Usage: pwsh scripts/start-telegram-bot.ps1

$claudioRoot = Split-Path $PSScriptRoot -Parent
$startCmd = "Set-Location '$claudioRoot'; python scripts/telegram-bot.py"
$encodedCmd = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($startCmd))
wt.exe new-tab --title "Claudio: Telegram Bot" -- pwsh -NoExit -EncodedCommand $encodedCmd
```

---

### `.claudio/telegram-session.json` (gitignored)

Written on each state change for crash recovery inspection (not used for actual session resumption — history is in-memory):

```json
{
  "active": false,
  "waiting_since": null,
  "processing": false,
  "turn_count": 0,
  "last_updated": "2026-03-26T02:00:00Z"
}
```

---

### `.claudio/screenshots/` (gitignored)

Directory where Claude saves Playwright MCP screenshots. Created by bot on startup if absent.

---

## CLAUDE.md Additions

Two additions to the root `CLAUDE.md`:

**1. In Session Startup — note about Telegram bot:**
```markdown
> **Note:** If a Telegram bot session is active, instructions will arrive prefixed with
> `TELEGRAM_CONTEXT:`. Follow the marker protocol in the prompt exactly.
> Save any screenshots to `D:/CLAUDIO/.claudio/screenshots/` via Playwright MCP.
```

**2. In Autonomous Scope — already covers this, no change needed.**

---

## Security Model

| Threat | Mitigation |
|---|---|
| Unauthorized sender | `update.effective_chat.id != TELEGRAM_CHAT_ID` → silently ignored |
| Bot token leak | Token in `.env` (gitignored), never logged or printed |
| Command injection via message text | Message passed as `claude -p` stdin argument (not shell-interpolated) |
| Runaway subprocess | 5-minute timeout kills the process |
| Path traversal in `<<<SCREENSHOT:>>>` | `path.exists()` check; bot never writes files, only reads them |
| Stale session after bot restart | History is in-memory; bot restart clears state, user re-sends |

---

## gitignore Additions

```
# Telegram bot ephemeral state
.claudio/telegram-session.json
.claudio/screenshots/
```

---

## Error Handling Reference

| Situation | Behavior |
|---|---|
| Claude subprocess crashes (non-zero exit) | Send stderr excerpt to Telegram, clear state |
| Claude hangs >5 minutes | Kill process, "Timed out" message, clear state |
| No output from Claude | Same as hang |
| Long-running task (>5 min still processing) | Send "⏳ Still working..." every 5 min |
| Response >4096 chars | Split at paragraph boundaries, send as multiple messages |
| `<<<SCREENSHOT:>>>` — file missing | Warning message, continue with text |
| `<<<SCREENSHOT:>>>` — file >10MB | Send as document instead of photo |
| Rapid messages while processing | Queue them, process after current task completes |
| No `<<<WAITING>>>` or `<<<DONE>>>` marker | Treat as done — clear conversation state |
| Session waiting >30 min with no reply | Auto-expire: next message starts a fresh conversation |
| Bot restart while conversation active | State cleared; user re-sends instruction |

---

## Installation

1. Ensure Python 3.10+ is available (`python --version`)
2. `pip install -r scripts/requirements-bot.txt`
3. Confirm `.env` has `TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID` (already set in Subsystem 2)
4. Run: `pwsh scripts/start-telegram-bot.ps1`

The bot opens in a dedicated Windows Terminal tab titled "Claudio: Telegram Bot". It polls Telegram continuously while that tab is open. To stop: close the tab or Ctrl+C.

---

## Upgrade Path

- **Auto-start on Windows login:** Add `start-telegram-bot.ps1` to Windows Task Scheduler (trigger: at logon). Out of scope for Subsystem 3.
- **Multiple projects in one instruction:** Already works — Claude parses the instruction and can queue tasks across multiple projects in one turn.
- **Subsystem 5 integration:** Playwright screenshots already flow through the `<<<SCREENSHOT:>>>` channel — no change needed when Subsystem 5 is built.
