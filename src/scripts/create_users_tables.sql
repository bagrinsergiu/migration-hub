-- Таблица для хранения пользователей
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `email` VARCHAR(255) NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(255) NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_login` DATETIME NULL,
  INDEX `idx_username` (`username`),
  INDEX `idx_email` (`email`),
  INDEX `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица для хранения ролей
CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `description` TEXT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица для связи пользователей и ролей (многие ко многим)
CREATE TABLE IF NOT EXISTS `user_roles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `role_id` INT NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_user_role` (`user_id`, `role_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_role_id` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица для хранения разрешений (permissions)
CREATE TABLE IF NOT EXISTS `permissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `description` TEXT NULL,
  `resource` VARCHAR(100) NOT NULL COMMENT 'Ресурс: migrations, waves, logs, settings, test, quality_analysis',
  `action` VARCHAR(50) NOT NULL COMMENT 'Действие: view, create, edit, delete, manage',
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_resource_action` (`resource`, `action`),
  INDEX `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица для связи ролей и разрешений (многие ко многим)
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `role_id` INT NOT NULL,
  `permission_id` INT NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_role_permission` (`role_id`, `permission_id`),
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE,
  INDEX `idx_role_id` (`role_id`),
  INDEX `idx_permission_id` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Обновляем таблицу admin_sessions для связи с users
-- Проверяем, существует ли колонка user_id перед добавлением
SET @dbname = DATABASE();
SET @tablename = 'admin_sessions';
SET @columnname = 'user_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (TABLE_SCHEMA = @dbname)
      AND (TABLE_NAME = @tablename)
      AND (COLUMN_NAME = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' INT NULL AFTER `admin_username`, ADD FOREIGN KEY (`', @columnname, '`) REFERENCES `users`(`id`) ON DELETE CASCADE, ADD INDEX `idx_', @columnname, '` (`', @columnname, '`)')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Вставляем дефолтные роли
INSERT INTO `roles` (`name`, `description`) VALUES
  ('admin', 'Полный доступ ко всем функциям'),
  ('manager', 'Управление миграциями и волнами'),
  ('viewer', 'Только просмотр'),
  ('reviewer', 'Доступ к мануальному ревью')
ON DUPLICATE KEY UPDATE `name` = `name`;

-- Вставляем дефолтные разрешения
INSERT INTO `permissions` (`name`, `description`, `resource`, `action`) VALUES
  -- Миграции
  ('migrations.view', 'Просмотр списка миграций', 'migrations', 'view'),
  ('migrations.create', 'Создание новых миграций', 'migrations', 'create'),
  ('migrations.edit', 'Редактирование миграций', 'migrations', 'edit'),
  ('migrations.delete', 'Удаление миграций', 'migrations', 'delete'),
  ('migrations.manage', 'Полное управление миграциями', 'migrations', 'manage'),
  
  -- Волны
  ('waves.view', 'Просмотр волн', 'waves', 'view'),
  ('waves.create', 'Создание волн', 'waves', 'create'),
  ('waves.edit', 'Редактирование волн', 'waves', 'edit'),
  ('waves.delete', 'Удаление волн', 'waves', 'delete'),
  ('waves.manage', 'Полное управление волнами', 'waves', 'manage'),
  
  -- Логи
  ('logs.view', 'Просмотр логов', 'logs', 'view'),
  ('logs.manage', 'Управление логами', 'logs', 'manage'),
  
  -- Настройки
  ('settings.view', 'Просмотр настроек', 'settings', 'view'),
  ('settings.edit', 'Редактирование настроек', 'settings', 'edit'),
  ('settings.manage', 'Полное управление настройками', 'settings', 'manage'),
  
  -- Тестирование
  ('test.view', 'Просмотр тестовых миграций', 'test', 'view'),
  ('test.create', 'Создание тестовых миграций', 'test', 'create'),
  ('test.manage', 'Полное управление тестами', 'test', 'manage'),
  
  -- Анализ качества
  ('quality_analysis.view', 'Просмотр анализа качества', 'quality_analysis', 'view'),
  ('quality_analysis.manage', 'Управление анализом качества', 'quality_analysis', 'manage'),
  
  -- Управление пользователями
  ('users.view', 'Просмотр пользователей', 'users', 'view'),
  ('users.create', 'Создание пользователей', 'users', 'create'),
  ('users.edit', 'Редактирование пользователей', 'users', 'edit'),
  ('users.delete', 'Удаление пользователей', 'users', 'delete'),
  ('users.manage', 'Полное управление пользователями', 'users', 'manage')
ON DUPLICATE KEY UPDATE `name` = `name`;

-- Назначаем все разрешения роли admin
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.name = 'admin'
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;

-- Назначаем базовые разрешения роли manager
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.name = 'manager'
  AND p.resource IN ('migrations', 'waves', 'logs')
  AND p.action IN ('view', 'create', 'edit', 'manage')
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;

-- Назначаем только просмотр роли viewer
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.name = 'viewer'
  AND p.action = 'view'
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;
