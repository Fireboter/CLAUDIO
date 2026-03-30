"""
Migrate kokett catalog data to new bawywear DB.
Copies: collections, groups, products, images, variants, novedades, settings.
All records get site='bawy' since bawywear has its own dedicated DB.

Run: python migrate_db_bawywear.py

NOTE: Product image files in /public/uploads/ are NOT copied automatically.
      Copy them via FTP from kokett server to bawywear server manually if needed.
"""
import sys
import pymysql

sys.stdout.reconfigure(encoding='utf-8', errors='replace')

SRC = dict(host='bbdd.kokett.ad',   user='ddb269776', password='KarenVB_13061975',
           database='ddb269776', connect_timeout=15, charset='utf8mb4')
DST = dict(host='bbdd.bawywear.com', user='ddb270789', password='KarenVB_13061975',
           database='ddb270789', connect_timeout=15, charset='utf8mb4')

src = pymysql.connect(**SRC, cursorclass=pymysql.cursors.DictCursor)
dst = pymysql.connect(**DST)
sc  = src.cursor()
dc  = dst.cursor()

dc.execute('SET FOREIGN_KEY_CHECKS=0')

def clear(tables):
    for t in tables:
        dc.execute(f'DELETE FROM `{t}`')
    dst.commit()

def migrate(label, src_sql, dst_sql, transform=None):
    src.ping(reconnect=True)
    sc.execute(src_sql)
    rows = sc.fetchall()
    count = 0
    for row in rows:
        values = transform(row) if transform else tuple(row.values())
        dc.execute(dst_sql, values)
        count += 1
    dst.commit()
    print(f'  {label}: {count} rows')

print('Clearing bawywear DB tables...')
clear(['OrderItem', 'CartReservation', 'DiscountCode',
       'ProductNovedades', 'ProductVariant', 'ProductImage', 'Product',
       'Collection', 'CollectionGroup', 'NewsletterSubscriber', 'ContactSubmission'])
dc.execute("DELETE FROM `Order`")
dst.commit()

print('\nMigrating catalog from kokett...')

# CollectionGroup — add site='bawy'
sc.execute('SELECT id, name, createdAt, updatedAt FROM CollectionGroup WHERE site = %s', ('kokett',))
rows = sc.fetchall()
for r in rows:
    dc.execute('INSERT INTO CollectionGroup (id, name, site, createdAt, updatedAt) VALUES (%s,%s,%s,%s,%s)',
               (r['id'], r['name'], 'bawy', r['createdAt'], r['updatedAt']))
dst.commit()
print(f'  CollectionGroup: {len(rows)} rows')

# Collection — add site='bawy'
sc.execute('SELECT id, name, image, groupId, createdAt, updatedAt FROM Collection WHERE site = %s', ('kokett',))
rows = sc.fetchall()
for r in rows:
    dc.execute('INSERT INTO Collection (id, name, image, groupId, site, createdAt, updatedAt) VALUES (%s,%s,%s,%s,%s,%s,%s)',
               (r['id'], r['name'], r['image'], r['groupId'], 'bawy', r['createdAt'], r['updatedAt']))
dst.commit()
print(f'  Collection: {len(rows)} rows')

# Product — add site='bawy'
sc.execute('SELECT id, name, description, price, discount, collectionId, onDemand, createdAt, updatedAt FROM Product WHERE site = %s', ('kokett',))
rows = sc.fetchall()
for r in rows:
    dc.execute('INSERT INTO Product (id, name, description, price, discount, collectionId, onDemand, site, createdAt, updatedAt) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)',
               (r['id'], r['name'], r['description'], r['price'], r['discount'],
                r['collectionId'], r['onDemand'], 'bawy', r['createdAt'], r['updatedAt']))
dst.commit()
print(f'  Product: {len(rows)} rows')

# ProductImage — no site column, copy as-is
migrate('ProductImage',
    "SELECT id, productId, url, displayOrder FROM ProductImage WHERE productId IN (SELECT id FROM Product WHERE site='kokett')",
    'INSERT INTO ProductImage (id, productId, url, displayOrder) VALUES (%s,%s,%s,%s)')

# ProductVariant — no site column, copy as-is
migrate('ProductVariant',
    "SELECT id, productId, size, color, material, stock, reserved FROM ProductVariant WHERE productId IN (SELECT id FROM Product WHERE site='kokett')",
    'INSERT INTO ProductVariant (id, productId, size, color, material, stock, reserved) VALUES (%s,%s,%s,%s,%s,%s,%s)')

# ProductNovedades
migrate('ProductNovedades',
    "SELECT productId, addedAt FROM ProductNovedades WHERE productId IN (SELECT id FROM Product WHERE site='kokett')",
    'INSERT INTO ProductNovedades (productId, addedAt) VALUES (%s,%s)')

# SiteSettings — upsert, keep site='bawy'
sc.execute("SELECT `key`, `value` FROM SiteSettings WHERE site = 'kokett'")
count = 0
for row in sc.fetchall():
    dc.execute("INSERT INTO SiteSettings (`key`, `value`, site) VALUES (%s,%s,'bawy') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
               (row['key'], row['value']))
    count += 1
dst.commit()
print(f'  SiteSettings: {count} rows (upserted)')

dc.execute('SET FOREIGN_KEY_CHECKS=1')
dst.commit()

src.close()
dst.close()
print('\nMigration complete.')
print('\nNEXT STEPS:')
print('1. Visit https://bawywear.com/setup.php to create tables (if not done yet)')
print('2. Visit https://bawywear.com/migrate-site-isolation.php to add site column')
print('3. Visit https://bawywear.com/migrate-preorder.php to add isPreorder columns')
print('4. THEN run this script again (tables must exist first)')
print('5. Copy /public/uploads/ files from kokett FTP to bawywear FTP if needed')
