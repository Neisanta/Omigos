<?php
/**
 * core.php — Núcleo compartilhado: sessão, CSRF, auth e helpers.
 * Incluir SEMPRE como: require_once __DIR__ . '/core.php';
 */

// Configuração de sessão segura
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('OMIGOSSESS');
    session_start();
}

// ===== Auto-login via cookie "lembrar de mim" =====
function try_remember_login(): void {
    if (is_logged_in() || empty($_COOKIE['omigos_remember'])) return;
    $cookie = $_COOKIE['omigos_remember'];
    if (!is_string($cookie) || strpos($cookie, ':') === false) return;

    [$userId, $token] = explode(':', $cookie, 2);
    $userId = (int)$userId;
    if ($userId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        setcookie('omigos_remember', '', ['expires' => time() - 3600, 'path' => '/']);
        return;
    }

    try {
        $stmt = db_exec("SELECT id, username, is_admin, remember_token, remember_expiry FROM users WHERE id = ? LIMIT 1", "i", [$userId]);
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || empty($row['remember_token']) || !hash_equals($row['remember_token'], hash('sha256', $token))) {
            setcookie('omigos_remember', '', ['expires' => time() - 3600, 'path' => '/']);
            return;
        }
        if (strtotime($row['remember_expiry']) < time()) {
            db_exec("UPDATE users SET remember_token = NULL, remember_expiry = NULL WHERE id = ?", "i", [$userId]);
            setcookie('omigos_remember', '', ['expires' => time() - 3600, 'path' => '/']);
            return;
        }
        session_regenerate_id(true);
        $_SESSION['user_id']  = (int)$row['id'];
        $_SESSION['username'] = $row['username'];
        $_SESSION['is_admin'] = ((int)$row['is_admin'] === 1);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        touch_last_active();
    } catch (mysqli_sql_exception $e) {
        error_log('Remember login error: ' . $e->getMessage());
    }
}
try_remember_login();

// ===== Helpers de saída =====
function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400): void {
    json_response(['success' => false, 'error' => $message], $status);
}

// ===== CSRF =====
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Valida o token CSRF de POST (header X-CSRF-Token ou campo csrf_token). */
function csrf_verify(): void {
    $sent = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals(csrf_token(), $sent)) {
        json_error('Token de segurança inválido ou expirado. Recarregue a página.', 403);
    }
}

// ===== Autenticação / Autorização =====
function current_user_id(): int {
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
}

function is_logged_in(): bool {
    return current_user_id() > 0;
}

function is_admin(): bool {
    return !empty($_SESSION['is_admin']);
}

function require_login(): void {
    if (!is_logged_in()) {
        // Detecta chamadas AJAX e responde com JSON/401
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $xhr = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
        if ($xhr || stripos($accept, 'application/json') !== false) {
            json_error('Sessão expirada. Faça login novamente.', 401);
        }
        header('Location: login.php');
        exit;
    }
}

function require_admin(): void {
    require_login();
    if (!is_admin()) {
        json_error('Acesso restrito ao administrador.', 403);
    }
}

/** Exige que o usuário esteja logado para endpoints de dados (retorna HTML de erro curto). */
function require_login_or_exit(): void {
    if (!is_logged_in()) {
        http_response_code(401);
        exit('Sessão expirada.');
    }
}

// ===== Banco =====
function db(): mysqli {
    static $conn = null;
    if ($conn === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        // Credenciais vêm do ambiente (veja o README). O padrão é o MySQL do
        // XAMPP local; em produção, defina OMIGOS_DB_HOST/USER/PASS/NAME.
        $host = getenv('OMIGOS_DB_HOST') ?: 'localhost';
        $user = getenv('OMIGOS_DB_USER') ?: 'root';
        $pass = getenv('OMIGOS_DB_PASS');
        $name = getenv('OMIGOS_DB_NAME') ?: 'chatphp';
        try {
            $conn = new mysqli($host, $user, $pass === false ? '' : $pass, $name);
            $conn->set_charset('utf8mb4');
            // Alinha o fuso do MySQL com o do PHP (NOW() == time())
            $conn->query("SET time_zone = '" . (new DateTime())->format('P') . "'");
        } catch (mysqli_sql_exception $e) {
            error_log('DB connection error: ' . $e->getMessage());
            http_response_code(500);
            exit('Erro de conexão com o banco de dados.');
        }
    }
    return $conn;
}

/** Query preparada auxiliar: retorna statement executado. */
function db_exec(string $sql, string $types = '', array $params = []): mysqli_stmt {
    $stmt = db()->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}

