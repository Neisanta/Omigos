<div align="center">
  <h1>Omigos Chat</h1>
  <p><strong>Chat web em PHP, MySQL e JavaScript para comunicação direta entre usuários.</strong></p>
  <p>
    <a href="#funcionalidades"><strong>Funcionalidades</strong></a>
    ·
    <a href="#como-executar"><strong>Executar localmente</strong></a>
    ·
    <a href="#arquitetura"><strong>Arquitetura</strong></a>
  </p>
</div>

---

Omigos é uma aplicação de mensagens em tempo quase real, desenvolvida para facilitar a comunicação entre estudantes. Cada usuário conversa diretamente com outro, acompanha conversas recentes e recebe atualizações por requisições assíncronas.

A interface usa **HTML, CSS e JavaScript puros**, sem framework e sem etapa de build. O backend utiliza PHP com `mysqli`, prepared statements e uma camada compartilhada de sessão, CSRF, autenticação e conexão com o banco.

> **Status:** base funcional pronta para execução local. O projeto não possui uma demonstração pública no momento; todos os dados devem ser criados no próprio ambiente.

## Funcionalidades verificadas no código

### Comunicação

- cadastro, login e logout de usuários;
- conversas diretas entre dois usuários;
- envio e carregamento incremental de mensagens por polling com `fetch`;
- indicador de “digitando...”;
- status online/offline com heartbeat;
- lista de conversas recentes e contador de mensagens não lidas;
- busca de usuários enquanto o termo é digitado;
- responder, editar, excluir e copiar mensagem;
- anexos de imagem com nome interno aleatório e validação de MIME e conteúdo.

### Conta e perfil

- perfil com nome, usuário, curso, RM, descrição e foto;
- opção “lembrar de mim” com token aleatório e hash SHA-256 armazenado no banco;
- tema claro/escuro com detecção do sistema e persistência em `localStorage`;
- layout responsivo com navegação lateral adaptada ao tamanho da tela.

### Administração

- painel administrativo protegido por sessão e `is_admin`;
- gestão de usuários, mensagens, fotos e anexos;
- configuração do período de retenção/exclusão de mensagens.

> Não há um papel ou fluxo específico de professor no código atual; a plataforma opera como comunicação direta entre usuários cadastrados.

## Tecnologias utilizadas

| Tecnologia | Função no projeto |
|---|---|
| **PHP 8.1+** | páginas, endpoints AJAX e regras de acesso |
| **MySQL / MariaDB** | usuários, conversas, mensagens, leituras, presença e configurações |
| **MySQLi** | conexão e prepared statements |
| **JavaScript** | polling, busca, envio, edição, exclusão, anexos e tema |
| **HTML5 / CSS3** | interface responsiva, tema escuro e componentes do chat |
| **Fetch API / AJAX** | comunicação assíncrona com endpoints PHP |
| **SessionStorage / LocalStorage** | estado da sessão do painel e preferência de tema |
| **Git / GitHub** | versionamento e organização do projeto |

## Arquitetura e organização

O projeto usa uma estrutura simples, com um núcleo compartilhado e endpoints pequenos separados por responsabilidade:

```text
core.php                  Sessão, CSRF, autenticação, banco e helpers
chat.php / conversation.php  Página principal e envio de mensagens
get_messages.php          Histórico incremental
check_new_messages*.php   Atualizações de mensagens e não lidas
typing.php / heartbeat.php Digitação e presença
mark_as_read.php          Marcação de mensagens como lidas
edit_message.php          Edição autorizada pelo remetente
delete_message_single.php Exclusão individual e anexo
send_message.php          Envio, anexos e notificação de não lidas
search_users.php          Busca assíncrona de usuários
user_list*.php            Lista de usuários e conversas recentes
profile.php / user_info.php  Perfil e dados do usuário
admin.php / admin_actions.php Painel e ações administrativas
omigos_schema.sql         Schema atual, sem dados pessoais
db_migration.sql          Migração de bases legadas
```

