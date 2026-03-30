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
    $filename = 'hero_' . time() . '.' . $ext;
    move_uploaded_file($_FILES['image']['tmp_name'], UPLOADS_PATH . '/' . $filename);
    json_response(['success' => true, 'url' => UPLOADS_URL . '/' . $filename]);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

if ($action === 'save') {
    foreach ($input['settings'] as $key => $value) {
        $existing = db_query('SELECT `key` FROM SiteSettings WHERE `key` = ? AND site = ?', [$key, SITE_ID]);
        if ($existing) {
            db_run('UPDATE SiteSettings SET `value` = ? WHERE `key` = ? AND site = ?', [$value, $key, SITE_ID]);
        } else {
            db_execute('INSERT INTO SiteSettings (site, `key`, `value`) VALUES (?, ?, ?)', [SITE_ID, $key, $value]);
        }
    }
    json_response(['success' => true]);
}

json_response(['error' => 'Unknown action'], 400);
