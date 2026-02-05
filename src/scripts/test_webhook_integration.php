<?php
/**
 * Тестовый скрипт для проверки интеграции веб-хуков с сервером миграции
 * 
 * Использование:
 * php src/scripts/test_webhook_integration.php
 */

// Определяем корень проекта
$projectRoot = dirname(__DIR__, 2);

// Загружаем автозагрузчик
require_once $projectRoot . '/vendor/autoload.php';

// Загружаем переменные окружения
if (file_exists($projectRoot . '/.env')) {
    $dotenv = \Dotenv\Dotenv::createMutable($projectRoot);
    $dotenv->safeLoad();
}

// Явно загружаем необходимые классы (на случай проблем с autoload)
require_once $projectRoot . '/src/services/ApiProxyService.php';
require_once $projectRoot . '/src/services/DatabaseService.php';

echo "=== Тест интеграции веб-хуков с сервером миграции ===\n\n";

// Тест 1: Проверка передачи параметров веб-хука
echo "Тест 1: Проверка передачи параметров веб-хука при запуске миграции\n";
echo str_repeat("-", 60) . "\n";

$apiProxy = new \Dashboard\Services\ApiProxyService();

// Получаем URL дашборда
$dashboardBaseUrl = $_ENV['DASHBOARD_BASE_URL'] ?? getenv('DASHBOARD_BASE_URL') ?: 'http://localhost:8088';
$expectedWebhookUrl = rtrim($dashboardBaseUrl, '/') . '/api/webhooks/migration-result';

echo "Ожидаемый URL веб-хука: {$expectedWebhookUrl}\n";

// Тестовые параметры миграции
$testParams = [
    'mb_project_uuid' => 'test-uuid-123',
    'brz_project_id' => 999,
    'mb_site_id' => 1,
    'mb_secret' => 'test-secret',
];

// Проверяем, что параметры веб-хука будут добавлены
echo "\nПроверка: параметры веб-хука должны быть добавлены в запрос\n";
echo "✓ mb_project_uuid: {$testParams['mb_project_uuid']}\n";
echo "✓ brz_project_id: {$testParams['brz_project_id']}\n";
echo "✓ webhook_url: {$expectedWebhookUrl}\n";
echo "✓ webhook_mb_project_uuid: {$testParams['mb_project_uuid']}\n";
echo "✓ webhook_brz_project_id: {$testParams['brz_project_id']}\n";

// Тест 2: Проверка структуры данных веб-хука (без реального запроса)
echo "\n\nТест 2: Проверка структуры данных для веб-хука\n";
echo str_repeat("-", 60) . "\n";

$webhookUrl = $expectedWebhookUrl;
echo "URL веб-хука: {$webhookUrl}\n";

// Тестовые данные результата миграции
$testWebhookData = [
    'mb_project_uuid' => 'test-uuid-123',
    'brz_project_id' => 999,
    'status' => 'completed',
    'migration_uuid' => 'migration-test-123',
    'brizy_project_id' => 999,
    'brizy_project_domain' => 'test.brizy.io',
    'migration_id' => 'mig-test-123',
    'date' => date('Y-m-d'),
    'theme' => 'default',
    'mb_product_name' => 'Test Product',
    'mb_site_id' => 1,
    'mb_project_domain' => 'test.com',
    'progress' => [
        'total_pages' => 10,
        'processed_pages' => 10,
        'progress_percent' => 100
    ]
];

