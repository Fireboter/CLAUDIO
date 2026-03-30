# import_products.py Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Write `import_products.py` at repo root to restore lost `ProductVariant` and `ProductImage` data from `ProductosKokett.csv`, downloading images from Shopify CDN and uploading them to FTP.

**Architecture:** Single self-contained Python script following the `migrate_db.py` pattern — reads CSV, connects to MySQL via `pymysql`, uploads images via `ftplib`. Supports `--dry-run` for safe testing (skips all DB writes and FTP uploads). Ends with a live DB verification report.

**Tech Stack:** Python 3, pymysql, ftplib, urllib.request, csv (all stdlib except pymysql)

**Spec:** `docs/superpowers/specs/2026-03-25-import-products-design.md`

---

## File Map

| File | Action | Purpose |
|------|--------|---------|
| `import_products.py` | Create | Entire script — helpers, DB/FTP logic, import loop, verification |

---

### Task 1: Scaffold with pure helpers

**Files:**
- Create: `import_products.py`

- [ ] **Step 1: Write the file with imports and three pure helper functions**

```python
"""
import_products.py — Restore ProductVariant + ProductImage from CSV.
Usage: python import_products.py [--dry-run]
"""
import csv
import ftplib
import io
import sys
import time
import urllib.request

import pymysql

# ── Config ────────────────────────────────────────────────────────────────────
CSV_PATH = r'C:\Users\adria\Downloads\ProductosKokett.csv'

DB = dict(host='bbdd.kokett.ad', user='ddb269776', password='KarenVB_13061975',
          database='ddb269776', connect_timeout=15, charset='utf8mb4')

FTP_HOST        = 'ftp.kokett.ad'
FTP_USER        = 'claude.kokett.ad'
FTP_PASS        = 'KarenVB_13061975'
FTP_UPLOADS_DIR = '/public/uploads/products'
UPLOADS_URL     = '/uploads/products'

# ── Pure helpers ──────────────────────────────────────────────────────────────

def parse_sizes(talla_str):
    """'xs; s; m; l' → ['xs', 's', 'm', 'l'].  '' → []."""
    if not talla_str or not talla_str.strip():
        return []
    return [s.strip() for s in talla_str.split(';') if s.strip()]

def clamp_stock(raw):
    """Parse stock value, clamp negatives to 0."""
    try:
        return max(0, int(float(raw)))
    except (ValueError, TypeError):
        return 0

def make_filename(product_id, ext, ts=None):
    """p_{id}_{ts}.{ext} — satisfies product.php regex /p_[^/]+$/"""
    if ts is None:
        ts = int(time.time())
    if ext not in ('jpg', 'jpeg', 'png', 'webp'):
        ext = 'jpg'
    return f'p_{product_id}_{ts}.{ext}'


if __name__ == '__main__':
    pass  # filled in later
```

- [ ] **Step 2: Run helper smoke tests**

```bash
cd C:/WebsMami
python -c "
import import_products as m
assert m.parse_sizes('xs; s; m; l') == ['xs', 's', 'm', 'l'], 'parse normal'
assert m.parse_sizes('')             == [],                    'parse empty'
assert m.parse_sizes('   ')         == [],                    'parse whitespace'
assert m.clamp_stock('-2')          == 0,                     'clamp negative'
assert m.clamp_stock('0')           == 0,                     'clamp zero'
assert m.clamp_stock('10')          == 10,                    'clamp positive'
assert m.make_filename(42, 'jpg', 100) == 'p_42_100.jpg',     'filename jpg'
assert m.make_filename(1,  'gif', 1)   == 'p_1_1.jpg',        'filename bad ext'
print('All helper tests PASSED')
"
```
Expected: `All helper tests PASSED`

- [ ] **Step 3: Commit**

```bash
cd C:/WebsMami
git add import_products.py
git commit -m "feat: scaffold import_products.py with parse_sizes, clamp_stock, make_filename"
```

---

### Task 2: DB connection + customValues column fix

**Files:**
- Modify: `import_products.py`

