<?php
/**
 * edit_message.php — Editar mensagem própria (AJAX, JSON).
 * POST: message_id, new_message, csrf_token
 */
require_once __DIR__ . '/core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método não permitido.', 405);
}
require_login();
csrf_verify();

$messageId  = (int)($_POST['message_id'] ?? 0);
$newMessage = trim($_POST['new_message'] ?? '');

if ($messageId <= 0 || $newMessage === '') {
    json_error('Dados inválidos.');
}
if (mb_strlen($newMessage) > 1000) {
    json_error('A mensagem excede o limite de 1000 caracteres.');
}

try {
    $stmt = db_exec("SELECT sender_id FROM messages WHERE id = ? LIMIT 1", "i", [$messageId]);
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        json_error('Mensagem não encontrada.', 404);
    }

    // Permissão: apenas o remetente pode editar (admin NÃO edita mensagens alheias)
    if ((int)$row['sender_id'] !== current_user_id()) {
        json_error('Você só pode editar suas próprias mensagens.', 403);
    }

    $stmt = db_exec("UPDATE messages SET message = ?, edited_at = NOW() WHERE id = ?", "si", [$newMessage, $messageId]);
    $stmt->close();

    json_response(['success' => true, 'message' => 'Mensagem atualizada!']);
} catch (mysqli_sql_exception $e) {
    error_log('edit_message error: ' . $e->getMessage());
    json_error('Erro ao atualizar a mensagem.', 500);
}
