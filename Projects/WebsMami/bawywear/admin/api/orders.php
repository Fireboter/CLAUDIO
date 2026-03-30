<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once SHARED_PATH . '/db.php';
require_once SHARED_PATH . '/helpers.php';
require_once SHARED_PATH . '/auth.php';
session_start(); auth_check();

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

$validStatuses = ['pending','processing','shipped','delivered','cancelled','payment_failed'];

switch ($action) {
    case 'update-status':
        $orderId = (int)($input['orderId'] ?? 0);
        $status  = $input['status'] ?? '';
        if (!$orderId || !in_array($status, $validStatuses)) json_response(['success' => false, 'message' => 'Datos inválidos']);
        db_run("UPDATE `Order` SET status = ?, updatedAt = NOW() WHERE id = ?", [$status, $orderId]);
        json_response(['success' => true]);

    default:
        json_response(['error' => 'Unknown action'], 400);
}
