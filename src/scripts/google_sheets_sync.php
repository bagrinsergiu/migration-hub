#!/usr/bin/env php
<?php
/**
 * Скрипт для периодической синхронизации данных из Google Sheets
 * 
 * Использование:
 * php src/scripts/google_sheets_sync.php [--spreadsheet-id=xxx] [--dry-run]
 * 
 * Параметры:
 * --spreadsheet-id=xxx  - синхронизировать только указанную таблицу
 * --dry-run            - режим тестирования (без реальной синхронизации)
 * 
 * Для автоматического запуска через cron:
 * */5 * * * * /usr/bin/php /path/to/project/src/scripts/google_sheets_sync.php >> /path/to/project/var/log/google_sheets_sync.log 2>&1
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Определяем корень проекта
$projectRoot = dirname(__DIR__, 2);

// Загружаем автозагрузчик
require_once $projectRoot . '/vendor/autoload.php';

// Загружаем переменные окружения
if (file_exists($projectRoot . '/.env')) {
    $dotenv = \Dotenv\Dotenv::createMutable($projectRoot);
    $dotenv->safeLoad();
}

// Загрузка .env.prod.local если существует
$prodEnv = $projectRoot . '/.env.prod.local';
if (file_exists($prodEnv)) {
    $dotenv = \Dotenv\Dotenv::createMutable($projectRoot, ['.env.prod.local']);
    $dotenv->safeLoad();
}

// Загружаем необходимые классы
require_once $projectRoot . '/src/services/DatabaseService.php';
require_once $projectRoot . '/src/services/GoogleSheetsService.php';
require_once $projectRoot . '/src/services/GoogleSheetsSyncService.php';

use Dashboard\Services\DatabaseService;
use Dashboard\Services\GoogleSheetsSyncService;
use Exception;

/**
 * Логирование в файл
 */
