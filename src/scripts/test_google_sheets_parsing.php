#!/usr/bin/env php
<?php
/**
 * Тестовый скрипт для проверки парсинга данных из Google Sheets
 * 
 * Использование:
 * php src/scripts/test_google_sheets_parsing.php <spreadsheet_id> <sheet_name>
 * 
 * Пример:
 * php src/scripts/test_google_sheets_parsing.php 1ZION... ZION
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

$prodEnv = $projectRoot . '/.env.prod.local';
if (file_exists($prodEnv)) {
    $dotenv = \Dotenv\Dotenv::createMutable($projectRoot, ['.env.prod.local']);
    $dotenv->safeLoad();
}

require_once $projectRoot . '/src/services/DatabaseService.php';
require_once $projectRoot . '/src/services/GoogleSheetsService.php';
require_once $projectRoot . '/src/services/GoogleSheetsSyncService.php';

use Dashboard\Services\DatabaseService;
use Dashboard\Services\GoogleSheetsService;
use Dashboard\Services\GoogleSheetsSyncService;
use Exception;

try {
    // Получаем параметры из командной строки
    if ($argc < 3) {
        echo "Использование: php test_google_sheets_parsing.php <google_sheets_id> <sheet_name>\n";
        echo "Пример: php test_google_sheets_parsing.php 1 1ZION... ZION\n";
        echo "\nИли используйте ID записи из таблицы google_sheets:\n";
        echo "php test_google_sheets_parsing.php <id> <sheet_name>\n";
        exit(1);
    }

    $googleSheetsId = $argv[1];
    $sheetName = $argv[2];

    echo "=== Тест парсинга Google Sheets ===\n\n";

    $dbService = new DatabaseService();
    $db = $dbService->getWriteConnection();

    // Проверяем, является ли первый параметр ID записи или spreadsheet_id
    $sheet = null;
    if (is_numeric($googleSheetsId)) {
        // Это ID записи в таблице google_sheets
        $sheet = $db->find(
            "SELECT spreadsheet_id, sheet_name FROM google_sheets WHERE id = ?",
            [(int)$googleSheetsId]
        );
        
        if (!$sheet) {
            throw new Exception("Запись с ID {$googleSheetsId} не найдена в таблице google_sheets");
        }
        
        $spreadsheetId = $sheet['spreadsheet_id'];
        $sheetName = $sheet['sheet_name'] ?? $sheetName;
        
        echo "Найдена запись в БД:\n";
        echo "  ID: {$googleSheetsId}\n";
        echo "  Spreadsheet ID: {$spreadsheetId}\n";
        echo "  Sheet Name: {$sheetName}\n\n";
    } else {
        // Это spreadsheet_id
        $spreadsheetId = $googleSheetsId;
    }

    // Инициализируем сервисы
    echo "Инициализация сервисов...\n";
    $googleSheetsService = new GoogleSheetsService();
    
    if (!$googleSheetsService->isAuthenticated()) {
        throw new Exception("Требуется OAuth авторизация. Выполните авторизацию через интерфейс.");
    }
    
    echo "✓ Авторизация успешна\n\n";

    // Шаг 1: Получаем данные листа
    echo "Шаг 1: Получение данных листа '{$sheetName}'...\n";
    $sheetData = $googleSheetsService->getSheetData($spreadsheetId, $sheetName);
    
    echo "✓ Получено строк: " . count($sheetData) . "\n";
    
    if (empty($sheetData)) {
        echo "⚠ Лист пуст!\n";
        exit(0);
    }

    // Показываем первые несколько строк для проверки
    echo "\nПервые 5 строк данных:\n";
    for ($i = 0; $i < min(5, count($sheetData)); $i++) {
        echo "  Строка " . ($i + 1) . ": " . json_encode($sheetData[$i], JSON_UNESCAPED_UNICODE) . "\n";
    }
    echo "\n";

    // Шаг 2: Парсим данные
    echo "Шаг 2: Парсинг данных...\n";
    $parsedData = $googleSheetsService->parseSheetData($sheetData);
    
    echo "✓ Распарсено строк: " . count($parsedData) . "\n\n";
    
    if (empty($parsedData)) {
        echo "⚠ После парсинга нет данных. Проверьте:\n";
        echo "  - Есть ли колонка 'UUID' в первой строке\n";
        echo "  - Есть ли колонка 'Person Brizy' в первой строке\n";
        echo "  - Есть ли данные в строках (кроме заголовка)\n";
        exit(0);
    }

    // Показываем распарсенные данные
    echo "Распарсенные данные:\n";
    foreach ($parsedData as $index => $row) {
        echo "  " . ($index + 1) . ". UUID: {$row['uuid']}, Person Brizy: " . ($row['person_brizy'] ?? 'null') . "\n";
    }
    echo "\n";

    // Шаг 3: Проверяем, какие миграции найдены
    echo "Шаг 3: Поиск миграций по UUID...\n";
    $syncService = new GoogleSheetsSyncService();
    
    $found = 0;
    $notFound = 0;
    
    foreach ($parsedData as $row) {
        $uuid = $row['uuid'];
        $migration = $syncService->findMigrationByUuid($uuid);
        
        if ($migration) {
            $found++;
            echo "  ✓ UUID {$uuid} -> Migration ID: {$migration['id']}\n";
        } else {
            $notFound++;
            echo "  ✗ UUID {$uuid} -> Миграция не найдена\n";
        }
    }
    
    echo "\nИтого:\n";
    echo "  Найдено миграций: {$found}\n";
    echo "  Не найдено: {$notFound}\n\n";

    // Шаг 4: Показываем, что будет добавлено в migration_reviewers
    echo "Шаг 4: Данные для добавления в migration_reviewers:\n";
    foreach ($parsedData as $row) {
        $uuid = $row['uuid'];
        $personBrizy = $row['person_brizy'];
        $migration = $syncService->findMigrationByUuid($uuid);
        
        if ($migration) {
            echo "  Migration ID: {$migration['id']}, UUID: {$uuid}, Person Brizy: " . ($personBrizy ?? 'null') . "\n";
        }
    }
    echo "\n";

    // Шаг 5: Спрашиваем, выполнить ли синхронизацию
    echo "=== Готово к синхронизации ===\n";
    echo "Для выполнения синхронизации используйте:\n";
    if (is_numeric($googleSheetsId)) {
        echo "  POST /api/google-sheets/sync/{$googleSheetsId}\n";
    } else {
        echo "  Найдите ID записи в таблице google_sheets и используйте:\n";
        echo "  POST /api/google-sheets/sync/<id>\n";
    }
    echo "\n";

} catch (Exception $e) {
    echo "ОШИБКА: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
