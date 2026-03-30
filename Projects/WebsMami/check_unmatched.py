"""Check which unmatched products still have broken/missing images."""
import json
import pymysql

DB = dict(host='bbdd.kokett.ad', user='ddb263474', password='DoMiNioKK*24',
          db='ddb263474', charset='utf8mb4')

UNMATCHED = [
    'TARJETA REGALO KOKETT 25', 'DUE COLLAR/PULSERA CRUZ BARROCA',
    'PULSERA  ANGELITO', 'Anillo crema con labradorita',
    'DUE COLLAR/PULSERA BERAKA', 'TARJETA REGALO KOKETT 10',
    'PULSERA CHENILLE PERSONALIZADA', 'COLLAR/PULSERA LETTER',
    'COLLAR LARGO ATIDA LG', 'Anillo color hielo con onix',
    'Anillo azul marino con piedra natural cuarzo sandia',
    'Plomifero Helsinki', 'TARJETA REGALO KOKETT 50',
    'CHAQUETA BORBOLETA', 'Collar Solitaire', 'Pendientes Terrace',
    'Pendientes Pimax', 'Pendientes Inher', 'Collar Perlas ODRI Barroco',
    'Pendientes Passion', 'Collar Blossom', 'Pulsera Flash',
    'ABRIGO GREY STUDIO', 'Mark Pendientes', 'Escudo Pendientes',
    'Scent Pendientes', 'Jas Pendientes', 'Zodiac Cuarzo Rosa Pulsera',
    'Collar Grace', 'Collar Alo', 'Pulsera Majesty', 'Pendientes Cresta',
    'Pendientes Cine', 'PULSERA CUERO NUDOS STONE',
    'PULSERA 2 CUEROS PASION PEQ. ORO', 'Collar Prix', 'Pulsera Embrace',
    'Pulsera Coast', 'Pendientes Bell', 'Pulsera MARMOL PETITE Saira',
]

conn = pymysql.connect(**DB)
cur = conn.cursor()
cur.execute("SELECT name, images FROM Product")
rows = {r[0]: r[1] for r in cur.fetchall()}
conn.close()

print('--- Products with missing/broken images ---')
for name, images_json in rows.items():
    try:
        imgs = json.loads(images_json or '[]') if images_json else []
    except Exception:
        imgs = []
    first = imgs[0] if imgs else None
    if not first or first.startswith('/uploads/') or 'vercel' not in first and 'shopify' not in first and 'cdn' not in first:
        print(f'  BROKEN: {name!r:50s}  img={first!r}')
