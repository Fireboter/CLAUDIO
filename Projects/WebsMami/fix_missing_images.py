"""
fix_missing_images.py — Find ProductImage records with non-p_ URLs, download + re-upload to FTP,
update DB record to the new /uploads/products/p_... URL.
"""
import ftplib
import io
import sys
import time
import urllib.request
import pymysql

DB = dict(host='bbdd.kokett.ad', user='ddb269776', password='KarenVB_13061975',
          database='ddb269776', connect_timeout=15, charset='utf8mb4')

FTP_HOST        = 'ftp.kokett.ad'
FTP_USER        = 'claude.kokett.ad'
FTP_PASS        = 'KarenVB_13061975'
FTP_UPLOADS_DIR = '/public/uploads/products'
UPLOADS_URL     = '/uploads/products'

# Fallback base for old relative /products/ paths (try old Vercel deployment or current domain)
OLD_DOMAIN = 'https://kokett.ad'

def connect_ftp():
    ftp = ftplib.FTP()
    ftp.connect(FTP_HOST, 21, timeout=30)
    ftp.login(FTP_USER, FTP_PASS)
    ftp.set_pasv(True)
    return ftp

def download(url):
    """Download bytes. Returns (bytes, ext) or (None, None)."""
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(req, timeout=20) as resp:
            data = resp.read()
        # Check it's not an HTML error page
        if data[:5] in (b'<!DOC', b'<html', b'<?php'):
            print(f'    WARN: got HTML response for {url}')
            return None, None
        url_path = url.split('?')[0]
        ext = url_path.rsplit('.', 1)[-1].lower() if '.' in url_path else 'jpg'
        if ext not in ('jpg', 'jpeg', 'png', 'webp'):
            ext = 'jpg'
        return data, ext
    except Exception as e:
        print(f'    WARN: download failed — {e}')
        return None, None

def main():
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

    conn = pymysql.connect(**DB, cursorclass=pymysql.cursors.DictCursor)
    cur = conn.cursor()

    # Find all ProductImage rows whose URL does NOT match the p_ pattern
    cur.execute("""
        SELECT pi.id, pi.productId, pi.url, pi.displayOrder, p.name
        FROM ProductImage pi
        JOIN Product p ON pi.productId = p.id
        WHERE pi.url NOT REGEXP 'p_[^/]+$'
        ORDER BY pi.productId, pi.displayOrder
    """)
    rows = cur.fetchall()
    print(f'Found {len(rows)} ProductImage records with non-p_ URLs\n')

    ftp = connect_ftp()

    fixed = failed = 0
    for row in rows:
        img_id     = row['id']
        product_id = row['productId']
        old_url    = row['url']
        name       = row['name']

        # Resolve absolute URL
        if old_url.startswith('http'):
            fetch_url = old_url
        else:
            fetch_url = OLD_DOMAIN + old_url

        print(f'[{product_id}] {name}')
        print(f'  old url: {old_url}')

        data, ext = download(fetch_url)
        if not data:
            print(f'  FAILED — could not download')
            failed += 1
            continue

        filename = f'p_{product_id}_{img_id}_{int(time.time())}.{ext}'
        try:
            ftp.storbinary(f'STOR {FTP_UPLOADS_DIR}/{filename}', io.BytesIO(data))
        except Exception as e:
            print(f'  FAILED — FTP upload error: {e}')
            failed += 1
            continue

        new_url = f'{UPLOADS_URL}/{filename}'
        cur.execute('UPDATE ProductImage SET url=%s WHERE id=%s', [new_url, img_id])
        conn.commit()

        print(f'  new url: {new_url}  ({len(data)} bytes)')
        fixed += 1

    ftp.quit()
    cur.close()
    conn.close()

    print(f'\n--- Done ---')
    print(f'Fixed:  {fixed}')
    print(f'Failed: {failed}')

if __name__ == '__main__':
    main()
