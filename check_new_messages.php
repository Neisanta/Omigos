<?php
/**
 * check_new_messages.php — Polling de não lidas (AJAX, JSON).
 * Retorna contagem por remetente + total + status online dos usuários.
 */
require_once __DIR__ . '/core.php';

require_login_or_exit();
touch_last_active();

try {
    $userId = current_user_id();

    $stmt = db_exec("SELECT m.sender_id, COUNT(*) c
            FROM unread_messages um
            JOIN messages m ON um.message_id = m.id
            WHERE um.user_id = ?
            GROUP BY m.sender_id", "i", [$userId]);
    $result = $stmt->get_result();

    $unread = [];
    $total = 0;
    while ($row = $result->fetch_assoc()) {
        $unread[(int)$row['sender_id']] = (int)$row['c'];
        $total += (int)$row['c'];
    }
    $stmt->close();

    json_response([
        'success' => true,
        'unread'  => $unread,
        'total'   => $total,
    ]);
} catch (mysqli_sql_exception $e) {
    error_log('check_new_messages error: ' . $e->getMessage());
    json_error('Erro ao verificar mensagens.', 500);
}
