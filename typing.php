<?php
/**
 * typing.php — Heartbeat do indicador "digitando..." (AJAX, JSON).
 * POST: receiver_id, csrf_token — grava que o usuário atual está digitando.
 */
require_once __DIR__ . '/core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método não permitido.', 405);
}
require_login();
csrf_verify();

$receiverId = (int)($_POST['receiver_id'] ?? 0);
if ($receiverId <= 0 || $receiverId === current_user_id()) {
    json_response(['success' => true]); // silencioso
}

try {
    $senderId = current_user_id();
    $key = conversation_key($senderId, $receiverId);

    // Upsert do status de digitação
    $stmt = db_exec("INSERT INTO typing_status (conversation_key, user_id, updated_at) VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE updated_at = NOW()", "si", [$key, $senderId]);
    $stmt->close();

    json_response(['success' => true]);
} catch (mysqli_sql_exception $e) {
    error_log('typing error: ' . $e->getMessage());
    json_response(['success' => true]); // não incomoda o usuário por isso
}
