#!/usr/bin/env php
<?php
/**
 * Скрипт для полной проверки и отладки синхронизации Google Sheets
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/vendor/autoload.php';

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

echo "=== Полная проверка синхронизации Google Sheets ===\n\n";

try {
    $dbService = new DatabaseService();
    $db = $dbService->getWriteConnection();

    // Шаг 1: Находим все подключенные таблицы
    echo "Шаг 1: Поиск подключенных таблиц...\n";
    $sheets = $db->getAllRows(
        "SELECT id, spreadsheet_id, spreadsheet_name, sheet_name, wave_id 
         FROM google_sheets 
         WHERE sheet_name IS NOT NULL 
         ORDER BY id DESC"
    );

    if (empty($sheets)) {
        echo "⚠ Нет подключенных таблиц с указанным листом\n";
        exit(0);
    }

    echo "✓ Найдено таблиц: " . count($sheets) . "\n\n";

    // Ищем таблицу с листом "ZION"
    $targetSheet = null;
    foreach ($sheets as $sheet) {
        if (strtolower($sheet['sheet_name']) === 'zion') {
            $targetSheet = $sheet;
            break;
        }
    }

    if (!$targetSheet) {
        echo "⚠ Таблица с листом 'ZION' не найдена. Доступные листы:\n";
        foreach ($sheets as $sheet) {
            echo "  - ID: {$sheet['id']}, Sheet: {$sheet['sheet_name']}, Spreadsheet: {$sheet['spreadsheet_id']}\n";
        }
        echo "\nИспользуем первую таблицу для теста...\n";
        $targetSheet = $sheets[0];
    }

    $sheetId = $targetSheet['id'];
    $spreadsheetId = $targetSheet['spreadsheet_id'];
    $sheetName = $targetSheet['sheet_name'];

    echo "✓ Выбрана таблица:\n";
    echo "  ID: {$sheetId}\n";
    echo "  Spreadsheet ID: {$spreadsheetId}\n";
    echo "  Sheet Name: {$sheetName}\n\n";

    // Шаг 2: Проверяем авторизацию
    echo "Шаг 2: Проверка авторизации Google Sheets...\n";
    $googleSheetsService = new GoogleSheetsService();
    
    if (!$googleSheetsService->isAuthenticated()) {
        throw new Exception("Требуется OAuth авторизация. Выполните авторизацию через интерфейс.");
    }
    
    echo "✓ Авторизация успешна\n\n";

    // Шаг 3: Получаем данные листа
    echo "Шаг 3: Получение данных листа '{$sheetName}'...\n";
    $sheetData = $googleSheetsService->getSheetData($spreadsheetId, $sheetName);
    
    echo "✓ Получено строк: " . count($sheetData) . "\n";
    
    if (empty($sheetData)) {
        throw new Exception("Лист пуст или не найден");
    }

    // Показываем первые строки
    echo "\nПервые 3 строки данных:\n";
    for ($i = 0; $i < min(3, count($sheetData)); $i++) {
        echo "  Строка " . ($i + 1) . ": " . json_encode($sheetData[$i], JSON_UNESCAPED_UNICODE) . "\n";
    }
    echo "\n";

    // Шаг 4: Парсим данные
    echo "Шаг 4: Парсинг данных...\n";
    $parsedData = $googleSheetsService->parseSheetData($sheetData);
    
    echo "✓ Распарсено строк: " . count($parsedData) . "\n";
    
    if (empty($parsedData)) {
        echo "⚠ После парсинга нет данных!\n";
        echo "Проверьте:\n";
        echo "  - Есть ли колонка 'UUID' в первой строке\n";
        echo "  - Есть ли колонка 'Person Brizy' в первой строке\n";
        echo "  - Есть ли данные в строках (кроме заголовка)\n";
        exit(1);
    }

    // Показываем распарсенные данные
    echo "\nРаспарсенные данные:\n";
    foreach ($parsedData as $index => $row) {
        echo "  " . ($index + 1) . ". UUID: {$row['uuid']}, Person Brizy: " . ($row['person_brizy'] ?? 'null') . "\n";
    }
    echo "\n";

    // Шаг 5: Проверяем миграции
    echo "Шаг 5: Поиск миграций по UUID...\n";
    $syncService = new GoogleSheetsSyncService();
    
    $found = 0;
    $notFound = [];
    
    foreach ($parsedData as $row) {
        $uuid = $row['uuid'];
        $migration = $syncService->findMigrationByUuid($uuid);
        
        if ($migration) {
            $found++;
            echo "  ✓ UUID {$uuid} -> Migration ID: {$migration['id']}\n";
        } else {
            $notFound[] = $uuid;
            echo "  ✗ UUID {$uuid} -> Миграция не найдена\n";
        }
    }
    
    echo "\nИтого:\n";
    echo "  Найдено миграций: {$found}\n";
    echo "  Не найдено: " . count($notFound) . "\n";
    
    if (!empty($notFound)) {
        echo "\n⚠ Не найденные UUID:\n";
        foreach ($notFound as $uuid) {
            echo "  - {$uuid}\n";
        }
    }
    echo "\n";

    // Шаг 6: Выполняем синхронизацию
    if ($found > 0) {
        echo "Шаг 6: Выполнение синхронизации...\n";
        $stats = $syncService->syncSheet($spreadsheetId, $sheetName, $targetSheet['wave_id'] ?? null);
        
        echo "✓ Синхронизация завершена\n";
        echo "\nСтатистика:\n";
        echo "  Всего строк: {$stats['total_rows']}\n";
        echo "  Обработано: {$stats['processed']}\n";
        echo "  Создано: {$stats['created']}\n";
        echo "  Обновлено: {$stats['updated']}\n";
        echo "  Не найдено миграций: {$stats['not_found']}\n";
        echo "  Ошибок: {$stats['errors']}\n";
        
        if (!empty($stats['errors_list'])) {
            echo "\nОшибки:\n";
            foreach ($stats['errors_list'] as $error) {
                echo "  - UUID {$error['uuid']}: {$error['error']}\n";
            }
        }
        echo "\n";

        // Шаг 7: Проверяем результаты в БД
        echo "Шаг 7: Проверка результатов в базе данных...\n";
        $reviewers = $db->getAllRows(
            "SELECT 
                mr.id,
                mr.migration_id,
                mr.uuid,
                mr.person_brizy,
                m.mb_project_uuid,
                m.brz_project_id,
                mr.created_at
             FROM migration_reviewers mr
             INNER JOIN migrations m ON mr.migration_id = m.id
             ORDER BY mr.created_at DESC
             LIMIT 20"
        );

        echo "✓ Найдено записей в migration_reviewers: " . count($reviewers) . "\n";
        
        if (!empty($reviewers)) {
            echo "\nПоследние записи:\n";
            foreach ($reviewers as $reviewer) {
                echo "  ID: {$reviewer['id']}, Migration ID: {$reviewer['migration_id']}, UUID: {$reviewer['uuid']}, Person: " . ($reviewer['person_brizy'] ?? 'null') . "\n";
            }
        }
        echo "\n";
    } else {
        echo "⚠ Нет миграций для синхронизации. Пропускаем шаг 6.\n\n";
    }

    echo "=== Проверка завершена ===\n";

} catch (Exception $e) {
    echo "ОШИБКА: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
