# Notas técnicas do Omigos Chat

Este documento registra o estado técnico do código atual. Ele substitui relatórios antigos que descreviam etapas intermediárias e podem ser usados para avaliar o que está implementado de fato.

## Funcionalidades presentes no código

### Autenticação e conta

- cadastro e login com `password_hash()`/`password_verify()`;
- sessão HttpOnly/SameSite e regeneração de ID no login;
- cookie “lembrar de mim” com token aleatório e hash SHA-256 no banco;
- perfil com foto, curso, RM e descrição;
- perfil administrativo protegido por `is_admin`.

### Mensagens

- conversa direta entre dois usuários;
- envio e atualização incremental por `fetch`/polling;
- indicador de digitação e status online por heartbeat;
- contador de mensagens não lidas e lista de conversas recentes;
- responder, editar, excluir e copiar mensagem;
- anexos de imagem validados por MIME e conteúdo.

### Interface

- tema claro/escuro com preferência persistida no navegador;
- layout responsivo e navegação lateral para telas menores;
- busca de usuários, feedback visual e mensagens de erro.

### Segurança

- token CSRF nas operações que alteram estado;
- prepared statements nas consultas de dados;
- escape de saída HTML;
- upload de imagem com limite de tamanho, MIME validado, conteúdo verificado e nome interno aleatório;
- verificação de propriedade antes de editar ou excluir uma mensagem;
- rate limiting no envio de mensagens.

## Banco de dados

`omigos_schema.sql` contém o schema atual, com sete tabelas e sem dados pessoais. `db_migration.sql` documenta a adaptação necessária para instalações legadas e deve ser executado com backup previo.

## Limitações conhecidas

- não existe papel específico de professor: todos os usuários usam o mesmo fluxo;
- a atualização de mensagens usa polling, não WebSocket;
- o rate limiting está aplicado ao envio de mensagens, não ao login/cadastro;
- não há suíte automatizada de testes de integração no repositório;
- o painel administrativo não possui uma seção de auditoria de ações;
- não há demonstração pública configurada;
- a migração de banco legado não é totalmente idempotente e deve ser executada uma única vez.

## Próximas ações recomendadas

1. adicionar testes de integração para registro, login, conversa, edição e exclusão;
2. aplicar rate limiting e monitoramento de falhas no login/cadastro;
3. migrar polling para WebSocket ou Server-Sent Events;
4. versionar migrações de banco de forma incremental;
5. revisar acessibilidade, auditoria administrativa e estratégia de anexos.

## Privacidade

O código-fonte não deve ser acompanhado por dumps de banco, mensagens, fotos, hashes de senha ou outros dados de usuários. Use o schema e cadastre usuários de teste pelo próprio aplicativo.
