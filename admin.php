<?php
require_once __DIR__ . '/core.php';
require_admin();
touch_last_active();

$success = "";
$error = "";

// Salvar configuração de exclusão de mensagens (POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deleteTime'])) {
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals(csrf_token(), $sent)) {
        $error = "Sessão inválida. Recarregue a página.";
    } else {
        $timeValue = $_POST['deleteTime'];
        $timeUnit  = $_POST['timeUnit'] ?? 'days';

        function convertToSeconds($time, $unit) {
            if (!ctype_digit((string)$time) || (string)$time === '') return 0;
            $time = (int)$time;
            switch ($unit) {
                case 'seconds': return $time;
                case 'minutes': return $time * 60;
                case 'hours':   return $time * 3600;
                case 'days':    return $time * 86400;
                case 'weeks':   return $time * 604800;
                case 'months':  return $time * 2592000;
                default:        return 0;
            }
        }

        $timeInSeconds = convertToSeconds($timeValue, $timeUnit);
        if ($timeInSeconds <= 0) {
            $error = "Informe um tempo válido.";
        } else {
            try {
                $stmt = db_exec("INSERT INTO settings (name, value) VALUES ('message_deletion_time', ?) ON DUPLICATE KEY UPDATE value = ?", "ss", [(string)$timeInSeconds, (string)$timeInSeconds]);
                $stmt->close();
                $success = "Configuração salva com sucesso!";
            } catch (mysqli_sql_exception $e) {
                error_log('admin save config: ' . $e->getMessage());
                $error = "Erro ao salvar a configuração.";
            }
        }
    }
}

