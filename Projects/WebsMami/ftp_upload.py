"""
FTP upload script for Kokett.ad deployment.
Target: /public/ (web root on DonDominio), /shared/ (outside web root)

Mapping:
  kokett/public/*  → /public/   (web root)
  kokett/ root files → /public/  (web-accessible: config.php, setup.php, etc.)
  kokett/ subdirs → / (FTP root, outside web root: pages/, admin/, api/, lang/)
  shared/          → /shared/   (outside web root, secure)
"""

import os
import sys
from ftplib import FTP, error_perm
from pathlib import Path

# Force UTF-8 output to handle filenames with special chars
sys.stdout.reconfigure(encoding='utf-8', errors='replace')

FTP_HOST = 'ftp.kokett.ad'
FTP_USER = 'claude.kokett.ad'
FTP_PASS = 'KarenVB_13061975'
FTP_PORT = 21

SCRIPT_DIR  = Path(__file__).parent
KOKETT_DIR  = SCRIPT_DIR / 'kokett'
SHARED_DIR  = SCRIPT_DIR / 'shared'

SKIP_DIRS  = {'node_modules', '.git', '__pycache__'}
SKIP_FILES = {'.DS_Store', 'Thumbs.db', 'ftp_upload.py'}
SKIP_NAMES = {'.gitignore'}


def ftp_mkdir(ftp: FTP, path: str) -> None:
    parts = [p for p in path.split('/') if p]
    current = ''
    for part in parts:
        current += '/' + part
        try:
            ftp.mkd(current)
        except error_perm:
            pass


def upload_file(ftp: FTP, local: Path, remote: str) -> None:
    remote_dir = '/'.join(remote.split('/')[:-1])
    if remote_dir:
        ftp_mkdir(ftp, remote_dir)
    with open(local, 'rb') as f:
        ftp.storbinary(f'STOR {remote}', f)


def collect_files():
    files = []

    # kokett/public/* → /public/
    public_dir = KOKETT_DIR / 'public'
    if public_dir.exists():
        for local in public_dir.rglob('*'):
            if local.is_file() and local.name not in SKIP_FILES and local.name not in SKIP_NAMES:
                rel = local.relative_to(public_dir)
                files.append((local, '/public/' + str(rel).replace('\\', '/')))

    # kokett/ root files → /public/ (web-accessible); root subdirs → FTP root (outside web root)
    for item in KOKETT_DIR.iterdir():
        if item.name == 'public' or item.name in SKIP_DIRS or item.name in SKIP_FILES:
            continue
        if item.is_file():
            if item.name not in SKIP_NAMES:
                files.append((item, '/public/' + item.name))
                if item.name == 'config.php':
                    files.append((item, '/config.php'))  # also at FTP root
        elif item.is_dir() and item.name not in SKIP_DIRS:
            for local in item.rglob('*'):
                if local.is_file() and local.name not in SKIP_FILES and local.name not in SKIP_NAMES:
                    rel = local.relative_to(KOKETT_DIR)
                    files.append((local, '/' + str(rel).replace('\\', '/')))

    # shared/ → /shared/
    if SHARED_DIR.exists():
        for local in SHARED_DIR.rglob('*'):
            if local.is_file() and local.name not in SKIP_FILES:
                rel = local.relative_to(SCRIPT_DIR)
                files.append((local, '/' + str(rel).replace('\\', '/')))

    return files


PRESERVE_DIRS = {'/public/uploads'}  # never deleted during deploy

def delete_in(ftp: FTP, path: str) -> None:
    """Recursively delete contents of a directory, preserving PRESERVE_DIRS."""
    if any(path.startswith(p) for p in PRESERVE_DIRS):
        print(f'  skipping (preserved): {path}')
        return
    try:
        items = []
        ftp.retrlines(f'LIST {path}', items.append)
    except Exception:
        return

    for line in items:
        parts = line.split()
        if not parts:
            continue
        name = parts[-1]
        if name in ('.', '..'):
            continue
        full = path.rstrip('/') + '/' + name
        if any(full.startswith(p) for p in PRESERVE_DIRS):
            print(f'  skipping (preserved): {full}')
            continue
        is_dir = line.startswith('d')
        if is_dir:
            delete_in(ftp, full)
            try:
                ftp.rmd(full)
                print(f'  rmdir {full}')
            except Exception as e:
                print(f'  could not rmdir {full}: {e}')
        else:
            try:
                ftp.delete(full)
                print(f'  del   {full}')
            except Exception as e:
                print(f'  could not del {full}: {e}')


def main():
    print(f'Connecting to {FTP_HOST} as {FTP_USER}...')
    ftp = FTP(timeout=30)
    ftp.connect(FTP_HOST, FTP_PORT)
    ftp.login(FTP_USER, FTP_PASS)
    ftp.set_pasv(True)
    print(f'Connected. PWD: {ftp.pwd()}')

    files = collect_files()
    print(f'\n{len(files)} files to upload.')
    print('Web root: /public/ | Shared library: /shared/')

    print('\nExisting /public/ contents:')
    try:
        items = []
        ftp.retrlines('LIST /public', items.append)
        for line in items[:15]:
            print(' ', line)
        if len(items) > 15:
            print(f'  ... and {len(items)-15} more')
    except Exception as e:
        print(f'  (error: {e})')

    ans = input('\nClear /public/ and upload all files? [y/N] ')
    if ans.lower() != 'y':
        print('Aborted.')
        ftp.quit()
        return

    print('\nClearing /public/...')
    delete_in(ftp, '/public')

    print('\nCreating /shared/ and /public/uploads/ directories...')
    ftp_mkdir(ftp, '/shared')
    ftp_mkdir(ftp, '/public/uploads')
    ftp_mkdir(ftp, '/public/uploads/products')
    ftp_mkdir(ftp, '/public/uploads/collections')

    print(f'\nUploading {len(files)} files...')
    ok = fail = 0
    for local, remote in files:
        try:
            upload_file(ftp, local, remote)
            print(f'  + {remote}')
            ok += 1
        except Exception as e:
            print(f'  FAIL {remote}: {e}')
            fail += 1

    ftp.quit()
    print(f'\nDone! Uploaded: {ok}, Failed: {fail}')
    print('\nNext step: visit https://kokett.ad/setup.php to create DB tables, then DELETE it.')


if __name__ == '__main__':
    main()
