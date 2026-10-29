<?php
/**
 * conversation_updates.php — Long polling incremental da conversa ativa (AJAX, JSON).
 *
 * GET: receiver_id  → o outro usuário da conversa
 *      last_id      → maior id de mensagem que o cliente já possui
 *      known_count  → total de mensagens que o cliente conhece (-1 = desconhecido)
 *      known_edited → timestamp unix da última edição conhecida (0 = nenhuma)
 *      known_typing → "1" se o cliente já exibe o indicador de digitação
 *      timeout      → segundos máximos segurando a requisição (3–25, padrão 15)
 *
 * Respostas:
 *  - updated=true + new_html → apenas as mensagens novas (id > last_id), prontas para anexar
 *  - updated=true + refresh=true → houve exclusão/edição remota; cliente recarrega a conversa
 *  - updated=false → nada mudou; o cliente reconsulta imediatamente
 *  Sempre inclui `typing` e a assinatura atual (`meta`) da conversa.
 *
 * O loop interna faz apenas queries indexadas e baratas; nenhuma renderização
 * acontece enquanto não há novidade. Sessão é liberada (session_write_close)
 * antes do loop para não travar as demais requisições do usuário.
 */
require_once __DIR__ . '/core.php';

require_login();

$receiverId = (int)($_GET['receiver_id'] ?? 0);
$lastId     = (int)($_GET['last_id'] ?? 0);
$knownCount = (int)($_GET['known_count'] ?? -1);
$knownEdited = (int)($_GET['known_edited'] ?? 0);
$knownTyping = (($_GET['known_typing'] ?? '0') === '1');
$timeout    = (int)($_GET['timeout'] ?? 15);

$me = current_user_id();
if ($receiverId <= 0 || $receiverId === $me) {
    json_error('Conversa inválida.');
}
$timeout = min(25, max(3, $timeout));

try {
    // O destinatário existe? (mesma semântica de get_messages.php)
    $stmt = db_exec("SELECT u.username, p.photo_path FROM users u LEFT JOIN user_photos p ON u.id = p.user_id WHERE u.id = ? LIMIT 1", "i", [$receiverId]);
    $other = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$other) {
        json_error('Usuário não encontrado.', 404);
    }

    // Libera o lock da sessão ANTES do loop longo (senão envio/digitação travariam)
    session_write_close();
    set_time_limit($timeout + 10);

    $deadline = time() + $timeout;
    $convKey  = conversation_key($me, $receiverId);

    while (true) {
        // Assinatura barata da conversa (queries indexadas)
        $stmt = db_exec("SELECT COALESCE(MAX(id), 0) AS last_id, COUNT(*) AS c, COALESCE(UNIX_TIMESTAMP(MAX(edited_at)), 0) AS edited
                FROM messages
                WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)",
            "iiii", [$me, $receiverId, $receiverId, $me]);
        $sig = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = db_exec("SELECT COUNT(*) c FROM typing_status WHERE conversation_key = ? AND user_id = ? AND updated_at >= NOW() - INTERVAL 3 SECOND", "si", [$convKey, $receiverId]);
        $typing = (int)$stmt->get_result()->fetch_assoc()['c'] > 0;
        $stmt->close();

        $hasNew    = ((int)$sig['last_id'] > $lastId);
        // Digitação também desperta o long polling (o cliente já sabe o estado atual)
        $typingChanged = ($typing !== $knownTyping);
        $changed   = $hasNew
            || ($knownCount >= 0 && (int)$sig['c'] !== $knownCount)
            || ($knownEdited > 0 && (int)$sig['edited'] > $knownEdited)
            || $typingChanged;

        if ($changed) {
            $html = '';
            $newCount = 0;
            if ($hasNew) {
                // Busca SOMENTE as mensagens novas desta conversa
                $stmt = db_exec("SELECT id, sender_id, message, sent_at, edited_at, file_path, file_name, file_type
                        FROM messages
                        WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)) AND id > ?
                        ORDER BY sent_at ASC, id ASC",
                    "iiiii", [$me, $receiverId, $receiverId, $me, $lastId]);
                $result = $stmt->get_result();

                $stmt2 = db_exec("SELECT u.nome, u.username, p.photo_path FROM users u LEFT JOIN user_photos p ON u.id = p.user_id WHERE u.id = ? LIMIT 1", "i", [$me]);
                $myRow = $stmt2->get_result()->fetch_assoc();
                $stmt2->close();

                $myPhoto   = !empty($myRow['photo_path']) ? $myRow['photo_path'] : 'foto_users/foto-padrao.png';
                $otherPhoto = !empty($other['photo_path']) ? $other['photo_path'] : 'foto_users/foto-padrao.png';

                while ($row = $result->fetch_assoc()) {
                    $isOwn = ((int)$row['sender_id'] === $me);
                    // Mantém o comportamento de get_messages: nome completo para as próprias
                    $html .= render_message_block($row, $isOwn ? (string)$myRow['nome'] : (string)$other['username'], $isOwn ? $myPhoto : $otherPhoto, $isOwn);
                    $newCount++;
                }
                $stmt->close();

                // Recalcula a assinatura após renderizar (cobrem-se corridas)
                $stmt = db_exec("SELECT COALESCE(MAX(id), 0) AS last_id, COUNT(*) AS c, COALESCE(UNIX_TIMESTAMP(MAX(edited_at)), 0) AS edited
                        FROM messages
                        WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)",
                    "iiii", [$me, $receiverId, $receiverId, $me]);
                $sig = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }

            json_response([
                'success' => true,
                'updated' => true,
                'refresh' => ($knownCount >= 0 && (int)$sig['c'] !== $knownCount && !$hasNew)
                    || ($knownEdited > 0 && (int)$sig['edited'] > $knownEdited),
                'new_html'  => $html,
                'new_count' => $newCount,
                'typing'    => $typing,
                'meta'      => [
                    'last_id' => (int)$sig['last_id'],
                    'count'   => (int)$sig['c'],
                    'edited'  => (int)$sig['edited'],
                ],
            ]);
        }

        if (time() >= $deadline) {
            json_response([
                'success' => true,
                'updated' => false,
                'typing'  => $typing,
                'meta'    => [
                    'last_id' => (int)$sig['last_id'],
                    'count'   => (int)$sig['c'],
                    'edited'  => (int)$sig['edited'],
                ],
            ]);
        }

        sleep(1);
    }
} catch (mysqli_sql_exception $e) {
    error_log('conversation_updates error: ' . $e->getMessage());
    json_error('Erro ao verificar atualizações.', 500);
}
