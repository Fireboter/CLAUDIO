# Image Upload Slots — Design Spec
Date: 2026-03-21

## Problem

The product form has a plain `<input type="file" multiple>` for uploading images. Once files are selected, there is no way to add more images without re-selecting everything. Users can only crop/edit images that have already been saved to the database. New products and new images on existing products have no crop step.

## Goal

Replace the file input with a visual "+" slot that always appears after the existing image thumbnails. Clicking it lets the user pick one image, crop it, then upload it immediately. Multiple images can be added sequentially. Works for both new and existing products.

## Scope

Changes apply to `bawywear/admin/pages/product-form.php` and its identical copy `kokett/admin/pages/product-form.php`.

---

## Design

### 1. HTML Structure

The image row becomes:

```
[ img1 ] [ img2 ] [ img3 ] [ + ]
```

- Existing PHP-rendered thumbnails (80×80) are unchanged.
- A "+" slot div is injected by **PHP**, as the last child inside `#existing-images`, after the closing `?>` of the image loop.
- A hidden `<input type="file" accept="image/*" id="add-image-input">` (no `multiple`, **no `name` attribute**) replaces the old `#image-upload` input. It lives outside the `<form>` tag to ensure it is never included in form serialisation.

**"+" slot style:**
```html
<div id="add-image-slot"
     onclick="document.getElementById('add-image-input').click()"
     style="width:80px;height:80px;border:2px dashed var(--color-gray-300);border-radius:4px;
            display:flex;align-items:center;justify-content:center;cursor:pointer;
            font-size:2rem;color:var(--color-gray-400);flex-shrink:0;
            transition:border-color .15s,color .15s"
     onmouseover="this.style.borderColor='var(--color-gray-500)';this.style.color='var(--color-gray-500)'"
     onmouseout="this.style.borderColor='var(--color-gray-300)';this.style.color='var(--color-gray-400)'">+</div>
```

When a new thumbnail is inserted it is placed **before** `#add-image-slot` so the "+" always stays last.

---

### 2. JavaScript Changes

#### State

```js
let pendingImages = [];
// Only populated for new products (no product ID). Contains image URLs uploaded
// but not yet linked because the product row does not exist yet.
// For existing products images are linked immediately and this array stays empty.
```

---

#### `#add-image-input` change handler

```js
document.getElementById('add-image-input').addEventListener('change', function () {
  const file = this.files[0];
  if (!file) return;
  const objectUrl = URL.createObjectURL(file);
  openCropModal(objectUrl, null, true); // isNew = true
  this.value = ''; // allow re-selecting the same file
});
```

---

#### `openCropModal(imgUrl, imageId, isNew = false)`

Add a third parameter `isNew`. Store in `cropState`:

```js
cropState = { imageId, isNew, objectUrl: isNew ? imgUrl : null, ... }
```

---

#### `closeCropModal()`

Updated to revoke any object URL:

```js
function closeCropModal() {
  if (cropState.objectUrl) URL.revokeObjectURL(cropState.objectUrl);
  cropState = {};
  document.getElementById('crop-overlay')?.remove();
}
```

---

#### `saveCrop()` — two paths

**Path A — cropping an existing (saved) image (`cropState.isNew = false`):**
Unchanged: upload crop → `link-image` → `delete-image` (old) → `location.reload()`.

**Path B — adding a new image (`cropState.isNew = true`):**

1. Upload cropped blob to `?action=upload-image` → get `url`.
2. **If a product ID exists:**
   - Call `link-image` with `{ productId, imageUrl: url }`.
   - The API response **must return** `{ success: true, imageId: <int> }` (see API Change below).
   - Insert a fully wired thumbnail div (see "New thumbnail structure") with `data-image-id` set and the star button included.
   - Do **not** push to `pendingImages[]` — the image is already linked.
