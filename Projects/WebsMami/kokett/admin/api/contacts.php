<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once SHARED_PATH . '/db.php';
require_once SHARED_PATH . '/helpers.php';
require_once SHARED_PATH . '/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start(); auth_check();

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

switch ($action) {
    case 'mark-read':
        db_run('UPDATE ContactSubmission SET isRead = 1 WHERE id = ?', [(int)$input['id']]);
        json_response(['success' => true]);

    case 'reply':
        $id      = (int)($input['id'] ?? 0);
        $subject = trim($input['subject'] ?? '');
        $message = trim($input['message'] ?? '');
        if (!$id || !$subject || !$message)
            json_response(['success' => false, 'message' => 'Faltan campos']);
        $rows = db_query('SELECT name, email FROM ContactSubmission WHERE id = ?', [$id]);
        if (empty($rows)) json_response(['success' => false, 'message' => 'Contacto no encontrado']);
        require_once SHARED_PATH . '/email.php';
        $ok = send_contact_reply($rows[0]['email'], $rows[0]['name'], $subject, $message);
        if ($ok) db_run('UPDATE ContactSubmission SET isRead = 1 WHERE id = ?', [$id]);
        json_response(['success' => $ok, 'message' => $ok ? 'Respuesta enviada' : 'Error al enviar']);

    case 'delete':
        db_run('DELETE FROM ContactSubmission WHERE id = ?', [(int)$input['id']]);
        json_response(['success' => true]);

    default:
        json_response(['error' => 'Unknown action'], 400);
}