O fluxo principal é: **JavaScript → endpoint PHP → core.php → MySQL**. Os endpoints que alteram dados exigem sessão válida e token CSRF.

## Banco de dados

[`omigos_schema.sql`](omigos_schema.sql) cria sete tabelas:

| Tabela | Finalidade |
|---|---|
| `users` | conta, perfil, papel administrativo, presença e token de sessão persistente |
| `user_photos` | referência da foto de perfil |
| `conversations` | par de usuários associado a uma conversa |
| `messages` | conteúdo, remetente, destinatário, edição e anexo |
| `unread_messages` | contadores de mensagens não lidas |
| `typing_status` | estado temporário do indicador de digitação |
| `settings` | configuração de retenção de mensagens |

O schema não inclui usuários, mensagens, hashes de senha ou outros dados pessoais. Chaves estrangeiras e `ON DELETE CASCADE` mantêm as relações consistentes.

## Como executar

### Requisitos

- PHP 8.1 ou superior com `mysqli` e `fileinfo`;
- MySQL 5.7+/8 ou MariaDB compatível;
- nenhuma dependência de JavaScript ou gerenciador de pacotes é necessária.

### 1. Criar o banco e importar o schema

```bash
mysql -u root -p -e "CREATE DATABASE chatphp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root -p chatphp < omigos_schema.sql
```

Em instalações antigas, faça backup e use [`db_migration.sql`](db_migration.sql) para adicionar as colunas e tabelas exigidas pelo código atual.

### 2. Configurar a conexão

Por padrão, a aplicação procura `localhost`, usuário `root`, senha vazia e banco `chatphp` — a configuração usual do XAMPP local. Em PowerShell:

```powershell
$env:OMIGOS_DB_HOST = "localhost"
$env:OMIGOS_DB_USER = "root"
$env:OMIGOS_DB_PASS = "sua_senha"
$env:OMIGOS_DB_NAME = "chatphp"
```

As variáveis são lidas por `core.php`; não é necessário gravar credenciais no repositório.

### 3. Iniciar a aplicação

A partir da pasta do projeto:

```bash
php -S localhost:8000
```

Acesse <http://localhost:8000/register.php>, cadastre dois usuários de teste e converse entre eles em janelas diferentes do navegador.

Para promover um usuário ao painel administrativo:

```sql
UPDATE users SET is_admin = 1 WHERE username = 'seu_usuario';
```

## Segurança aplicada

- senha armazenada com `password_hash()` e verificada com `password_verify()`;
- token CSRF em operações que alteram estado;
- prepared statements nas consultas de dados;
- escape de saída HTML com `htmlspecialchars()`;
- cookie “lembrar de mim” com HttpOnly, SameSite e expiração;
- regeneração da sessão no login;
- validação de MIME e conteúdo das imagens, limite de tamanho e nome interno aleatório;
- verificação de propriedade antes de editar ou excluir uma mensagem;
- rate limiting no envio de mensagens para evitar envios em excesso.

## Aprendizados demonstrados

- modelagem de relacionamentos para mensagens e não lidas;
- autenticação, sessão persistente e autorização por ownership;
- comunicação assíncrona com polling e JavaScript;
- upload seguro de arquivos e tratamento de imagens;
- organização de endpoints e reutilização de uma camada central de segurança;
- evolução de uma interface responsiva com JavaScript sem framework.

## Próximas melhorias

- substituir polling por WebSocket ou Server-Sent Events quando a infraestrutura permitir;
- automatizar testes de integração do banco e dos fluxos de mensagem;
- revisar a acessibilidade por teclado e leitor de tela em todas as telas;
- versionar o schema com migrações incrementais;
- substituir o envio de anexo por arquivo por armazenamento com autorização e antivírus.

## Privacidade

- não versione dumps de banco, fotos, mensagens ou hashes de senha;
- `uploads/`, `foto_users/`, `*.local` e `.env` estão ignorados pelo Git;
- o repositório contém somente o código, o schema e a documentação.

---

<div align="center">
  <sub>Projeto de demonstração de desenvolvimento web, banco de dados e segurança de aplicações.</sub>
</div>
