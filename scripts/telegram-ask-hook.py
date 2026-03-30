#!/usr/bin/env python3
"""
telegram-ask-hook.py — Forward AskUserQuestion prompts to Telegram.

Called by PreToolUse hook when Claude invokes AskUserQuestion.
Sends the question (+ options) to Telegram so the user can answer remotely.
"""
import json
import sys
from pathlib import Path

CLAUDIO_ROOT = Path(__file__).parent.parent


def load_tg():
    import importlib.util
    spec = importlib.util.spec_from_file_location('_tg', CLAUDIO_ROOT / 'scripts' / '_tg.py')
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def main():
    try:
        raw = sys.stdin.read().strip()
        if not raw:
            return
        hook = json.loads(raw)
        tool_input = hook.get('tool_input', {})
        question = (
            tool_input.get('question', '')
            or tool_input.get('prompt', '')
            or tool_input.get('message', '')
        )
        if not question:
            return

        msg = f'\u2753 <b>Claude is asking:</b>\n\n{question}'

        options = tool_input.get('options', [])
        if options:
            msg += '\n\n<b>Options:</b>\n' + '\n'.join(f'  \u2022 {o}' for o in options)

        tg = load_tg()
        tg.send('claudio', msg)
    except Exception:
        pass  # hooks must never crash


if __name__ == '__main__':
    main()
