"""
Updates product images in the DB using Shopify CDN URLs from the CSV.
Matches by product name (case-insensitive, stripped).
"""

import csv
import json
import pymysql

# DB config (same as kokett/config.php)
DB = dict(host='bbdd.kokett.ad', user='ddb263474', password='DoMiNioKK*24',
          db='ddb263474', charset='utf8mb4')

CSV_PATH = r'C:\Kokett\data\ProductosKokett.csv'

def normalize(name: str) -> str:
    return name.strip().upper()

def main():
    # Load CSV → {normalized_name: image_url}
    csv_images = {}
    with open(CSV_PATH, newline='', encoding='utf-8-sig') as f:
        reader = csv.DictReader(f)
        for row in reader:
            name = normalize(row['nombre'])
            image_url = row['images'].strip()
            if name and image_url:
                csv_images[name] = image_url

    print(f'CSV: {len(csv_images)} products with images')

    conn = pymysql.connect(**DB)
    cur = conn.cursor()

    cur.execute('SELECT id, name, images FROM Product')
    products = cur.fetchall()
    print(f'DB:  {len(products)} products total')

    updated = 0
    not_found = []

    for product_id, name, images_json in products:
        key = normalize(name)
        if key in csv_images:
            new_images = json.dumps([csv_images[key]], ensure_ascii=False)
            cur.execute('UPDATE Product SET images = %s WHERE id = %s', (new_images, product_id))
            updated += 1
        else:
            not_found.append(name)

    conn.commit()
    cur.close()
    conn.close()

    print(f'\nUpdated: {updated} products')
    if not_found:
        print(f'No CSV match for {len(not_found)} products:')
        for n in not_found:
            print(f'  - {n}')

if __name__ == '__main__':
    main()
