-- Создание таблиц для интеграции Google Sheets
-- Выполните этот SQL скрипт в вашей базе данных

-- Таблица для хранения информации о подключенных Google таблицах
CREATE TABLE IF NOT EXISTS `google_sheets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `spreadsheet_id` VARCHAR(255) NOT NULL COMMENT 'ID Google таблицы',
  `spreadsheet_name` VARCHAR(500) NULL COMMENT 'Название таблицы (для отслеживания изменений)',
  `sheet_id` VARCHAR(255) NULL COMMENT 'ID листа в Google Sheets',
  `sheet_name` VARCHAR(255) NULL COMMENT 'Название листа',
  `wave_id` VARCHAR(100) NULL COMMENT 'ID волны миграции (связь с таблицей waves)',
  `last_synced_at` DATETIME NULL COMMENT 'Время последней синхронизации',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `idx_spreadsheet_id` (`spreadsheet_id`),
  INDEX `idx_wave_id` (`wave_id`),
  INDEX `idx_last_synced_at` (`last_synced_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Таблица для хранения информации о подключенных Google таблицах';

-- Таблица для связи миграций с ревьюерами из Google Sheets
CREATE TABLE IF NOT EXISTS `migration_reviewers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `migration_id` INT(11) UNSIGNED NOT NULL COMMENT 'ID миграции из таблицы migrations',
  `person_brizy` VARCHAR(255) NULL COMMENT 'Имя человека из Google таблицы',
  `uuid` VARCHAR(255) NULL COMMENT 'UUID проекта из Google таблицы',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_migration_id` (`migration_id`),
  INDEX `idx_uuid` (`uuid`),
  UNIQUE KEY `unique_migration_uuid` (`migration_id`, `uuid`),
  CONSTRAINT `fk_migration_reviewers_migration` FOREIGN KEY (`migration_id`) REFERENCES `migrations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Таблица для связи миграций с ревьюерами из Google Sheets';
