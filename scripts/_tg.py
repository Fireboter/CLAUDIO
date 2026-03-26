#!/usr/bin/env python3
"""Quick Telegram sender — called as: python scripts/_tg.py <agent> <message>"""
import json, os, sys, time, urllib.error, urllib.request
from pathlib import Path
from dotenv import load_dotenv

load_dotenv(Path(__file__).parent.parent / '.env')

TOKEN   = os.environ.get('TELEGRAM_BOT_TOKEN', '')
CHAT    = os.environ.get('TELEGRAM_CHAT_ID', '')
THREADS = {
    'claudio':      os.environ.get('TELEGRAM_THREAD_CLAUDIO', ''),
    'claudetrader': os.environ.get('TELEGRAM_THREAD_CLAUDETRADER', ''),
    'websmami':     os.environ.get('TELEGRAM_THREAD_WEBSMAMI', ''),
    'claudeseo':    os.environ.get('TELEGRAM_THREAD_CLAUDESEO', ''),
}

def _post_json(endpoint: str, payload: dict, retries: int = 5) -> dict:
    data = json.dumps(payload).encode('utf-8')
    req  = urllib.request.Request(
        f'https://api.telegram.org/bot{TOKEN}/{endpoint}',
        data=data, headers={'Content-Type': 'application/json'}
    )
    for attempt in range(retries):
        try:
            resp = urllib.request.urlopen(req, timeout=10)
            return json.loads(resp.read())
        except urllib.error.HTTPError as e:
            if e.code == 429:
                wait = int(e.headers.get('Retry-After', 5))
                print(f'[tg] rate-limited, waiting {wait}s...')
                time.sleep(wait + 1)
            else:
                raise
    raise RuntimeError(f'Failed after {retries} retries')

def send(agent: str, text: str):
    if not TOKEN or not CHAT:
        print('[tg] missing credentials')
        return
    thread_str = THREADS.get(agent.lower(), '')
    payload = {'chat_id': CHAT, 'text': text, 'parse_mode': 'HTML'}
    if thread_str:
        payload['message_thread_id'] = int(thread_str)
    result = _post_json('sendMessage', payload)
    print(f'[tg] sent to {agent} (msg {result["result"]["message_id"]})')

if __name__ == '__main__':
    agent = sys.argv[1] if len(sys.argv) > 1 else 'claudio'
    text  = sys.argv[2] if len(sys.argv) > 2 else '(no message)'
    send(agent, text)