/** Verifica se uma coluna existe em uma tabela do banco atual. */
function column_exists(string $table, string $column): bool {
    $stmt = db_exec("SELECT COUNT(*) c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?", "ss", [$table, $column]);
    return (int)$stmt->get_result()->fetch_assoc()['c'] > 0;
}

// ===== Heartbeat de presença =====
function touch_last_active(): void {
    if (is_logged_in()) {
        try {
            db_exec("UPDATE users SET last_active = NOW() WHERE id = ?", "i", [current_user_id()]);
        } catch (mysqli_sql_exception $e) {
            error_log('Heartbeat error: ' . $e->getMessage());
        }
    }
}

/** Um usuário está online se ativou o sistema nos últimos 60 segundos. */
function user_is_online(?string $lastActive): bool {
    if (empty($lastActive)) return false;
    $ts = strtotime($lastActive);
    return $ts !== false && (time() - $ts) <= 60;
}

/** Chave de conversa ordenada (independe de quem é user1/user2). */
function conversation_key(int $a, int $b): string {
    return $a < $b ? "$a:$b" : "$b:$a";
}

/** HTML seguro para anexo de mensagem (imagem embutida ou link para download). */
function render_attachment_html(?string $filePath, ?string $fileName, ?string $fileType): string {
    if (empty($filePath) || empty($fileName)) return '';
    if (strpos($filePath, '..') !== false) return '';
    $safePath  = e($filePath);
    $safeName  = e($fileName);
    $imgTypes  = ['jpg', 'png', 'gif', 'webp'];

    if (in_array($fileType, $imgTypes, true)) {
        return '<a href="' . $safePath . '" target="_blank" rel="noopener" class="message-attachment image">'
             . '<img src="' . $safePath . '" alt="' . $safeName . '" class="attachment-image" loading="lazy"></a>';
    }
    // Anexos antigos (pré-política de somente imagens) continuam exibidos como link
    $icon = ($fileType === 'pdf') ? 'fa-file-pdf' : (($fileType === 'zip') ? 'fa-file-archive' : 'fa-file-alt');
    return '<a href="' . $safePath . '" download="' . $safeName . '" class="message-attachment file">'
         . '<i class="fas ' . $icon . '"></i><span class="attachment-name">' . $safeName . '</span></a>';
}

/**
 * Valida e salva um upload de IMAGEM (único tipo de anexo permitido).
 * - Confere MIME real via finfo e conteúdo real via getimagesize (não confia na extensão)
 * - Gera nome interno aleatório (nunca usa o nome enviado pelo usuário)
 * - Aplica limite de tamanho
 *
 * @param array  $file     Item de $_FILES (ex.: $_FILES['file'])
 * @param string $destDir  Diretório de destino relativo ao projeto (ex.: 'uploads')
 * @param int    $maxBytes Tamanho máximo em bytes
 * @return array{path:string, ext:string, orig_name:string}
 * @throws RuntimeException Mensagem amigável quando o arquivo é recusado
 */
function save_image_upload(array $file, string $destDir, int $maxBytes): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Falha no envio do arquivo. Tente novamente.');
    }
    $tmp  = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('Arquivo vazio.');
    }
    if ($size > $maxBytes) {
        throw new RuntimeException('Arquivo muito grande. Limite de ' . (int)round($maxBytes / 1048576) . 'MB.');
    }
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Arquivo inválido.');
    }

    // Tipos permitidos: apenas imagens reais (SVG propositalmente não é aceito)
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if ($mime === false || !isset($allowed[$mime])) {
        throw new RuntimeException('Apenas imagens são permitidas (JPG, PNG, GIF ou WebP).');
    }

    // Valida o CONTEÚDO real: getimagesize só funciona com imagens íntegras
    $info = @getimagesize($tmp);
    if ($info === false || empty($info[0]) || empty($info[1]) || ($info['mime'] ?? '') !== $mime) {
        throw new RuntimeException('O arquivo enviado não é uma imagem válida.');
    }

    $ext = $allowed[$mime];
    $relDir = rtrim($destDir, '/');

    // Nome interno aleatório — evita colisões, path traversal e execução por nome
    $path = $relDir . '/img_' . bin2hex(random_bytes(16)) . '.' . $ext;
    $absDir = __DIR__ . '/' . $relDir;
    if (!is_dir($absDir)) { mkdir($absDir, 0775, true); }
    if (!move_uploaded_file($tmp, __DIR__ . '/' . $path)) {
        throw new RuntimeException('Falha ao salvar o arquivo.', 500);
    }

    // Nome original sanitizado — usado apenas para exibição, nunca no caminho em disco
    $orig = pathinfo((string)($file['name'] ?? ''), PATHINFO_FILENAME);
    $orig = preg_replace('/[^a-zA-Z0-9_.\-]/', '', $orig);
    if ($orig === '' || $orig === '.') $orig = 'imagem';
    $orig = mb_substr($orig, 0, 100) . '.' . $ext;

    return ['path' => $path, 'ext' => $ext, 'orig_name' => $orig];
}

