<?php
/**
 * Упрощенный скрипт для создания таблиц Google Sheets
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/vendor/autoload.php';

// Загружаем переменные окружения
if (file_exists($projectRoot . '/.env')) {
    $dotenv = \Dotenv\Dotenv::createMutable($projectRoot);
    $dotenv->safeLoad();
}

// Загружаем классы вручную (так как автозагрузка может не работать)
require_once $projectRoot . '/src/services/DatabaseService.php';

use Dashboard\Services\DatabaseService;

try {
    echo "Подключение к БД...\n";
    $dbService = new DatabaseService();
    $db = $dbService->getWriteConnection();
    echo "✓ Подключение успешно\n\n";
    
    echo "Создание таблицы google_sheets...\n";
    $sql1 = "CREATE TABLE IF NOT EXISTS `google_sheets` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Таблица для хранения информации о подключенных Google таблицах'";
    
    $db->getAllRows($sql1);
    echo "✓ Таблица google_sheets создана\n\n";
    
    echo "Создание таблицы migration_reviewers...\n";
    $sql2 = "CREATE TABLE IF NOT EXISTS `migration_reviewers` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Таблица для связи миграций с ревьюерами из Google Sheets'";
    
    $db->getAllRows($sql2);
    echo "✓ Таблица migration_reviewers создана\n\n";
    
    echo "Проверка структуры таблиц...\n";
    $tables = ['google_sheets', 'migration_reviewers'];
    foreach ($tables as $table) {
        $result = $db->getAllRows("DESCRIBE `{$table}`");
        echo "\nТаблица `{$table}`:\n";
        foreach ($result as $row) {
            echo "  - {$row['Field']} ({$row['Type']})\n";
        }
    }
    
    echo "\n✓ Миграция успешно завершена!\n";
    
} catch (Exception $e) {
    echo "ОШИБКА: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
