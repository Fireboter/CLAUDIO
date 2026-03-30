"""
fix_stock_variants.py — Fix ProductVariant stock and structure after DB restoration.

Actions:
1. Read CSV → build name→{stock, sizes} map
2. Clean up duplicate no-size variants (keep one, set stock=max(existing,20))
3. For clothing items not in CSV (256,257,268): replace no-size with xs/s/m/l/xl/2xl at stock=20
4. Set all variant stock to max(current,20)
"""
import csv
import sys
import pymysql

CSV_PATH = r'C:\Users\adria\Downloads\ProductosKokett.csv'

DB = dict(host='bbdd.kokett.ad', user='ddb269776', password='KarenVB_13061975',
          database='ddb269776', connect_timeout=15, charset='utf8mb4')

# Clothing products not in CSV that need size variants
CLOTHING_IDS = {256, 257, 268}
DEFAULT_SIZES = ['xs', 's', 'm', 'l', 'xl', '2xl']
MIN_STOCK = 20


def parse_sizes(talla_str):
    if not talla_str or not talla_str.strip():
        return []
    return [s.strip().lower() for s in talla_str.split(';') if s.strip()]


def load_csv():
    """Returns dict: lowercase_name → {stock: int, sizes: [str]}"""
    data = {}
    with open(CSV_PATH, newline='', encoding='utf-8-sig') as f:
        reader = csv.DictReader(f)
        for row in reader:
            activo = row.get('activo', '1').strip()
            if activo == '0':
                continue
            name = row.get('nombre', '').strip().lower()
            if not name:
                continue
            try:
                stock = max(0, int(float(row.get('stock', '0') or '0')))
            except (ValueError, TypeError):
                stock = 0
            sizes = parse_sizes(row.get('talla', ''))
            data[name] = {'stock': stock, 'sizes': sizes}
    return data