- [ ] **Step 1: Add connect_db and ensure_custom_values_column after the helpers**

```python
# ── DB ────────────────────────────────────────────────────────────────────────

def connect_db():
    return pymysql.connect(**DB, cursorclass=pymysql.cursors.DictCursor)

def ensure_custom_values_column(conn):
    """
    ALTER TABLE ProductVariant ADD COLUMN customValues TEXT DEFAULT NULL.
    setup.php never creates this column, but admin/api/products.php needs it.
    Must run BEFORE any ProductVariant INSERTs.
    Not called in --dry-run mode.
    """
    cur = conn.cursor()
    try:
        cur.execute('ALTER TABLE ProductVariant ADD COLUMN customValues TEXT DEFAULT NULL')
        conn.commit()
        print('  Added customValues column to ProductVariant')
    except pymysql.err.OperationalError as e:
        if 'Duplicate column name' in str(e):
            print('  customValues already exists — OK')
        else:
            raise
    finally:
        cur.close()
```

- [ ] **Step 2: Test DB connection and ALTER TABLE**

```bash
cd C:/WebsMami
python -c "
import import_products as m
conn = m.connect_db()
cur = conn.cursor()
cur.execute('SELECT COUNT(*) as n FROM Product')
print('Products in DB:', cur.fetchone()['n'])
m.ensure_custom_values_column(conn)
conn.close()
print('DB test PASSED')
"
```
Expected: prints product count, "customValues already exists — OK" (or "Added..."), then `DB test PASSED`

- [ ] **Step 3: Commit**

```bash
git add import_products.py
git commit -m "feat: add connect_db and ensure_custom_values_column to import_products.py"
```

---

### Task 3: FTP + image download/upload

**Files:**
- Modify: `import_products.py`

- [ ] **Step 1: Add connect_ftp, download_image, upload_image after the DB section**

```python
# ── FTP + images ──────────────────────────────────────────────────────────────

def connect_ftp():
    ftp = ftplib.FTP()
    ftp.connect(FTP_HOST, 21, timeout=30)
    ftp.login(FTP_USER, FTP_PASS)
    ftp.set_pasv(True)
    for d in ['/public/uploads', '/public/uploads/products']:
        try:
            ftp.mkd(d)
        except ftplib.error_perm:
            pass  # already exists
    return ftp

def download_image(url):
    """Download image bytes from URL. Returns (bytes, ext) or (None, None) on failure."""
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(req, timeout=20) as resp:
            data = resp.read()
        url_path = url.split('?')[0]
        ext = url_path.rsplit('.', 1)[-1].lower() if '.' in url_path else 'jpg'
        if ext not in ('jpg', 'jpeg', 'png', 'webp'):
            ext = 'jpg'
        return data, ext
    except Exception as e:
        print(f'    WARN: download failed — {e}')
        return None, None

def upload_image(ftp, data, filename):
    """Upload bytes to FTP and return the DB-stored URL."""
    ftp.storbinary(f'STOR {FTP_UPLOADS_DIR}/{filename}', io.BytesIO(data))
    return f'{UPLOADS_URL}/{filename}'
```

- [ ] **Step 2: Test FTP connection**

```bash
cd C:/WebsMami
python -c "
import import_products as m
ftp = m.connect_ftp()
print('FTP connected OK')
ftp.quit()
"
```
Expected: `FTP connected OK`

- [ ] **Step 3: Test image download — check bytes are real JPEG, not an HTML error page**

```bash
cd C:/WebsMami
python -c "
import import_products as m
# Use first URL from CSV — this is a real Shopify CDN image
data, ext = m.download_image('https://cdn.shopify.com/s/files/1/0693/3797/2933/files/camiseta-mono.jpg?v=1752432949')
assert data is not None,          'no data returned'
assert len(data) > 1000,          'response too small'
assert data[:3] == b'\xff\xd8\xff', 'not a valid JPEG (got HTML error page?)'
assert ext == 'jpg',              f'wrong ext: {ext}'
print(f'Download OK: {len(data)} bytes, ext={ext}')
"
```
Expected: `Download OK: NNNNN bytes, ext=jpg`

