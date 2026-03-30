"""
Fix WebP images incorrectly stored with .jpg extension.
- Finds products whose Product.images JSON contains .jpg files that are actually WebP
- Renames them to .webp on FTP server
- Updates Product.images JSON in database
"""

import io
import json
import pymysql
from ftplib import FTP

# DB
DB_HOST = 'bbdd.kokett.ad'
DB_NAME = 'ddb263474'
DB_USER = 'ddb263474'
DB_PASS = 'DoMiNioKK*24'

# FTP
FTP_HOST = 'ftp.kokett.ad'
FTP_USER = 'claude.kokett.ad'
FTP_PASS = 'KarenVB_13061975'

def is_webp(data: bytes) -> bool:
    return data[:4] == b'RIFF' and data[8:12] == b'WEBP'

def main():
    print("Connecting to DB...")
    db = pymysql.connect(host=DB_HOST, user=DB_USER, password=DB_PASS, database=DB_NAME)
    cur = db.cursor()

    # Get all products with images JSON containing .jpg paths
    cur.execute("SELECT id, images FROM Product WHERE images IS NOT NULL AND images != '' AND images != '[]'")
    rows = cur.fetchall()
    print(f"Found {len(rows)} products with images JSON")

    print("Connecting to FTP...")
    ftp = FTP(FTP_HOST)
    ftp.login(FTP_USER, FTP_PASS)
    print(f"FTP connected: {ftp.getwelcome()}")

    to_fix = []  # (product_id, old_images_json, new_images_json)

    for product_id, images_json in rows:
        try:
            images = json.loads(images_json)
        except Exception:
            continue
        if not isinstance(images, list):
            continue

        new_images = list(images)
        changed = False

        for i, url in enumerate(images):
            if not isinstance(url, str) or not url.lower().endswith('.jpg'):
                continue
            if not url.startswith('/uploads/'):
                continue

            # FTP path: web URL /uploads/... → FTP /public/uploads/...
            ftp_path = '/public' + url

            # Download first 12 bytes to check format
            buf = io.BytesIO()
            try:
                ftp.retrbinary(f'RETR {ftp_path}', buf.write, blocksize=12)
            except Exception as e:
                print(f"  SKIP (download error) {url}: {e}")
                continue

            data = buf.getvalue()
            if len(data) < 12:
                print(f"  SKIP (too small) {url}")
                continue

            if not is_webp(data):
                # Genuine JPEG, skip
                continue

            # It's a WebP with .jpg extension — fix it
            new_url = url[:-4] + '.webp'
            print(f"  WebP found: {url} -> {new_url}")

            # Download full file
            full_buf = io.BytesIO()
            try:
                ftp.retrbinary(f'RETR {ftp_path}', full_buf.write)
            except Exception as e:
                print(f"  ERROR downloading full file {url}: {e}")
                continue

            full_buf.seek(0)

            # Upload as .webp
            try:
                ftp.storbinary(f'STOR /public{new_url}', full_buf)
                print(f"    Uploaded as {new_url}")
            except Exception as e:
                print(f"  ERROR uploading {new_url}: {e}")
                continue

            # Delete old .jpg
            try:
                ftp.delete(ftp_path)
                print(f"    Deleted {ftp_path}")
            except Exception as e:
                print(f"  WARN: Could not delete {ftp_path}: {e}")

            new_images[i] = new_url
            changed = True

        if changed:
            to_fix.append((product_id, json.dumps(new_images)))

    ftp.quit()

    if not to_fix:
        print("\nNo WebP-as-JPEG files found. Nothing to update.")
        db.close()
        return

    print(f"\nUpdating {len(to_fix)} products in DB...")
    for product_id, new_json in to_fix:
        cur.execute("UPDATE Product SET images = %s WHERE id = %s", (new_json, product_id))
        print(f"  Updated product {product_id}")

    db.commit()
    db.close()
    print("Done!")

if __name__ == '__main__':
    main()