def main():
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

    csv_data = load_csv()
    print(f'Loaded {len(csv_data)} active products from CSV')

    conn = pymysql.connect(**DB, cursorclass=pymysql.cursors.DictCursor)
    cur = conn.cursor()

    # -------------------------------------------------------------------------
    # Step 1: Find duplicate no-size variants and clean them up
    # -------------------------------------------------------------------------
    print('\n--- Step 1: Clean duplicate no-size variants ---')
    cur.execute("""
        SELECT productId, COUNT(*) as cnt
        FROM ProductVariant
        WHERE (size IS NULL OR size = '') AND (color IS NULL OR color = '') AND (material IS NULL OR material = '')
        GROUP BY productId
        HAVING COUNT(*) > 1
    """)
    dup_products = cur.fetchall()
    print(f'Products with duplicate no-size variants: {len(dup_products)}')

    for row in dup_products:
        pid = row['productId']
        cur.execute("""
            SELECT id, stock FROM ProductVariant
            WHERE productId=%s AND (size IS NULL OR size='') AND (color IS NULL OR color='') AND (material IS NULL OR material='')
            ORDER BY id
        """, [pid])
        variants = cur.fetchall()

        # Keep the first one, delete the rest
        keep_id = variants[0]['id']
        delete_ids = [v['id'] for v in variants[1:]]
        max_stock = max(v['stock'] for v in variants)
        new_stock = max(max_stock, MIN_STOCK)

        cur.execute(f"DELETE FROM ProductVariant WHERE id IN ({','.join(['%s']*len(delete_ids))})", delete_ids)
        cur.execute("UPDATE ProductVariant SET stock=%s WHERE id=%s", [new_stock, keep_id])
        conn.commit()
        print(f'  Product {pid}: kept id={keep_id}, deleted {len(delete_ids)} dupes, stock={new_stock}')

    # -------------------------------------------------------------------------
    # Step 2: Replace no-size variants for clothing items with size variants
    # -------------------------------------------------------------------------
    print('\n--- Step 2: Add size variants to clothing items ---')
    for pid in CLOTHING_IDS:
        # Delete all existing variants for this product
        cur.execute("DELETE FROM ProductVariant WHERE productId=%s", [pid])
        conn.commit()

        # Insert one variant per size
        for size in DEFAULT_SIZES:
            cur.execute("""
                INSERT INTO ProductVariant (productId, size, color, material, stock, reserved, customValues)
                VALUES (%s, %s, NULL, NULL, %s, 0, NULL)
            """, [pid, size, MIN_STOCK])
        conn.commit()

        cur.execute("SELECT name FROM Product WHERE id=%s", [pid])
        name_row = cur.fetchone()
        name = name_row['name'] if name_row else f'id={pid}'
        print(f'  Product {pid} ({name}): replaced with {len(DEFAULT_SIZES)} size variants at stock={MIN_STOCK}')

    # -------------------------------------------------------------------------
    # Step 3: Apply CSV stock to matched products (only if CSV stock > MIN_STOCK)
    # -------------------------------------------------------------------------
    print('\n--- Step 3: Apply CSV stock data where > minimum ---')
    cur.execute("SELECT id, name FROM Product")
    all_products = cur.fetchall()

    applied = 0
    for p in all_products:
        pid = p['id']
        name_key = p['name'].strip().lower()
        if name_key not in csv_data:
            continue
        csv_stock = csv_data[name_key]['stock']
        if csv_stock <= MIN_STOCK:
            continue

        # Get current variants
        cur.execute("SELECT id, size FROM ProductVariant WHERE productId=%s", [pid])
        variants = cur.fetchall()
        if not variants:
            continue

        sizes = csv_data[name_key]['sizes']
        if sizes and len(variants) == len(sizes):
            # Distribute CSV stock evenly across variants
            per_variant = max(csv_stock // len(variants), MIN_STOCK)
            for v in variants:
                cur.execute("UPDATE ProductVariant SET stock=%s WHERE id=%s", [per_variant, v['id']])
        else:
            # Update all variants for this product
            per_variant = max(csv_stock, MIN_STOCK)
            for v in variants:
                cur.execute("UPDATE ProductVariant SET stock=%s WHERE id=%s", [per_variant, v['id']])
        conn.commit()
        applied += 1

    print(f'  Applied CSV stock to {applied} products')

    # -------------------------------------------------------------------------
    # Step 4: Ensure every variant has at least MIN_STOCK
    # -------------------------------------------------------------------------
    print('\n--- Step 4: Set minimum stock on all remaining low-stock variants ---')
    cur.execute("SELECT COUNT(*) as cnt FROM ProductVariant WHERE stock < %s", [MIN_STOCK])
    low = cur.fetchone()['cnt']
    print(f'  Variants with stock < {MIN_STOCK}: {low}')

    if low:
        cur.execute("UPDATE ProductVariant SET stock=%s WHERE stock < %s", [MIN_STOCK, MIN_STOCK])
        conn.commit()
        print(f'  Updated {cur.rowcount} variants to stock={MIN_STOCK}')

    # -------------------------------------------------------------------------
    # Verification
    # -------------------------------------------------------------------------
    print('\n--- Verification ---')
    cur.execute("SELECT COUNT(*) as cnt FROM ProductVariant")
    total = cur.fetchone()['cnt']
    cur.execute("SELECT COUNT(*) as cnt FROM ProductVariant WHERE stock < %s", [MIN_STOCK])
    still_low = cur.fetchone()['cnt']
    cur.execute("SELECT COUNT(DISTINCT productId) as cnt FROM ProductVariant")
    products_with_variants = cur.fetchone()['cnt']
    cur.execute("SELECT COUNT(*) as cnt FROM Product")
    total_products = cur.fetchone()['cnt']

    print(f'Total variants:              {total}')
    print(f'Products with variants:      {products_with_variants} / {total_products}')
    print(f'Variants with stock < {MIN_STOCK}:  {still_low}')

    # Sample: ring products (preserved structure)
    print('\nRing product variants (should be preserved):')
    for pid in (180, 251):
        cur.execute("SELECT size, stock FROM ProductVariant WHERE productId=%s ORDER BY id", [pid])
        rows = cur.fetchall()
        sizes_str = ', '.join(f"{r['size']}({r['stock']})" for r in rows)
        cur.execute("SELECT name FROM Product WHERE id=%s", [pid])
        n = cur.fetchone()
        print(f'  [{pid}] {n["name"] if n else "?"}: {sizes_str}')

    # Sample: clothing items
    print('\nClothing product variants:')
    for pid in CLOTHING_IDS:
        cur.execute("SELECT size, stock FROM ProductVariant WHERE productId=%s ORDER BY id", [pid])
        rows = cur.fetchall()
        sizes_str = ', '.join(f"{r['size']}({r['stock']})" for r in rows)
        cur.execute("SELECT name FROM Product WHERE id=%s", [pid])
        n = cur.fetchone()
        print(f'  [{pid}] {n["name"] if n else "?"}: {sizes_str}')

    cur.close()
    conn.close()
    print('\nDone.')


if __name__ == '__main__':
    main()
