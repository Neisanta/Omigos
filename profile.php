<?php
/**
 * profile.php — Perfil do usuário logado (AJAX, JSON).
 * GET: dados do próprio usuário
 * POST action=update: nome, bio, instagram
 * POST action=avatar: upload de nova foto
 */
require_once __DIR__ . '/core.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $userId = current_user_id();
    $stmt = db_exec("SELECT u.id, u.nome, u.username, u.curso, u.rm, u.bio, u.instagram, p.photo_path
            FROM users u LEFT JOIN user_photos p ON u.id = p.user_id WHERE u.id = ? LIMIT 1", "i", [$userId]);
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) json_error('Usuário não encontrado.', 404);

    json_response(['success' => true, 'user' => [
        'id' => (int)$row['id'],
        'nome' => $row['nome'],
        'username' => $row['username'],
        'curso' => $row['curso'],
        'rm' => $row['rm'],
        'bio' => $row['bio'],
        'instagram' => $row['instagram'],
        'photo_path' => !empty($row['photo_path']) ? $row['photo_path'] : 'foto_users/foto-padrao.png',
    ]]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    touch_last_active();
    $userId = current_user_id();
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $nome      = trim($_POST['nome'] ?? '');
        $bio       = trim($_POST['bio'] ?? '');
        $instagram = trim($_POST['instagram'] ?? '');

        if ($nome === '' || mb_strlen($nome) > 100) {
            json_error('Informe um nome válido (até 100 caracteres).');
        }
        if (mb_strlen($bio) > 500) {
            json_error('A bio deve ter até 500 caracteres.');
        }
        $instagram = ltrim($instagram, '@');
        if ($instagram !== '' && !preg_match('/^[a-zA-Z0-9._]{1,30}$/', $instagram)) {
            json_error('Instagram inválido.');
        }

        try {
            $stmt = db_exec("UPDATE users SET nome = ?, bio = ?, instagram = ? WHERE id = ?", "sssi", [$nome, $bio, $instagram, $userId]);
            $stmt->close();
            json_response(['success' => true, 'message' => 'Perfil atualizado com sucesso!']);
        } catch (mysqli_sql_exception $e) {
            error_log('profile update error: ' . $e->getMessage());
            json_error('Erro ao atualizar o perfil.', 500);
        }
    }

    if ($action === 'avatar') {
        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
            json_error('Selecione uma foto para enviar.');
        }
        try {
            // Somente imagens, validadas por conteúdo real (helper compartilhado)
            $img = save_image_upload($_FILES['photo'], 'foto_users', 5 * 1024 * 1024);
            $photo_path = $img['path'];

            // Foto antiga (para remover se não for a padrão)
            $stmt = db_exec("SELECT photo_path FROM user_photos WHERE user_id = ? LIMIT 1", "i", [$userId]);
            $oldRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $oldPath = $oldRow['photo_path'] ?? null;

            // Grava o novo caminho no banco
            $stmt = db_exec("SELECT id FROM user_photos WHERE user_id = ? LIMIT 1", "i", [$userId]);
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($exists) {
                $stmt = db_exec("UPDATE user_photos SET photo_path = ? WHERE user_id = ?", "si", [$photo_path, $userId]);
                $stmt->close();
            } else {
                $stmt = db_exec("INSERT INTO user_photos (user_id, photo_path) VALUES (?, ?)", "is", [$userId, $photo_path]);
                $stmt->close();
            }

            // Remove a foto antiga do disco se não for a padrão
            if ($oldPath && !in_array($oldPath, ['foto_users/foto-padrao.png', $photo_path], true) && strpos($oldPath, '..') === false && is_file(__DIR__ . '/' . $oldPath)) {
                @unlink(__DIR__ . '/' . $oldPath);
            }

            json_response(['success' => true, 'photo_path' => $photo_path, 'message' => 'Foto atualizada!']);
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), (int)($e->getCode() ?: 400));
        } catch (mysqli_sql_exception $e) {
            error_log('avatar error: ' . $e->getMessage());
            json_error('Erro ao atualizar a foto.', 500);
        }
    }

    json_error('Ação inválida.');
}

json_error('Método não permitido.', 405);
