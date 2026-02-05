-- Создание таблицы waves для хранения информации о волнах миграций
-- Выполните этот SQL скрипт в вашей базе данных

CREATE TABLE IF NOT EXISTS `waves` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `wave_id` VARCHAR(100) NOT NULL UNIQUE,
  `name` VARCHAR(255) NOT NULL,
  `workspace_id` INT NULL,
  `workspace_name` VARCHAR(255) NULL,
  `status` VARCHAR(50) DEFAULT 'pending',
  `progress_total` INT DEFAULT 0,
  `progress_completed` INT DEFAULT 0,
  `progress_failed` INT DEFAULT 0,
  `batch_size` INT DEFAULT 3,
  `mgr_manual` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `completed_at` DATETIME NULL,
  INDEX `idx_status` (`status`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
