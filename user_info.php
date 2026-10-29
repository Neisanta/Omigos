<?php
/**
 * user_info.php — Informações de um usuário (AJAX, JSON).
 * GET: user_id
 */
require_once __DIR__ . '/core.php';

require_login_or_exit();

$targetId = (int)($_GET['user_id'] ?? 0);
if ($targetId <= 0) {
    json_error('ID do usuário não especificado.');
}

try {
    $stmt = db_exec("SELECT u.id, u.nome, u.username, u.curso, u.bio, u.instagram, u.last_active, p.photo_path,
            (u.last_active IS NOT NULL AND u.last_active >= NOW() - INTERVAL 60 SECOND) AS online
            FROM users u
            LEFT JOIN user_photos p ON u.id = p.user_id
            WHERE u.id = ? LIMIT 1", "i", [$targetId]);
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        json_error('Usuário não encontrado.', 404);
    }

    json_response([
        'success'    => true,
        'user'       => [
            'id'         => (int)$row['id'],
            'nome'       => $row['nome'],
            'username'   => $row['username'],
            'curso'      => $row['curso'],
            'bio'        => $row['bio'],
            'instagram'  => $row['instagram'],
            'photo_path' => !empty($row['photo_path']) ? $row['photo_path'] : 'foto_users/foto-padrao.png',
            'online'     => (bool)$row['online'],
            'is_self'    => (int)$row['id'] === current_user_id(),
        ],
    ]);
} catch (mysqli_sql_exception $e) {
    error_log('user_info error: ' . $e->getMessage());
    json_error('Erro ao carregar informações.', 500);
}