- [ ] **Step 4: Commit**

```bash
git add import_products.py
git commit -m "feat: add FTP connect and image download/upload to import_products.py"
```

---

### Task 4: Main import loop + CLI entry point

**Files:**
- Modify: `import_products.py`

**Important:** Step 1 adds `print_verification` first, because `run_import` calls it. Step 2 adds `run_import` second.

- [ ] **Step 1: Add print_verification function**

```python
# ── Verification ──────────────────────────────────────────────────────────────

def print_verification(conn):
    cur = conn.cursor()

    cur.execute('SELECT COUNT(*) as n FROM Product')
    total = cur.fetchone()['n']

    cur.execute('SELECT COUNT(DISTINCT productId) as n FROM ProductImage')
    with_img = cur.fetchone()['n']

    cur.execute('SELECT COUNT(DISTINCT productId) as n FROM ProductVariant')
    with_var = cur.fetchone()['n']

    print(f'\n--- Verification ---')
    print(f'Total products:               {total}')
    print(f'Products with >= 1 image:     {with_img} / {total}')
    print(f'Products with >= 1 variant:   {with_var} / {total}')

    # Sample: first sized product and first no-size product
    cur.execute("""
        SELECT p.id, p.name,
               (SELECT url FROM ProductImage WHERE productId=p.id ORDER BY displayOrder LIMIT 1) AS img,
               (SELECT GROUP_CONCAT(CONCAT(COALESCE(size,'—'), '(', stock, ')') ORDER BY id SEPARATOR ' ')
                FROM ProductVariant WHERE productId=p.id) AS vars
        FROM Product p ORDER BY p.id LIMIT 3
    """)
    print('\nSample (first 3 products):')
    for r in cur.fetchall():
        print(f"  [{r['id']}] {r['name']}")
        print(f"        img:      {r['img'] or 'NONE'}")
        print(f"        variants: {r['vars'] or 'NONE'}")

    cur.close()
```

- [ ] **Step 2: Add run_import function (after print_verification)**