/**
 * Renderiza o bloco HTML de uma mensagem (todo o conteúdo é escapado).
 * Usado por get_messages.php, send_message.php e conversation_updates.php.
 *
 * @param array  $row      Linha de messages (id, message, sent_at, edited_at, file_*)
 * @param string $username Nome/username de quem enviou a mensagem
 * @param string $photo    Caminho da foto do remetente
 * @param bool   $isOwn    true se a mensagem é do usuário logado
 */
function render_message_block(array $row, string $username, string $photo, bool $isOwn): string {
    $id     = (int)$row['id'];
    $text   = (string)($row['message'] ?? '');
    $time   = date('d/m/Y H:i', strtotime($row['sent_at'] ?? 'now'));
    $edited = !empty($row['edited_at']) ? ' <span class="edited-tag">(editada)</span>' : '';

    ob_start();
    ?>
    <div class="message-block <?= $isOwn ? 'user-message' : 'other-message' ?>" data-id="<?= $id ?>" data-sender="<?= e($username) ?>">
        <img src="<?= e($photo) ?>" class="message-avatar" alt="Avatar">
        <div class="message-content">
            <div class="message-header">
                <span class="sender-name"><?= e($username) ?></span>
                <span class="timestamp"><?= e($time) ?><?= $edited ?></span>
            </div>
            <?php if ($text !== ''): ?>
            <p class="message-text"><?= e($text) ?></p>
            <?php endif; ?>
            <?= render_attachment_html($row['file_path'] ?? null, $row['file_name'] ?? null, $row['file_type'] ?? null) ?>
            <div class="message-actions">
                <?php if ($isOwn): ?>
                <button type="button" class="message-action-btn edit" data-message-id="<?= $id ?>" title="Editar" aria-label="Editar mensagem"><i class="fas fa-pen"></i></button>
                <button type="button" class="message-action-btn delete" data-message-id="<?= $id ?>" title="Excluir" aria-label="Excluir mensagem"><i class="fas fa-trash"></i></button>
                <?php endif; ?>
                <button type="button" class="message-action-btn copy" title="Copiar" aria-label="Copiar mensagem"><i class="fas fa-copy"></i></button>
                <button type="button" class="message-action-btn reply" title="Responder" aria-label="Responder mensagem"><i class="fas fa-reply"></i></button>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/** Anti-spam: verifica se o remetente ainda pode enviar mensagens (máx. $max em $windowSeconds). */
function sender_under_rate_limit(int $senderId, int $max, int $windowSeconds): bool {
    $stmt = db_exec("SELECT COUNT(*) c FROM messages WHERE sender_id = ? AND sent_at >= NOW() - INTERVAL ? SECOND", "ii", [$senderId, $windowSeconds]);
    $count = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    return $count < $max;
}

/** Obtém ou cria o id inteiro da conversa entre dois usuários (seguro, com transação). */
function get_or_create_conversation(int $user1, int $user2): int {
    $conn = db();
    $k = conversation_key($user1, $user2);

    $stmt = db_exec("SELECT id FROM conversations WHERE CONCAT(LEAST(user1_id, user2_id), ':', GREATEST(user1_id, user2_id)) = ? LIMIT 1", "s", [$k]);
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) return (int)$row['id'];

    // Cria dentro de transação para evitar corrida
    $conn->begin_transaction();
    try {
        // Cria a conversa com user1 sempre < user2 para casar com a chave
        $lo = min($user1, $user2);
        $hi = max($user1, $user2);
        $stmt = $conn->prepare("INSERT INTO conversations (user1_id, user2_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $lo, $hi);
        $stmt->execute();
        $id = (int)$conn->insert_id;
        $conn->commit();
        return $id;
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        // Corrida: outra requisição criou — busca novamente
        $stmt = db_exec("SELECT id FROM conversations WHERE CONCAT(LEAST(user1_id, user2_id), ':', GREATEST(user1_id, user2_id)) = ? LIMIT 1", "s", [$k]);
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) return (int)$row['id'];
        throw $e;
    }
}
