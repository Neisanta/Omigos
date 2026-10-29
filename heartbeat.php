<?php
/**
 * heartbeat.php — Presença: atualiza last_active e retorna status online (AJAX, JSON).
 */
require_once __DIR__ . '/core.php';

require_login_or_exit();
touch_last_active();

try {
    $stmt = db_exec("SELECT id, last_active FROM users WHERE last_active >= NOW() - INTERVAL 60 SECOND");
    $result = $stmt->get_result();
    $online = [];
    while ($row = $result->fetch_assoc()) {
        $online[(int)$row['id']] = true;
    }
    $stmt->close();

    json_response([
        'success' => true,
        'online'  => array_keys($online),
        'me'      => current_user_id(),
    ]);
} catch (mysqli_sql_exception $e) {
    error_log('heartbeat error: ' . $e->getMessage());
    json_error('Erro de heartbeat.', 500);
}
