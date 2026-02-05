<?php
/**
 * Скрипт для создания таблицы migration_pages
 * 
 * Использование:
 * php dashboard/api/scripts/create_migration_pages_table.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dashboard\Services\DatabaseService;

try {
    $dbService = new DatabaseService();
    $db = $dbService->getWriteConnection();
    
    $sql = "CREATE TABLE IF NOT EXISTS `migration_pages` (
      `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
      `migration_id` int(11) unsigned DEFAULT NULL COMMENT 'ID миграции из таблицы migrations',
      `brz_project_id` int(11) NOT NULL COMMENT 'ID проекта Brizy',
      `mb_project_uuid` varchar(255) NOT NULL COMMENT 'UUID проекта MB',
      `slug` varchar(255) NOT NULL COMMENT 'Slug страницы',
      `collection_items_id` int(11) NOT NULL COMMENT 'ID collection item страницы',
      `title` varchar(500) DEFAULT NULL COMMENT 'Название страницы',
      `is_homepage` tinyint(1) DEFAULT 0 COMMENT 'Главная страница',
      `is_protected` tinyint(1) DEFAULT 0 COMMENT 'Защищенная страница',
      `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `idx_project_slug` (`brz_project_id`, `slug`),
      KEY `idx_migration_id` (`migration_id`),
      KEY `idx_brz_project_id` (`brz_project_id`),
      KEY `idx_mb_project_uuid` (`mb_project_uuid`),
      CONSTRAINT `fk_migration_pages_migration` FOREIGN KEY (`migration_id`) REFERENCES `migrations` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Таблица для хранения информации о страницах миграции'";
    
    // Выполняем SQL
    $db->getAllRows($sql);
    
    echo "Таблица migration_pages успешно создана!\n";
    
} catch (Exception $e) {
    echo "Ошибка при создании таблицы: " . $e->getMessage() . "\n";
    exit(1);
}
