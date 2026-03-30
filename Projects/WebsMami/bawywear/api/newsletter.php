<?php
require_once dirname(__DIR__) . '/config.php';
require_once SHARED_PATH . '/db.php';
require_once SHARED_PATH . '/helpers.php';
session_start();

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
if (!$email) json_response(['success' => false, 'message' => 'Email inválido']);

$existing = db_query('SELECT id FROM NewsletterSubscriber WHERE email = ?', [$email]);
if (!empty($existing)) json_response(['success' => false, 'message' => 'already_subscribed']);

db_execute('INSERT INTO NewsletterSubscriber (email, isActive, createdAt) VALUES (?, 1, NOW())', [$email]);
json_response(['success' => true]);
