#!/usr/bin/env python3
"""
telegram-bot.py — Claudio bidirectional Telegram bot (inbox bridge)

Receives Telegram messages and writes them to .claudio/telegram-inbox.json.
The main Claudio terminal reads that file via the UserPromptSubmit hook and
responds using its full context (all MCPs, plugins, hooks).

No claude subprocess is spawned here — this bot is a pure message bridge.
Run via: python scripts/telegram-bot.py
"""

import json
import os
import sys
import uuid
from datetime import datetime, timezone
from pathlib import Path

from dotenv import load_dotenv
from telegram import Update
from telegram.ext import Application, MessageHandler, filters, ContextTypes

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

CLAUDIO_ROOT    = Path(__file__).parent.parent
INBOX_FILE      = CLAUDIO_ROOT / ".claudio" / "telegram-inbox.json"
SCREENSHOTS_DIR = CLAUDIO_ROOT / ".claudio" / "screenshots"

load_dotenv(CLAUDIO_ROOT / ".env")

TELEGRAM_BOT_TOKEN      = os.getenv("TELEGRAM_BOT_TOKEN", "")
TELEGRAM_CHAT_ID        = os.getenv("TELEGRAM_CHAT_ID", "")
TELEGRAM_THREAD_CLAUDIO = int(os.getenv("TELEGRAM_THREAD_CLAUDIO", "0")) or None

if not TELEGRAM_BOT_TOKEN or not TELEGRAM_CHAT_ID:
    print("ERROR: TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID must be set in .env", file=sys.stderr)
    sys.exit(1)

SCREENSHOTS_DIR.mkdir(parents=True, exist_ok=True)

# ---------------------------------------------------------------------------
# Inbox helpers
# ---------------------------------------------------------------------------

def read_inbox() -> dict:
    if INBOX_FILE.exists():
        try:
            return json.loads(INBOX_FILE.read_text(encoding="utf-8"))
        except Exception:
            pass
    return {"messages": []}


def append_to_inbox(text: str, from_name: str, thread_id: int | None) -> str:
    """Append a message to the inbox file. Returns the new message ID."""
    inbox = read_inbox()
    msg_id = str(uuid.uuid4())[:8]
    inbox["messages"].append({
        "id":        msg_id,
        "text":      text,
        "from":      from_name,
        "thread_id": thread_id,
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "processed": False,
    })
    INBOX_FILE.write_text(json.dumps(inbox, indent=2, ensure_ascii=False), encoding="utf-8")
    return msg_id


# ---------------------------------------------------------------------------
# Message handler
# ---------------------------------------------------------------------------

async def handle_message(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Receive Telegram message, write to inbox, acknowledge receipt."""
    # Security: only accept messages from the configured chat
    if str(update.effective_chat.id) != TELEGRAM_CHAT_ID:
        return

    # Forum group: only accept commands from the Claudio topic thread
    if TELEGRAM_THREAD_CLAUDIO:
        incoming_thread = getattr(update.message, "message_thread_id", None)
        if incoming_thread != TELEGRAM_THREAD_CLAUDIO:
            return  # message is in an agent output thread — ignore

    if update.message is None or update.message.text is None:
        return

    user_text = update.message.text.strip()
    if not user_text:
        return

    from_name = update.effective_user.first_name if update.effective_user else "user"
    thread_id = getattr(update.message, "message_thread_id", None)

    msg_id = append_to_inbox(user_text, from_name, thread_id)
    print(f"[inbox] queued message {msg_id} from {from_name}: {user_text[:60]}")

    # Acknowledge immediately — Claudio terminal will send the real reply
    await update.message.reply_text(
        f"Got it — Claudio is processing your message (id: {msg_id})."
    )


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main():
    print(f"Starting Claudio Telegram bot — inbox bridge (root: {CLAUDIO_ROOT})")
    print(f"Inbox: {INBOX_FILE}")
    print(f"Claudio topic thread: {TELEGRAM_THREAD_CLAUDIO}")
    print("Messages will be injected into the Claudio terminal via UserPromptSubmit hook.")
    print("Bot running — polling for messages. Press Ctrl+C to stop.")

    app = Application.builder().token(TELEGRAM_BOT_TOKEN).build()
    app.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, handle_message))
    app.run_polling(allowed_updates=Update.ALL_TYPES)


if __name__ == "__main__":
    main()
