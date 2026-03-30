"""
fix_variants_comprehensive.py — Comprehensive fix for product variants:

1. Normalize clothing size values to uppercase (xs→XS, 2xl→XXL) to match admin presets
2. Set customValues JSON for all size/color/material variants (required by admin UI)
3. Add missing clothing sizes (XS/S/M/L/XL/XXL) to clothing products with partial/no sizes
4. Update Product.hasSize/hasColor/hasMaterial flags
5. Print full verification summary
"""
import json
import sys
import pymysql

DB = dict(host='bbdd.kokett.ad', user='ddb269776', password='KarenVB_13061975',
          database='ddb269776', connect_timeout=15, charset='utf8mb4')

# Standard clothing sizes matching the admin "Ropa" preset exactly
CLOTHING_SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL']

# Normalize size: clothing lowercase → uppercase matching admin preset
SIZE_MAP = {'xs': 'XS', 's': 'S', 'm': 'M', 'l': 'L', 'xl': 'XL', '2xl': 'XXL', '2XL': 'XXL',
            'xxl': 'XXL', 'xs ': 'XS', ' xs': 'XS'}

# Keywords that identify clothing products (not accessories)
CLOTHING_KEYWORDS = ['camiseta', 'vestido', 'trench', 'sudadera', 'abrigo', 'chaqueta',
                     'pantalon', 'falda', 'camisa', 'blazer', 'polo', 'body', 'top',
                     'plomifero', 'plomífero']

MIN_STOCK = 20


def is_clothing(name):
    n = name.lower()
    return any(k in n for k in CLOTHING_KEYWORDS)


