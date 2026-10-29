<?php
/**
 * get_messages.php — Mensagens da conversa (AJAX, JSON).
 * GET: receiver_id
 *
 * Retorna até as 200 mensagens mais recentes (HTML escapado no servidor),
 * o indicador de digitação e a assinatura da conversa (`meta`: last_id,
 * count, edited) usada pelo cliente para atualizações incrementais.
 */
require_once __DIR__ . '/core.php';

require_login_or_exit();
touch_last_active();

$receiverId = (int)($_GET['receiver_id'] ?? 0);
if ($receiverId <= 0) {
    json_error('ID do destinatário não especificado.');
}

try {
    $userId = current_user_id();

    // Verifica se o outro usuário está digitando (últimos 3 segundos)
    $key = conversation_key($userId, $receiverId);
    $stmt = db_exec("SELECT COUNT(*) c FROM typing_status WHERE conversation_key = ? AND user_id = ? AND updated_at >= NOW() - INTERVAL 3 SECOND", "si", [$key, $receiverId]);
    $otherTyping = (int)$stmt->get_result()->fetch_assoc()['c'] > 0;
    $stmt->close();

    // Busca o nome/foto do destinatário
    $stmt = db_exec("SELECT u.username, p.photo_path FROM users u LEFT JOIN user_photos p ON u.id = p.user_id WHERE u.id = ?", "i", [$receiverId]);
    $other = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$other) {
        json_error('Usuário não encontrado.', 404);
    }

    $stmt = db_exec("SELECT u.nome AS my_name, u.username AS my_username, p.photo_path AS my_photo FROM users u LEFT JOIN user_photos p ON u.id = p.user_id WHERE u.id = ?", "i", [$userId]);
    $me = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Mensagens (limite de 200 mais recentes, em ordem cronológica)
    $sql = "SELECT id, sender_id, message, sent_at, edited_at, file_path, file_name, file_type
            FROM (
                SELECT id, sender_id, message, sent_at, edited_at, file_path, file_name, file_type
                FROM messages
                WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
                ORDER BY sent_at DESC, id DESC
                LIMIT 200
            ) recent
            ORDER BY sent_at ASC, id ASC";
    $stmt = db_exec($sql, "iiii", [$userId, $receiverId, $receiverId, $userId]);
    $result = $stmt->get_result();

    // Assinatura da conversa inteira (não apenas das 200 carregadas)
    $stmt2 = db_exec("SELECT COALESCE(MAX(id), 0) AS last_id, COUNT(*) AS c, COALESCE(UNIX_TIMESTAMP(MAX(edited_at)), 0) AS edited
            FROM messages
            WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)",
        "iiii", [$userId, $receiverId, $receiverId, $userId]);
    $metaRow = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();

    $myPhoto    = !empty($me['my_photo']) ? $me['my_photo'] : 'foto_users/foto-padrao.png';
    $otherPhoto = !empty($other['photo_path']) ? $other['photo_path'] : 'foto_users/foto-padrao.png';

    ob_start();
    if ($result->num_rows === 0) {
        ?>
        <div class="empty-state">
            <div class="empty-state-icon">&#128172;</div>
            <div class="empty-state-title">Nenhuma mensagem ainda</div>
            <div class="empty-state-description">Envie a primeira mensagem para <strong><?= e($other['username']) ?></strong>.</div>
        </div>
        <?php
    } else {
        while ($row = $result->fetch_assoc()) {
            $isOwn = ((int)$row['sender_id'] === $userId);
            echo render_message_block($row, $isOwn ? (string)$me['my_name'] : (string)$other['username'], $isOwn ? $myPhoto : $otherPhoto, $isOwn);
        }
    }
    $html = ob_get_clean();
    $stmt->close();

    json_response([
        'success' => true,
        'html'    => $html,
        'typing'  => $otherTyping,
        'meta'    => [
            'last_id' => (int)$metaRow['last_id'],
            'count'   => (int)$metaRow['c'],
            'edited'  => (int)$metaRow['edited'],
        ],
    ]);
} catch (mysqli_sql_exception $e) {
    error_log('get_messages error: ' . $e->getMessage());
    json_error('Erro ao carregar mensagens.', 500);
}
