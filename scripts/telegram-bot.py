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
from datetime import datetime, timezone
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

TELEGRAM_BOT_TOKEN   = os.getenv("TELEGRAM_BOT_TOKEN", "")
TELEGRAM_CHAT_ID     = os.getenv("TELEGRAM_CHAT_ID", "")
TELEGRAM_THREAD_CLAUDIO = int(os.getenv("TELEGRAM_THREAD_CLAUDIO", "0")) or None

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
            "last_updated": datetime.now(timezone.utc).isoformat(),
        }
        with open(SESSION_FILE, "w", encoding="utf-8") as f:
            json.dump(summary, f, indent=2)
    except Exception:
        pass  # session file is informational only — never fatal


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
    if not history:
        raise ValueError("build_prompt called with empty history")
    registry = read_registry_safe()
    formatted_history = "\n".join(
        f"{'User' if h['role'] == 'user' else 'Claudio'}: {h['content']}"
        for h in history[:-1]  # all but the latest
    )

    # Escape curly braces in all user-controlled strings so str.format() cannot
    # interpret them as placeholders (prompt injection / KeyError prevention)
    def _esc(s: str) -> str:
        return s.replace("{", "{{").replace("}", "}}")

    history_str = _esc(formatted_history) or "(none — this is the first turn)"
    registry_str = _esc(registry)
    latest_str = _esc(history[-1]["content"])

    return SYSTEM_WRAPPER.format(
        turn=len(history),
        registry_state=registry_str,
        history=history_str,
        latest_message=latest_str,
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


# ---------------------------------------------------------------------------
# Screenshot handler
# ---------------------------------------------------------------------------

SCREENSHOT_RE = re.compile(r"<<<SCREENSHOT:\s*(.+?)>>>")
MAX_PHOTO_BYTES = 10 * 1024 * 1024  # 10 MB Telegram limit for photos


async def handle_screenshots(text: str, update: Update, context: ContextTypes.DEFAULT_TYPE) -> str:
    """Send each <<<SCREENSHOT: path>>> as a Telegram photo/document, remove markers from text."""
    thread_id = getattr(update.message, 'message_thread_id', None)
    for match in SCREENSHOT_RE.finditer(text):
        path = Path(match.group(1).strip())
        if path.exists():
            try:
                file_size = path.stat().st_size
                with open(path, "rb") as f:
                    if file_size > MAX_PHOTO_BYTES:
                        kwargs = {"chat_id": update.effective_chat.id, "document": f, "filename": path.name}
                        if thread_id:
                            kwargs["message_thread_id"] = thread_id
                        await context.bot.send_document(**kwargs)
                    else:
                        kwargs = {"chat_id": update.effective_chat.id, "photo": f}
                        if thread_id:
                            kwargs["message_thread_id"] = thread_id
                        await context.bot.send_photo(**kwargs)
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
        state["waiting_since"] = datetime.now(timezone.utc)
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
    result = await run_claude(list(state["history"]))  # pass a copy to avoid mutation issues
    await dispatch_output(result, update, context)


# ---------------------------------------------------------------------------
# Message handler
# ---------------------------------------------------------------------------

async def handle_message(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Entry point for all incoming Telegram messages."""
    # Security: only accept messages from the configured chat
    if str(update.effective_chat.id) != TELEGRAM_CHAT_ID:
        return

    # In a forum group: only process commands sent in the Claudio topic thread
    if TELEGRAM_THREAD_CLAUDIO:
        incoming_thread = getattr(update.message, 'message_thread_id', None)
        if incoming_thread != TELEGRAM_THREAD_CLAUDIO:
            return  # message is in an agent output thread — ignore

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
