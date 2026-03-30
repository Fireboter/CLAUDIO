# Product Options & Preventa Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix product options UX (auto-combinations, preset names, stock-only mode) and add a Preventa (pre-order) mode with unlimited stock and an aggregated admin view.

**Architecture:** Four independent concerns — (1) product form JS fixes, (2) DB migration + API, (3) cart/checkout preorder flagging, (4) admin orders preventa tab. Each is self-contained and can be deployed separately.

**Tech Stack:** PHP 8, MySQL/MariaDB, vanilla JS, no build step.

---

## Chunk 1: DB Migration + Product API

### Task 1: Run DB migration

**Files:**
- Reference: `kokett/setup.php` (schema reference only)
- Run directly on the server via SSH/phpMyAdmin

- [ ] **Step 1: Add `isPreorder` to Product table**

```sql
ALTER TABLE Product ADD COLUMN isPreorder TINYINT(1) NOT NULL DEFAULT 0;
```

- [ ] **Step 2: Add `isPreorder` to OrderItem table**

```sql
ALTER TABLE OrderItem ADD COLUMN isPreorder TINYINT(1) NOT NULL DEFAULT 0;
```

- [ ] **Step 3: Verify columns exist**

```sql
DESCRIBE Product;
DESCRIBE OrderItem;
```

Expected: both tables show `isPreorder` column with default 0.

---

### Task 2: Products API — save `isPreorder` + stock-only variant

**Files:**
- Modify: `kokett/admin/api/products.php` — `create`/`update` case

- [ ] **Step 1: Read the current create/update case** (lines 29–103 of `kokett/admin/api/products.php`)

- [ ] **Step 2: Add `isPreorder` to the UPDATE and INSERT queries**

In the `create`/`update` case, after the existing `$onDemand` line add:
```php
$isPreorder = (int)($input['isPreorder'] ?? 0);
```

Change the UPDATE query to include `isPreorder=?`:
```php
db_run('UPDATE Product SET name=?, description=?, price=?, discount=?, collectionId=?, onDemand=?, isPreorder=?, hasSize=?, hasColor=?, hasMaterial=?, updatedAt=NOW() WHERE id=?',
    [$name, $descr, $price, $discount, $collId, $onDemand, $isPreorder, $hasSize, $hasColor, $hasMat, $id]);
```

Change the INSERT query to include `isPreorder`:
```php
$id = db_execute('INSERT INTO Product (name, description, price, discount, collectionId, onDemand, isPreorder, hasSize, hasColor, hasMaterial, createdAt, updatedAt) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())',
    [$name, $descr, $price, $discount, $collId, $onDemand, $isPreorder, $hasSize, $hasColor, $hasMat]);
```

- [ ] **Step 3: Handle stock-only (no-variant) products**

When the frontend sends `variants` with a single entry that has `customValues = {}` and `stock > 0`, the existing code already handles it (it inserts/updates the variant). No special backend logic needed — the frontend sends the correct payload.

- [ ] **Step 4: Commit**

```bash
cd C:/WebsMami
git add kokett/admin/api/products.php
git commit -m "feat: save isPreorder flag on Product create/update"
```

---

## Chunk 2: Product Form — UX Fixes

### Task 3: Remove "Generar combinaciones" button — auto-trigger on changes

**Files:**
- Modify: `kokett/admin/pages/product-form.php`

- [ ] **Step 1: Remove the button and its container div**

Find and remove this block (lines 121–125):
```html
<div style="margin-top:0.75rem;display:flex;align-items:center;gap:0.75rem">
  <button type="button" onclick="generateCombinations()" class="btn btn-sm" style="background:#000;color:#fff">⚡ Generar combinaciones</button>
  <span id="combo-info" style="font-size:0.8rem;color:var(--color-gray-500)"></span>
</div>
```

Replace with just the info span (no button, no wrapper div needed — put it after `#options-container`):
```html
<p id="combo-info" style="font-size:0.8rem;color:var(--color-gray-500);margin-top:0.5rem"></p>
```

- [ ] **Step 2: Auto-call `generateCombinations()` from every mutation point**

In the JS, update `addTagVal` — add call at the end (after `updateComboInfo()`):
```js
function addTagVal(idx, val, input) {
  if (!val) return;
  const vals = val.split(',').map(s => s.trim()).filter(Boolean);
  vals.forEach(v => { if (!options[idx].values.includes(v)) options[idx].values.push(v); });
  if (input) input.value = '';
  renderTags(idx);
  generateCombinations(); // auto-generate
}
```

