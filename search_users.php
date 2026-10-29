<?php
/**
 * search_users.php — Busca de usuários (AJAX, JSON).
 * GET: search_term
 */
require_once __DIR__ . '/core.php';

require_login_or_exit();
touch_last_active();

$searchTerm = trim($_GET['search_term'] ?? '');
if ($searchTerm === '') {
    json_response(['success' => true, 'users' => []]);
}

try {
    $userId = current_user_id();
    $like = '%' . $searchTerm . '%';

    $stmt = db_exec("SELECT u.id, u.username, u.curso, u.last_active, p.photo_path,
            (SELECT COUNT(*) FROM unread_messages um
             JOIN messages m ON um.message_id = m.id
             WHERE m.sender_id = u.id AND um.user_id = ?) AS unread_count,
            (u.last_active IS NOT NULL AND u.last_active >= NOW() - INTERVAL 60 SECOND) AS online
            FROM users u
            LEFT JOIN user_photos p ON u.id = p.user_id
            WHERE u.id != ? AND (u.username LIKE ? OR u.curso LIKE ? OR u.nome LIKE ?)
            ORDER BY u.username ASC
            LIMIT 50", "issss", [$userId, $userId, $like, $like, $like]);
    $result = $stmt->get_result();

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = [
            'id'         => (int)$row['id'],
            'username'   => $row['username'],
            'curso'      => $row['curso'],
            'photo_path' => !empty($row['photo_path']) ? $row['photo_path'] : 'foto_users/foto-padrao.png',
            'online'     => (bool)$row['online'],
            'unread'     => (int)$row['unread_count'],
        ];
    }
    $stmt->close();

    json_response(['success' => true, 'users' => $users]);
} catch (mysqli_sql_exception $e) {
    error_log('search_users error: ' . $e->getMessage());
    json_error('Erro ao buscar usuários.', 500);
}
