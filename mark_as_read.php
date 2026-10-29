<?php
/**
 * mark_as_read.php — Marca mensagens de um remetente como lidas (AJAX, JSON).
 * POST: sender_id, csrf_token
 */
require_once __DIR__ . '/core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método não permitido.', 405);
}
require_login();
csrf_verify();

$senderId = (int)($_POST['sender_id'] ?? 0);
if ($senderId <= 0) {
    json_error('Remetente inválido.');
}

try {
    $userId = current_user_id();
    $stmt = db_exec("DELETE um FROM unread_messages um
            JOIN messages m ON um.message_id = m.id
            WHERE um.user_id = ? AND m.sender_id = ?", "ii", [$userId, $senderId]);
    $stmt->close();

    json_response(['success' => true]);
} catch (mysqli_sql_exception $e) {
    error_log('mark_as_read error: ' . $e->getMessage());
    json_error('Erro ao marcar como lida.', 500);
}
