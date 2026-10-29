<?php
require_once __DIR__ . '/core.php';

// Se já está logado, vai direto para o chat
if (is_logged_in()) {
    header("Location: chat.php");
    exit();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // CSRF
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals(csrf_token(), $sent)) {
        $error = "Sessão inválida. Recarregue a página e tente novamente.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = !empty($_POST['remember']);

        if ($username === '' || $password === '') {
            $error = "Preencha usuário e senha.";
        } else {
            try {
                $stmt = db_exec("SELECT id, username, password, is_admin FROM users WHERE username = ? LIMIT 1", "s", [$username]);
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                // Mensagem genérica: não revela se o usuário existe
                if ($row && password_verify($password, $row['password'])) {
                    // Proteção contra fixação de sessão
                    session_regenerate_id(true);
                    $_SESSION['user_id']  = (int)$row['id'];
                    $_SESSION['username'] = $row['username'];
                    $_SESSION['is_admin'] = ((int)$row['is_admin'] === 1);
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    // "Lembrar de mim" — token seguro por 30 dias
                    if ($remember) {
                        $token = bin2hex(random_bytes(32));
                        $hash  = hash('sha256', $token);
                        $expiry = date('Y-m-d H:i:s', time() + 30 * 86400);
                        db_exec("UPDATE users SET remember_token = ?, remember_expiry = ? WHERE id = ?", "ssi", [$hash, $expiry, (int)$row['id']]);
                        setcookie('omigos_remember', $row['id'] . ':' . $token, [
                            'expires'  => time() + 30 * 86400,
                            'path'     => '/',
                            'httponly' => true,
                            'samesite' => 'Lax',
                            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                        ]);
                    }

                    touch_last_active();
                    header("Location: chat.php");
                    exit();
                } else {
                    $error = "Usuário ou senha incorretos.";
                }
            } catch (mysqli_sql_exception $e) {
                error_log('Login error: ' . $e->getMessage());
                $error = "Erro interno. Tente novamente.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="website icon" type="image/png" href="img/logom.png">
    <title>Entrar — Omigos Chat</title>
    <link rel="stylesheet" href="clogin.css">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
</head>
<body>
    <div class="background">
        <div class="wrapper">
            <div class="brand">
                <img src="img/omigos.png" alt="Omigos" class="brand-logo">
            </div>
            <form id="login_form" action="login.php" method="POST" autocomplete="on">
                <h1>Login</h1>
                <?= csrf_field() ?>
                <?php if (!empty($error)): ?>
                    <div class="error" role="alert"><?= e($error) ?></div>
                <?php endif; ?>
                <div class="input-box">
                    <input id="login" name="username" type="text" placeholder="Nome de usuário" required autofocus autocomplete="username" maxlength="50">
                    <i class="bx bxs-user"></i>
                </div>
                <div class="input-box">
                    <input id="senha" name="password" type="password" placeholder="Senha" required autocomplete="current-password">
                    <i class="bx bxs-lock-alt"></i>
                </div>
                <div class="remember-forgot">
                    <label>
                        <input type="checkbox" name="remember" value="1">
                        Lembrar de mim
                    </label>
                </div>
                <button type="submit" class="btn">Entrar</button>
                <div class="register-link">
                    <p>Não tem uma conta? <a href="register.php">Cadastre-se</a></p>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
