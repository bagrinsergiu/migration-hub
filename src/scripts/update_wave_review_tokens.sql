-- Обновление таблицы wave_review_tokens для поддержки настроек доступа
ALTER TABLE `wave_review_tokens` 
  ADD COLUMN IF NOT EXISTS `name` VARCHAR(255) NULL COMMENT 'Название токена для удобства управления',
  ADD COLUMN IF NOT EXISTS `description` TEXT NULL COMMENT 'Описание токена',
  ADD COLUMN IF NOT EXISTS `created_by` INT NULL COMMENT 'ID пользователя, создавшего токен',
  ADD COLUMN IF NOT EXISTS `settings` JSON NULL COMMENT 'Общие настройки доступа (JSON)',
  ADD INDEX IF NOT EXISTS `idx_created_by` (`created_by`),
  ADD FOREIGN KEY IF NOT EXISTS (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- Таблица для индивидуальных настроек доступа к проектам для каждого токена
CREATE TABLE IF NOT EXISTS `wave_review_token_projects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `token_id` INT NOT NULL,
  `mb_uuid` VARCHAR(100) NOT NULL COMMENT 'UUID проекта миграции',
  `allowed_tabs` JSON NULL COMMENT 'Массив разрешенных вкладок для этого проекта',
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_token_project` (`token_id`, `mb_uuid`),
  FOREIGN KEY (`token_id`) REFERENCES `wave_review_tokens`(`id`) ON DELETE CASCADE,
  INDEX `idx_token_id` (`token_id`),
  INDEX `idx_mb_uuid` (`mb_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
