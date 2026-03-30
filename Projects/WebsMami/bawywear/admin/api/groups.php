<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once SHARED_PATH . '/db.php';
require_once SHARED_PATH . '/helpers.php';
require_once SHARED_PATH . '/auth.php';
session_start(); auth_check();

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

switch ($action) {
    case 'create':
        $name = trim($input['name'] ?? '');
        if (!$name) json_response(['success' => false, 'message' => 'Nombre requerido']);
        $id = db_execute('INSERT INTO CollectionGroup (name) VALUES (?)', [$name]);
        json_response(['success' => true, 'id' => $id]);

    case 'update':
        $id   = (int)($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        if (!$id || !$name) json_response(['success' => false, 'message' => 'ID y nombre requeridos']);
        db_run('UPDATE CollectionGroup SET name=? WHERE id=?', [$name, $id]);
        json_response(['success' => true]);

    case 'delete':
        $id = (int)($input['id'] ?? 0);
        $count = db_query('SELECT COUNT(*) as c FROM Collection WHERE groupId = ?', [$id])[0]['c'];
        if ($count > 0) json_response(['success' => false, 'message' => 'El grupo tiene colecciones asignadas']);
        db_run('DELETE FROM CollectionGroup WHERE id = ?', [$id]);
        json_response(['success' => true]);

    default:
        json_response(['error' => 'Unknown action'], 400);
}