Update `removeTag`:
```js
function removeTag(idx, vi) {
  options[idx].values.splice(vi, 1);
  renderTags(idx);
  generateCombinations(); // auto-generate
}
```

Update `loadPreset`:
```js
function loadPreset(idx, preset) {
  PRESETS[preset].forEach(v => { if (!options[idx].values.includes(v)) options[idx].values.push(v); });
  renderTags(idx);
  generateCombinations(); // auto-generate
}
```

Update `removeOption`:
```js
function removeOption(idx) {
  options.splice(idx, 1);
  renderOptions();
  generateCombinations(); // auto-generate
}
```

Update the option name `onchange` in `renderOptions()`:
```js
onchange="options[${idx}].name=this.value;generateCombinations()"
```

- [ ] **Step 3: Remove `updateComboInfo()` calls now replaced by `generateCombinations()`**

`generateCombinations()` calls `renderVariantsTable()` which doesn't update the combo count. Add `updateComboInfo()` call at the end of `generateCombinations()`:
```js
function generateCombinations() {
  const activeOpts = options.filter(o => o.name && o.values.length > 0);
  if (activeOpts.length === 0) {
    variantRows = [];
    renderVariantsTable();
    updateComboInfo();
    return;
  }
  // ... existing combo logic ...
  renderVariantsTable();
  updateComboInfo();
}
```

Also remove the early `alert()` — when no options, just clear the table silently.

- [ ] **Step 4: Commit**

```bash
git add kokett/admin/pages/product-form.php
git commit -m "feat: auto-generate combinations on option changes, remove manual button"
```

---

### Task 4: Fix preset button — set option name when empty

**Files:**
- Modify: `kokett/admin/pages/product-form.php` (JS only)

- [ ] **Step 1: Update `loadPreset` to also set the option name if blank**

```js
function loadPreset(idx, preset) {
  if (!options[idx].name) options[idx].name = preset; // set name if blank
  PRESETS[preset].forEach(v => { if (!options[idx].values.includes(v)) options[idx].values.push(v); });
  renderTags(idx);
  // Also update the visible input
  const nameInputs = document.querySelectorAll('.opt-name');
  if (nameInputs[idx]) nameInputs[idx].value = options[idx].name;
  generateCombinations();
}
```

- [ ] **Step 2: Commit**

```bash
git add kokett/admin/pages/product-form.php
git commit -m "fix: preset button sets option name when field is empty"
```

---

### Task 5: Stock-only mode (no variants)

**Files:**
- Modify: `kokett/admin/pages/product-form.php`

- [ ] **Step 1: Add a stock-only input section after `#options-container`**

After the options section div and before the variants table section, add:
```html
<!-- Stock-only (shown when no options defined) -->
<div id="stock-only-row" style="margin-top:0.75rem;display:none">
  <label style="font-size:0.875rem;font-weight:500;display:flex;align-items:center;gap:0.75rem">
    Stock total:
    <input type="number" id="stock-only-input" min="0" value="0"
           style="width:80px;padding:0.3rem 0.5rem;border:1px solid var(--color-gray-300);border-radius:4px;font-size:0.875rem">
  </label>
</div>
```

- [ ] **Step 2: Toggle stock-only row based on options**

Add a helper called from `generateCombinations()` and `renderOptions()`:
```js
function updateStockOnlyVisibility() {
  const hasOptions = options.some(o => o.name && o.values.length > 0);
  const stockRow = document.getElementById('stock-only-row');
  const variantsSection = document.getElementById('variants-table').closest('div[style*="margin-top:1.5rem"]');
  stockRow.style.display = hasOptions ? 'none' : 'block';
  variantsSection.style.display = hasOptions ? 'block' : 'none';
}
```

Call `updateStockOnlyVisibility()` at end of `generateCombinations()` and `renderOptions()`.

- [ ] **Step 3: Initialize stock-only input from existing single no-option variant**

In the `Init` block at the bottom, after `renderVariantsTable(initialVariants)`:
```js
// If single variant with no customValues, populate stock-only input
if (initialVariants.length === 1 && Object.keys(initialVariants[0].customValues || {}).length === 0) {
  document.getElementById('stock-only-input').value = initialVariants[0].stock || 0;
}
updateStockOnlyVisibility();
```

- [ ] **Step 4: Include stock-only in `saveProduct()`**

