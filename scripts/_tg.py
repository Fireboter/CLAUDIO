#!/usr/bin/env python3
"""Quick Telegram sender.

Usage:
  python scripts/_tg.py <agent> <message>
  python scripts/_tg.py photo <agent> <image_path> [caption]
  python scripts/_tg.py --check   (print unprocessed inbox messages)
"""
import json, mimetypes, os, sys, time, urllib.error, urllib.request
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


def send_photo(agent: str, image_path: str, caption: str = ''):
    if not TOKEN or not CHAT:
        print('[tg] missing credentials')
        return
    thread_str = THREADS.get(agent.lower(), '')
    path = Path(image_path)
    if not path.exists():
        print(f'[tg] image not found: {image_path}')
        return

    boundary = f'----TgBoundary{int(time.time())}'
    with open(path, 'rb') as f:
        img_data = f.read()

    fields = [('chat_id', CHAT)]
    if caption:
        fields += [('caption', caption), ('parse_mode', 'HTML')]
    if thread_str:
        fields.append(('message_thread_id', thread_str))

    body = b''
    for name, val in fields:
        body += (f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{val}\r\n').encode()

    mime = mimetypes.guess_type(str(path))[0] or 'application/octet-stream'
    body += (f'--{boundary}\r\nContent-Disposition: form-data; name="photo"; filename="{path.name}"\r\nContent-Type: {mime}\r\n\r\n').encode()
    body += img_data
    body += f'\r\n--{boundary}--\r\n'.encode()

    req = urllib.request.Request(
        f'https://api.telegram.org/bot{TOKEN}/sendPhoto',
        data=body,
        headers={'Content-Type': f'multipart/form-data; boundary={boundary}'}
    )
    for attempt in range(5):
        try:
            resp = urllib.request.urlopen(req, timeout=30)
            result = json.loads(resp.read())
            print(f'[tg] photo sent to {agent} (msg {result["result"]["message_id"]})')
            return
        except urllib.error.HTTPError as e:
            if e.code == 429:
                wait = int(e.headers.get('Retry-After', 5))
                print(f'[tg] rate-limited, waiting {wait}s...')
                time.sleep(wait + 1)
            else:
                print(f'[tg] HTTP {e.code}: {e.read().decode()}')
                raise
    raise RuntimeError('send_photo failed after retries')


if __name__ == '__main__':
    if len(sys.argv) > 1 and sys.argv[1] == '--check':
        # Print unprocessed inbox messages
        p = Path('D:/CLAUDIO/.claudio/telegram-inbox.json')
        if p.exists():
            msgs = [m for m in json.loads(p.read_text()).get('messages', []) if not m.get('processed')]
            for m in msgs:
                print(f'TELEGRAM_CONTEXT [{m["from"]}]: {m["text"]}')
    elif len(sys.argv) > 1 and sys.argv[1] == 'photo':
        agent      = sys.argv[2] if len(sys.argv) > 2 else 'claudio'
        image_path = sys.argv[3] if len(sys.argv) > 3 else ''
        caption    = sys.argv[4] if len(sys.argv) > 4 else ''
        send_photo(agent, image_path, caption)
    else:
        agent = sys.argv[1] if len(sys.argv) > 1 else 'claudio'
        text  = sys.argv[2] if len(sys.argv) > 2 else '(no message)'
        send(agent, text)