3. **If no product ID** (new product form):
   - Skip `link-image`.
   - Insert a thumbnail div with `data-pending-url` attribute set to `url` (no `data-image-id`, no star button).
   - Push `url` to `pendingImages[]`.
4. `URL.revokeObjectURL(cropState.objectUrl)` then clear `cropState.objectUrl`.
5. Close modal (call `closeCropModal()`). **No page reload.**

---

#### New thumbnail structure

For **linked** images (existing product, imageId known):

```html
<div style="position:relative" data-image-id="<imageId>">
  <img src="<url>" title="Clic para recortar"
       onclick="openCropModal('<url>', <imageId>)"
       style="width:80px;height:80px;object-fit:cover;border-radius:4px;cursor:pointer">
  <button type="button" onclick="removeImage(<imageId>, this)"
          style="...same as existing remove button...">×</button>
  <button type="button" onclick="setCover(<imageId>, <productId>, this)"
          title="Usar como portada" style="...same as existing star button...">★</button>
</div>
```

For **pending** images (new product, no imageId yet):

```html
<div style="position:relative" data-pending-url="<url>">
  <img src="<url>" style="width:80px;height:80px;object-fit:cover;border-radius:4px">
  <button type="button" onclick="removePendingImage(this, '<escaped-url>')"
          style="...same as existing remove button...">×</button>
</div>
```

Note: pending thumbnails have no crop-on-click and no star button, because there is no imageId or productId yet.

---

#### New `removePendingImage(btn, url)` helper

```js
function removePendingImage(btn, url) {
  pendingImages = pendingImages.filter(u => u !== url);
  btn.closest('[data-pending-url]').remove();
}
```

---

#### `saveProduct()`

Replace the old file-input upload loop:

```js
// OLD:
const filesInput = form.querySelector('#image-upload');
const uploadedUrls = [];
for (const file of filesInput.files) { ... }
data.newImages = uploadedUrls;

// NEW:
data.newImages = [...pendingImages];
```

For new products, `data.newImages` contains the pre-uploaded URLs. The existing `create` handler already inserts these into `ProductImage` after creating the product row.

---

### 3. API Change — `link-image` must return `imageId`

Current response: `{ "success": true }`

Required response: `{ "success": true, "imageId": <int> }`

Change in `bawywear/admin/api/products.php` and `kokett/admin/api/products.php`, in the `link-image` action:

```php
// After the INSERT:
$newId = db()->lastInsertId();
echo json_encode(['success' => true, 'imageId' => (int)$newId]);
```

---

## Edge Cases

| Case | Behavior |
|------|----------|
| User cancels crop modal | `closeCropModal()` revokes object URL; no upload happens; no thumbnail added |
| Crop upload fails | Error shown in modal; no thumbnail added; `pendingImages` unchanged; object URL revoked on close |
| New product — abandon form without saving | Uploaded files (in `pendingImages`) are orphaned on disk with no DB row. Accepted trade-off; a periodic cleanup job for orphaned `p_*` files is recommended but out of scope for this feature. |
| Existing product — remove newly added image | `removeImage(imageId, btn)` works normally (image is linked, has a DB row) |
| New product — remove pending image | `removePendingImage(btn, url)` removes from DOM and `pendingImages[]`; no API call needed since no DB row exists |
| Re-cropping a thumbnail added in this session | After `saveCrop()` Path B (existing product), the new thumbnail calls `openCropModal(url, imageId)` — Path A applies from then on, which reloads the page after crop. This is correct and consistent. |

---

## Files Changed

| File | Change |
|------|--------|
| `bawywear/admin/pages/product-form.php` | HTML: replace file input + add "+" slot; JS: `closeCropModal`, `openCropModal`, `saveCrop`, `saveProduct`, new `removePendingImage`, new `#add-image-input` handler |
| `kokett/admin/pages/product-form.php` | Identical changes |
| `bawywear/admin/api/products.php` | `link-image` action: return `imageId` in response |
| `kokett/admin/api/products.php` | Identical change |
