<?php
/**
 * admin_actions.php — Ações administrativas (POST + CSRF + admin obrigatório).
 */
require_once __DIR__ . '/core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método não permitido. Use POST.', 405);
}
require_admin();
csrf_verify();

$action = $_POST['action'] ?? '';
$back   = $_POST['back'] ?? 'admin.php';
// Valida o redirect para evitar open redirect
if (!is_string($back) || !preg_match('/^admin\.php(\?.*)?$/', $back)) {
    $back = 'admin.php';
}

if ($action === 'delete_user') {
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId <= 0) {
        header("Location: $back");
        exit();
    }
    try {
        // Foto do usuário (para remover do disco)
        $stmt = db_exec("SELECT photo_path FROM user_photos WHERE user_id = ? LIMIT 1", "i", [$userId]);
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $photoPath = $row['photo_path'] ?? null;

        // Anexos das mensagens que serão removidas (para limpar do disco)
        $stmt = db_exec("SELECT file_path FROM messages WHERE (sender_id = ? OR receiver_id = ?) AND file_path IS NOT NULL", "ii", [$userId, $userId]);
        $result = $stmt->get_result();
        $attachPaths = [];
        while ($r = $result->fetch_assoc()) {
            if (!empty($r['file_path']) && strpos($r['file_path'], '..') === false) $attachPaths[] = $r['file_path'];
        }
        $stmt->close();

        // Remove mensagens e dados do usuário (unread_messages tem CASCADE)
        db_exec("DELETE FROM messages WHERE sender_id = ? OR receiver_id = ?", "ii", [$userId, $userId]);
        db_exec("DELETE FROM typing_status WHERE user_id = ?", "i", [$userId]);
        db_exec("DELETE FROM user_photos WHERE user_id = ?", "i", [$userId]);
        db_exec("DELETE FROM users WHERE id = ?", "i", [$userId]);

        // Remove a foto do disco se não for a padrão
        if ($photoPath && $photoPath !== 'foto_users/foto-padrao.png' && strpos($photoPath, '..') === false && is_file(__DIR__ . '/' . $photoPath)) {
            @unlink(__DIR__ . '/' . $photoPath);
        }

        // Remove os anexos do disco
        foreach ($attachPaths as $p) {
            if (is_file(__DIR__ . '/' . $p)) @unlink(__DIR__ . '/' . $p);
        }
    } catch (mysqli_sql_exception $e) {
        error_log('admin delete_user: ' . $e->getMessage());
    }
    header("Location: $back");
    exit();
}

if ($action === 'delete_message') {
    $messageId = (int)($_POST['message_id'] ?? 0);
    if ($messageId > 0) {
        try {
            // Anexo da mensagem (para remover do disco)
            $stmt = db_exec("SELECT file_path FROM messages WHERE id = ? LIMIT 1", "i", [$messageId]);
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $attachPath = $row['file_path'] ?? null;

            db_exec("DELETE FROM messages WHERE id = ?", "i", [$messageId]);
            db_exec("DELETE FROM unread_messages WHERE message_id = ?", "i", [$messageId]);

            if ($attachPath && strpos($attachPath, '..') === false && is_file(__DIR__ . '/' . $attachPath)) {
                @unlink(__DIR__ . '/' . $attachPath);
            }
        } catch (mysqli_sql_exception $e) {
            error_log('admin delete_message: ' . $e->getMessage());
        }
    }
    header("Location: $back");
    exit();
}

// Ação desconhecida
header("Location: $back");
exit();
