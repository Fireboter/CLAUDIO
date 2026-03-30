"""
FTP upload - uploads without deleting first.
Our .htaccess + index.php will override the existing WP install.
"""

import sys
from ftplib import FTP, error_perm
from pathlib import Path

sys.stdout.reconfigure(encoding='utf-8', errors='replace')

FTP_HOST = 'ftp.kokett.ad'
FTP_USER = 'claude.kokett.ad'
FTP_PASS = 'KarenVB_13061975'

SCRIPT_DIR = Path(__file__).parent
KOKETT_DIR = SCRIPT_DIR / 'kokett'
SHARED_DIR = SCRIPT_DIR / 'shared'

SKIP_DIRS  = {'node_modules', '.git', '__pycache__'}
SKIP_FILES = {'.DS_Store', 'Thumbs.db', 'ftp_upload.py',
              'ftp_upload_nodeletion.py', 'ftp_upload_log.py'}
SKIP_NAMES = set()


def ftp_mkdir(ftp, path):
    parts = [p for p in path.split('/') if p]
    cur = ''
    for part in parts:
        cur += '/' + part
        try:
            ftp.mkd(cur)
        except error_perm:
            pass


def upload_file(ftp, local, remote):
    remote_dir = '/'.join(remote.split('/')[:-1])
    if remote_dir:
        ftp_mkdir(ftp, remote_dir)
    with open(local, 'rb') as f:
        ftp.storbinary(f'STOR {remote}', f)


def collect_files():
    files = []

    # kokett/public/* → /public/
    pub = KOKETT_DIR / 'public'
    if pub.exists():
        for local in pub.rglob('*'):
            if local.is_file() and local.name not in SKIP_FILES:
                rel = local.relative_to(pub)
                files.append((local, '/public/' + str(rel).replace('\\', '/')))

    # kokett/ root files → /public/ (web-accessible); root dirs → FTP root (outside web root)
    for item in KOKETT_DIR.iterdir():
        if item.name in ('public', *SKIP_DIRS) or item.name in SKIP_FILES:
            continue
        if item.is_file():
            files.append((item, '/public/' + item.name))
            if item.name == 'config.php':
                files.append((item, '/config.php'))  # also at FTP root (loaded by index.php)
        elif item.is_dir() and item.name not in SKIP_DIRS:
            for local in item.rglob('*'):
                if local.is_file() and local.name not in SKIP_FILES:
                    rel = local.relative_to(KOKETT_DIR)
                    files.append((local, '/' + str(rel).replace('\\', '/')))

    # shared/ → /shared/ (outside web root)
    if SHARED_DIR.exists():
        for local in SHARED_DIR.rglob('*'):
            if local.is_file() and local.name not in SKIP_FILES:
                rel = local.relative_to(SCRIPT_DIR)
                files.append((local, '/' + str(rel).replace('\\', '/')))

    return files


ftp = FTP(timeout=30)
ftp.connect(FTP_HOST, 21)
ftp.login(FTP_USER, FTP_PASS)
ftp.set_pasv(True)
print(f'Connected. PWD: {ftp.pwd()}')

files = collect_files()
print(f'{len(files)} files to upload...\n')

ftp_mkdir(ftp, '/shared')
ftp_mkdir(ftp, '/public/uploads')
ftp_mkdir(ftp, '/public/uploads/products')

ok = fail = 0
for local, remote in files:
    try:
        upload_file(ftp, local, remote)
        print(f'+ {remote}')
        ok += 1
    except Exception as e:
        print(f'FAIL {remote}: {e}')
        fail += 1

ftp.quit()
print(f'\nDone! OK={ok} FAIL={fail}')
print('Next: switch DNS to DonDominio, then visit /setup.php to create DB tables.')
