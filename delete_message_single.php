<?php
/**
 * delete_message_single.php — Excluir mensagem (apenas o REMETENTE ou admin) (AJAX, JSON).
 * POST: message_id, csrf_token
 *
 * O destinatário NÃO pode excluir a mensagem do remetente: isso apagaria
 * o registro também para quem enviou (exclusão indevida de mensagem alheia).
 */
require_once __DIR__ . '/core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método não permitido.', 405);
}
require_login();
csrf_verify();

$messageId = (int)($_POST['message_id'] ?? 0);
if ($messageId <= 0) {
    json_error('ID da mensagem inválido.');
}

try {
    $userId = current_user_id();
    $stmt = db_exec("SELECT sender_id, receiver_id, file_path FROM messages WHERE id = ? LIMIT 1", "i", [$messageId]);
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        json_error('Mensagem não encontrada.', 404);
    }

    // Permissão: apenas o remetente (ou admin) pode excluir
    $isAllowed = ((int)$row['sender_id'] === $userId) || is_admin();
    if (!$isAllowed) {
        json_error('Você não tem permissão para excluir esta mensagem.', 403);
    }

    $stmt = db_exec("DELETE FROM messages WHERE id = ?", "i", [$messageId]);
    $stmt->close();

    // unread_messages tem ON DELETE CASCADE, mas garantimos a limpeza
    $stmt = db_exec("DELETE FROM unread_messages WHERE message_id = ?", "i", [$messageId]);
    $stmt->close();

    // Remove o anexo da mensagem do disco (se existir e for um caminho seguro)
    if (!empty($row['file_path']) && strpos($row['file_path'], '..') === false && is_file(__DIR__ . '/' . $row['file_path'])) {
        @unlink(__DIR__ . '/' . $row['file_path']);
    }

    json_response(['success' => true, 'message' => 'Mensagem excluída!']);
} catch (mysqli_sql_exception $e) {
    error_log('delete_message_single error: ' . $e->getMessage());
    json_error('Erro ao excluir a mensagem.', 500);
}
