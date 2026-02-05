<?php
/**
 * Скрипт для создания таблиц Google Sheets
 * 
 * Использование:
 * php src/scripts/create_google_sheets_tables.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dashboard\Services\DatabaseService;

try {
    $dbService = new DatabaseService();
    $db = $dbService->getWriteConnection();
    
    // Читаем SQL из файла
    $sqlFile = __DIR__ . '/create_google_sheets_tables.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL файл не найден: {$sqlFile}");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Разбиваем на отдельные запросы (разделитель ;)
    $queries = array_filter(
        array_map('trim', explode(';', $sql)),
        function($query) {
            return !empty($query) && !preg_match('/^--/', $query);
        }
    );
    
    echo "Создание таблиц для Google Sheets...\n";
    
    foreach ($queries as $query) {
        if (empty(trim($query))) {
            continue;
        }
        
        try {
            $db->getAllRows($query);
            echo "✓ Запрос выполнен успешно\n";
        } catch (Exception $e) {
            // Игнорируем ошибки "таблица уже существует"
            if (strpos($e->getMessage(), 'already exists') !== false || 
                strpos($e->getMessage(), 'Duplicate') !== false) {
                echo "⚠ Таблица уже существует, пропускаем...\n";
            } else {
                throw $e;
            }
        }
    }
    
    echo "\n✓ Таблицы google_sheets и migration_reviewers успешно созданы!\n";
    echo "\nПроверка структуры таблиц:\n";
    
    // Проверяем созданные таблицы
    $tables = ['google_sheets', 'migration_reviewers'];
    foreach ($tables as $table) {
        try {
            $result = $db->getAllRows("DESCRIBE `{$table}`");
            echo "\nТаблица `{$table}`:\n";
            foreach ($result as $row) {
                echo "  - {$row['Field']} ({$row['Type']})\n";
            }
        } catch (Exception $e) {
            echo "⚠ Ошибка при проверке таблицы {$table}: " . $e->getMessage() . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "ОШИБКА: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
