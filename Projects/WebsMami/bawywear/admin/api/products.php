<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once SHARED_PATH . '/db.php';
require_once SHARED_PATH . '/helpers.php';
require_once SHARED_PATH . '/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();
auth_check();

header('Content-Type: application/json');

// Image upload (multipart)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'upload-image') {
    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK)
        json_response(['success' => false, 'message' => 'No file uploaded']);
    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp']))
        json_response(['success' => false, 'message' => 'Invalid file type']);
    $filename = uniqid('p_') . '.' . $ext;
    $dest = UPLOADS_PATH . '/products/' . $filename;
    move_uploaded_file($_FILES['image']['tmp_name'], $dest);
    json_response(['success' => true, 'url' => UPLOADS_URL . '/products/' . $filename]);
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

switch ($action) {

    case 'create':
    case 'update':
        $isUpdate = $action === 'update';
        $id       = $isUpdate ? (int)$input['id'] : null;
        $name     = trim($input['name'] ?? '');
        $price    = (float)($input['price'] ?? 0);
        $discount = !empty($input['discount']) ? (float)$input['discount'] : null;
        $descr    = trim($input['description'] ?? '');
        $collId   = !empty($input['collectionId']) ? (int)$input['collectionId'] : null;
        $onDemand = (int)($input['onDemand'] ?? 0);
        $isPreorder = (int)($input['isPreorder'] ?? 0);
        $hasSize  = (int)($input['hasSize'] ?? 0);
        $hasColor = (int)($input['hasColor'] ?? 0);
        $hasMat   = (int)($input['hasMaterial'] ?? 0);

        if (!$name || $price <= 0)
            json_response(['success' => false, 'message' => 'Nombre y precio son obligatorios']);

        if ($isUpdate) {
            db_run('UPDATE Product SET name=?, description=?, price=?, discount=?, collectionId=?, onDemand=?, isPreorder=?, hasSize=?, hasColor=?, hasMaterial=?, updatedAt=NOW() WHERE id=?',
                [$name, $descr, $price, $discount, $collId, $onDemand, $isPreorder, $hasSize, $hasColor, $hasMat, $id]);
        } else {
            $id = db_execute('INSERT INTO Product (name, description, price, discount, collectionId, onDemand, isPreorder, hasSize, hasColor, hasMaterial, createdAt, updatedAt) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())',
                [$name, $descr, $price, $discount, $collId, $onDemand, $isPreorder, $hasSize, $hasColor, $hasMat]);
        }

        // New images → ProductImage table
        if (!empty($input['newImages'])) {
            $maxOrder = db_query('SELECT COALESCE(MAX(displayOrder),0) as m FROM ProductImage WHERE productId=?', [$id])[0]['m'];
            foreach ($input['newImages'] as $url) {
                db_execute('INSERT INTO ProductImage (productId, url, displayOrder) VALUES (?,?,?)', [$id, $url, ++$maxOrder]);
            }
        }

        // Smart variant upsert — preserve stock/reserved by ID
        if (isset($input['variants'])) {
            $existingIds = array_column(
                db_query('SELECT id FROM ProductVariant WHERE productId=?', [$id]), 'id'
            );
            $keptIds = [];
            foreach ($input['variants'] as $v) {
                $vid  = !empty($v['id']) ? (int)$v['id'] : 0;
                $sz   = $v['size']     ?? null;
                $cl   = $v['color']    ?? null;
                $mat  = $v['material'] ?? null;
                $stk  = max(0, (int)($v['stock'] ?? 0));
                $cv   = !empty($v['customValues']) ? json_encode($v['customValues'], JSON_UNESCAPED_UNICODE) : null;
                if ($vid && in_array($vid, $existingIds)) {
                    db_run('UPDATE ProductVariant SET size=?, color=?, material=?, stock=?, customValues=? WHERE id=? AND productId=?',
                        [$sz, $cl, $mat, $stk, $cv, $vid, $id]);
                    $keptIds[] = $vid;
                } else {
                    $newId = db_execute('INSERT INTO ProductVariant (productId, size, color, material, stock, reserved, customValues) VALUES (?,?,?,?,?,0,?)',
                        [$id, $sz, $cl, $mat, $stk, $cv]);
                    $keptIds[] = $newId;
                }
            }
            // Delete variants removed from the form
            foreach ($existingIds as $eid) {
                if (!in_array($eid, $keptIds)) {
                    db_run('DELETE FROM ProductVariant WHERE id=?', [$eid]);
                }
            }
        }

        // Novedades
        if (isset($input['isNovedades'])) {
            if ($input['isNovedades']) {
                db_run('INSERT IGNORE INTO ProductNovedades (productId) VALUES (?)', [$id]);
            } else {
                db_run('DELETE FROM ProductNovedades WHERE productId=?', [$id]);
            }
        }

        json_response(['success' => true, 'id' => $id]);

    case 'move':
        $id = (int)($input['id'] ?? 0);
        if (!$id) json_response(['success' => false, 'message' => 'ID requerido']);
        if (($input['type'] ?? '') === 'novedades') {
            if ($input['add'] ?? false) {
                db_run('INSERT IGNORE INTO ProductNovedades (productId) VALUES (?)', [$id]);
            } else {
                db_run('DELETE FROM ProductNovedades WHERE productId=?', [$id]);
            }
        } else {
            $collId = !empty($input['collectionId']) ? (int)$input['collectionId'] : null;
            db_run('UPDATE Product SET collectionId=? WHERE id=?', [$collId, $id]);
        }
        json_response(['success' => true]);

    case 'delete':
        $id = (int)($input['id'] ?? 0);
        if (!$id) json_response(['success' => false, 'message' => 'ID requerido']);
        $imgs = db_query('SELECT url FROM ProductImage WHERE productId=?', [$id]);
        foreach ($imgs as $img) {
            if (strpos($img['url'], '/uploads/') === 0) {
                $path = UPLOADS_PATH . substr($img['url'], strlen('/uploads'));
                if (file_exists($path)) unlink($path);
            }
        }
        db_run('DELETE FROM Product WHERE id=?', [$id]);
        json_response(['success' => true]);

    case 'set-cover-image':
        $imageId   = (int)($input['imageId'] ?? 0);
        $productId = (int)($input['productId'] ?? 0);
        if (!$imageId || !$productId) json_response(['success' => false, 'message' => 'IDs requeridos']);
        $others = db_query('SELECT id FROM ProductImage WHERE productId=? AND id!=? ORDER BY displayOrder ASC', [$productId, $imageId]);
        db_run('UPDATE ProductImage SET displayOrder=0 WHERE id=?', [$imageId]);
        foreach ($others as $i => $img) {
            db_run('UPDATE ProductImage SET displayOrder=? WHERE id=?', [$i + 1, $img['id']]);
        }
        json_response(['success' => true]);

    case 'delete-image':
        $imageId = (int)($input['imageId'] ?? 0);
        $imgs = db_query('SELECT url FROM ProductImage WHERE id=?', [$imageId]);
        if (!empty($imgs) && strpos($imgs[0]['url'], '/uploads/') === 0) {
            $path = UPLOADS_PATH . substr($imgs[0]['url'], strlen('/uploads'));
            if (file_exists($path)) unlink($path);
        }
        db_run('DELETE FROM ProductImage WHERE id=?', [$imageId]);
        json_response(['success' => true]);

    case 'link-image':
        $productId = (int)($input['productId'] ?? 0);
        $imageUrl  = trim($input['imageUrl'] ?? '');
        if (!$productId || !$imageUrl) json_response(['success' => false, 'message' => 'Missing params']);
        $maxOrder = db_query('SELECT COALESCE(MAX(displayOrder),0) as m FROM ProductImage WHERE productId=?', [$productId])[0]['m'];
        $newImageId = db_execute('INSERT INTO ProductImage (productId, url, displayOrder) VALUES (?,?,?)', [$productId, $imageUrl, $maxOrder + 1]);
        json_response(['success' => true, 'imageId' => (int)$newImageId]);

    default:
        json_response(['error' => 'Unknown action'], 400);
}