function logMessage($message, $level = 'INFO')
{
    $projectRoot = dirname(__DIR__, 2);
    $logDir = $projectRoot . '/var/log';
    
    // Создаем директорию, если не существует
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/google_sheets_sync.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [{$level}] {$message}\n";
    
    // Выводим в консоль
    echo $logMessage;
    
    // Записываем в файл
    @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

/**
 * Парсинг аргументов командной строки
 */
function parseArguments($argv)
{
    $options = [
        'spreadsheet_id' => null,
        'dry_run' => false
    ];
    
    foreach ($argv as $arg) {
        if (strpos($arg, '--spreadsheet-id=') === 0) {
            $options['spreadsheet_id'] = substr($arg, strlen('--spreadsheet-id='));
        } elseif ($arg === '--dry-run') {
            $options['dry_run'] = true;
        }
    }
    
    return $options;
}

/**
 * Проверка, нужно ли синхронизировать таблицу
 */
function shouldSync($sheet, $syncInterval)
{
    if (empty($sheet['last_synced_at'])) {
        return true; // Никогда не синхронизировалась
    }
    
    $lastSynced = strtotime($sheet['last_synced_at']);
    $now = time();
    $elapsed = $now - $lastSynced;
    
    return $elapsed >= $syncInterval;
}

try {
    logMessage("=== Запуск синхронизации Google Sheets ===");
    
    // Парсим аргументы
    $options = parseArguments($argv);
    $dryRun = $options['dry_run'];
    $spreadsheetId = $options['spreadsheet_id'];
    
    if ($dryRun) {
        logMessage("РЕЖИМ ТЕСТИРОВАНИЯ (dry-run): изменения не будут сохранены", 'WARNING');
    }
    
    // Получаем интервал синхронизации из переменных окружения
    $syncInterval = (int)($_ENV['GOOGLE_SYNC_INTERVAL'] ?? getenv('GOOGLE_SYNC_INTERVAL') ?: 300); // По умолчанию 5 минут
    logMessage("Интервал синхронизации: {$syncInterval} секунд");
    
    // Инициализируем сервисы
    $dbService = new DatabaseService();
    $syncService = new GoogleSheetsSyncService();
    
    // Получаем список таблиц для синхронизации
    $db = $dbService->getWriteConnection();
    
    if ($spreadsheetId) {
        // Синхронизируем только указанную таблицу
        logMessage("Синхронизация таблицы: {$spreadsheetId}");
        $sheets = $db->getAllRows(
            "SELECT id, spreadsheet_id, spreadsheet_name, sheet_name, wave_id, last_synced_at 
             FROM google_sheets 
             WHERE spreadsheet_id = ? AND sheet_name IS NOT NULL",
            [$spreadsheetId]
        );
    } else {
        // Получаем все таблицы, которые нужно синхронизировать
        $allSheets = $db->getAllRows(
            "SELECT id, spreadsheet_id, spreadsheet_name, sheet_name, wave_id, last_synced_at 
             FROM google_sheets 
             WHERE sheet_name IS NOT NULL 
             ORDER BY last_synced_at ASC NULLS FIRST, created_at ASC"
        );
        
        // Фильтруем по интервалу синхронизации
        $sheets = [];
        foreach ($allSheets as $sheet) {
            if (shouldSync($sheet, $syncInterval)) {
                $sheets[] = $sheet;
            }
        }
    }
    
    if (empty($sheets)) {
        logMessage("Нет таблиц для синхронизации");
        exit(0);
    }
    
    logMessage("Найдено таблиц для синхронизации: " . count($sheets));
    
    // Статистика
    $totalStats = [
        'total_sheets' => count($sheets),
        'synced' => 0,
        'failed' => 0,
        'skipped' => 0,
        'total_rows' => 0,
        'total_processed' => 0,
        'total_created' => 0,
        'total_updated' => 0,
        'total_not_found' => 0,
        'total_errors' => 0
    ];
    
    // Синхронизируем каждую таблицу
    foreach ($sheets as $sheet) {
        $sheetId = $sheet['id'];
        $spreadsheetId = $sheet['spreadsheet_id'];
        $sheetName = $sheet['sheet_name'];
        $lastSynced = $sheet['last_synced_at'] ?: 'никогда';
        
        logMessage("Синхронизация: ID={$sheetId}, Spreadsheet={$spreadsheetId}, Sheet={$sheetName}, Последняя синхронизация: {$lastSynced}");
        
        if ($dryRun) {
            logMessage("  [DRY-RUN] Пропуск реальной синхронизации", 'WARNING');
            $totalStats['skipped']++;
            continue;
        }
        
        try {
            // Синхронизируем лист
            $stats = $syncService->syncSheet(
                $spreadsheetId,
                $sheetName,
                $sheet['wave_id'] ?? null
            );
            
            // Обновляем статистику
            $totalStats['synced']++;
            $totalStats['total_rows'] += $stats['total_rows'];
            $totalStats['total_processed'] += $stats['processed'];
            $totalStats['total_created'] += $stats['created'];
            $totalStats['total_updated'] += $stats['updated'];
            $totalStats['total_not_found'] += $stats['not_found'];
            $totalStats['total_errors'] += $stats['errors'];
            
            // Логируем результат
            logMessage("  ✓ Успешно: строк={$stats['total_rows']}, обработано={$stats['processed']}, создано={$stats['created']}, обновлено={$stats['updated']}, не найдено={$stats['not_found']}, ошибок={$stats['errors']}");
            
            // Логируем ошибки, если есть
            if (!empty($stats['errors_list'])) {
                foreach ($stats['errors_list'] as $error) {
                    logMessage("  ⚠ Ошибка для UUID {$error['uuid']}: {$error['error']}", 'ERROR');
                }
            }
            
        } catch (Exception $e) {
            $totalStats['failed']++;
            logMessage("  ✗ Ошибка синхронизации: " . $e->getMessage(), 'ERROR');
            logMessage("  Stack trace: " . $e->getTraceAsString(), 'ERROR');
            // Продолжаем синхронизацию других таблиц
        }
    }
    
    // Выводим итоговую статистику
    logMessage("=== Итоговая статистика ===");
    logMessage("Всего таблиц: {$totalStats['total_sheets']}");
    logMessage("Успешно синхронизировано: {$totalStats['synced']}");
    logMessage("Пропущено (dry-run): {$totalStats['skipped']}");
    logMessage("Ошибок: {$totalStats['failed']}");
    logMessage("Всего строк обработано: {$totalStats['total_rows']}");
    logMessage("Создано записей: {$totalStats['total_created']}");
    logMessage("Обновлено записей: {$totalStats['total_updated']}");
    logMessage("Миграций не найдено: {$totalStats['total_not_found']}");
    logMessage("Ошибок при обработке: {$totalStats['total_errors']}");
    logMessage("=== Синхронизация завершена ===");
    
    // Exit code: 0 - успех, 1 - есть ошибки
    exit($totalStats['failed'] > 0 ? 1 : 0);
    
} catch (Exception $e) {
    logMessage("КРИТИЧЕСКАЯ ОШИБКА: " . $e->getMessage(), 'ERROR');
    logMessage("Stack trace: " . $e->getTraceAsString(), 'ERROR');
    exit(1);
}