try {
    // Usuários registrados
    $users = db_exec("SELECT u.id, u.nome, u.username, u.curso, u.rm, u.is_admin, u.last_active, p.photo_path,
            (u.last_active IS NOT NULL AND u.last_active >= NOW() - INTERVAL 60 SECOND) AS online
            FROM users u LEFT JOIN user_photos p ON u.id = p.user_id
            ORDER BY u.id ASC")->get_result();

    $config = db_exec("SELECT value FROM settings WHERE name = 'message_deletion_time' LIMIT 1")->get_result()->fetch_assoc();
    $totalUsers = $users->num_rows;

    $totalMessages = (int)db_exec("SELECT COUNT(*) c FROM messages")->get_result()->fetch_assoc()['c'];
    $totalUnread = (int)db_exec("SELECT COUNT(*) c FROM unread_messages")->get_result()->fetch_assoc()['c'];

    // Conversas e mensagens de um usuário (view)
    $viewUserId = (int)($_GET['view'] ?? 0);
    $viewUser = null;
    $conversations = null;
    if ($viewUserId > 0) {
        $stmt = db_exec("SELECT id, nome, username FROM users WHERE id = ? LIMIT 1", "i", [$viewUserId]);
        $viewUser = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($viewUser) {
            $conversations = db_exec("SELECT c.id,
                    u1.username AS user1_username, u2.username AS user2_username,
                    (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id) AS total_messages
                FROM conversations c
                LEFT JOIN users u1 ON c.user1_id = u1.id
                LEFT JOIN users u2 ON c.user2_id = u2.id
                WHERE c.user1_id = ? OR c.user2_id = ?
                ORDER BY c.id ASC", "ii", [$viewUserId, $viewUserId])->get_result();
        }
    }

    // Mensagens de uma conversa (view)
    $convId = (int)($_GET['conversation'] ?? 0);
    $convMessages = null;
    if ($convId > 0) {
        $convMessages = db_exec("SELECT m.id, m.message, m.sent_at, m.sender_id, m.receiver_id,
                u1.username AS sender_username, u2.username AS receiver_username
            FROM messages m
            LEFT JOIN users u1 ON m.sender_id = u1.id
            LEFT JOIN users u2 ON m.receiver_id = u2.id
            WHERE m.conversation_id = ?
            ORDER BY m.sent_at ASC, m.id ASC", "i", [$convId])->get_result();
    }
} catch (mysqli_sql_exception $e) {
    error_log('admin error: ' . $e->getMessage());
    die("Erro ao carregar o painel.");
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Admin — Omigos Chat</title>
    <link rel="icon" type="image/png" href="img/logom.png">
    <link rel="stylesheet" href="admin_styles.css">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.15.4/css/all.css" crossorigin="anonymous">
    <script>
        (function() {
            var stored = null;
            try { stored = localStorage.getItem('omigos_theme'); } catch (e) {}
            var dark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
        })();
    </script>
</head>
<body>
    <div class="admin-shell">
        <header class="admin-header">
            <h1><i class="fas fa-shield-alt" aria-hidden="true"></i> Painel Admin</h1>
            <div class="admin-header-actions">
                <button id="admin-theme-toggle" class="icon-btn" aria-label="Alternar tema"><i class="fas fa-moon"></i></button>
                <a href="chat.php" class="btn ghost"><i class="fas fa-comments" aria-hidden="true"></i> Ir para o Chat</a>
                <a href="logout.php" class="btn danger"><i class="fas fa-sign-out-alt" aria-hidden="true"></i> Sair</a>
            </div>
        </header>

        <?php if ($success): ?><div class="alert success" role="alert"><?= e($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <section class="stats-grid">
            <div class="stat-card"><span class="stat-value"><?= (int)$totalUsers ?></span><span class="stat-label">Usuários</span></div>
            <div class="stat-card"><span class="stat-value"><?= $totalMessages ?></span><span class="stat-label">Mensagens</span></div>
            <div class="stat-card"><span class="stat-value"><?= $totalUnread ?></span><span class="stat-label">Não lidas</span></div>
            <div class="stat-card"><span class="stat-value"><?= (int)round(((int)($config['value'] ?? 0)) / 86400) ?>d</span><span class="stat-label">Retenção</span></div>
        </section>

        <section class="admin-card">
            <h2><i class="fas fa-clock" aria-hidden="true"></i> Tempo de exclusão de mensagens</h2>
            <form method="POST" class="config-form">
                <?= csrf_field() ?>
                <div class="config-row">
                    <div class="config-field">
                        <label for="deleteTime">Tempo</label>
                        <input type="number" id="deleteTime" name="deleteTime" min="1" required value="<?= (int)($config['value'] ?? 2592000) ?>">
                    </div>
                    <div class="config-field">
                        <label for="timeUnit">Unidade</label>
                        <select id="timeUnit" name="timeUnit" required>
                            <option value="seconds">Segundos</option>
                            <option value="minutes">Minutos</option>
                            <option value="hours">Horas</option>
                            <option value="days" selected>Dias</option>
                            <option value="weeks">Semanas</option>
                            <option value="months">Meses</option>
                        </select>
                    </div>
                    <button type="submit" class="btn primary"><i class="fas fa-save" aria-hidden="true"></i> Salvar</button>
                </div>
                <p class="hint">O valor exibido é o total em segundos. Ex.: 30 dias = 2592000.</p>
            </form>
        </section>

        <section class="admin-card">
            <h2><i class="fas fa-users" aria-hidden="true"></i> Usuários registrados (<?= (int)$totalUsers ?>)</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>ID</th><th>Nome</th><th>Usuário</th><th>Curso</th><th>RM</th><th>Tipo</th><th>Status</th><th>Ações</th></tr>
                    </thead>
                    <tbody>
                        <?php while ($user = $users->fetch_assoc()): ?>
                        <tr>
                            <td><?= (int)$user['id'] ?></td>
                            <td><?= e($user['nome'] ?? '—') ?></td>
                            <td><?= e($user['username'] ?? '—') ?></td>
                            <td><?= e($user['curso'] ?? '—') ?></td>
                            <td><?= e((string)($user['rm'] ?? '—')) ?></td>
                            <td><?= ((int)$user['is_admin'] === 1) ? '<span class="badge admin">Admin</span>' : '<span class="badge">Usuário</span>' ?></td>
                            <td><?= ((int)$user['online'] === 1) ? '<span class="badge online">Online</span>' : '<span class="badge offline">Offline</span>' ?></td>
                            <td class="actions">
                                <a href="admin.php?view=<?= (int)$user['id'] ?>" class="btn small ghost"><i class="fas fa-eye"></i> Ver</a>
                                <form method="POST" action="admin_actions.php" class="inline-form" onsubmit="return confirm('Excluir o usuário <?= e($user['username'] ?? '') ?> e todas as suas mensagens?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                    <button type="submit" class="btn small danger"><i class="fas fa-trash"></i> Excluir</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php if ($viewUser && $conversations): ?>
        <section class="admin-card">
            <h2><i class="fas fa-comments" aria-hidden="true"></i> Conversas de <?= e($viewUser['username']) ?> (ID <?= (int)$viewUser['id'] ?>)</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Conversa</th><th>Usuário 1</th><th>Usuário 2</th><th>Mensagens</th><th>Ações</th></tr></thead>
                    <tbody>
                        <?php while ($c = $conversations->fetch_assoc()): ?>
                        <tr>
                            <td>#<?= (int)$c['id'] ?></td>
                            <td><?= e($c['user1_username'] ?? '—') ?></td>
                            <td><?= e($c['user2_username'] ?? '—') ?></td>
                            <td><?= (int)$c['total_messages'] ?></td>
                            <td class="actions">
                                <a href="admin.php?view=<?= (int)$viewUserId ?>&conversation=<?= (int)$c['id'] ?>" class="btn small ghost"><i class="fas fa-envelope-open"></i> Ver mensagens</a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        <?php if ($conversations->num_rows === 0): ?>
                        <tr><td colspan="5" class="empty-row">Nenhuma conversa encontrada.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($convMessages): ?>
        <section class="admin-card">
            <h2><i class="fas fa-envelope" aria-hidden="true"></i> Mensagens da conversa #<?= (int)$convId ?></h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>ID</th><th>Remetente</th><th>Destinatário</th><th>Mensagem</th><th>Data/Hora</th><th>Ações</th></tr></thead>
                    <tbody>
                        <?php while ($m = $convMessages->fetch_assoc()): ?>
                        <tr>
                            <td><?= (int)$m['id'] ?></td>
                            <td><?= e($m['sender_username'] ?? '—') ?></td>
                            <td><?= e($m['receiver_username'] ?? '—') ?></td>
                            <td class="msg-cell"><?= e($m['message'] ?? '[anexo]') ?></td>
                            <td><?= e($m['sent_at']) ?></td>
                            <td class="actions">
                                <form method="POST" action="admin_actions.php" class="inline-form" onsubmit="return confirm('Excluir esta mensagem?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_message">
                                    <input type="hidden" name="message_id" value="<?= (int)$m['id'] ?>">
                                    <input type="hidden" name="back" value="<?= e('admin.php?view=' . $viewUserId . '&conversation=' . $convId) ?>">
                                    <button type="submit" class="btn small danger"><i class="fas fa-trash"></i> Excluir</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>
    </div>

    <script>
        document.getElementById('admin-theme-toggle')?.addEventListener('click', function() {
            var current = document.documentElement.getAttribute('data-theme');
            var next = current === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            try { localStorage.setItem('omigos_theme', next); } catch (e) {}
            this.querySelector('i').className = next === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        });
        (function() {
            var t = document.documentElement.getAttribute('data-theme');
            var btn = document.getElementById('admin-theme-toggle');
            if (btn) btn.querySelector('i').className = t === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        })();
    </script>
</body>
</html>
