-- Таблица для хранения сессий администраторов
CREATE TABLE IF NOT EXISTS `admin_sessions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `session_id` VARCHAR(255) NOT NULL UNIQUE,
  `user_id` INT NULL,
  `admin_username` VARCHAR(100) NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` TEXT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  `last_activity` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_active` TINYINT(1) DEFAULT 1,
  INDEX `idx_session_id` (`session_id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_expires_at` (`expires_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица для публичных токенов доступа к волнам (для мануального ревью)
CREATE TABLE IF NOT EXISTS `wave_review_tokens` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `wave_id` VARCHAR(100) NOT NULL,
  `token` VARCHAR(64) NOT NULL UNIQUE,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  INDEX `idx_token` (`token`),
  INDEX `idx_wave_id` (`wave_id`),
  FOREIGN KEY (`wave_id`) REFERENCES `waves`(`wave_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
