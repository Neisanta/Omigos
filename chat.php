<?php
require_once __DIR__ . '/core.php';
require_login();
touch_last_active();

// Dados do usuário atual
$myId = current_user_id();
$stmt = db_exec("SELECT u.id, u.nome, u.username, u.curso, u.bio, u.instagram, p.photo_path
        FROM users u LEFT JOIN user_photos p ON u.id = p.user_id WHERE u.id = ? LIMIT 1", "i", [$myId]);
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$me) {
    header("Location: logout.php");
    exit();
}
$myPhoto = !empty($me['photo_path']) ? $me['photo_path'] : 'foto_users/foto-padrao.png';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Omigos Chat</title>
    <link rel="icon" type="image/png" href="img/logom.png">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.15.4/css/all.css" crossorigin="anonymous">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="my-user-id" content="<?= (int)$myId ?>">
    <script>
        // Aplica o tema ANTES da renderização (evita flash)
        (function() {
            var stored = null;
            try { stored = localStorage.getItem('omigos_theme'); } catch (e) {}
            var urlTheme = new URLSearchParams(window.location.search).get('theme');
            var dark = stored ? stored === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (urlTheme === 'dark' || urlTheme === 'light') dark = (urlTheme === 'dark');
            document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
        })();
    </script>
</head>
<body>
    <div id="app">
        <!-- Backdrop da sidebar em mobile -->
        <div id="sidebar-backdrop" aria-hidden="true"></div>

        <aside id="sidebar" aria-label="Menu lateral">
            <header class="sidebar-header">
                <h2>
                    <i class="fas fa-comments sidebar-icon" aria-hidden="true"></i>
                    Omigos
                </h2>
                <div class="sidebar-header-actions">
                    <button id="theme-toggle" class="icon-btn" aria-label="Alternar tema claro/escuro" title="Alternar tema">
                        <i class="fas fa-moon" aria-hidden="true"></i>
                    </button>
                    <a id="logout-btn" class="icon-btn" href="logout.php" aria-label="Sair da conta" title="Sair">
                        <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
                    </a>
                    <button id="toggle-sidebar" class="icon-btn" aria-label="Fechar menu" title="Fechar menu">
                        <i class="fas fa-times" aria-hidden="true"></i>
                    </button>
                </div>
            </header>
            <div class="sidebar-content">
                <!-- Usuário atual -->
                <div class="current-user" id="current-user-info" role="button" tabindex="0" aria-label="Abrir meu perfil" title="Meu perfil">
                    <img src="<?= e($myPhoto) ?>" class="user-photo" id="current-user-photo" alt="Minha foto">
                    <div class="current-user-text">
                        <span class="user-username" id="current-user-username"><?= e($me['username']) ?></span>
                        <span class="current-user-status"><span class="status-dot online" id="user-status-dot"></span> Online</span>
                    </div>
                    <i class="fas fa-user-edit current-user-edit" aria-hidden="true"></i>
                </div>

                <div class="search-container">
                    <i class="fas fa-search search-icon" aria-hidden="true"></i>
                    <input type="text" id="search_users" placeholder="Pesquisar usuários" autocomplete="off" aria-label="Pesquisar usuários">
                </div>
                <div class="button-group" role="tablist" aria-label="Tipo de lista">
                    <button id="load_all_users" class="switch-button active" role="tab" aria-selected="true">Usuários</button>
                    <button id="load_recent_users" class="switch-button" role="tab" aria-selected="false">Recentes</button>
                </div>
                <ul id="user_list_ul" aria-label="Lista de usuários">
                    <li class="empty-state"><div class="empty-state-title">Carregando...</div></li>
                </ul>
            </div>
        </aside>

        <section id="chat-wrapper">
            <header class="chat-header">
                <button id="back-to-list" class="back-btn" aria-label="Voltar à lista de conversas">
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                </button>
                <div class="selected-user-info" id="selected-user-info" role="button" tabindex="0" aria-label="Ver informações do usuário">
                    <img src="img/omigos.png" class="selected-user-photo" id="selected_user_photo" alt="">
                    <div class="selected-user-text">
                        <span class="selected-user-username" id="selected_user_name">Selecione uma conversa</span>
                        <span class="status-badge offline" id="user-status-badge"><span class="status-dot-small"></span><span id="user-status-text">Offline</span></span>
                    </div>
                </div>
            </header>
            <div class="chat-area">
                <div id="conversation" class="conversation" aria-live="polite">
                    <div class="empty-state">
                        <div class="empty-state-icon">&#128172;</div>
                        <div class="empty-state-title">Bem-vindo ao Omigos!</div>
                        <div class="empty-state-description">Selecione um usuário na lista para começar a conversar.</div>
                    </div>
                </div>
                <div id="typing-indicator" class="typing-indicator hidden" aria-live="polite">
                    <span>Digitando</span>
                    <div class="typing-dots"><span></span><span></span><span></span></div>
                </div>
                <div id="loading-overlay" class="loading-overlay hidden">
                    <div class="spinner" aria-hidden="true"></div>
                    <p>Carregando...</p>
                </div>
            </div>
            <form id="send_message" enctype="multipart/form-data" autocomplete="off">
                <div id="file-preview-bar" class="file-preview-bar hidden">
                    <i class="fas fa-paperclip" aria-hidden="true"></i>
                    <span id="file-preview-name"></span>
                    <button type="button" id="file-preview-remove" class="icon-btn" aria-label="Remover anexo"><i class="fas fa-times"></i></button>
                </div>
                <input type="hidden" name="receiver_id" id="receiver_id" value="">
                <input type="hidden" name="csrf_token" id="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="file" name="file" id="file_input" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
                <button type="button" id="attach-btn" class="attach-btn" aria-label="Anexar arquivo" title="Anexar arquivo">
                    <i class="fas fa-paperclip" aria-hidden="true"></i>
                </button>
                <input type="text" name="message" id="message" placeholder="Digite sua mensagem" maxlength="1000" aria-label="Mensagem">
                <button type="submit" class="send-btn" aria-label="Enviar mensagem"><i class="fas fa-paper-plane" aria-hidden="true"></i></button>
            </form>
        </section>
    </div>

    <!-- Modal: informações do outro usuário -->
    <div id="user-info-modal" class="modal-backdrop hidden" role="dialog" aria-modal="true" aria-labelledby="user-info-title">
        <div class="modal">
            <button type="button" class="modal-close icon-btn" aria-label="Fechar"><i class="fas fa-times"></i></button>
            <img id="user-info-photo" src="" alt="Foto do usuário" class="modal-avatar">
            <h3 id="user-info-name"></h3>
            <p class="modal-username" id="user-info-username"></p>
            <div class="modal-meta">
                <p><strong>Curso:</strong> <span id="user-info-course">—</span></p>
                <p id="user-info-bio-wrap"><strong>Bio:</strong> <span id="user-info-bio"></span></p>
                <p id="user-info-insta-wrap" class="hidden"><a id="user-info-insta" href="#" target="_blank" rel="noopener" class="insta-link"><i class="fab fa-instagram" aria-hidden="true"></i> Instagram</a></p>
            </div>
        </div>
    </div>

    <!-- Modal: meu perfil -->
    <div id="profile-modal" class="modal-backdrop hidden" role="dialog" aria-modal="true" aria-labelledby="profile-title">
        <div class="modal">
            <button type="button" class="modal-close icon-btn" aria-label="Fechar"><i class="fas fa-times"></i></button>
            <h3 id="profile-title">Meu Perfil</h3>
            <div class="profile-avatar-wrap">
                <img id="profile-photo" src="<?= e($myPhoto) ?>" alt="Minha foto" class="modal-avatar">
                <button type="button" id="profile-avatar-btn" class="avatar-edit-btn" aria-label="Alterar foto" title="Alterar foto"><i class="fas fa-camera"></i></button>
                <input type="file" id="profile-avatar-input" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
            </div>
            <form id="profile-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update">
                <label class="profile-label" for="profile-nome">Nome completo</label>
                <input type="text" id="profile-nome" name="nome" maxlength="100" required>
                <label class="profile-label" for="profile-bio">Bio</label>
                <textarea id="profile-bio" name="bio" rows="3" maxlength="500"></textarea>
                <label class="profile-label" for="profile-instagram">Instagram</label>
                <input type="text" id="profile-instagram" name="instagram" maxlength="30" placeholder="usuario (opcional)">
                <p class="profile-meta">Usuário: <span id="profile-username"></span> · Curso: <span id="profile-curso"></span></p>
                <button type="submit" class="btn primary">Salvar alterações</button>
            </form>
        </div>
    </div>

    <div id="toast-container" aria-live="polite" aria-atomic="true"></div>

    <script src="chat.js"></script>
</body>
</html>
