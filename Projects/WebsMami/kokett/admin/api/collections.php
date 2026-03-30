<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once SHARED_PATH . '/db.php';
require_once SHARED_PATH . '/helpers.php';
require_once SHARED_PATH . '/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start(); auth_check();

header('Content-Type: application/json');

if (isset($_GET['action']) && $_GET['action'] === 'upload-image') {
    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) json_response(['success' => false]);
    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp'])) json_response(['success' => false]);
    $filename = uniqid('col_') . '.' . $ext;
    move_uploaded_file($_FILES['image']['tmp_name'], UPLOADS_PATH . '/collections/' . $filename);
    json_response(['success' => true, 'url' => UPLOADS_URL . '/collections/' . $filename]);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

switch ($action) {
    case 'create':
        $id = db_execute('INSERT INTO Collection (name, groupId, image, createdAt, updatedAt) VALUES (?, ?, ?, NOW(), NOW())',
            [trim($input['name']), $input['groupId'] ?: null, $input['image'] ?? null]);
        json_response(['success' => true, 'id' => $id]);

    case 'update':
        db_run('UPDATE Collection SET name=?, groupId=?, updatedAt=NOW() WHERE id=?',
            [trim($input['name']), $input['groupId'] ?: null, (int)$input['id']]);
        if (!empty($input['image'])) db_run('UPDATE Collection SET image=? WHERE id=?', [$input['image'], (int)$input['id']]);
        json_response(['success' => true]);

    case 'delete':
        db_run('UPDATE Product SET collectionId = NULL WHERE collectionId = ?', [(int)$input['id']]);
        db_run('DELETE FROM Collection WHERE id = ?', [(int)$input['id']]);
        json_response(['success' => true]);

    default:
        json_response(['error' => 'Unknown action'], 400);
}
