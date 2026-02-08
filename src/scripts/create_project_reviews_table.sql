-- Таблица для хранения ревью проектов (комментарии и статусы ревью)
CREATE TABLE IF NOT EXISTS `project_reviews` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `token_id` INT NOT NULL COMMENT 'ID токена ревью',
  `brz_project_id` INT NOT NULL COMMENT 'Brizy Project ID',
  `mb_project_uuid` VARCHAR(255) NOT NULL COMMENT 'UUID проекта MB',
  `review_status` ENUM('approved', 'rejected', 'needs_changes', 'pending') DEFAULT 'pending' COMMENT 'Статус ревью',
  `comment` TEXT NULL COMMENT 'Комментарий ревью',
  `reviewed_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата и время завершения ревью',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_token_project` (`token_id`, `brz_project_id`),
  FOREIGN KEY (`token_id`) REFERENCES `wave_review_tokens`(`id`) ON DELETE CASCADE,
  INDEX `idx_token_id` (`token_id`),
  INDEX `idx_brz_project_id` (`brz_project_id`),
  INDEX `idx_mb_project_uuid` (`mb_project_uuid`),
  INDEX `idx_review_status` (`review_status`),
  INDEX `idx_reviewed_at` (`reviewed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
