-- =============================================================
-- Omigos Chat — migração do schema legado para o código atual
--
-- Use este arquivo apenas em uma instalação antiga que já possui
-- as tabelas do dump original. Novas instalações devem importar
-- diretamente omigos_schema.sql.
--
-- Faça backup antes de executar. Cada ALTER deve ser executado
-- uma única vez; em uma base já migrada, pule o comando correspondente.
-- =============================================================

-- Sessão "lembrar de mim" e presença do usuário.
ALTER TABLE `users`
  ADD COLUMN `remember_token` CHAR(64) NULL AFTER `is_admin`,
  ADD COLUMN `remember_expiry` DATETIME NULL AFTER `remember_token`,
  ADD COLUMN `last_active` DATETIME NULL AFTER `remember_expiry`;

-- Edição de mensagens e anexos de imagem.
ALTER TABLE `messages`
  ADD COLUMN `edited_at` DATETIME NULL AFTER `sent_at`,
  ADD COLUMN `file_path` VARCHAR(255) NULL AFTER `edited_at`,
  ADD COLUMN `file_name` VARCHAR(255) NULL AFTER `file_path`,
  ADD COLUMN `file_type` VARCHAR(100) NULL AFTER `file_name`;

-- Indicador de "digitando...".
CREATE TABLE IF NOT EXISTS `typing_status` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_key` VARCHAR(64) NOT NULL,
  `user_id` INT NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_typing_conversation_user` (`conversation_key`, `user_id`),
  KEY `idx_typing_updated_at` (`updated_at`),
  CONSTRAINT `fk_typing_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Índices usados pelas consultas de mensagens e não lidas.
ALTER TABLE `messages`
  ADD INDEX `idx_messages_sent_at` (`sent_at`),
  ADD INDEX `idx_messages_conversation_sender` (`conversation_id`, `sender_id`),
  ADD INDEX `idx_messages_receiver` (`receiver_id`, `sent_at`);

ALTER TABLE `unread_messages`
  ADD INDEX `idx_unread_user_message` (`user_id`, `message_id`),
  ADD INDEX `idx_unread_message_user` (`message_id`, `user_id`);

ALTER TABLE `conversations`
  ADD INDEX `idx_conversations_user2` (`user2_id`);

ALTER TABLE `users`
  ADD INDEX `idx_users_course` (`curso`),
  ADD INDEX `idx_users_last_active` (`last_active`);

-- Configuração padrão: retenção de mensagens em 30 dias.
INSERT IGNORE INTO `settings` (`name`, `value`) VALUES ('message_deletion_time', '2592000');
