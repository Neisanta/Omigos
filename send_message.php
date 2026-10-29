<?php
/**
 * send_message.php — Envio de mensagens (AJAX, JSON).
 * POST: message, receiver_id, csrf_token [, file — SOMENTE imagens: JPG/PNG/GIF/WEBP]
 */
require_once __DIR__ . '/core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método não permitido.', 405);
}
require_login();
csrf_verify();
touch_last_active();

$receiverId = (int)($_POST['receiver_id'] ?? 0);
$message    = trim($_POST['message'] ?? '');

// Anexo presente?
$hasUpload = isset($_FILES['file']) && is_array($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE;

// 1) Validações básicas (antes de mover qualquer arquivo para o disco)
if ($receiverId <= 0) {
    json_error('Selecione um usuário para conversar.');
}
if ($message === '' && !$hasUpload) {
    json_error('A mensagem não pode estar vazia.');
}
if (mb_strlen($message) > 1000) {
    json_error('A mensagem excede o limite de 1000 caracteres.');
}
if ($receiverId === current_user_id()) {
    json_error('Você não pode enviar mensagem para si mesmo.');
}

try {
    // 2) Destinatário existe?
    $stmt = db_exec("SELECT id FROM users WHERE id = ? LIMIT 1", "i", [$receiverId]);
    if (!$stmt->get_result()->fetch_assoc()) {
        json_error('Usuário não encontrado.');
    }
    $stmt->close();

    $senderId = current_user_id();

    // 3) Anti-spam: limita envios em sequência
    if (!sender_under_rate_limit($senderId, 20, 30)) {
        json_error('Você está enviando mensagens rápido demais. Aguarde alguns segundos.', 429);
    }

    // 4) Anti-duplicação (idempotente): mesma mensagem de texto para o mesmo
    //    destinatário em menos de 3 segundos devolve a mensagem já gravada —
    //    cobre duplo-clique, retry de rede e bypass do frontend
    if ($message !== '') {
        $stmt = db_exec("SELECT id, sender_id, message, sent_at, edited_at, file_path, file_name, file_type
                FROM messages
                WHERE sender_id = ? AND receiver_id = ? AND sent_at >= NOW() - INTERVAL 3 SECOND
                ORDER BY id DESC LIMIT 5",
            "ii", [$senderId, $receiverId]);
        $result = $stmt->get_result();
        $dupRow = null;
        while ($row = $result->fetch_assoc()) {
            if ($row['message'] === $message) { $dupRow = $row; break; } // comparação binária exata
        }
        $stmt->close();
        if ($dupRow !== null) {
            $stmt = db_exec("SELECT u.username, p.photo_path FROM users u LEFT JOIN user_photos p ON u.id = p.user_id WHERE u.id = ? LIMIT 1", "i", [$senderId]);
            $me = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $photo = !empty($me['photo_path']) ? $me['photo_path'] : 'foto_users/foto-padrao.png';
            json_response([
                'success'    => true,
                'message_id' => (int)$dupRow['id'],
                'html'       => render_message_block($dupRow, (string)$me['username'], $photo, true),
                'duplicate'  => true,
            ]);
        }
    }

    // 5) Só agora salva o anexo — apenas imagens, validadas por conteúdo
    //    real (finfo + getimagesize), com nome interno aleatório
    $filePath = null; $fileName = null; $fileType = null;
    if ($hasUpload) {
        try {
            $img = save_image_upload($_FILES['file'], 'uploads', 8 * 1024 * 1024);
            $filePath = $img['path'];
            $fileName = $img['orig_name'];
            $fileType = $img['ext'];
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), (int)($e->getCode() ?: 400));
        }
    }

    $convId   = get_or_create_conversation($senderId, $receiverId);

    $conn = db();
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO messages (conversation_id, sender_id, receiver_id, message, file_path, file_name, file_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiissss", $convId, $senderId, $receiverId, $message, $filePath, $fileName, $fileType);
        $stmt->execute();
        $messageId = (int)$stmt->insert_id;
        $stmt->close();

        // Notifica o destinatário (não lidas)
        $stmt = $conn->prepare("INSERT INTO unread_messages (user_id, message_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $receiverId, $messageId);
        $stmt->execute();
        $stmt->close();

        // Limpa o status "digitando" do remetente
        $key = conversation_key($senderId, $receiverId);
        $stmt = $conn->prepare("DELETE FROM typing_status WHERE conversation_key = ? AND user_id = ?");
        $stmt->bind_param("si", $key, $senderId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        // Remove o anexo recém-salvo para não deixar arquivo órfão no disco
        if ($filePath && strpos($filePath, '..') === false && is_file(__DIR__ . '/' . $filePath)) {
            @unlink(__DIR__ . '/' . $filePath);
        }
        throw $e;
    }

    // Busca dados do remetente para renderizar a mensagem
    $stmt = db_exec("SELECT u.username, p.photo_path FROM users u LEFT JOIN user_photos p ON u.id = p.user_id WHERE u.id = ?", "i", [$senderId]);
    $me = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $photo = !empty($me['photo_path']) ? $me['photo_path'] : 'foto_users/foto-padrao.png';
    $html = render_message_block([
        'id'        => $messageId,
        'message'   => $message,
        'sent_at'   => null,
        'edited_at' => null,
        'file_path' => $filePath,
        'file_name' => $fileName,
        'file_type' => $fileType,
    ], (string)$me['username'], $photo, true);

    json_response([
        'success'    => true,
        'message_id' => $messageId,
        'html'       => $html,
    ]);
} catch (mysqli_sql_exception $e) {
    error_log('send_message error: ' . $e->getMessage());
    json_error('Erro ao enviar a mensagem. Tente novamente.', 500);
}
