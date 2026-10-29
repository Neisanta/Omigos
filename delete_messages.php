<?php
/**
 * delete_messages.php — Exclui mensagens mais antigas que N segundos (admin apenas, JSON).
 * POST: seconds, csrf_token
 */
require_once __DIR__ . '/core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método não permitido. Use POST.', 405);
}
require_admin();
csrf_verify();

$data = json_decode(file_get_contents('php://input'), true);
$seconds = (int)($data['seconds'] ?? $_POST['seconds'] ?? 0);

if ($seconds <= 0 || $seconds > 31536000) {
    json_error('Intervalo de tempo inválido.');
}

try {
    $stmt = db_exec("DELETE FROM messages WHERE sent_at < NOW() - INTERVAL ? SECOND", "i", [$seconds]);
    $deleted = $stmt->affected_rows;
    $stmt->close();
    json_response(['success' => true, 'deleted' => $deleted]);
} catch (mysqli_sql_exception $e) {
    error_log('delete_messages: ' . $e->getMessage());
    json_error('Erro ao excluir mensagens.', 500);
}