In `saveProduct()`, after building `data.variants`, add:
```js
// Stock-only mode: if no options, send a single variant with just stock
if (!options.some(o => o.name && o.values.length > 0)) {
  const stockVal = parseInt(document.getElementById('stock-only-input').value) || 0;
  const existingSimple = initialVariants.find(v => Object.keys(v.customValues || {}).length === 0);
  data.variants = [{ id: existingSimple?.id || '', customValues: {}, size: null, color: null, material: null, stock: stockVal }];
}
```

- [ ] **Step 5: Commit**

```bash
git add kokett/admin/pages/product-form.php
git commit -m "feat: stock-only mode when no options defined"
```

---

### Task 6: Preventa checkbox in product form

**Files:**
- Modify: `kokett/admin/pages/product-form.php`
- Modify: `kokett/admin/index.php` (or wherever `$product` is loaded for the edit page — check how `isPreorder` is fetched)

- [ ] **Step 1: Add preventa checkbox to the form flags area**

In the checkboxes area (lines 77–86), add after "En Novedades":
```html
<label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer;font-size:0.875rem">
  <input type="checkbox" name="isPreorder" id="isPreorder" value="1" <?= !empty($p['isPreorder']) ? 'checked' : '' ?>>
  Preventa
</label>
```

- [ ] **Step 2: Include `isPreorder` in `saveProduct()` JS**

In `saveProduct()`, after the `onDemand` line:
```js
data.isPreorder = form.querySelector('#isPreorder').checked ? 1 : 0;
```

- [ ] **Step 3: Verify admin product edit page loads `isPreorder`**

