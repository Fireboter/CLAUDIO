<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once SHARED_PATH . '/db.php';
require_once SHARED_PATH . '/helpers.php';
require_once SHARED_PATH . '/auth.php';
require_once SHARED_PATH . '/email.php';
session_start(); auth_check();

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

switch ($action) {
    case 'send-test':
        $ok = send_newsletter(ADMIN_EMAIL, $input['subject'], $input['body']);
        json_response(['success' => $ok, 'message' => $ok ? 'Email de prueba enviado a ' . ADMIN_EMAIL : 'Error al enviar']);

    case 'send-all':
        $subscribers = db_query('SELECT email FROM NewsletterSubscriber WHERE isActive = 1');
        $sent = 0; $failed = 0;
        foreach ($subscribers as $s) {
            if (send_newsletter($s['email'], $input['subject'], $input['body'])) $sent++;
            else $failed++;
        }
        json_response(['success' => true, 'message' => "Enviados: $sent. Fallidos: $failed."]);

    case 'unsubscribe':
        db_run('UPDATE NewsletterSubscriber SET isActive = 0 WHERE id = ?', [(int)$input['id']]);
        json_response(['success' => true]);

    default:
        json_response(['error' => 'Unknown action'], 400);
}
