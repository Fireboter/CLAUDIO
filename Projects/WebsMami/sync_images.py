"""
Downloads all product images from CDN in parallel,
uploads them to FTP /uploads/products/, updates DB paths.
"""

import asyncio
import json
import os
import re
import tempfile
import threading
from ftplib import FTP, error_perm
from pathlib import Path
from urllib.parse import urlparse
from concurrent.futures import ThreadPoolExecutor, as_completed

import aiohttp
import pymysql

# ── Config ────────────────────────────────────────────────────────────────────
DB = dict(host='bbdd.kokett.ad', user='ddb263474', password='DoMiNioKK*24',
          db='ddb263474', charset='utf8mb4')

FTP_HOST = 'ftp.kokett.ad'
FTP_USER = 'claude.kokett.ad'
FTP_PASS = 'KarenVB_13061975'
FTP_DIR  = '/public/uploads/products/'

DOWNLOAD_WORKERS = 20   # parallel HTTP downloads
FTP_WORKERS      = 5    # parallel FTP connections
TMPDIR = Path(tempfile.gettempdir()) / 'kokett_images'

# ── Helpers ───────────────────────────────────────────────────────────────────

def safe_filename(url: str) -> str:
    """Extract filename from URL, strip query string."""
    path = urlparse(url).path
    name = path.split('/')[-1]
    # sanitize
    name = re.sub(r'[^\w.\-]', '_', name)
    return name or 'image.jpg'


def ftp_upload(local_path: Path, remote_name: str) -> bool:
    """Upload one file via a fresh FTP connection."""
    try:
        ftp = FTP()
        ftp.connect(FTP_HOST, 21, timeout=30)
        ftp.login(FTP_USER, FTP_PASS)
        ftp.set_pasv(True)
        try:
            ftp.mkd(FTP_DIR)
        except error_perm:
            pass
        ftp.cwd(FTP_DIR)
        with open(local_path, 'rb') as f:
            ftp.storbinary(f'STOR {remote_name}', f)
        ftp.quit()
        return True
    except Exception as e:
        print(f'  FTP FAIL {remote_name}: {e}')
        return False


async def download_image(session: aiohttp.ClientSession, url: str, dest: Path) -> bool:
    """Download one image to dest."""
    try:
        async with session.get(url, timeout=aiohttp.ClientTimeout(total=30)) as r:
            if r.status == 200:
                dest.write_bytes(await r.read())
                return True
            print(f'  HTTP {r.status} {url}')
            return False
    except Exception as e:
        print(f'  DL FAIL {url}: {e}')
        return False


async def download_all(tasks: list[tuple[str, Path]]) -> dict[str, bool]:
    """Download all images concurrently."""
    sem = asyncio.Semaphore(DOWNLOAD_WORKERS)
    results = {}

    async def bounded(url, dest):
        async with sem:
            results[url] = await download_image(session, url, dest)

    async with aiohttp.ClientSession() as session:
        await asyncio.gather(*(bounded(url, dest) for url, dest in tasks))
    return results

# ── Main ──────────────────────────────────────────────────────────────────────

def main():
    TMPDIR.mkdir(exist_ok=True)

    # 1. Fetch all products with external image URLs
    conn = pymysql.connect(**DB)
    cur = conn.cursor()
    cur.execute('SELECT id, images FROM Product')
    rows = cur.fetchall()

    # Build list: (product_id, image_url, local_filename)
    to_process = []
    for product_id, images_json in rows:
        try:
            imgs = json.loads(images_json or '[]') if images_json else []
        except Exception:
            imgs = []
        url = imgs[0] if imgs else None
        if url and (url.startswith('http://') or url.startswith('https://')):
            filename = safe_filename(url)
            to_process.append((product_id, url, filename))

    print(f'{len(to_process)} products with remote images to migrate')

    # 2. Download all images in parallel
    print(f'\nDownloading with {DOWNLOAD_WORKERS} workers...')
    dl_tasks = [(url, TMPDIR / filename) for _, url, filename in to_process]
    dl_results = asyncio.run(download_all(dl_tasks))

    downloaded = sum(1 for ok in dl_results.values() if ok)
    print(f'Downloaded: {downloaded}/{len(dl_tasks)}')

    # 3. Upload to FTP in parallel
    print(f'\nUploading via FTP with {FTP_WORKERS} workers...')
    upload_map = {}  # filename → success
    upload_tasks = []
    for _, url, filename in to_process:
        local = TMPDIR / filename
        if dl_results.get(url) and local.exists():
            upload_tasks.append((local, filename))

    with ThreadPoolExecutor(max_workers=FTP_WORKERS) as pool:
        futures = {pool.submit(ftp_upload, local, name): name for local, name in upload_tasks}
        for i, future in enumerate(as_completed(futures), 1):
            name = futures[future]
            ok = future.result()
            upload_map[name] = ok
            if ok:
                print(f'  [{i}/{len(upload_tasks)}] + {name}')

    uploaded = sum(1 for ok in upload_map.values() if ok)
    print(f'Uploaded: {uploaded}/{len(upload_tasks)}')

    # 4. Update DB paths
    print('\nUpdating DB...')
    updated = 0
    for product_id, url, filename in to_process:
        if upload_map.get(filename):
            new_path = f'/uploads/products/{filename}'
            new_images = json.dumps([new_path], ensure_ascii=False)
            cur.execute('UPDATE Product SET images = %s WHERE id = %s', (new_images, product_id))
            updated += 1

    conn.commit()
    cur.close()
    conn.close()

    print(f'DB updated: {updated} products')
    print('\nDone! All images are now self-hosted.')


if __name__ == '__main__':
    main()
