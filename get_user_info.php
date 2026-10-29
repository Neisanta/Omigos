<?php
/**
 * get_user_info.php — Compatibilidade: delega para user_info.php (aceita ?id= ou ?user_id=).
 */
require_once __DIR__ . '/core.php';

require_login_or_exit();

if (isset($_GET['id']) && !isset($_GET['user_id'])) {
    $_GET['user_id'] = $_GET['id'];
}

require __DIR__ . '/user_info.php';
