"""
Migrates data from old Kokett Next.js DB (ddb263474) to new PHP DB (ddb269776).
Run: python migrate_db.py
"""
import sys
import pymysql

sys.stdout.reconfigure(encoding='utf-8', errors='replace')

OLD = dict(host='bbdd.kokett.ad', user='ddb263474', password='DoMiNioKK*24',
           database='ddb263474', connect_timeout=15, charset='utf8mb4')
NEW = dict(host='bbdd.kokett.ad', user='ddb269776', password='KarenVB_13061975',
           database='ddb269776', connect_timeout=15, charset='utf8mb4')

src = pymysql.connect(**OLD, cursorclass=pymysql.cursors.DictCursor)
dst = pymysql.connect(**NEW)
sc = src.cursor()
dc = dst.cursor()

dc.execute('SET FOREIGN_KEY_CHECKS=0')

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

print('Clearing new DB tables...')
for t in ['OrderItem', 'Order', 'CartReservation', 'DiscountCode',
          'ProductNovedades', 'ProductVariant', 'ProductImage', 'Product',
          'Collection', 'CollectionGroup', 'NewsletterSubscriber',
          'ContactSubmission']:
    dc.execute(f'DELETE FROM `{t}`')
dst.commit()

print('\nMigrating data...')

# CollectionGroup
migrate('CollectionGroup',
    'SELECT id, name, createdAt, updatedAt FROM CollectionGroup',
    'INSERT INTO CollectionGroup (id, name, createdAt, updatedAt) VALUES (%s,%s,%s,%s)')

# Collection
migrate('Collection',
    'SELECT id, name, image, groupId, createdAt, updatedAt FROM Collection',
    'INSERT INTO Collection (id, name, image, groupId, createdAt, updatedAt) VALUES (%s,%s,%s,%s,%s,%s)')

# Product (only columns our schema has)
migrate('Product',
    'SELECT id, name, description, price, discount, collectionId, onDemand, createdAt, updatedAt FROM Product',
    'INSERT INTO Product (id, name, description, price, discount, collectionId, onDemand, createdAt, updatedAt) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)')

# ProductImage
migrate('ProductImage',
    'SELECT id, productId, url, displayOrder, color, material FROM ProductImage',
    'INSERT INTO ProductImage (id, productId, url, displayOrder, color, material) VALUES (%s,%s,%s,%s,%s,%s)')

# ProductVariant
migrate('ProductVariant',
    'SELECT id, productId, size, color, material, stock, reserved FROM ProductVariant',
    'INSERT INTO ProductVariant (id, productId, size, color, material, stock, reserved) VALUES (%s,%s,%s,%s,%s,%s,%s)')

# ProductNovedades (old has id col, new doesn't)
migrate('ProductNovedades',
    'SELECT productId, addedAt FROM ProductNovedades',
    'INSERT INTO ProductNovedades (productId, addedAt) VALUES (%s,%s)')

# Orders (historical)
migrate('Order',
    'SELECT id, orderNumber, customerName, customerEmail, customerPhone, shippingAddress, totalAmount, shippingMethod, shippingCost, status, sessionId, createdAt, updatedAt FROM `Order`',
    'INSERT INTO `Order` (id, orderNumber, customerName, customerEmail, customerPhone, shippingAddress, totalAmount, shippingMethod, shippingCost, status, sessionId, createdAt, updatedAt) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)')

# OrderItems
migrate('OrderItem',
    'SELECT id, orderId, productId, variantId, quantity, price, createdAt FROM OrderItem',
    'INSERT INTO OrderItem (id, orderId, productId, variantId, quantity, price, createdAt) VALUES (%s,%s,%s,%s,%s,%s,%s)')

# SiteSettings — UPDATE values from old into new (new already has rows from setup.php)
src.ping(reconnect=True)
sc.execute('SELECT `key`, `value` FROM SiteSettings')
count = 0
for row in sc.fetchall():
    dc.execute('INSERT INTO SiteSettings (`key`, `value`) VALUES (%s,%s) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)',
               (row['key'], row['value']))
    count += 1
dst.commit()
print(f'  SiteSettings: {count} rows (upserted)')

# NewsletterSubscriber
migrate('NewsletterSubscriber',
    'SELECT id, email, isActive, createdAt FROM NewsletterSubscriber',
    'INSERT INTO NewsletterSubscriber (id, email, isActive, createdAt) VALUES (%s,%s,%s,%s)')

# ContactSubmission
migrate('ContactSubmission',
    'SELECT id, name, email, message, formType, isRead, createdAt FROM ContactSubmission',
    'INSERT INTO ContactSubmission (id, name, email, message, formType, isRead, createdAt) VALUES (%s,%s,%s,%s,%s,%s,%s)')

dc.execute('SET FOREIGN_KEY_CHECKS=1')
dst.commit()

src.close()
dst.close()
print('\nMigration complete.')
