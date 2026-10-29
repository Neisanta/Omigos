<?php
/**
 * delete_old_messages.php — Exclui mensagens antigas conforme configuração (admin apenas).
 * POST: csrf_token
 */
require_once __DIR__ . '/core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método não permitido. Use POST.', 405);
}
require_admin();
csrf_verify();

header('Content-Type: application/json; charset=utf-8');

try {
    $config = db_exec("SELECT value FROM settings WHERE name = 'message_deletion_time' LIMIT 1")->get_result()->fetch_assoc();
    if (!$config) {
        json_response(['success' => false, 'error' => 'Configuração de exclusão não encontrada.']);
    }

    $timeInSeconds = (int)$config['value'];
    if ($timeInSeconds <= 0) {
        json_response(['success' => false, 'error' => 'Configuração inválida.']);
    }

    $stmt = db_exec("DELETE FROM messages WHERE sent_at < NOW() - INTERVAL ? SECOND", "i", [$timeInSeconds]);
    $deleted = $stmt->affected_rows;
    $stmt->close();

    json_response(['success' => true, 'deleted' => $deleted]);
} catch (mysqli_sql_exception $e) {
    error_log('delete_old_messages: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Erro ao excluir mensagens.'], 500);
}