def main():
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
    conn = pymysql.connect(**DB, cursorclass=pymysql.cursors.DictCursor)
    cur = conn.cursor()

    # -------------------------------------------------------------------------
    # Step 1: Normalize size values (lowercase → uppercase) and set customValues
    # -------------------------------------------------------------------------
    print('--- Step 1: Normalize size values and set customValues ---')

    cur.execute("""
        SELECT id, size, color, material, customValues
        FROM ProductVariant
        WHERE size IS NOT NULL AND size != ''
    """)
    size_variants = cur.fetchall()
    print(f'Variants with size set: {len(size_variants)}')

    size_normalized = 0
    cv_updated = 0
    for v in size_variants:
        vid = v['id']
        old_size = v['size']
        # Normalize: lowercase clothing sizes → uppercase; ring/other sizes kept as-is
        new_size = SIZE_MAP.get(old_size, old_size.strip())  # T8, T11, etc. stay as-is

        # Build customValues: {Talla: size}
        # Only add Color/Material if set (they're all NULL in current data)
        cv_obj = {'Talla': new_size}
        if v['color']:
            cv_obj['Color'] = v['color']
        if v['material']:
            cv_obj['Material'] = v['material']
        new_cv = json.dumps(cv_obj, ensure_ascii=False)

        cur.execute(
            'UPDATE ProductVariant SET size=%s, customValues=%s WHERE id=%s',
            [new_size, new_cv, vid]
        )
        if new_size != old_size:
            size_normalized += 1
        cv_updated += 1

    conn.commit()
    print(f'  Sizes normalized: {size_normalized} (e.g. xs→XS, 2xl→XXL)')
    print(f'  customValues set: {cv_updated}')

    # -------------------------------------------------------------------------
    # Step 2: Add missing clothing sizes to clothing products
    # -------------------------------------------------------------------------
    print('\n--- Step 2: Add missing clothing sizes to clothing products ---')

    # Get all products with clothing keywords
    cur.execute('SELECT id, name FROM Product ORDER BY id')
    all_products = cur.fetchall()
    clothing_products = [(r['id'], r['name']) for r in all_products if is_clothing(r['name'])]
    print(f'Clothing products found: {len(clothing_products)}')

    added_variants = 0
    fixed_products = 0
    for pid, name in clothing_products:
        cur.execute("""
            SELECT id, size, stock FROM ProductVariant
            WHERE productId=%s
            ORDER BY id
        """, [pid])
        variants = cur.fetchall()

        # Get current standard sizes (XS/S/M/L/XL/XXL only, normalized)
        current_sizes = set()
        for v in variants:
            s = v['size']
            if s:
                normalized = SIZE_MAP.get(s, s)
                if normalized in CLOTHING_SIZES:
                    current_sizes.add(normalized)

        missing_sizes = [s for s in CLOTHING_SIZES if s not in current_sizes]

        if not missing_sizes:
            continue  # Already has all 6 sizes

        # If only has a no-size variant (size=NULL), delete it first
        has_no_size_only = all(v['size'] is None or v['size'] == '' for v in variants)
        if has_no_size_only:
            cur.execute("DELETE FROM ProductVariant WHERE productId=%s", [pid])
            print(f'  [{pid}] {name}: removed no-size variant, adding all {CLOTHING_SIZES}')
        else:
            print(f'  [{pid}] {name}: has {sorted(current_sizes)}, adding {missing_sizes}')

        for size in missing_sizes:
            cv = json.dumps({'Talla': size}, ensure_ascii=False)
            cur.execute("""
                INSERT INTO ProductVariant (productId, size, color, material, stock, reserved, customValues)
                VALUES (%s, %s, NULL, NULL, %s, 0, %s)
            """, [pid, size, MIN_STOCK, cv])
            added_variants += 1

        conn.commit()
        fixed_products += 1

    print(f'\n  Fixed {fixed_products} clothing products, added {added_variants} size variants')

    # -------------------------------------------------------------------------
    # Step 3: Update Product.hasSize/hasColor/hasMaterial flags
    # -------------------------------------------------------------------------
    print('\n--- Step 3: Update hasSize/hasColor/hasMaterial flags ---')
    cur.execute("""
        UPDATE Product p
        SET
          hasSize     = (SELECT COUNT(*) > 0 FROM ProductVariant WHERE productId=p.id AND size IS NOT NULL AND size != ''),
          hasColor    = (SELECT COUNT(*) > 0 FROM ProductVariant WHERE productId=p.id AND color IS NOT NULL AND color != ''),
          hasMaterial = (SELECT COUNT(*) > 0 FROM ProductVariant WHERE productId=p.id AND material IS NOT NULL AND material != '')
    """)
    conn.commit()
    print(f'  Updated flags for {cur.rowcount} products')

    # -------------------------------------------------------------------------
    # Verification
    # -------------------------------------------------------------------------
    print('\n--- Verification ---')

    cur.execute("SELECT COUNT(*) as cnt FROM ProductVariant WHERE customValues IS NOT NULL AND customValues != 'null'")
    cv_count = cur.fetchone()['cnt']
    cur.execute("SELECT COUNT(*) as cnt FROM ProductVariant")
    total = cur.fetchone()['cnt']
    cur.execute("SELECT COUNT(*) as cnt FROM ProductVariant WHERE stock < %s", [MIN_STOCK])
    low = cur.fetchone()['cnt']
    cur.execute("SELECT COUNT(DISTINCT productId) as cnt FROM ProductVariant WHERE size IS NOT NULL AND size != ''")
    products_with_sizes = cur.fetchone()['cnt']
    cur.execute("SELECT COUNT(*) as cnt FROM Product WHERE hasSize=1")
    has_size_flag = cur.fetchone()['cnt']

    print(f'Total variants:                {total}')
    print(f'Variants with customValues:    {cv_count}')
    print(f'Variants with stock < {MIN_STOCK}:   {low}')
    print(f'Products with size variants:   {products_with_sizes}')
    print(f'Products with hasSize=1 flag:  {has_size_flag}')

    # Sample clothing products
    print('\nSample clothing products:')
    for pid, name in clothing_products[:10]:
        cur.execute("SELECT size, stock FROM ProductVariant WHERE productId=%s ORDER BY FIELD(size,'XS','S','M','L','XL','XXL') = 0, FIELD(size,'XS','S','M','L','XL','XXL')", [pid])
        rows = cur.fetchall()
        sizes_str = ', '.join(f"{r['size']}({r['stock']})" for r in rows) or '(none)'
        print(f'  [{pid}] {name}: {sizes_str}')

    # Ring products
    print('\nRing products:')
    for pid in (180, 251):
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
