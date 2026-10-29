<?php
require_once __DIR__ . '/core.php';

// Limpa o cookie "lembrar de mim"
if (is_logged_in()) {
    try { db_exec("UPDATE users SET remember_token = NULL, remember_expiry = NULL WHERE id = ?", "i", [current_user_id()]); } catch (Throwable $e) {}
}
setcookie('omigos_remember', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);

// Destrói a sessão completamente
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $params['path'], 'domain' => $params['domain'], 'secure' => $params['secure'], 'httponly' => $params['httponly']]);
}
session_destroy();

header("Location: login.php?loggedout=1");
exit();