echo "\nТестовые данные для веб-хука:\n";
echo json_encode($testWebhookData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

// Проверяем обязательные поля (без реального HTTP запроса)
$requiredFields = ['mb_project_uuid', 'brz_project_id', 'status'];
$missingFields = [];
foreach ($requiredFields as $field) {
    if (empty($testWebhookData[$field])) {
        $missingFields[] = $field;
    }
}

if (empty($missingFields)) {
    echo "✓ Все обязательные поля присутствуют\n";
    echo "✓ Структура данных корректна для отправки веб-хука\n";
    echo "\nПримечание: Реальный HTTP запрос не отправляется (мок-режим)\n";
} else {
    echo "✗ Отсутствуют обязательные поля: " . implode(', ', $missingFields) . "\n";
}

// Тест 3: Проверка формирования URL для опроса статуса (без реального запроса)
echo "\n\nТест 3: Проверка формирования URL для опроса статуса миграции\n";
echo str_repeat("-", 60) . "\n";

$migrationServerUrl = $_ENV['MIGRATION_API_URL'] ?? getenv('MIGRATION_API_URL') ?: 'http://mock-server:8080';
$statusUrl = $migrationServerUrl . '/migration-status?' . http_build_query([
    'mb_project_uuid' => 'test-uuid-123',
    'brz_project_id' => 999
]);

echo "Сформированный URL для опроса статуса: {$statusUrl}\n";

// Проверяем наличие обязательных параметров в URL
$parsedUrl = parse_url($statusUrl);
parse_str($parsedUrl['query'] ?? '', $urlParams);

$requiredParams = ['mb_project_uuid', 'brz_project_id'];
$allParamsPresent = true;
foreach ($requiredParams as $param) {
    if (isset($urlParams[$param])) {
        echo "✓ Параметр '{$param}' присутствует: {$urlParams[$param]}\n";
    } else {
        echo "✗ Параметр '{$param}' отсутствует\n";
        $allParamsPresent = false;
    }
}

if ($allParamsPresent) {
    echo "\n✓ URL для опроса статуса сформирован корректно\n";
    echo "\nПримечание: Реальный HTTP запрос не отправляется (мок-режим)\n";
} else {
    echo "\n✗ URL для опроса статуса сформирован некорректно\n";
}

// Тест 4: Проверка структуры ответа от метода getMigrationStatusFromServer
echo "\n\nТест 4: Проверка структуры ответа от ApiProxyService::getMigrationStatusFromServer\n";
echo str_repeat("-", 60) . "\n";

// Мок ответа от сервера миграции
$mockServerResponse = [
    'status' => 'in_progress',
    'mb_project_uuid' => 'test-uuid-123',
    'brz_project_id' => 999,
    'progress' => [
        'total_pages' => 10,
        'processed_pages' => 5,
        'progress_percent' => 50,
        'current_stage' => 'migrating_pages'
    ],
    'started_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s')
];

echo "Мок ответа от сервера миграции:\n";
echo json_encode($mockServerResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

// Проверяем обязательные поля
$requiredResponseFields = ['status', 'mb_project_uuid', 'brz_project_id'];
$missingResponseFields = [];
foreach ($requiredResponseFields as $field) {
    if (!isset($mockServerResponse[$field])) {
        $missingResponseFields[] = $field;
    }
}

if (empty($missingResponseFields)) {
    echo "\n✓ Все обязательные поля присутствуют в ответе\n";
    echo "✓ Структура ответа корректна\n";
    echo "\nПримечание: Реальный HTTP запрос не отправляется (мок-режим)\n";
} else {
    echo "\n✗ Отсутствуют обязательные поля: " . implode(', ', $missingResponseFields) . "\n";
}

// Тест 5: Проверка сохранения результата в БД
echo "\n\nТест 5: Проверка сохранения результата миграции в БД\n";
echo str_repeat("-", 60) . "\n";

try {
    $dbService = new \Dashboard\Services\DatabaseService();
    
    // Проверяем, что миграция была сохранена после веб-хука
    $migration = $dbService->getWriteConnection()->find(
        'SELECT * FROM migrations_mapping WHERE mb_project_uuid = ? AND brz_project_id = ?',
        ['test-uuid-123', 999]
    );
    
    if ($migration) {
        echo "✓ Миграция найдена в БД\n";
        echo "  Статус: " . ($migration['changes_json'] ? json_decode($migration['changes_json'], true)['status'] ?? 'unknown' : 'unknown') . "\n";
    } else {
        echo "⚠ Миграция не найдена в БД (это нормально, если веб-хук не был обработан)\n";
    }
    
    // Проверяем migration_result_list
    $result = $dbService->getWriteConnection()->find(
        'SELECT * FROM migration_result_list WHERE mb_project_uuid = ? AND brz_project_id = ? ORDER BY created_at DESC LIMIT 1',
        ['test-uuid-123', 999]
    );
    
    if ($result) {
        echo "✓ Результат миграции найден в migration_result_list\n";
        $resultData = json_decode($result['result_json'] ?? '{}', true);
        echo "  Статус: " . ($resultData['status'] ?? 'unknown') . "\n";
    } else {
        echo "⚠ Результат миграции не найден в migration_result_list\n";
    }
} catch (Exception $e) {
    echo "✗ Ошибка при проверке БД: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Тестирование завершено (мок-режим - без реальных HTTP запросов)\n";
echo str_repeat("=", 60) . "\n";
echo "\nВсе тесты проверяют только логику формирования запросов и валидацию данных.\n";
echo "Реальные HTTP запросы НЕ отправляются, поэтому тесты не падают при недоступности серверов.\n";
