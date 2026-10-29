# Omigos Chat

Chat privado em PHP + MySQL para grupos de amigos. Conversas diretas, indicador
de digitação, status online, contador de não lidas, busca de usuários, edição e
exclusão de mensagens, foto de perfil e painel administrativo.

Interface em **HTML + CSS + JavaScript puro** — sem framework, sem build.

## Requisitos

- XAMPP (PHP 8.1+ com `mysqli`) — MySQL ativo na porta 3306
- Nenhuma dependência externa de frontend

## Instalação

1. Copie a pasta para `C:\xampp\htdocs\chatphp`.
2. Crie o banco e as tabelas:

   ```bash
   mysql -u root -p < omigos_schema.sql
   ```

   O `omigos_schema.sql` contém **apenas a estrutura** (6 tabelas). Nenhum dado
   pessoal de ninguém é versionado neste repositório.

3. Configure a conexão (opcional — os padrões já funcionam no XAMPP local):

   | Variável | Padrão |
   |----------|--------|
   | `OMIGOS_DB_HOST` | `localhost` |
   | `OMIGOS_DB_USER` | `root` |
   | `OMIGOS_DB_PASS` | *(vazio)* |
   | `OMIGOS_DB_NAME` | `chatphp` |

   ```powershell
   $env:OMIGOS_DB_USER = "root"
   $env:OMIGOS_DB_PASS = "sua-senha"
   ```

4. Rode:

   ```bash
   php -S localhost:8000 -t C:\xampp\htdocs\chatphp
   ```

   ou acesse <http://localhost/chatphp> pelo Apache do XAMPP.

5. Cadastre o primeiro usuário em `register.php` e promova a admin com
   `UPDATE users SET is_admin = 1 WHERE username = 'seu_usuario';`

## Funcionalidades

**Conversa**
- Mensagens diretas com entrega e leitura em tempo real (polling)
- Indicador de "digitando..."
- Status online/offline com heartbeat
- Responder (`@usuario:`), editar, excluir e copiar mensagem
- Exclusão automática por tempo configurável (`settings.message_deletion_time`)
- Badge de mensagens não lidas e lista de conversas recentes
- Busca de usuários em tempo real

**Conta**
- Cadastro e login com `password_hash()` (bcrypt)
- "Lembrar de mim" via cookie com token aleatório + hash SHA-256 no banco
- Perfil com foto, curso e username
- Tema claro/escuro com detecção automática do sistema

**Administração** (`admin.php`)
- Gestão de usuários, mensagens e configurações do chat

**Segurança**
- Prepared statements em 100% das queries (SQL injection corrigido)
- Saída HTML escapada no servidor
- Token CSRF em operações que alteram estado
- Sessão HttpOnly/SameSite, regeneração de ID no login, fingerprint de UA
- Rate limiting no login e no registro

## Estrutura

```
admin.php                  Painel administrativo
admin_actions.php          Ações do painel (prepared statements)
core.php                   Sessão, CSRF, auth, conexão e helpers
db.php                     Ponte para core.php
chat.php                   Envio de mensagem
conversation.php           Render da conversa
get_messages.php           Histórico (prepared statement)
check_new_messages.php     Polling de novas mensagens
typing.php                 Indicador de digitação
heartbeat.php              Status online
mark_as_read.php           Marca como lida
delete_message_single.php  Exclusão de mensagem
edit_message.php           Edição de mensagem
search_users.php           Busca de usuários
user_list.php              Lista de usuários
profile.php / user_info.php  Perfil
register.php / login.php / logout.php  Autenticação
img/                       Fundos, logo e ícones
omigos_schema.sql          Estrutura do banco (sem dados)
REVITALIZATION_REPORT.md   Detalhe das correções e funcionalidades
```

## Privacidade

Este repositório é uma **base de código**. Ele não contém:

- dados pessoais (nomes, matrículas, perfis, mensagens) de ninguém;
- hashes de senha;
- fotos de perfil ou arquivos enviados por usuários (`uploads/`, `foto_users/`);
- o dump do banco (`omigos_chatphp.sql`) — use `omigos_schema.sql`.

Crie seu próprio banco e cadastre seus usuários pela tela de registro.
