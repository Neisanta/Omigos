<?php
/**
 * get_deletion_time.php — Retorna o tempo de retenção de mensagens (JSON, login necessário).
 */
require_once __DIR__ . '/core.php';
require_login_or_exit();

try {
    $row = db_exec("SELECT value FROM settings WHERE name = 'message_deletion_time' LIMIT 1")->get_result()->fetch_assoc();
    if ($row) {
        json_response(['success' => true, 'time' => (int)$row['value']]);
    }
    json_response(['success' => false]);
} catch (mysqli_sql_exception $e) {
    error_log('get_deletion_time: ' . $e->getMessage());
    json_response(['success' => false], 500);
}
