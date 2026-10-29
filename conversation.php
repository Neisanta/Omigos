<?php
/**
 * conversation.php — Compatibilidade: delega o envio para send_message.php.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/send_message.php';
}

// GET: redireciona para o chat
require_once __DIR__ . '/core.php';
require_login();
header("Location: chat.php");
exit();
