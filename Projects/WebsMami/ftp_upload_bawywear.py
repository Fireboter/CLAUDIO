"""
FTP upload script for Bawywear.com deployment.
Flattens the dev structure into a production-ready flat layout.

Dev structure → Production (FTP root = /):
  bawywear/public/*  → /public/
  bawywear/* (excl. public/)  → /
  shared/            → /shared/

Run: python ftp_upload_bawywear.py
"""

import os
import sys
from ftplib import FTP, error_perm
from pathlib import Path

# FTP credentials
FTP_HOST = 'ftp.bawywear.com'
FTP_USER = 'ftp.bawywear.com'
FTP_PASS = 'KarenVB_13061975'
FTP_PORT = 21

# Local paths
SCRIPT_DIR   = Path(__file__).parent
BAWYWEAR_DIR = SCRIPT_DIR / 'bawywear'
SHARED_DIR   = SCRIPT_DIR / 'shared'

# Files/dirs to skip
SKIP_DIRS       = {'node_modules', '.git', '__pycache__', '.idea'}
SKIP_FILES      = {'.DS_Store', 'Thumbs.db', 'ftp_upload.py', 'ftp_upload_bawywear.py', '.gitignore'}
SKIP_EXTENSIONS = {'.bak', '.pyc'}


def ftp_mkdir(ftp: FTP, path: str) -> None:
    """Create directory (and parents) on FTP, ignore if exists."""
    parts = [p for p in path.split('/') if p]
    current = ''
    for part in parts:
        current += '/' + part
        try:
            ftp.mkd(current)
        except error_perm:
            pass  # already exists


def ftp_upload_file(ftp: FTP, local_path: Path, remote_path: str) -> None:
    """Upload a single file to the FTP server."""
    remote_dir = '/'.join(remote_path.split('/')[:-1])
    if remote_dir:
        ftp_mkdir(ftp, remote_dir)
    with open(local_path, 'rb') as f:
        ftp.storbinary(f'STOR {remote_path}', f)


def collect_files():
    """
    Returns list of (local_path, remote_path) tuples.
    Applies the dev→flat mapping.
    """
    files = []

    # 1. bawywear/public/* → /public/
    public_dir = BAWYWEAR_DIR / 'public'
    if public_dir.exists():
        for local in public_dir.rglob('*'):
            if local.is_file():
                if local.name in SKIP_FILES or local.suffix in SKIP_EXTENSIONS:
                    continue
                relative = local.relative_to(public_dir)
                remote = '/public/' + str(relative).replace('\\', '/')
                files.append((local, remote))

    # 2. bawywear/* (excluding public/) → /public/
    for item in BAWYWEAR_DIR.iterdir():
        if item.name == 'public':
            continue
        if item.name in SKIP_DIRS or item.name in SKIP_FILES:
            continue
        if item.is_file():
            if item.suffix in SKIP_EXTENSIONS:
                continue
            files.append((item, '/public/' + item.name))
            if item.name == 'config.php':
                files.append((item, '/config.php'))  # also at FTP root (loaded by index.php)
        elif item.is_dir():
            if item.name in SKIP_DIRS:
                continue
            for local in item.rglob('*'):
                if local.is_file():
                    if local.name in SKIP_FILES or local.suffix in SKIP_EXTENSIONS:
                        continue
                    relative = local.relative_to(BAWYWEAR_DIR)
                    remote = '/' + str(relative).replace('\\', '/')  # FTP root (outside web root)
                    files.append((local, remote))

    # 3. shared/ → /shared/
    if SHARED_DIR.exists():
        for local in SHARED_DIR.rglob('*'):
            if local.is_file():
                if local.name in SKIP_FILES or local.suffix in SKIP_EXTENSIONS or local.name in SKIP_DIRS:
                    continue
                relative = local.relative_to(SCRIPT_DIR)
                remote = '/' + str(relative).replace('\\', '/')
                files.append((local, remote))

    return files


PRESERVE_DIRS = {'/public/uploads'}  # never deleted during deploy

def delete_all_remote(ftp: FTP) -> None:
    """Recursively delete all files and directories at FTP root, preserving uploads."""
    def delete_dir(path):
        if any(path.startswith(p) for p in PRESERVE_DIRS):
            print(f'  skipping (preserved): {path}')
            return
        try:
            items = ftp.nlst(path)
        except Exception:
            return
        for item in items:
            name = item.split('/')[-1]
            if name in ('.', '..', ''):
                continue
            full = path.rstrip('/') + '/' + name
            if any(full.startswith(p) for p in PRESERVE_DIRS):
                print(f'  skipping (preserved): {full}')
                continue
            try:
                ftp.delete(full)
                print(f'  deleted file: {full}')
            except error_perm:
                delete_dir(full)
                try:
                    ftp.rmd(full)
                    print(f'  deleted dir:  {full}')
                except Exception as e:
                    print(f'  could not remove dir {full}: {e}')
    delete_dir('/')


def main():
    print(f'Connecting to {FTP_HOST}...')
    try:
        ftp = FTP()
        ftp.connect(FTP_HOST, FTP_PORT, timeout=30)
        ftp.login(FTP_USER, FTP_PASS)
    except Exception as e:
        print(f'ERROR: Could not connect: {e}')
        sys.exit(1)

    print(f'Connected! Current dir: {ftp.pwd()}')
    ftp.set_pasv(True)

    # List existing files
    print('\nExisting files on server:')
    try:
        existing = ftp.nlst('/')
        for f in existing[:20]:
            print(f'  {f}')
        if len(existing) > 20:
            print(f'  ... and {len(existing)-20} more')
    except Exception as e:
        print(f'  (could not list: {e})')

    # Collect files to upload
    files = collect_files()
    print(f'\n{len(files)} files to upload.')

    # Confirm
    ans = input('\nDelete all existing server files and upload? [y/N] ')
    if ans.lower() != 'y':
        print('Aborted.')
        ftp.quit()
        sys.exit(0)

    # Delete existing files
    print('\nDeleting existing files...')
    delete_all_remote(ftp)

    # Create uploads dirs
    print('\nCreating /public/uploads/ directories...')
    ftp_mkdir(ftp, '/public')
    ftp_mkdir(ftp, '/public/uploads')
    ftp_mkdir(ftp, '/public/uploads/products')
    ftp_mkdir(ftp, '/public/uploads/collections')

    # Upload files
    print(f'\nUploading {len(files)} files...')
    uploaded = 0
    failed = 0
    for local_path, remote_path in files:
        try:
            ftp_upload_file(ftp, local_path, remote_path)
            print(f'  + {remote_path}')
            uploaded += 1
        except Exception as e:
            print(f'  FAIL {remote_path}: {e}')
            failed += 1

    print(f'\nDone! Uploaded: {uploaded}, Failed: {failed}')
    ftp.quit()

    print('\nNext steps:')
    print('1. Fill in DB_NAME, DB_USER, DB_PASS, REDSYS_*, SMTP_*, ADMIN_SECRET in config.php on server')
    print('2. Visit https://bawywear.com/setup.php to create DB tables')
    print('3. Visit https://bawywear.com/migrate-preorder.php to add isPreorder columns')
    print('4. Delete setup.php and migrate-preorder.php after use')


if __name__ == '__main__':
    main()
