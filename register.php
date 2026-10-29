<?php
require_once __DIR__ . '/core.php';

// Se já está logado, vai para o chat
if (is_logged_in()) {
    header("Location: chat.php");
    exit();
}

$error = "";
$old = ['nome' => '', 'username' => '', 'bio' => '', 'instagram' => ''];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // CSRF
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals(csrf_token(), $sent)) {
        $error = "Sessão inválida. Recarregue a página e tente novamente.";
    } else {
        $nome      = trim($_POST['nome'] ?? '');
        $username  = trim($_POST['username'] ?? '');
        $curso     = $_POST['curso'] ?? '';
        $rm        = $_POST['rm'] ?? '';
        $password  = $_POST['password'] ?? '';
        $confirm   = $_POST['password_confirm'] ?? '';
        $bio       = trim($_POST['bio'] ?? '');
        $instagram = trim($_POST['instagram'] ?? '');

        $old['nome'] = $nome; $old['username'] = $username;
        $old['bio'] = $bio; $old['instagram'] = $instagram;

        $cursosValidos = ['informática', 'meio ambiente', 'logistica', 'recursos humanos', 'administração'];

        // Validações
        if ($nome === '' || mb_strlen($nome) > 100) {
            $error = "Informe seu nome completo (até 100 caracteres).";
        } elseif (!preg_match('/^[a-zA-Z0-9_.]{3,30}$/', $username)) {
            $error = "Usuário inválido: use 3 a 30 caracteres (letras, números, _ ou .).";
        } elseif (!in_array($curso, $cursosValidos, true)) {
            $error = "Por favor, selecione um curso válido.";
        } elseif (!ctype_digit($rm) || strlen($rm) > 10) {
            $error = "Informe um RM numérico válido.";
        } elseif (strlen($password) < 6) {
            $error = "A senha deve ter pelo menos 6 caracteres.";
        } elseif ($password !== $confirm) {
            $error = "As senhas não coincidem.";
        } elseif ($instagram !== '' && !preg_match('/^[a-zA-Z0-9._]{1,30}$/', ltrim($instagram, '@'))) {
            $error = "Instagram inválido (use apenas letras, números, . ou _).";
        } else {
            $instagram = ltrim($instagram, '@');

            // Upload seguro da foto (opcional) — somente imagens, validadas por conteúdo real
            $photo_path = "foto_users/foto-padrao.png";
            if (isset($_FILES['photo']) && is_array($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                try {
                    $img = save_image_upload($_FILES['photo'], 'foto_users', 5 * 1024 * 1024);
                    $photo_path = $img['path'];
                } catch (RuntimeException $e) {
                    $error = $e->getMessage();
                }
            }

            if ($error === '') {
                try {
                    // Verifica duplicidade de username
                    $stmt = db_exec("SELECT id FROM users WHERE username = ? LIMIT 1", "s", [$username]);
                    $dup = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($dup) {
                        $error = "Nome de usuário já em uso.";
                        // Remove a foto recém-enviada, se houver
                        if ($photo_path !== "foto_users/foto-padrao.png" && is_file(__DIR__ . '/' . $photo_path)) {
                            @unlink(__DIR__ . '/' . $photo_path);
                        }
                    } else {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = db_exec("INSERT INTO users (nome, username, curso, rm, password, bio, instagram) VALUES (?, ?, ?, ?, ?, ?, ?)", "sssssss", [$nome, $username, $curso, (int)$rm, $hash, $bio, $instagram]);
                        $userId = (int)$stmt->insert_id;
                        $stmt->close();

                        $stmt = db_exec("INSERT INTO user_photos (user_id, photo_path) VALUES (?, ?)", "is", [$userId, $photo_path]);
                        $stmt->close();

                        header("Location: login.php?registered=1");
                        exit();
                    }
                } catch (mysqli_sql_exception $e) {
                    error_log('Register error: ' . $e->getMessage());
                    $error = "Erro ao registrar. Tente novamente.";
                    if ($photo_path !== "foto_users/foto-padrao.png" && is_file(__DIR__ . '/' . $photo_path)) {
                        @unlink(__DIR__ . '/' . $photo_path);
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br" dir="ltr">
<head>
    <meta charset="UTF-8">
    <title>Criar conta — Omigos Chat</title>
    <link rel="stylesheet" href="cregistro.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="website icon" type="image/png" href="img/logom.png">
    <script>
        function previewImage(event) {
            var input = event.target;
            var output = document.getElementById('preview');
            if (input.files && input.files[0]) {
                var file = input.files[0];
                if (file.size > 5 * 1024 * 1024) {
                    alert('Foto muito grande. Limite de 5MB.');
                    input.value = '';
                    output.style.display = 'none';
                    return;
                }
                var reader = new FileReader();
                reader.onload = function() {
                    output.style.display = 'block';
                    output.src = reader.result;
                };
                reader.readAsDataURL(file);
            } else {
                output.style.display = 'none';
                output.src = '#';
            }
        }
    </script>
</head>
<body>
    <div class="container">
        <div class="title">Registro</div>
        <div class="content">
            <form action="register.php" method="POST" enctype="multipart/form-data" id="register_form">
                <?= csrf_field() ?>
                <div class="user-details">
                    <div class="input-box">
                        <span class="details">Nome Completo</span>
                        <input type="text" name="nome" id="Nome" placeholder="Insira seu nome" required maxlength="100" value="<?= e($old['nome']) ?>">
                    </div>
                    <div class="input-box">
                        <span class="details">Nome de Usuário</span>
                        <input type="text" name="username" id="NomeDeUsuario" placeholder="Insira seu nome de usuário" required maxlength="30" pattern="[a-zA-Z0-9_.]{3,30}" title="3 a 30 caracteres: letras, números, _ ou ." value="<?= e($old['username']) ?>">
                    </div>
                    <div class="input-box">
                        <span class="details">Curso</span>
                        <select class="select" name="curso" id="Curso" required>
                            <option value="selecione">Selecione...</option>
                            <option value="informática">Informática</option>
                            <option value="meio ambiente">Meio Ambiente</option>
                            <option value="logistica">Logística</option>
                            <option value="recursos humanos">Recursos Humanos</option>
                            <option value="administração">Administração</option>
                        </select>
                    </div>
                    <div class="input-box">
                        <span class="RM">Número RM</span>
                        <input type="number" name="rm" id="rm" placeholder="Insira seu RM" required min="1" max="9999999999">
                    </div>
                    <div class="input-box">
                        <span class="details">Senha</span>
                        <input type="password" name="password" id="Senha" placeholder="Crie sua senha" required minlength="6">
                    </div>
                    <div class="input-box">
                        <span class="details">Confirme a Senha</span>
                        <input type="password" name="password_confirm" id="CSenha" placeholder="Confirme sua senha" required minlength="6">
                    </div>
                    <div class="input-box full">
                        <span class="details">Breve descrição</span>
                        <textarea name="bio" id="Bio" placeholder="Escreva um pouco sobre você..." rows="4" maxlength="500"><?= e($old['bio']) ?></textarea>
                    </div>
                    <div class="input-box">
                        <span class="details">Instagram</span>
                        <input type="text" name="instagram" id="Instagram" placeholder="Seu Instagram (opcional)" maxlength="30" value="<?= e($old['instagram']) ?>">
                    </div>
                    <div class="input-box">
                        <span class="details">Foto de Perfil</span>
                        <input type="file" name="photo" accept="image/png,image/jpeg,image/gif,image/webp" onchange="previewImage(event)">
                    </div>
                    <div class="input-box">
                        <img id="preview" src="#" alt="Pré-visualização da foto de perfil" style="max-width: 140px; max-height: 140px; display: none; border-radius: 50%;">
                    </div>
                </div>
                <?php if (!empty($error)): ?>
                    <div class="error" role="alert"><?= e($error) ?></div>
                <?php endif; ?>
                <input class="button" type="submit" value="Registrar" name="registro">
                <div class="register-link">
                    <p>Já tem uma conta? <a href="login.php">Entrar</a></p>
                </div>
            </form>
        </div>
    </div>
    <script>
        document.getElementById('register_form').addEventListener('submit', function(e) {
            var s = document.getElementById('Senha').value;
            var c = document.getElementById('CSenha').value;
            if (s !== c) {
                e.preventDefault();
                alert('As senhas não coincidem.');
            }
        });
    </script>
</body>
</html>
