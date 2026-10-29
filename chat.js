/**
 * Omigos Chat — JavaScript moderno (vanilla, sem dependências).
 * Recursos: tempo real (long polling incremental), não lidas, digitação,
 * status online, editar/excluir/responder/copiar, anexos (só imagens),
 * perfil, dark mode, responsivo.
 */
(function () {
    'use strict';

    // ===== Estado =====
    const state = {
        myUserId: parseInt(document.querySelector('meta[name="my-user-id"]')?.content || '0', 10),
        activeUserId: null,
        activeTab: 'all',          // 'all' | 'recents'
        searchActive: false,
        attachedFile: null,
        lastScrollNearBottom: true,
        pollUnreadTimer: null,
        heartbeatTimer: null,
        typingTimeout: null,
        knownUnread: {},           // {senderId: count}
        convMeta: null,            // {lastId, count, edited} — assinatura da conversa ativa
        convPollAbort: null,       // AbortController do long polling em andamento
        sending: false,            // guarda contra envio duplicado
        sendQueue: [],             // mensagens aguardando o envio atual terminar
        typingShown: false         // indicador "digitando" está visível?
    };

    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

    // ===== Atalhos =====
    const $ = (sel) => document.querySelector(sel);
    const $$ = (sel) => Array.from(document.querySelectorAll(sel));
    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

    const el = {
        sidebar: $('#sidebar'),
        backdrop: $('#sidebar-backdrop'),
        toggleSidebar: $('#toggle-sidebar'),
        themeToggle: $('#theme-toggle'),
        conversation: $('#conversation'),
        loadingOverlay: $('#loading-overlay'),
        typingIndicator: $('#typing-indicator'),
        messageInput: $('#message'),
        sendForm: $('#send_message'),
        sendBtn: $('.send-btn'),
        attachBtn: $('#attach-btn'),
        fileInput: $('#file_input'),
        filePreviewBar: $('#file-preview-bar'),
        filePreviewName: $('#file-preview-name'),
        filePreviewRemove: $('#file-preview-remove'),
        receiverId: $('#receiver_id'),
        selectedUserPhoto: $('#selected_user_photo'),
        selectedUserName: $('#selected_user_name'),
        statusBadge: $('#user-status-badge'),
        statusText: $('#user-status-text'),
        myStatusDot: $('#user-status-dot'),
        searchUsers: $('#search_users'),
        userListUl: $('#user_list_ul'),
        loadAllUsers: $('#load_all_users'),
        loadRecentUsers: $('#load_recent_users'),
        backToList: $('#back-to-list'),
        currentUserInfo: $('#current-user-info'),
        currentUserPhoto: $('#current-user-photo'),
        toastContainer: $('#toast-container'),
        userInfoModal: $('#user-info-modal'),
        profileModal: $('#profile-modal')
    };

    // ===== API helper =====
    async function api(url, options = {}) {
        const opts = Object.assign({ headers: { 'X-Requested-With': 'XMLHttpRequest' } }, options);
        if (opts.body instanceof FormData) {
            // Não definir Content-Type: o browser define com boundary
        } else if (opts.body && typeof opts.body === 'object') {
            opts.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
            const params = new URLSearchParams();
            for (const [k, v] of Object.entries(opts.body)) params.append(k, v);
            opts.body = params.toString();
        }
        const response = await fetch(url, opts);
        let data = null;
        try { data = await response.json(); } catch (e) { /* resposta não-JSON */ }
        if (response.status === 401) {
            window.location.href = 'login.php';
            throw new Error('Sessão expirada');
        }
        return { ok: response.ok, status: response.status, data };
    }

    // ===== Toasts =====
    function toast(message, type = 'success') {
        if (!el.toastContainer) return;
        const t = document.createElement('div');
        t.className = 'toast ' + type;
        const icon = document.createElement('i');
        icon.className = type === 'error' ? 'fas fa-exclamation-circle' : 'fas fa-check-circle';
        const span = document.createElement('span');
        span.textContent = message; // sempre escapado
        t.appendChild(icon);
        t.appendChild(span);
        el.toastContainer.appendChild(t);
        setTimeout(() => {
            t.classList.add('leaving');
            setTimeout(() => t.remove(), 300);
        }, 3000);
    }
    const showSuccess = (m) => toast(m, 'success');
    const showError = (m) => toast(m, 'error');

    // ===== Loading =====
    function showLoading() { el.loadingOverlay?.classList.remove('hidden'); }
    function hideLoading() { el.loadingOverlay?.classList.add('hidden'); }

    // ===== Tema =====
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        try { localStorage.setItem('omigos_theme', theme); } catch (e) {}
        const icon = el.themeToggle?.querySelector('i');
        if (icon) icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    }

    // ===== Scroll =====
    function isNearBottom() {
        if (!el.conversation) return true;
        return el.conversation.scrollHeight - el.conversation.scrollTop - el.conversation.clientHeight < 120;
    }
    function scrollToBottom() {
        if (el.conversation) el.conversation.scrollTop = el.conversation.scrollHeight;
    }

    // ===== Sidebar (mobile) =====
    function openSidebar() {
        el.sidebar?.classList.add('visible');
        el.backdrop?.classList.add('visible');
    }
    function closeSidebar() {
        el.sidebar?.classList.remove('visible');
        el.backdrop?.classList.remove('visible');
    }

    // ===== Lista de usuários =====
    async function loadUserList(recents = state.activeTab === 'recents') {
        try {
            const url = recents ? 'user_list_recents.php' : 'user_list.php';
            const qs = state.activeUserId ? ('?active_id=' + encodeURIComponent(state.activeUserId)) : '';
            const res = await api(url + qs);
            if (!res.ok || !res.data || !res.data.success) {
                throw new Error(res.data?.error || 'Erro ao carregar lista');
            }
            el.userListUl.innerHTML = res.data.html; // HTML escapado no servidor
        } catch (err) {
            if (err.message !== 'Sessão expirada') {
                console.error('loadUserList:', err);
                el.userListUl.innerHTML = '<li class="empty-state"><div class="empty-state-title">Erro ao carregar lista</div></li>';
            }
        }
    }

    function renderSearchResults(users) {
        el.userListUl.innerHTML = '';
        if (!users.length) {
            const li = document.createElement('li');
            li.className = 'empty-state';
            li.innerHTML = '<div class="empty-state-icon">&#128269;</div><div class="empty-state-title">Nenhum usuário encontrado</div>';
            el.userListUl.appendChild(li);
            return;
        }
        for (const user of users) {
            const li = document.createElement('li');
            li.className = 'user_item' + (String(user.id) === String(state.activeUserId) ? ' active' : '');
            li.dataset.id = user.id;
            li.setAttribute('role', 'button');
            li.setAttribute('tabindex', '0');
            li.setAttribute('aria-label', 'Conversar com ' + user.username);

            const img = document.createElement('img');
            img.src = user.photo_path || 'foto_users/foto-padrao.png';
            img.className = 'user_photo';
            img.alt = '';
            li.appendChild(img);

            const name = document.createElement('span');
            name.className = 'user_name';
            name.textContent = user.username; // escapado
            li.appendChild(name);

            if (user.curso) {
                const course = document.createElement('span');
                course.className = 'user_course';
                course.textContent = user.curso;
                li.appendChild(course);
            }

            // Pontinho de status online
            const dot = document.createElement('span');
            dot.className = 'status-dot ' + (user.online ? 'online' : 'offline');
            dot.title = user.online ? 'Online' : 'Offline';
            li.appendChild(dot);

            if (user.unread > 0) {
                const badge = document.createElement('span');
                badge.className = 'unread-count';
                badge.dataset.count = user.unread;
                badge.textContent = user.unread;
                li.appendChild(badge);
                state.knownUnread[user.id] = user.unread;
            }
            el.userListUl.appendChild(li);
        }
    }

    async function searchUsers(term) {
        try {
            const res = await api('search_users.php?search_term=' + encodeURIComponent(term));
            if (!res.ok || !res.data || !res.data.success) throw new Error('erro');
            renderSearchResults(res.data.users || []);
        } catch (err) {
            if (err.message !== 'Sessão expirada') console.error('searchUsers:', err);
        }
    }

    // ===== Selecionar usuário =====
    function selectUser(userId) {
        userId = String(parseInt(userId, 10) || 0);
        if (!userId || userId === '0') return;

        $$('.user_item').forEach(item => item.classList.toggle('active', item.dataset.id === userId));
        state.activeUserId = userId;
        el.receiverId.value = userId;

        // Fecha a busca aberta
        state.searchActive = false;

        // Cancela o long polling da conversa anterior
        stopConversationPolling();
        state.convMeta = null;
        state.sendQueue.length = 0; // fila pertence à conversa anterior

        // Carrega info do usuário selecionado (header)
        loadSelectedUserInfo(userId);

        // Carrega a conversa e inicia o long polling incremental
        loadConversation(userId).then(() => startConversationPolling(userId));

        // Mobile: fecha a sidebar
        if (window.innerWidth <= 768) closeSidebar();

        // Foco no campo de mensagem
        setTimeout(() => el.messageInput?.focus(), 150);
    }

    async function loadSelectedUserInfo(userId) {
        try {
            const res = await api('user_info.php?user_id=' + encodeURIComponent(userId));
            if (!res.ok || !res.data || !res.data.success) throw new Error(res.data?.error || 'erro');
            const user = res.data.user;
            el.selectedUserPhoto.src = user.photo_path || 'foto_users/foto-padrao.png';
            el.selectedUserPhoto.alt = user.username || '';
            el.selectedUserName.textContent = user.username || 'Usuário';
            updateStatusBadge(!!user.online);
        } catch (err) {
            if (err.message !== 'Sessão expirada') console.error('loadSelectedUserInfo:', err);
        }
    }

    function updateStatusBadge(isOnline) {
        if (!el.statusBadge) return;
        el.statusBadge.classList.toggle('online', isOnline);
        el.statusBadge.classList.toggle('offline', !isOnline);
        if (el.statusText) el.statusText.textContent = isOnline ? 'Online' : 'Offline';
    }

    function updateMyStatus(isOnline) {
        if (!el.myStatusDot) return;
        el.myStatusDot.classList.toggle('online', isOnline);
        el.myStatusDot.classList.toggle('offline', !isOnline);
    }

    // ===== Conversa =====
    // Normaliza a assinatura vinda do servidor (snake_case → camelCase)
    function setConvMeta(meta) {
        state.convMeta = meta ? {
            lastId: parseInt(meta.last_id ?? meta.lastId ?? 0, 10) || 0,
            count: parseInt(meta.count ?? -1, 10),
            edited: parseInt(meta.edited ?? 0, 10) || 0
        } : null;
    }

    async function loadConversation(receiverId, isPolling = false) {
        if (!receiverId) return;
        try {
            if (!isPolling) {
                showLoading();
                state.lastScrollNearBottom = true;
            } else {
                state.lastScrollNearBottom = isNearBottom();
            }

            const res = await api('get_messages.php?receiver_id=' + encodeURIComponent(receiverId));
            if (!isPolling) hideLoading();

            if (!res.ok || !res.data || !res.data.success) {
                throw new Error(res.data?.error || 'Erro ao carregar mensagens');
            }

            // Descarta resposta obsoleta (usuário trocou de conversa durante a requisição)
            if (String(state.activeUserId) !== String(receiverId)) return;

            el.conversation.innerHTML = res.data.html;

            // Assinatura atual da conversa (para o long polling incremental)
            setConvMeta(res.data.meta);

            // Indicador de digitação do outro usuário
            state.typingShown = !!res.data.typing;
            if (state.typingShown) showTypingIndicator();
            else hideTypingIndicator();

            if (state.lastScrollNearBottom) scrollToBottom();

            // Marca como lidas
            markMessagesAsRead(receiverId);
        } catch (err) {
            if (!isPolling) hideLoading();
            if (err.message !== 'Sessão expirada') {
                console.error('loadConversation:', err);
                el.conversation.innerHTML =
                    '<div class="empty-state"><div class="empty-state-icon">&#9888;</div>' +
                    '<div class="empty-state-title">Erro ao carregar mensagens</div>' +
                    '<div class="empty-state-description">Tente novamente mais tarde.</div></div>';
            }
        }
    }

    // ===== Append incremental: adiciona SOMENTE mensagens novas =====
    function appendNewMessages(html) {
        if (!html) return;
        const wasNearBottom = isNearBottom();

        const tpl = document.createElement('template');
        tpl.innerHTML = html;
        const blocks = Array.from(tpl.content.querySelectorAll('.message-block'));

        let added = 0;
        let hasIncoming = false;
        for (const block of blocks) {
            const id = block.dataset.id;
            // Evita duplicação: pula mensagens que já existem no chat
            if (!id || el.conversation.querySelector('.message-block[data-id="' + CSS.escape(id) + '"]')) continue;
            if (block.classList.contains('other-message')) hasIncoming = true;
            el.conversation.appendChild(block);
            added++;
        }
        if (added === 0) return;

        // Remove o estado vazio, se ainda existir
        el.conversation.querySelector('.empty-state')?.remove();

        // Preserva o scroll: só desce automaticamente se já estava no fim
        if (wasNearBottom) scrollToBottom();

        if (hasIncoming) {
            if (document.hidden) {
                notifyNewIncoming(added);
            } else {
                markMessagesAsRead(state.activeUserId);
            }
        }
    }

    function notifyNewIncoming(count) {
        playBeep();
        if ('Notification' in window && Notification.permission === 'granted') {
            try {
                const name = el.selectedUserName ? el.selectedUserName.textContent : 'Nova mensagem';
                new Notification('Omigos Chat', { body: name + ': ' + count + ' nova(s) mensagem(ns)' });
            } catch (e) {}
        }
    }

    // ===== Long polling da conversa ativa =====
    // O servidor segura a requisição até haver mudança real; nada é
    // reconstruído quando não há novidade.
    function stopConversationPolling() {
        if (state.convPollAbort) {
            state.convPollAbort.abort();
            state.convPollAbort = null;
        }
    }

    async function conversationPollLoop(receiverId) {
        while (state.activeUserId && String(state.activeUserId) === String(receiverId)) {
            const ctrl = new AbortController();
            state.convPollAbort = ctrl;
            try {
                const meta = state.convMeta;
                const qs = new URLSearchParams({
                    receiver_id: String(receiverId),
                    last_id: meta ? String(meta.lastId) : '0',
                    known_count: meta ? String(meta.count) : '-1',
                    known_edited: meta ? String(meta.edited) : '0',
                    known_typing: state.typingShown ? '1' : '0',
                    timeout: '15'
                });
                const res = await api('conversation_updates.php?' + qs.toString(), { signal: ctrl.signal });

                // Conversa trocou durante a espera → descarta
                if (!state.activeUserId || String(state.activeUserId) !== String(receiverId)) return;
                if (!res.ok || !res.data || !res.data.success) throw new Error('Resposta inválida');

                // Indicador de digitação: aplica somente quando muda
                if (res.data.typing !== state.typingShown) {
                    state.typingShown = !!res.data.typing;
                    if (state.typingShown) showTypingIndicator();
                    else hideTypingIndicator();
                }

                // Assinatura sempre atualizada
                if (res.data.meta) setConvMeta(res.data.meta);

                if (res.data.updated) {
                    if (res.data.refresh) {
                        // Exclusão/edição remota: recarrega preservando o scroll
                        await loadConversation(receiverId, true);
                    } else if (res.data.new_html) {
                        appendNewMessages(res.data.new_html);
                    }
                }
            } catch (err) {
                if (err && err.name === 'AbortError') return;
                if (err.message === 'Sessão expirada') return;
                // Erro de rede/servidor: espera antes de tentar de novo
                await sleep(3000);
            } finally {
                if (state.convPollAbort === ctrl) state.convPollAbort = null;
            }
        }
    }

    function startConversationPolling(receiverId) {
        stopConversationPolling();
        conversationPollLoop(receiverId);
    }

    async function markMessagesAsRead(receiverId) {
        try {
            const res = await api('mark_as_read.php', { method: 'POST', body: { sender_id: receiverId, csrf_token: CSRF } });
            if (res.ok && res.data && res.data.success) {
                // Remove badge na lista
                const item = el.userListUl.querySelector('.user_item[data-id="' + parseInt(receiverId, 10) + '"]');
                const badge = item?.querySelector('.unread-count');
                if (badge) { badge.remove(); state.knownUnread[receiverId] = 0; }
                updateTitleBadge();
            }
        } catch (err) {
            if (err.message !== 'Sessão expirada') console.error('markAsRead:', err);
        }
    }

    // ===== Enviar mensagem =====
    // Envia a mensagem atual (ou enfileira se um envio já está em andamento).
    // Limpa o campo imediatamente (ótico); em caso de erro o texto volta.
    async function sendMessage() {
        const message = el.messageInput.value.trim();
        const file = state.attachedFile;
        const receiverId = el.receiverId.value;

        if (!receiverId) {
            showError('Selecione um usuário para conversar');
            return;
        }
        if (!message && !file) {
            showError('Digite uma mensagem ou anexe uma imagem');
            return;
        }
        if (message.length > 1000) {
            showError('A mensagem excede o limite de 1000 caracteres');
            return;
        }

        // Otimista: limpa o campo ANTES de enviar — assim Enter/cliques
        // repetidos durante o envio não re-enviam o mesmo texto
        el.messageInput.value = '';
        clearAttachment();

        if (state.sending) {
            // Envio em andamento: enfileira em vez de descartar
            state.sendQueue.push({ message, file });
            return;
        }
        await doSend(message, file);
    }

    async function doSend(message, file) {
        const receiverId = el.receiverId.value;
        if (!receiverId) return;

        state.sending = true;
        el.sendBtn.disabled = true;
        el.sendBtn.classList.add('sending');
        hideTypingIndicator(); // esconde o indicador enquanto envia

        let ok = false;
        try {
            const fd = new FormData();
            fd.append('receiver_id', receiverId);
            fd.append('message', message);
            fd.append('csrf_token', CSRF);
            if (file) fd.append('file', file);

            const res = await api('send_message.php', { method: 'POST', body: fd });
            if (!res.ok || !res.data || !res.data.success) {
                throw new Error(res.data?.error || 'Erro ao enviar a mensagem');
            }
            ok = true;

            // Anexa SOMENTE a mensagem nova — e apenas se ainda estamos
            // na mesma conversa (o usuário pode ter trocado durante o envio)
            if (String(state.activeUserId) === String(receiverId)) {
                appendNewMessages(res.data.html);
                scrollToBottom();

                // Atualiza a assinatura local — evita refresh desnecessário
                if (res.data.message_id) {
                    if (state.convMeta) {
                        state.convMeta.lastId = Math.max(state.convMeta.lastId || 0, res.data.message_id);
                        if (!res.data.duplicate) {
                            state.convMeta.count = (state.convMeta.count || 0) + 1;
                        }
                    } else {
                        state.convMeta = { lastId: res.data.message_id, count: 1, edited: 0 };
                    }
                }
            }
        } catch (err) {
            if (err.message !== 'Sessão expirada') {
                console.error('sendMessage:', err);
                showError(err.message || 'Erro de conexão. Tente novamente.');
                // Devolve o texto ao campo para o usuário não perder a mensagem
                if (message) el.messageInput.value = message;
                state.sendQueue.length = 0; // interrompe a fila; usuário reenvia
            }
        } finally {
            state.sending = false;
            el.sendBtn.disabled = false;
            el.sendBtn.classList.remove('sending');
            if (ok && state.sendQueue.length > 0) {
                const next = state.sendQueue.shift();
                doSend(next.message, next.file);
            }
        }
    }

    // ===== Anexos (apenas imagens) =====
    function setAttachment(file) {
        // Validação de conveniência — o backend revalida por conteúdo real
        if (!/^image\/(jpeg|png|gif|webp)$/i.test(file.type)) {
            showError('Apenas imagens são aceitas (JPG, PNG, GIF ou WebP).');
            return;
        }
        if (file.size > 8 * 1024 * 1024) {
            showError('Imagem muito grande. Limite de 8MB.');
            return;
        }
        state.attachedFile = file;
        el.filePreviewName.textContent = file.name + ' (' + formatSize(file.size) + ')';
        el.filePreviewBar.classList.remove('hidden');
    }

    function clearAttachment() {
        state.attachedFile = null;
        el.fileInput.value = '';
        el.filePreviewBar.classList.add('hidden');
        el.filePreviewName.textContent = '';
    }

    function formatSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    // ===== Digitação =====
    function showTypingIndicator() {
        el.typingIndicator?.classList.remove('hidden');
    }
    function hideTypingIndicator() {
        el.typingIndicator?.classList.add('hidden');
    }
    function notifyTyping() {
        const receiverId = el.receiverId.value;
        if (!receiverId) return;
        clearTimeout(state.typingTimeout);
        state.typingTimeout = setTimeout(() => {
            api('typing.php', { method: 'POST', body: { receiver_id: receiverId, csrf_token: CSRF } })
                .catch(() => {});
        }, 800);
    }

    // ===== Não lidas (polling) =====
    async function pollUnread() {
        try {
            const res = await api('check_new_messages.php');
            if (!res.ok || !res.data || !res.data.success) return;
            const unread = res.data.unread || {};

            // Atualiza badges por usuário (substitui, não acumula)
            for (const [senderId, count] of Object.entries(unread)) {
                const item = el.userListUl.querySelector('.user_item[data-id="' + senderId + '"]');
                if (!item) continue;
                let badge = item.querySelector('.unread-count');
                const prev = state.knownUnread[senderId] || 0;
                if (count > 0) {
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.className = 'unread-count';
                        badge.dataset.count = count;
                        item.appendChild(badge);
                    }
                    badge.dataset.count = count;
                    badge.textContent = count;
                    // Nova mensagem chegou?
                    if (count > prev) onNewMessage(senderId, count - prev);
                } else if (badge) {
                    badge.remove();
                }
                state.knownUnread[senderId] = count;
            }

            // Remove badges de remetentes que não têm mais não lidas
            el.userListUl.querySelectorAll('.user_item .unread-count').forEach(badge => {
                const item = badge.closest('.user_item');
                if (item && !(item.dataset.id in unread)) {
                    badge.remove();
                    state.knownUnread[item.dataset.id] = 0;
                }
            });

            updateTitleBadge();
        } catch (err) {
            // silencioso (polling)
        }
    }

    function onNewMessage(senderId, amount) {
        // Som + notificação do navegador se a aba estiver oculta
        if (document.hidden) {
            playBeep();
            if ('Notification' in window && Notification.permission === 'granted') {
                try {
                    const item = el.userListUl.querySelector('.user_item[data-id="' + senderId + '"] .user_name');
                    const name = item ? item.textContent : 'Nova mensagem';
                    new Notification('Omigos Chat', { body: name + ': ' + amount + ' nova(s) mensagem(ns)' });
                } catch (e) {}
            }
        }
    }

    function updateTitleBadge() {
        const total = Object.values(state.knownUnread).reduce((a, b) => a + (b || 0), 0);
        document.title = total > 0 ? '(' + total + ') Omigos Chat' : 'Omigos Chat';
    }

    // ===== Som (Web Audio, sem arquivos externos) =====
    function playBeep() {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.value = 880;
            gain.gain.setValueAtTime(0.08, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.25);
            osc.start();
            osc.stop(ctx.currentTime + 0.25);
            setTimeout(() => ctx.close(), 400);
        } catch (e) {}
    }

    // ===== Heartbeat (status online) =====
    async function heartbeat() {
        try {
            const res = await api('heartbeat.php');
            if (res.ok && res.data && res.data.success) {
                const online = res.data.online || [];
                updateMyStatus(online.includes(state.myUserId));
                // Atualiza o badge do usuário selecionado
                if (state.activeUserId) {
                    updateStatusBadge(online.includes(parseInt(state.activeUserId, 10)));
                }
                // Atualiza pontinhos na lista
                el.userListUl.querySelectorAll('.user_item .status-dot').forEach(dot => {
                    const item = dot.closest('.user_item');
                    if (!item) return;
                    const isOn = online.includes(parseInt(item.dataset.id, 10));
                    dot.classList.toggle('online', isOn);
                    dot.classList.toggle('offline', !isOn);
                });
            }
        } catch (err) {
            // silencioso
        }
    }

    // ===== Ações de mensagem =====
    function messageActionHandler(e) {
        const btn = e.target.closest('.message-action-btn');
        if (!btn) return;
        e.stopPropagation();
        const block = btn.closest('.message-block');
        if (!block) return;
        const messageId = btn.dataset.messageId || block.dataset.id;

        if (btn.classList.contains('edit')) editMessage(block, messageId);
        else if (btn.classList.contains('delete')) deleteMessage(messageId);
        else if (btn.classList.contains('copy')) copyMessage(block);
        else if (btn.classList.contains('reply')) replyToMessage(block);
    }

    function editMessage(block, messageId) {
        const textEl = block.querySelector('.message-text');
        if (!textEl || block.querySelector('.message-edit-textarea')) return;
        const current = textEl.textContent;

        const textarea = document.createElement('textarea');
        textarea.className = 'message-edit-textarea';
        textarea.value = current;
        textarea.rows = 2;
        textEl.replaceWith(textarea);
        textarea.focus();

        const actions = block.querySelector('.message-actions');
        const originalActions = actions ? actions.innerHTML : '';
        if (actions) {
            actions.innerHTML =
                '<button type="button" class="message-action-btn save" title="Salvar"><i class="fas fa-check"></i></button>' +
                '<button type="button" class="message-action-btn cancel" title="Cancelar"><i class="fas fa-times"></i></button>';
        }

        const finish = (newText) => {
            const p = document.createElement('p');
            p.className = 'message-text';
            p.textContent = newText;
            textarea.replaceWith(p);
            if (actions) actions.innerHTML = originalActions; // restaura botões com data-id
        };

        actions?.addEventListener('click', async function onAction(e2) {
            const b2 = e2.target.closest('.message-action-btn');
            if (!b2) return;
            e2.stopPropagation();
            if (b2.classList.contains('save')) {
                const newText = textarea.value.trim();
                if (!newText) { showError('A mensagem não pode estar vazia'); return; }
                try {
                    const res = await api('edit_message.php', { method: 'POST', body: { message_id: messageId, new_message: newText, csrf_token: CSRF } });
                    if (res.ok && res.data && res.data.success) {
                        actions.removeEventListener('click', onAction);
                        finish(newText);
                        // Recarrega para restaurar os botões com data-id corretos
                        if (state.activeUserId) loadConversation(state.activeUserId, true);
                        showSuccess('Mensagem atualizada!');
                    } else {
                        showError(res.data?.error || 'Erro ao atualizar');
                    }
                } catch (err) {
                    if (err.message !== 'Sessão expirada') showError('Erro ao conectar com o servidor');
                }
            } else if (b2.classList.contains('cancel')) {
                actions.removeEventListener('click', onAction);
                finish(current);
            }
        });

        textarea.addEventListener('keydown', function(e3) {
            if (e3.key === 'Enter' && !e3.shiftKey) {
                e3.preventDefault();
                actions?.querySelector('.save')?.click();
            } else if (e3.key === 'Escape') {
                actions?.querySelector('.cancel')?.click();
            }
        });
    }

    async function deleteMessage(messageId) {
        if (!confirm('Tem certeza que deseja excluir esta mensagem?')) return;
        try {
            const res = await api('delete_message_single.php', { method: 'POST', body: { message_id: messageId, csrf_token: CSRF } });
            if (res.ok && res.data && res.data.success) {
                const block = el.conversation.querySelector('.message-block[data-id="' + parseInt(messageId, 10) + '"]');
                if (block) {
                    block.classList.add('removing');
                    setTimeout(() => {
                        block.remove();
                        if (!el.conversation.querySelector('.message-block')) {
                            el.conversation.innerHTML =
                                '<div class="empty-state"><div class="empty-state-icon">&#128172;</div>' +
                                '<div class="empty-state-title">Nenhuma mensagem ainda</div></div>';
                        }
                    }, 250);
                }
                // Mantém a assinatura local em sincronia com o servidor
                if (state.convMeta) state.convMeta.count = Math.max(0, (state.convMeta.count || 1) - 1);
                showSuccess('Mensagem excluída!');
            } else {
                showError(res.data?.error || 'Erro ao excluir');
            }
        } catch (err) {
            if (err.message !== 'Sessão expirada') showError('Erro ao conectar com o servidor');
        }
    }

    function copyMessage(block) {
        const textEl = block.querySelector('.message-text');
        const text = textEl ? textEl.textContent : '';
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(
                () => showSuccess('Mensagem copiada!'),
                () => fallbackCopy(text)
            );
        } else {
            fallbackCopy(text);
        }
    }

    function fallbackCopy(text) {
        const temp = document.createElement('textarea');
        temp.value = text;
        temp.style.position = 'fixed';
        temp.style.opacity = '0';
        document.body.appendChild(temp);
        temp.select();
        try { document.execCommand('copy'); showSuccess('Mensagem copiada!'); }
        catch (e) { showError('Não foi possível copiar'); }
        temp.remove();
    }

    function replyToMessage(block) {
        const sender = block.dataset.sender || 'usuário';
        const textEl = block.querySelector('.message-text');
        const text = textEl ? textEl.textContent : '';
        el.messageInput.value = '@' + sender + ': ' + text;
        el.messageInput.focus();
        // Move o cursor para o final
        el.messageInput.setSelectionRange(el.messageInput.value.length, el.messageInput.value.length);
    }

    // ===== Modal: info do outro usuário =====
    function openModal(modal) { modal?.classList.remove('hidden'); }
    function closeModal(modal) { modal?.classList.add('hidden'); }

    async function openUserInfoModal(userId) {
        try {
            const res = await api('user_info.php?user_id=' + encodeURIComponent(userId));
            if (!res.ok || !res.data || !res.data.success) throw new Error(res.data?.error || 'erro');
            const user = res.data.user;
            $('#user-info-photo').src = user.photo_path || 'foto_users/foto-padrao.png';
            $('#user-info-name').textContent = user.nome || user.username;
            $('#user-info-username').textContent = '@' + user.username;
            $('#user-info-course').textContent = user.curso || '—';
            $('#user-info-bio').textContent = user.bio || '—';
            const instaWrap = $('#user-info-insta-wrap');
            if (user.instagram) {
                instaWrap.classList.remove('hidden');
                $('#user-info-insta').href = 'https://www.instagram.com/' + encodeURIComponent(user.instagram);
            } else {
                instaWrap.classList.add('hidden');
            }
            openModal(el.userInfoModal);
        } catch (err) {
            if (err.message !== 'Sessão expirada') showError('Erro ao carregar informações');
        }
    }

    // ===== Modal: meu perfil =====
    async function openProfileModal() {
        try {
            const res = await api('profile.php');
            if (!res.ok || !res.data || !res.data.success) throw new Error(res.data?.error || 'erro');
            const user = res.data.user;
            $('#profile-photo').src = user.photo_path || 'foto_users/foto-padrao.png';
            $('#profile-nome').value = user.nome || '';
            $('#profile-bio').value = user.bio || '';
            $('#profile-instagram').value = user.instagram || '';
            $('#profile-username').textContent = user.username || '';
            $('#profile-curso').textContent = user.curso || '';
            openModal(el.profileModal);
        } catch (err) {
            if (err.message !== 'Sessão expirada') showError('Erro ao carregar perfil');
        }
    }

    async function saveProfile(e) {
        e.preventDefault();
        const form = e.target;
        const fd = new FormData(form);
        try {
            const res = await api('profile.php', { method: 'POST', body: fd });
            if (res.ok && res.data && res.data.success) {
                showSuccess(res.data.message || 'Perfil atualizado!');
                closeModal(el.profileModal);
                // Atualiza o nome no card do usuário atual
                const nome = $('#profile-nome').value;
                // O card mostra o username; mantém
            } else {
                showError(res.data?.error || 'Erro ao salvar');
            }
        } catch (err) {
            if (err.message !== 'Sessão expirada') showError('Erro ao salvar perfil');
        }
    }

    async function uploadAvatar(file) {
        if (!file) return;
        if (!/^image\//.test(file.type)) { showError('Envie uma imagem (JPG, PNG, GIF ou WebP).'); return; }
        if (file.size > 5 * 1024 * 1024) { showError('Foto muito grande. Limite de 5MB.'); return; }
        const fd = new FormData();
        fd.append('action', 'avatar');
        fd.append('csrf_token', CSRF);
        fd.append('photo', file);
        try {
            const res = await api('profile.php', { method: 'POST', body: fd });
            if (res.ok && res.data && res.data.success) {
                const newSrc = res.data.photo_path + '?t=' + Date.now();
                $('#profile-photo').src = newSrc;
                el.currentUserPhoto.src = newSrc;
                // Atualiza o avatar nas mensagens abertas
                el.conversation.querySelectorAll('.message-block.user-message .message-avatar').forEach(img => { img.src = newSrc; });
                showSuccess('Foto atualizada!');
                loadUserList();
            } else {
                showError(res.data?.error || 'Erro ao enviar foto');
            }
        } catch (err) {
            if (err.message !== 'Sessão expirada') showError('Erro ao enviar foto');
        }
    }

    // ===== Eventos =====
    function setupEventListeners() {
        // Tema
        el.themeToggle?.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-theme');
            applyTheme(current === 'dark' ? 'light' : 'dark');
        });
        // Ícone inicial do toggle
        const icon = el.themeToggle?.querySelector('i');
        if (icon) icon.className = document.documentElement.getAttribute('data-theme') === 'dark' ? 'fas fa-sun' : 'fas fa-moon';

        // Sidebar mobile
        el.toggleSidebar?.addEventListener('click', () => {
            if (el.sidebar?.classList.contains('visible')) closeSidebar();
            else openSidebar();
        });
        el.backdrop?.addEventListener('click', closeSidebar);

        // Tabs
        el.loadAllUsers?.addEventListener('click', () => {
            state.activeTab = 'all';
            el.loadAllUsers.classList.add('active');
            el.loadAllUsers.setAttribute('aria-selected', 'true');
            el.loadRecentUsers.classList.remove('active');
            el.loadRecentUsers.setAttribute('aria-selected', 'false');
            state.searchActive = false;
            el.searchUsers.value = '';
            loadUserList(false);
        });
        el.loadRecentUsers?.addEventListener('click', () => {
            state.activeTab = 'recents';
            el.loadRecentUsers.classList.add('active');
            el.loadRecentUsers.setAttribute('aria-selected', 'true');
            el.loadAllUsers.classList.remove('active');
            el.loadAllUsers.setAttribute('aria-selected', 'false');
            state.searchActive = false;
            el.searchUsers.value = '';
            loadUserList(true);
        });

        // Busca
        let searchTimeout = null;
        el.searchUsers?.addEventListener('input', function () {
            clearTimeout(searchTimeout);
            const term = this.value.trim();
            searchTimeout = setTimeout(() => {
                if (term !== '') {
                    state.searchActive = true;
                    searchUsers(term);
                } else {
                    state.searchActive = false;
                    loadUserList(state.activeTab === 'recents');
                }
            }, 300);
        });

        // Clique na lista de usuários (delegação)
        el.userListUl?.addEventListener('click', (e) => {
            const item = e.target.closest('.user_item');
            if (item && item.dataset.id) selectUser(item.dataset.id);
        });
        // Teclado na lista
        el.userListUl?.addEventListener('keydown', (e) => {
            if ((e.key === 'Enter' || e.key === ' ') && e.target.classList?.contains('user_item')) {
                e.preventDefault();
                if (e.target.dataset.id) selectUser(e.target.dataset.id);
            }
        });

        // Enviar mensagem
        el.sendForm?.addEventListener('submit', (e) => {
            e.preventDefault();
            sendMessage();
        });

        // Digitação → indicador
        el.messageInput?.addEventListener('input', notifyTyping);

        // Anexo
        el.attachBtn?.addEventListener('click', () => el.fileInput?.click());
        el.fileInput?.addEventListener('change', function () {
            if (this.files && this.files[0]) setAttachment(this.files[0]);
        });
        el.filePreviewRemove?.addEventListener('click', clearAttachment);

        // Voltar à lista (mobile)
        el.backToList?.addEventListener('click', () => {
            el.backToList.style.display = '';
            state.activeUserId = null;
            el.receiverId.value = '';
            stopConversationPolling();
            state.convMeta = null;
            state.sendQueue.length = 0;
            el.selectedUserPhoto.src = 'img/omigos.png';
            el.selectedUserPhoto.alt = '';
            el.selectedUserName.textContent = 'Selecione uma conversa';
            el.conversation.innerHTML =
                '<div class="empty-state"><div class="empty-state-icon">&#128172;</div>' +
                '<div class="empty-state-title">Bem-vindo ao Omigos!</div>' +
                '<div class="empty-state-description">Selecione um usuário na lista para começar a conversar.</div></div>';
            hideTypingIndicator();
            state.typingShown = false;
            openSidebar();
        });

        // Header do chat → info do usuário
        document.querySelector('#selected-user-info')?.addEventListener('click', () => {
            if (state.activeUserId) openUserInfoModal(state.activeUserId);
        });
        document.querySelector('#selected-user-info')?.addEventListener('keydown', (e) => {
            if ((e.key === 'Enter' || e.key === ' ') && state.activeUserId) {
                e.preventDefault();
                openUserInfoModal(state.activeUserId);
            }
        });

        // Card do usuário atual → perfil
        el.currentUserInfo?.addEventListener('click', openProfileModal);
        el.currentUserInfo?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openProfileModal(); }
        });

        // Ações de mensagem (delegação)
        el.conversation?.addEventListener('click', messageActionHandler);

        // Fechar modais
        $$('.modal-backdrop').forEach(backdrop => {
            backdrop.addEventListener('click', (e) => {
                if (e.target === backdrop) closeModal(backdrop);
            });
            backdrop.querySelector('.modal-close')?.addEventListener('click', () => closeModal(backdrop));
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeModal(el.userInfoModal);
                closeModal(el.profileModal);
            }
        });

        // Perfil
        $('#profile-form')?.addEventListener('submit', saveProfile);
        $('#profile-avatar-btn')?.addEventListener('click', () => $('#profile-avatar-input')?.click());
        $('#profile-avatar-input')?.addEventListener('change', function () {
            if (this.files && this.files[0]) uploadAvatar(this.files[0]);
            this.value = '';
        });

        // Solicitar permissão de notificação na primeira interação
        if ('Notification' in window && Notification.permission === 'default') {
            document.addEventListener('click', function once() {
                Notification.requestPermission();
                document.removeEventListener('click', once);
            }, { once: true });
        }

        // Atualiza a lista quando a janela volta ao foco
        window.addEventListener('focus', () => { loadUserList(); pollUnread(); });

        // Ao voltar para a aba: marca como lidas mensagens da conversa aberta
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible' && state.activeUserId) {
                markMessagesAsRead(state.activeUserId);
                pollUnread();
                heartbeat();
            }
        });

        // Redimensionamento: fecha sidebar overlay se voltar para desktop
        window.addEventListener('resize', () => {
            if (window.innerWidth > 768) closeSidebar();
        });
    }

    // ===== Inicialização =====
    function init() {
        setupEventListeners();
        loadUserList(false);
        heartbeat();

        // Deep-link: ?user=ID abre a conversa diretamente
        // (o long polling é iniciado dentro de selectUser)
        const urlUser = new URLSearchParams(window.location.search).get('user');
        if (urlUser && /^\d+$/.test(urlUser)) {
            selectUser(urlUser);
        }

        // Polling leve de não lidas (badges + notificações) — roda sempre,
        // inclusive em aba oculta (é o que dispara som/notificação)
        state.pollUnreadTimer = setInterval(pollUnread, 5000);

        // Heartbeat: presença
        state.heartbeatTimer = setInterval(() => {
            if (!document.hidden) heartbeat();
        }, 30000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