Check `kokett/admin/index.php` — find the query that loads `$product` for the edit page. Confirm `isPreorder` is included in `SELECT *` (it should be, since we're selecting all columns). If using explicit column list, add `isPreorder`.

- [ ] **Step 4: Commit**

```bash
git add kokett/admin/pages/product-form.php
git commit -m "feat: preventa checkbox in product form"
```

---

## Chunk 3: Cart + Checkout — Preventa Stock Skip

### Task 7: Skip stock check for preventa products in cart

**Files:**
- Modify: `shared/cart.php` — `cart_add()` function

- [ ] **Step 1: Read `cart_add()` function** (lines 35–103 of `shared/cart.php`)

- [ ] **Step 2: Update the stock-check query to also fetch `isPreorder`**

Current query (line 52):
```php
$variants = db_query(
    'SELECT v.stock, v.reserved, p.onDemand FROM ProductVariant v JOIN Product p ON v.productId = p.id WHERE v.id = ?',
    [$variantId]
);
```

New query:
```php
$variants = db_query(
    'SELECT v.stock, v.reserved, p.onDemand, p.isPreorder FROM ProductVariant v JOIN Product p ON v.productId = p.id WHERE v.id = ?',
    [$variantId]
);
```

- [ ] **Step 3: Skip stock check for preventa products**

Current condition (line 61):
```php
if (!$isOnDemand) {
```

New condition:
```php
$isPreorder = (bool)$variant['isPreorder'];
if (!$isOnDemand && !$isPreorder) {
```

- [ ] **Step 4: Commit**

```bash
git add shared/cart.php
git commit -m "feat: skip stock check for preventa products in cart"
```

---

### Task 8: Flag OrderItems as preorder in checkout

**Files:**
- Modify: `kokett/api/checkout-submit.php`

- [ ] **Step 1: Read `checkout-submit.php`** (lines 64–68 — the OrderItem insert loop)

- [ ] **Step 2: Query each product's `isPreorder` flag when inserting OrderItems**

Replace the OrderItem insert loop:
```php
foreach ($cartItems as $item) {
    // Check if this product is a preventa
    $prod = db_query('SELECT isPreorder FROM Product WHERE id = ?', [$item['productId']]);
    $isPreorder = !empty($prod[0]['isPreorder']) ? 1 : 0;

    $stmt2 = $pdo->prepare('INSERT INTO OrderItem (orderId, productId, variantId, quantity, price, isPreorder, createdAt) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $stmt2->execute([$orderId, $item['productId'], $item['variantId'], $item['quantity'], $item['price'], $isPreorder]);
}
```

- [ ] **Step 3: Commit**

```bash
git add kokett/api/checkout-submit.php
git commit -m "feat: flag OrderItems as isPreorder at checkout"
```

---

## Chunk 4: Admin Orders — Preventa Tab

### Task 9: Add preventa tab and aggregated table to admin orders page

**Files:**
- Modify: `kokett/admin/pages/orders.php`

- [ ] **Step 1: Read the current orders page** (`kokett/admin/pages/orders.php`)

- [ ] **Step 2: Add tab navigation for "Pedidos" vs "Preventa"**

At the top of the page (after `$pageTitle`, before the status filter buttons), detect a `?tab=preventa` param:
```php
$tab = $_GET['tab'] ?? 'orders';
```

Replace the existing filter buttons block with tab headers + conditional content:
```html
<div style="display:flex;gap:0;margin-bottom:1.5rem;border-bottom:2px solid var(--color-gray-200)">
  <a href="/admin/orders" style="padding:0.5rem 1.25rem;font-weight:600;font-size:0.875rem;border-bottom:2px solid <?= $tab==='orders' ? '#000' : 'transparent' ?>;margin-bottom:-2px;color:<?= $tab==='orders' ? '#000' : 'var(--color-gray-500)' ?>">Pedidos</a>
  <a href="/admin/orders?tab=preventa" style="padding:0.5rem 1.25rem;font-weight:600;font-size:0.875rem;border-bottom:2px solid <?= $tab==='preventa' ? '#000' : 'transparent' ?>;margin-bottom:-2px;color:<?= $tab==='preventa' ? '#000' : 'var(--color-gray-500)' ?>">Preventa</a>
</div>
```

- [ ] **Step 3: Wrap existing orders table in `$tab === 'orders'` block**

```php
<?php if ($tab === 'orders'): ?>
<!-- existing status filter + table HTML -->
<?php endif; ?>
```

- [ ] **Step 4: Add preventa aggregated table**

Query: group all `OrderItem` rows where `isPreorder = 1`, join to `Product` and `ProductVariant`, sum quantities.

Add before the closing PHP:
```php
<?php if ($tab === 'preventa'): ?>
<?php
$preorderItems = db_query("
    SELECT
        p.id AS productId,
        p.name AS productName,
        oi.variantId,
        pv.size, pv.color, pv.material, pv.customValues,
        SUM(oi.quantity) AS totalQty,
        COUNT(DISTINCT oi.orderId) AS orderCount
    FROM OrderItem oi
    JOIN Product p ON oi.productId = p.id
    LEFT JOIN ProductVariant pv ON oi.variantId = pv.id
    WHERE oi.isPreorder = 1
    GROUP BY p.id, p.name, oi.variantId, pv.size, pv.color, pv.material, pv.customValues
    ORDER BY p.name, oi.variantId
");
?>
<table class="admin-table">
  <thead>
    <tr>
      <th>Producto</th>
      <th>Variante</th>
      <th>Pedidos</th>
      <th>Cantidad total</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($preorderItems as $row):
        // Build variant label from customValues or standard columns
        $cv = !empty($row['customValues']) ? (json_decode($row['customValues'], true) ?? []) : [];
        $variantParts = [];
        if ($cv) {
            foreach ($cv as $k => $v) $variantParts[] = "$k: $v";
        } else {
            if ($row['size'])     $variantParts[] = 'Talla: ' . $row['size'];
            if ($row['color'])    $variantParts[] = 'Color: ' . $row['color'];
            if ($row['material']) $variantParts[] = 'Material: ' . $row['material'];
        }
        $variantLabel = $variantParts ? implode(', ', $variantParts) : '—';
    ?>
    <tr>
      <td><strong><?= e($row['productName']) ?></strong></td>
      <td style="color:var(--color-gray-500);font-size:0.875rem"><?= e($variantLabel) ?></td>
      <td style="font-size:0.875rem"><?= (int)$row['orderCount'] ?></td>
      <td><strong><?= (int)$row['totalQty'] ?></strong></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($preorderItems)): ?>
    <tr><td colspan="4" style="text-align:center;color:var(--color-gray-500);padding:2rem">No hay preventa activa.</td></tr>
    <?php endif; ?>
  </tbody>
</table>
<?php endif; ?>
```

- [ ] **Step 5: Commit**

```bash
git add kokett/admin/pages/orders.php
git commit -m "feat: preventa tab in admin orders with aggregated pre-order items table"
```

---

## Final Checklist

- [ ] DB migration ran successfully (both ALTER TABLE statements)
- [ ] Product form: adding a value auto-generates combinations
- [ ] Product form: clicking a preset button sets name + values in one click
- [ ] Product form: with no options, shows "Stock total" input; variants table hidden
- [ ] Product form: "Preventa" checkbox saves correctly
- [ ] Cart: preventa products can be added regardless of stock level
- [ ] Checkout: OrderItems for preventa products have `isPreorder = 1`
- [ ] Admin orders: "Preventa" tab shows grouped table of items to order
- [ ] Deploy to server via `ftp_upload.py` or equivalent
