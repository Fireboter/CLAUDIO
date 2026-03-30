<?php
require_once dirname(__DIR__) . '/config.php';
require_once SHARED_PATH . '/db.php';
require_once SHARED_PATH . '/helpers.php';
session_start();

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$code  = strtoupper(trim($input['code'] ?? ''));

if (!$code) json_response(['valid' => false, 'message' => 'Código vacío']);

$rows = db_query('SELECT id, amount, expiresAt, usedAt FROM DiscountCode WHERE code = ?', [$code]);
if (empty($rows)) json_response(['valid' => false, 'message' => 'Código no válido']);

$dc = $rows[0];
if ($dc['usedAt']) json_response(['valid' => false, 'message' => 'Código ya utilizado']);
if (strtotime($dc['expiresAt']) < time()) json_response(['valid' => false, 'message' => 'Código expirado']);

json_response(['valid' => true, 'amount' => (float)$dc['amount']]);
