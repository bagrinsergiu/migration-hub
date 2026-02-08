<?php
/**
 * Тестовый скрипт для обновления Google Таблицы после миграции
 * 
 * Использование:
 * php src/scripts/test_update_google_sheet.php
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

// Загружаем классы вручную
require_once $projectRoot . '/src/services/DatabaseService.php';
require_once $projectRoot . '/src/services/GoogleSheetsService.php';

use Dashboard\Services\GoogleSheetsService;
use Dashboard\Services\DatabaseService;

$waveId = '1770309432_6765';
$uuid = '97b2de9e-85d5-469e-936d-c229e0302ea0';

echo "═══════════════════════════════════════════════════════════════\n";
echo "  ТЕСТ ОБНОВЛЕНИЯ GOOGLE ТАБЛИЦЫ ПОСЛЕ МИГРАЦИИ\n";
echo "═══════════════════════════════════════════════════════════════\n\n";
echo "Параметры теста:\n";
echo "  • Wave ID: {$waveId}\n";
echo "  • UUID: {$uuid}\n\n";

try {
    // 1. Получить информацию о миграции из волны
    echo "┌─────────────────────────────────────────────────────────┐\n";
    echo "│ ЭТАП 1: Получение информации о миграции                 │\n";
    echo "└─────────────────────────────────────────────────────────┘\n";
    
    $dbService = new DatabaseService();
    echo "  ✓ Подключение к БД установлено\n";
    
    echo "  → Поиск миграции по UUID...\n";
    $migration = $dbService->getMigrationByUuid($uuid);
    
    if (!$migration) {
        throw new Exception("Миграция с UUID '{$uuid}' не найдена в таблице migrations");
    }
    
    echo "  ✓ Миграция найдена!\n\n";
    echo "  Информация о миграции:\n";
    echo "    • Status: " . ($migration['status'] ?? 'N/A') . "\n";
    echo "    • MB UUID: " . ($migration['mb_project_uuid'] ?? 'N/A') . "\n";
    echo "    • Wave ID: " . ($migration['wave_id'] ?? 'N/A') . "\n";
    echo "    • Brizy Project ID: " . ($migration['brz_project_id'] ?? 'N/A') . "\n";
    
    // Проверяем, что миграция принадлежит указанной волне
    $migrationWaveId = $migration['wave_id'] ?? null;
    if (empty($migrationWaveId)) {
        echo "\n  ⚠️  ВНИМАНИЕ: У миграции нет wave_id!\n";
        echo "     Это означает, что миграция не является частью волны.\n";
        echo "     Обновление Google Таблицы не будет выполнено.\n\n";
    } elseif ($migrationWaveId !== $waveId) {
        echo "\n  ⚠️  ВНИМАНИЕ: wave_id миграции ({$migrationWaveId}) не совпадает с указанным ({$waveId})\n";
        echo "     Будет использован wave_id из миграции: {$migrationWaveId}\n\n";
    }
    
    // Получаем brizy_project_domain
    $brizyProjectDomain = null;
    
    echo "  → Поиск brizy_project_domain...\n";
    // Пробуем получить из результата миграции
    $migrationResult = $dbService->getMigrationResultByUuid($uuid);
    
    if ($migrationResult && !empty($migrationResult['brizy_project_domain'])) {
        $brizyProjectDomain = $migrationResult['brizy_project_domain'];
        echo "    ✓ Найден в migration_result_list.brizy_project_domain\n";
    } elseif (!empty($migration['result_json'])) {
        echo "    → Парсинг result_json...\n";
        $resultJson = is_string($migration['result_json']) 
            ? json_decode($migration['result_json'], true) 
            : $migration['result_json'];
        
        if (isset($resultJson['value']['brizy_project_domain'])) {
            $brizyProjectDomain = $resultJson['value']['brizy_project_domain'];
            echo "    ✓ Найден в result_json.value.brizy_project_domain\n";
        } elseif (isset($resultJson['brizy_project_domain'])) {
            $brizyProjectDomain = $resultJson['brizy_project_domain'];
            echo "    ✓ Найден в result_json.brizy_project_domain\n";
        } else {
            echo "    ⚠️  Не найден в result_json\n";
        }
    } else {
        echo "    ⚠️  result_json пуст\n";
    }
    
    if (empty($brizyProjectDomain)) {
        // Если домен не найден, используем тестовое значение
        echo "    ⚠️  brizy_project_domain не найден в результатах миграции\n";
        echo "    → Используется тестовое значение для проверки функционала\n";
        $brizyProjectDomain = 'https://test-migrated-site.brizy.io';
    }
    
    echo "\n  ✓ Brizy Project Domain: {$brizyProjectDomain}\n\n";
    
    // 2. Обновить Google Таблицу
    echo "┌─────────────────────────────────────────────────────────┐\n";
    echo "│ ЭТАП 2: Обновление Google Таблицы                       │\n";
    echo "└─────────────────────────────────────────────────────────┘\n";
    
    echo "  → Инициализация GoogleSheetsService...\n";
    $googleSheetsService = new GoogleSheetsService();
    echo "  ✓ Сервис инициализирован\n";
    
    echo "  → Проверка авторизации Google Sheets...\n";
    if (!$googleSheetsService->isAuthenticated()) {
        throw new Exception("Google Sheets не авторизован. Необходимо выполнить авторизацию через API.");
    }
    echo "  ✓ Авторизация Google Sheets: OK\n\n";
    
    // Проверяем наличие wave_id перед обновлением
    if (empty($migrationWaveId)) {
        echo "  ⚠️  ВНИМАНИЕ: У миграции нет wave_id. Обновление Google Таблицы не будет выполнено.\n";
        echo "     Это нормально для одиночных миграций (не из волны).\n\n";
        echo "═══════════════════════════════════════════════════════════════\n";
        echo "  ⚠️  ТЕСТ ПРЕРВАН: Миграция не является частью волны\n";
        echo "═══════════════════════════════════════════════════════════════\n";
        exit(0);
    }
    
    echo "  ✓ wave_id найден: {$migrationWaveId}\n\n";
    
    echo "  → Вызов updateWebsiteBrizyForMigration()...\n";
    echo "    Параметры:\n";
    echo "      • mb_project_uuid: {$uuid}\n";
    echo "      • website_url: {$brizyProjectDomain}\n";
    echo "    (метод автоматически найдет wave_id и обновит Google Таблицу)\n\n";
    
    $googleSheetsService->updateWebsiteBrizyForMigration($uuid, $brizyProjectDomain);
    
    echo "  ✓ Метод updateWebsiteBrizyForMigration() выполнен успешно!\n";
    echo "    (Проверьте логи для деталей обновления)\n\n";
    
    echo "═══════════════════════════════════════════════════════════════\n";
    echo "  ✅ ТЕСТ ЗАВЕРШЕН УСПЕШНО\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
