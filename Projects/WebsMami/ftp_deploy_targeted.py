"""
Targeted FTP deploy: uploads exactly 12 specified files to Kokett and Bawywear.
Uses passive mode FTP. Credentials from ftp_upload.py and ftp_upload_bawywear.py.
"""

from ftplib import FTP, error_perm
from pathlib import Path

BASE = Path(r'C:\WebsMami')

# --- Credentials ---
KOKETT = {
    'host': 'ftp.kokett.ad',
    'user': 'claude.kokett.ad',
    'password': 'KarenVB_13061975',
    'port': 21,
}

BAWYWEAR = {
    'host': 'ftp.bawywear.com',
    'user': 'ftp.bawywear.com',
    'password': 'KarenVB_13061975',
    'port': 21,
}

# --- File maps: (local_path, remote_path) ---
KOKETT_FILES = [
    (BASE / 'kokett/admin/pages/layout-header.php',   '/admin/pages/layout-header.php'),
    (BASE / 'kokett/admin/pages/product-form.php',    '/admin/pages/product-form.php'),
    (BASE / 'kokett/admin/pages/products-new.php',    '/admin/pages/products-new.php'),
    (BASE / 'kokett/admin/pages/products-edit.php',   '/admin/pages/products-edit.php'),
    (BASE / 'kokett/admin/pages/marketing.php',       '/admin/pages/marketing.php'),
    (BASE / 'kokett/admin/pages/settings.php',        '/admin/pages/settings.php'),
    (BASE / 'kokett/admin/api/products.php',          '/admin/api/products.php'),
    (BASE / 'kokett/admin/api/settings.php',          '/admin/api/settings.php'),
    (BASE / 'kokett/public/assets/css/admin.css',     '/assets/css/admin.css'),
]

BAWYWEAR_FILES = [
    (BASE / 'bawywear/admin/pages/layout-header.php', '/admin/pages/layout-header.php'),
    (BASE / 'bawywear/admin/pages/product-form.php',  '/admin/pages/product-form.php'),
    (BASE / 'bawywear/admin/pages/products-new.php',  '/admin/pages/products-new.php'),
    (BASE / 'bawywear/admin/pages/products-edit.php', '/admin/pages/products-edit.php'),
    (BASE / 'bawywear/admin/pages/marketing.php',     '/admin/pages/marketing.php'),
    (BASE / 'bawywear/admin/pages/settings.php',      '/admin/pages/settings.php'),
    (BASE / 'bawywear/admin/api/products.php',        '/admin/api/products.php'),
    (BASE / 'bawywear/admin/api/settings.php',        '/admin/api/settings.php'),
    (BASE / 'bawywear/public/assets/css/admin.css',   '/public/assets/css/admin.css'),
]


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


def upload_file(ftp: FTP, local: Path, remote: str) -> None:
    remote_dir = '/'.join(remote.split('/')[:-1])
    if remote_dir:
        ftp_mkdir(ftp, remote_dir)
    with open(local, 'rb') as f:
        ftp.storbinary(f'STOR {remote}', f)


def deploy(label: str, creds: dict, files: list) -> None:
    print(f'\n{"="*60}')
    print(f'  {label}  ->  {creds["host"]}')
    print(f'{"="*60}')
    try:
        ftp = FTP()
        ftp.connect(creds['host'], creds['port'], timeout=30)
        ftp.login(creds['user'], creds['password'])
        ftp.set_pasv(True)
        print(f'  Connected (passive mode). cwd: {ftp.pwd()}\n')
    except Exception as e:
        print(f'  CONNECT FAILED: {e}')
        return

    ok = 0
    fail = 0
    for local, remote in files:
        if not local.exists():
            print(f'  SKIP   {remote}  (local file not found: {local})')
            fail += 1
            continue
        try:
            upload_file(ftp, local, remote)
            print(f'  OK     {remote}')
            ok += 1
        except Exception as e:
            print(f'  FAIL   {remote}  →  {e}')
            fail += 1

    print(f'\n  Result: {ok} uploaded, {fail} failed')
    try:
        ftp.quit()
    except Exception:
        pass


if __name__ == '__main__':
    deploy('KOKETT',   KOKETT,   KOKETT_FILES)
    deploy('BAWYWEAR', BAWYWEAR, BAWYWEAR_FILES)
    print('\nAll done.')
