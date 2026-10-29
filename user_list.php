<?php
/**
 * user_list.php — Lista de todos os usuários (AJAX, JSON).
 * Retorna apenas os <li> da lista (sem duplicar sidebar/IDs).
 */
require_once __DIR__ . '/core.php';

require_login_or_exit();
touch_last_active();

try {
    $userId = current_user_id();

    $stmt = db_exec("SELECT u.id, u.username, u.curso, p.photo_path,
            (SELECT COUNT(*) FROM unread_messages um
             JOIN messages m ON um.message_id = m.id
             WHERE m.sender_id = u.id AND um.user_id = ?) AS unread_count,
            (u.last_active IS NOT NULL AND u.last_active >= NOW() - INTERVAL 60 SECOND) AS online
            FROM users u
            LEFT JOIN user_photos p ON u.id = p.user_id
            WHERE u.id != ?
            ORDER BY u.username ASC", "ii", [$userId, $userId]);
    $result = $stmt->get_result();

    $activeId = (int)($_GET['active_id'] ?? 0);

    ob_start();
    if ($result->num_rows === 0) {
        echo '<li class="empty-state"><div class="empty-state-title">Nenhum usuário cadastrado</div></li>';
    } else {
        while ($row = $result->fetch_assoc()) {
            $photo = !empty($row['photo_path']) ? $row['photo_path'] : 'foto_users/foto-padrao.png';
            $active = ((int)$row['id'] === $activeId) ? ' active' : '';
            ?>
            <li class="user_item<?= $active ?>" data-id="<?= (int)$row['id'] ?>" role="button" tabindex="0" aria-label="Conversar com <?= e($row['username']) ?>">
                <img src="<?= e($photo) ?>" class="user_photo" alt="">
                <span class="user_name"><?= e($row['username']) ?></span>
                <?php if (!empty($row['curso'])): ?><span class="user_course"><?= e($row['curso']) ?></span><?php endif; ?>
                <span class="status-dot <?= ((int)$row['online'] === 1) ? 'online' : 'offline' ?>" title="<?= ((int)$row['online'] === 1) ? 'Online' : 'Offline' ?>"></span>
                <?php if ((int)$row['unread_count'] > 0): ?><span class="unread-count" data-count="<?= (int)$row['unread_count'] ?>"><?= (int)$row['unread_count'] ?></span><?php endif; ?>
            </li>
            <?php
        }
    }
    $html = ob_get_clean();
    $stmt->close();

    json_response(['success' => true, 'html' => $html]);
} catch (mysqli_sql_exception $e) {
    error_log('user_list error: ' . $e->getMessage());
    json_error('Erro ao carregar lista de usuários.', 500);
}
