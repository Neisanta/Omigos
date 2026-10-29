-- Migração de otimização para Omigos Chat
-- Executa-se após o setup inicial do banco de dados
-- Preserva todos os dados existentes

-- --------------------------------------------------------
-- Índices para tabela `messages`
-- --------------------------------------------------------
-- Adicionar índice em sent_at para otimizar exclusão de mensagens antigas
ALTER TABLE `messages`
  ADD INDEX `idx_messages_sent_at` (`sent_at`);

-- Adicionar índice composto para buscas por conversa
ALTER TABLE `messages`
  ADD INDEX `idx_messages_conversation_sender` (`conversation_id`, `sender_id`);

-- Adicionar índice para receiver_id em queries de carregamento
ALTER TABLE `messages`
  ADD INDEX `idx_messages_receiver_id` (`receiver_id`);

-- --------------------------------------------------------
-- Índices para tabela `unread_messages`
-- --------------------------------------------------------
-- Otimizar contagem de mensagens não lidas
ALTER TABLE `unread_messages`
  ADD INDEX `idx_unread_messages_user` (`user_id`, `message_id`);

-- Adicionar índice composto para exclusão em cascata
ALTER TABLE `unread_messages`
  ADD INDEX `idx_unread_messages_sender` (`message_id`, `user_id`);

-- --------------------------------------------------------
-- Índices para tabela `conversations`
-- --------------------------------------------------------
-- Otimizar busca de conversas existentes
ALTER TABLE `conversations`
  ADD INDEX `idx_conversations_users` (`user1_id`, `user2_id`);

-- --------------------------------------------------------
-- Índices para tabela `users`
-- --------------------------------------------------------
-- Já existe UNIQUE KEY username, mas garantir performance em buscas
ALTER TABLE `users`
  MODIFY `username` VARCHAR(50) NOT NULL,
  ADD INDEX `idx_users_course` (`curso`);

-- --------------------------------------------------------
-- Atualizar configuração de exclusão de mensagens (opcional)
-- --------------------------------------------------------
-- Atualizar tempo padrão de exclusão para 30 dias (2592000 segundos)
UPDATE `settings` SET `value` = '2592000' WHERE `name` = 'message_deletion_time';

-- --------------------------------------------------------
-- Relatório de alterações
-- --------------------------------------------------------
SELECT 'Índices aplicados com sucesso' AS status;