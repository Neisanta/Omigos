<?php
/**
 * user_list_recents.php — Lista de usuários com conversas recentes (AJAX, JSON).
 * Retorna apenas os <li> da lista.
 */
require_once __DIR__ . '/core.php';

require_login_or_exit();
touch_last_active();

try {
    $userId = current_user_id();

    $sql = "SELECT u.id, u.username, u.curso, p.photo_path, m.last_message_date,
            (SELECT COUNT(*) FROM unread_messages um
             JOIN messages msg ON um.message_id = msg.id
             WHERE msg.sender_id = u.id AND msg.receiver_id = ? AND um.user_id = ?) AS unread_count,
            (u.last_active IS NOT NULL AND u.last_active >= NOW() - INTERVAL 60 SECOND) AS online
            FROM users u
            LEFT JOIN user_photos p ON u.id = p.user_id
            INNER JOIN (
                SELECT
                    CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END AS other_id,
                    MAX(sent_at) AS last_message_date
                FROM messages
                WHERE sender_id = ? OR receiver_id = ?
                GROUP BY other_id
            ) AS m ON u.id = m.other_id
            WHERE u.id != ?
            ORDER BY m.last_message_date DESC";
    $stmt = db_exec($sql, "iiiiii", [$userId, $userId, $userId, $userId, $userId, $userId]);
    $result = $stmt->get_result();

    $activeId = (int)($_GET['active_id'] ?? 0);

    ob_start();
    if ($result->num_rows === 0) {
        echo '<li class="empty-state"><div class="empty-state-icon">&#128172;</div><div class="empty-state-title">Nenhuma conversa ainda</div><div class="empty-state-description">Selecione a aba "Usuários" para começar.</div></li>';
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
    error_log('user_list_recents error: ' . $e->getMessage());
    json_error('Erro ao carregar conversas recentes.', 500);
}
