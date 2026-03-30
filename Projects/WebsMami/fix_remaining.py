"""Fix the 6 remaining products with broken images using manual name mapping."""
import json
import pymysql

DB = dict(host='bbdd.kokett.ad', user='ddb263474', password='DoMiNioKK*24',
          db='ddb263474', charset='utf8mb4')

# DB name → Shopify CDN URL
MANUAL = {
    'PULSERA  ANGELITO':              'https://cdn.shopify.com/s/files/1/0693/3797/2933/files/PULSERA-ANGELITO.jpg?v=1740053097',
    'DUE COLLAR/PULSERA BERAKA':      'https://cdn.shopify.com/s/files/1/0693/3797/2933/files/beraka5.jpg?v=1737203766',
    'PULSERA CHENILLE PERSONALIZADA ':'https://cdn.shopify.com/s/files/1/0693/3797/2933/files/CHENILLE.jpg?v=1736006316',
    'COLLAR/PULSERA LETTER ':         'https://cdn.shopify.com/s/files/1/0693/3797/2933/files/collar-letter2.jpg?v=1735729161',
    'COLLAR LARGO ATIDA LG ':         'https://cdn.shopify.com/s/files/1/0693/3797/2933/files/atida-amatista-2.jpg?v=1735579427',
}

conn = pymysql.connect(**DB)
cur = conn.cursor()

# Fix the 5 exact-name matches
for name, url in MANUAL.items():
    new_images = json.dumps([url], ensure_ascii=False)
    rows = cur.execute('UPDATE Product SET images = %s WHERE name = %s', (new_images, name))
    print(f'  {"OK" if rows else "NOT FOUND":8s} {name!r}')

# Fix the ónix anillo by ID lookup via current broken image path
cur.execute("SELECT id, name FROM Product WHERE images LIKE '%anillo-moka%' AND name LIKE '%hielo%'")
row = cur.fetchone()
if row:
    url = 'https://cdn.shopify.com/s/files/1/0693/3797/2933/files/anillo-blanco.jpg?v=1735570228'
    cur.execute('UPDATE Product SET images = %s WHERE id = %s', (json.dumps([url]), row[0]))
    print(f'  OK       {row[1]!r}')
else:
    print('  NOT FOUND anillo hielo onix')

conn.commit()
conn.close()
print('\nDone.')