```python
# ── Import loop ───────────────────────────────────────────────────────────────

def run_import(dry_run=False):
    tag = '[DRY RUN] ' if dry_run else ''
    print(f'{tag}Starting import from {CSV_PATH}\n')

    conn = connect_db()
    cur  = conn.cursor()

    # Step 0: fix missing column BEFORE any inserts (skip in dry-run — no DB writes)
    if not dry_run:
        ensure_custom_values_column(conn)

    # Read existing products (SELECT is safe in dry-run)
    cur.execute('SELECT id, name FROM Product')
    existing = {r['name'].strip().lower(): r['id'] for r in cur.fetchall()}
    print(f'  {len(existing)} existing products found in DB\n')

    ftp = None if dry_run else connect_ftp()

    imported = skipped = img_errors = 0
    dry_id = -1  # negative placeholder IDs for dry-run new products (avoids collisions)

    with open(CSV_PATH, newline='', encoding='utf-8') as f:
        for row in csv.DictReader(f):
            name = row['nombre'].strip()
            if not name:
                continue
            if row.get('activo', '1').strip() == '0':
                skipped += 1
                continue

            price   = float(row.get('precio') or 0)
            stock   = clamp_stock(row.get('stock', 0))
            descr   = row.get('descripcion', '').strip()
            img_url = row.get('images', '').strip()
            sizes   = parse_sizes(row.get('talla', ''))

            # Match or insert product
            key        = name.lower()
            product_id = existing.get(key)

            if product_id is None:
                print(f'  NEW    {name}')
                if not dry_run:
                    cur.execute(
                        'INSERT INTO Product '
                        '(name, description, price, hasSize, hasColor, hasMaterial, onDemand, isPreorder, createdAt, updatedAt) '
                        'VALUES (%s,%s,%s,%s,0,0,0,0,NOW(),NOW())',
                        [name, descr, price, 1 if sizes else 0]
                    )
                    conn.commit()
                    product_id = cur.lastrowid
                    existing[key] = product_id
                else:
                    product_id = dry_id  # unique negative placeholder per new product
                    dry_id -= 1
            else:
                print(f'  MATCH  {name} (id={product_id})')

            # Clear stale data before re-inserting (skip in dry-run)
            if not dry_run:
                cur.execute('DELETE FROM ProductImage   WHERE productId=%s', [product_id])
                cur.execute('DELETE FROM ProductVariant WHERE productId=%s', [product_id])
                conn.commit()

            # Image
            db_url = None
            if img_url:
                img_data, ext = download_image(img_url)
                if img_data:
                    filename = make_filename(product_id, ext)
                    if dry_run:
                        db_url = f'{UPLOADS_URL}/{filename}'
                    else:
                        db_url = upload_image(ftp, img_data, filename)
                        cur.execute(
                            'INSERT INTO ProductImage (productId, url, displayOrder) VALUES (%s,%s,0)',
                            [product_id, db_url]
                        )
                        conn.commit()
                    print(f'         image  → {db_url}')
                else:
                    img_errors += 1

            # Variants (explicit size=NULL for no-size products)
            sizes_label = ', '.join(sizes) if sizes else '(no-size)'
            print(f'         sizes  → {sizes_label}  stock={stock}')
            if not dry_run:
                if sizes:
                    per = max(0, stock // len(sizes)) if stock > 0 else 0
                    for sz in sizes:
                        cur.execute(
                            'INSERT INTO ProductVariant (productId, size, stock, reserved, customValues) '
                            'VALUES (%s,%s,%s,0,NULL)',
                            [product_id, sz, per]
                        )
                else:
                    # Explicit size=NULL for accessories with no size dimension
                    cur.execute(
                        'INSERT INTO ProductVariant (productId, size, stock, reserved, customValues) '
                        'VALUES (%s,NULL,%s,0,NULL)',
                        [product_id, stock]
                    )
                conn.commit()

            imported += 1

    if ftp:
        ftp.quit()

    print(f'\n{tag}Done: {imported} imported, {skipped} skipped (inactive), {img_errors} image errors')

    if not dry_run:
        print_verification(conn)

    cur.close()
    conn.close()
```

- [ ] **Step 3: Replace `if __name__ == '__main__': pass` with the real entry point**

```python
if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
    run_import(dry_run='--dry-run' in sys.argv)
```

- [ ] **Step 4: Run dry-run to validate CSV parsing (no DB/FTP writes, no ALTER TABLE)**

```bash
cd C:/WebsMami
python import_products.py --dry-run 2>&1 | head -80
```
Expected: MATCH/NEW lines per product, image URL previews with distinct IDs, variant info, ends with:
`[DRY RUN] Done: NNN imported, N skipped (inactive), N image errors`

- [ ] **Step 5: Commit**

```bash
git add import_products.py
git commit -m "feat: add run_import loop, print_verification, and CLI entry point"
```

---

### Task 5: Full run + browser verification

- [ ] **Step 1: Run the real import**

```bash
cd C:/WebsMami
python import_products.py
```

Expected end of output:
```
--- Verification ---
Total products:               NNN
Products with >= 1 image:     NNN / NNN
Products with >= 1 variant:   NNN / NNN

Sample (first 3 products):
  [1] CAMISETA MONO
        img:      /uploads/products/p_1_XXXXXXXXXX.jpg
        variants: xs(0) s(0) m(0) l(0) xl(0) 2xl(0)
```

- [ ] **Step 2: Spot-check a product page in browser**

Open `https://kokett.ad/product/1` (or any product ID shown in the verification output).

Confirm:
- Product image is visible
- Size buttons appear (xs, s, m, l, xl, 2xl for clothing products)
- Accessories (no `talla`) show "Añadir a la cesta" directly
- Clicking a size with stock=0 shows "Sin stock" — this is correct (stock is 0 from CSV)

- [ ] **Step 3: Final commit**

```bash
git add import_products.py
git commit -m "feat: complete import_products.py — restores ProductVariant and ProductImage from CSV"
```
