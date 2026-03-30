<?php
require_once dirname(__DIR__) . '/config.php';
require_once SHARED_PATH . '/db.php';
require_once SHARED_PATH . '/helpers.php';
require_once SHARED_PATH . '/email.php';
session_start();

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error' => 'Method not allowed'], 405);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$name     = trim($input['name'] ?? '');
$email    = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
$message  = trim($input['message'] ?? '');
$formType = $input['formType'] ?? 'contact';

if (!$name || !$email || !$message) json_response(['success' => false, 'message' => 'Todos los campos son obligatorios']);

db_execute('INSERT INTO ContactSubmission (name, email, message, formType, isRead, createdAt) VALUES (?, ?, ?, ?, 0, NOW())',
    [$name, $email, $message, $formType]);

send_contact_notification($name, $email, $message, $formType);

json_response(['success' => true]);
