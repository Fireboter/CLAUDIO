# Design: import_products.py — Restore Product Variants & Images from CSV

**Date:** 2026-03-25
**Status:** Approved

## Problem

After a DB restoration, `ProductVariant` and `ProductImage` tables are empty.
Products exist in the DB (name, price, collection) but have no stock variants or images.
The original data source is `ProductosKokett.csv` with 228 products exported from Shopify.

## Solution

A single Python script `import_products.py` (at repo root) that:

1. Reads `C:\Users\adria\Downloads\ProductosKokett.csv`
2. Matches each CSV row to an existing `Product` row by name (preserves collection assignments)
3. Downloads the product image from Shopify CDN
4. Uploads it to FTP as `/public/uploads/products/p_{id}_{ts}.jpg`
5. Inserts `ProductImage` and `ProductVariant` rows into MySQL
6. Fixes the missing `customValues` column on `ProductVariant` via ALTER TABLE (Step 0, before any inserts)
7. Prints a verification report at the end

## CSV Structure

| Column | Usage |
|--------|-------|
| `nombre` | Product name — used to match existing DB rows |
| `descripcion` | HTML description — stored as-is |
| `stock` | Total stock — divided evenly across sizes (clamped to 0 if negative) |
| `precio` | Price — used only for new products (existing rows keep their price) |
| `images` | Single Shopify CDN URL — downloaded and re-uploaded to FTP |
| `talla` | Semicolon-separated sizes e.g. `xs; s; m; l; xl; 2xl` — each becomes a `ProductVariant` |
| `activo` | `0` = skip row |
| `coste` | Ignored (cost price, not needed for restore) |

## Data Flow

```
Step 0: ALTER TABLE ProductVariant ADD COLUMN IF NOT EXISTS customValues TEXT DEFAULT NULL

For each CSV row:
  └─ activo=0? → skip
  └─ match Product by name (lowercase trim)
       ├─ found → use existing product_id (preserves collection/price/admin edits)
       └─ not found → INSERT new Product row with:
                        hasSize=1 if talla set else 0
                        hasColor=0, hasMaterial=0
                        onDemand=0, isPreorder=0
  └─ DELETE existing ProductImage WHERE productId=product_id
  └─ DELETE existing ProductVariant WHERE productId=product_id
  └─ download image from Shopify CDN
       ├─ success → upload to FTP as p_{id}_{ts}.jpg → INSERT ProductImage
       └─ fail    → warn + skip image, continue
  └─ talla set?
       ├─ yes → INSERT one ProductVariant per size, size=value, stock=(total/n_sizes), customValues=NULL
       └─ no  → INSERT one ProductVariant, no size, stock=total, customValues=NULL
```

## DB Changes

- **Step 0 (before any inserts):** `ALTER TABLE ProductVariant ADD COLUMN customValues TEXT DEFAULT NULL` (skip if already exists)
  - Required because `admin/api/products.php` uses this column but `setup.php` never creates it
  - All ProductVariant rows inserted by this script set `customValues = NULL`; `product.php` falls back correctly to standard `size`/`color`/`material` columns when `customValues` is empty (lines 24–32 of product.php)

## Image URL Convention

- FTP path: `/public/uploads/products/p_{productId}_{unixTimestamp}.{ext}`
- DB stored URL: `/uploads/products/p_{productId}_{unixTimestamp}.{ext}`
- Satisfies the filter in `product.php`: `preg_match('#/p_[^/]+$#', $url)`
- Note: the admin upload handler uses PHP `uniqid('p_')` which produces a hex string (e.g. `p_6604abc1.jpg`). This script uses `p_{id}_{ts}` instead — both satisfy the regex filter; the difference is intentional and cosmetic.

## Error Handling

| Scenario | Behaviour |
|----------|-----------|
| Image download fails | Log warning, product imported without image |
| FTP upload fails | Log warning, product imported without image |
| `activo=0` | Row skipped (checked before any DB lookup or insert) |
| Negative stock | Clamped to 0 |
| Name not found in DB | New Product row inserted (see Data Flow for column values) |
| Script run twice | Idempotent — DELETEs variants/images for the matched product_id before re-inserting |

## Verification Output (end of script)

```
Products with >= 1 image:   NNN / 228
Products with >= 1 variant: NNN / 228
Sample checks:
  "CAMISETA MONO" -> image /uploads/products/p_1_..., variants: xs(0) s(0) m(0) l(0) xl(0) 2xl(0)
  "COLLARES NEKKO LG" -> image /uploads/products/p_3_..., variants: (no-size, stock=0)
```

## Out of Scope

- HTML descriptions showing as literal text in product page (`e()` escapes HTML) — pre-existing issue
- Collection assignments for newly inserted products — user sets via admin panel
- Multiple images per product — CSV has one image per product
