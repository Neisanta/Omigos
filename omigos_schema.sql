-- =============================================================
-- Omigos Chat — schema MySQL/MariaDB
--
-- Estrutura compatível com o código atual do aplicativo.
-- Não contém dados pessoais, mensagens, hashes de senha ou usuários.
--
-- Uso:
--   mysql -u root -p -e "CREATE DATABASE chatphp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--   mysql -u root -p chatphp < omigos_schema.sql
-- =============================================================

SET NAMES utf8mb4;
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome` VARCHAR(100) NOT NULL,
  `username` VARCHAR(50) NOT NULL,
  `curso` VARCHAR(50) NOT NULL,
  `rm` INT UNSIGNED NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `bio` TEXT NULL,
  `instagram` VARCHAR(50) NULL,
  `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
  `remember_token` CHAR(64) NULL,
  `remember_expiry` DATETIME NULL,
  `last_active` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_course` (`curso`),
  KEY `idx_users_last_active` (`last_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_photos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `photo_path` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_photos_user` (`user_id`),
  CONSTRAINT `fk_user_photos_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conversations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user1_id` INT UNSIGNED NOT NULL,
  `user2_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_conversation_users` (`user1_id`, `user2_id`),
  KEY `idx_conversations_user2` (`user2_id`),
  CONSTRAINT `fk_conversations_user1`
    FOREIGN KEY (`user1_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_conversations_user2`
    FOREIGN KEY (`user2_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` INT UNSIGNED NOT NULL,
  `sender_id` INT UNSIGNED NOT NULL,
  `receiver_id` INT UNSIGNED NOT NULL,
  `message` TEXT NULL,
  `sent_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `edited_at` DATETIME NULL,
  `file_path` VARCHAR(255) NULL,
  `file_name` VARCHAR(255) NULL,
  `file_type` VARCHAR(100) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_messages_conversation_sender` (`conversation_id`, `sender_id`),
  KEY `idx_messages_receiver` (`receiver_id`, `sent_at`),
  KEY `idx_messages_sent_at` (`sent_at`),
  CONSTRAINT `fk_messages_conversation`
    FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_sender`
    FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_receiver`
    FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `unread_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `message_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_unread_user_message` (`user_id`, `message_id`),
  KEY `idx_unread_message_user` (`message_id`, `user_id`),
  CONSTRAINT `fk_unread_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_unread_message`
    FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `typing_status` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_key` VARCHAR(64) NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_typing_conversation_user` (`conversation_key`, `user_id`),
  KEY `idx_typing_updated_at` (`updated_at`),
  CONSTRAINT `fk_typing_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `value` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configuração inicial não pessoal: retenção de mensagens em 30 dias.
INSERT IGNORE INTO `settings` (`name`, `value`) VALUES ('message_deletion_time', '2592000');
