"""Upload already-downloaded product images to /public/uploads/products/ on FTP."""
import tempfile
from pathlib import Path
from ftplib import FTP, error_perm
from concurrent.futures import ThreadPoolExecutor, as_completed

FTP_HOST = 'ftp.kokett.ad'
FTP_USER = 'claude.kokett.ad'
FTP_PASS = 'KarenVB_13061975'
REMOTE_DIR = '/public/uploads/products/'
TMPDIR = Path(tempfile.gettempdir()) / 'kokett_images'

def upload(local: Path) -> tuple[str, bool]:
    try:
        ftp = FTP()
        ftp.connect(FTP_HOST, 21, timeout=30)
        ftp.login(FTP_USER, FTP_PASS)
        ftp.set_pasv(True)
        ftp.cwd(REMOTE_DIR)
        with open(local, 'rb') as f:
            ftp.storbinary(f'STOR {local.name}', f)
        ftp.quit()
        return local.name, True
    except Exception as e:
        return local.name, False

images = list(TMPDIR.glob('*'))
print(f'Found {len(images)} cached images to upload')

with ThreadPoolExecutor(max_workers=5) as pool:
    futures = {pool.submit(upload, img): img for img in images}
    ok = fail = 0
    for i, future in enumerate(as_completed(futures), 1):
        name, success = future.result()
        if success:
            ok += 1
            print(f'  [{i}/{len(images)}] + {name}')
        else:
            fail += 1
            print(f'  [{i}/{len(images)}] FAIL {name}')

print(f'\nDone! Uploaded: {ok}, Failed: {fail}')
