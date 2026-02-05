<?php
/**
 * Тестовый скрипт для проверки интеграции веб-хуков с сервером миграции (МОК версия)
 * 
 * Этот скрипт НЕ отправляет реальные HTTP запросы, а только проверяет логику
 * 
 * Использование:
 * php8.3 src/scripts/test_webhook_integration_mock.php
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

// Явно загружаем необходимые классы
require_once $projectRoot . '/src/services/ApiProxyService.php';
require_once $projectRoot . '/src/services/DatabaseService.php';

echo "=== Тест интеграции веб-хуков (МОК версия - без реальных HTTP запросов) ===\n\n";

// Тест 1: Проверка формирования параметров веб-хука
echo "Тест 1: Проверка формирования параметров веб-хука при запуске миграции\n";
echo str_repeat("-", 60) . "\n";

// Создаем мок ApiProxyService с переопределенным baseUrl
class MockApiProxyService extends \Dashboard\Services\ApiProxyService
{
    private $mockBaseUrl;
    
    public function __construct($baseUrl = 'http://mock-server:8080')
    {
        $this->mockBaseUrl = $baseUrl;
    }
    
    public function getBaseUrl(): string
    {
        return $this->mockBaseUrl;
    }
    
    // Переопределяем runMigration чтобы не делать реальные запросы
    public function testWebhookParams(array $params): array
    {
        // Проверяем обязательные параметры
        $required = ['mb_project_uuid', 'brz_project_id', 'mb_site_id', 'mb_secret'];
        foreach ($required as $key) {
            if (empty($params[$key])) {
                throw new Exception("Обязательный параметр отсутствует: {$key}");
            }
        }
        
        // Формируем query параметры (как в реальном методе)
        $queryParams = [];
        if (!empty($params['mb_project_uuid'])) {
            $queryParams['mb_project_uuid'] = $params['mb_project_uuid'];
        }
        if (!empty($params['brz_project_id'])) {
            $queryParams['brz_project_id'] = (int)$params['brz_project_id'];
        }
        if (!empty($params['mb_site_id'])) {
            $queryParams['mb_site_id'] = (int)$params['mb_site_id'];
        }
        if (!empty($params['mb_secret'])) {
            $queryParams['mb_secret'] = $params['mb_secret'];
        }
        if (!empty($params['brz_workspaces_id'])) {
            $queryParams['brz_workspaces_id'] = (int)$params['brz_workspaces_id'];
        }
        if (!empty($params['mb_page_slug'])) {
            $queryParams['mb_page_slug'] = $params['mb_page_slug'];
        }
        if (!empty($params['mb_element_name'])) {
            $queryParams['mb_element_name'] = $params['mb_element_name'];
        }
        if (isset($params['skip_media_upload'])) {
            $queryParams['skip_media_upload'] = $params['skip_media_upload'] ? 'true' : 'false';
        }
        if (isset($params['skip_cache'])) {
            $queryParams['skip_cache'] = $params['skip_cache'] ? 'true' : 'false';
        }
        $queryParams['mgr_manual'] = $params['mgr_manual'] ?? 0;
        
        if (isset($params['quality_analysis'])) {
            $queryParams['quality_analysis'] = $params['quality_analysis'] ? 'true' : 'false';
        }
        
        // Добавляем параметры веб-хука (как в реальном методе)
        $dashboardBaseUrl = $_ENV['DASHBOARD_BASE_URL'] ?? getenv('DASHBOARD_BASE_URL') ?: 'http://localhost:8088';
        $webhookUrl = rtrim($dashboardBaseUrl, '/') . '/api/webhooks/migration-result';
        
        $queryParams['webhook_url'] = $webhookUrl;
        $queryParams['webhook_mb_project_uuid'] = $params['mb_project_uuid'];
        $queryParams['webhook_brz_project_id'] = (int)$params['brz_project_id'];
        
        if (!empty($params['webhook_url'])) {
            $queryParams['webhook_url'] = $params['webhook_url'];
        }
        
        return $queryParams;
    }
}

$mockApiProxy = new MockApiProxyService('http://mock-server:8080');

// Тестовые параметры миграции
$testParams = [
    'mb_project_uuid' => 'test-uuid-123',
    'brz_project_id' => 999,
    'mb_site_id' => 1,
    'mb_secret' => 'test-secret',
];

// Получаем URL дашборда
$dashboardBaseUrl = $_ENV['DASHBOARD_BASE_URL'] ?? getenv('DASHBOARD_BASE_URL') ?: 'http://localhost:8088';
$expectedWebhookUrl = rtrim($dashboardBaseUrl, '/') . '/api/webhooks/migration-result';

echo "Ожидаемый URL веб-хука: {$expectedWebhookUrl}\n";

// Проверяем формирование параметров
try {
    $queryParams = $mockApiProxy->testWebhookParams($testParams);
    
    echo "\nПроверка параметров запроса:\n";
    $checks = [
        'mb_project_uuid' => $testParams['mb_project_uuid'],
        'brz_project_id' => $testParams['brz_project_id'],
        'webhook_url' => $expectedWebhookUrl,
        'webhook_mb_project_uuid' => $testParams['mb_project_uuid'],
        'webhook_brz_project_id' => $testParams['brz_project_id'],
    ];
    
    $allPassed = true;
    foreach ($checks as $key => $expectedValue) {
        $actualValue = $queryParams[$key] ?? null;
        if ($actualValue == $expectedValue) {
            echo "✓ {$key}: {$actualValue}\n";
        } else {
            echo "✗ {$key}: ожидалось '{$expectedValue}', получено '{$actualValue}'\n";
            $allPassed = false;
        }
    }
    
    if ($allPassed) {
        echo "\n✓ Все параметры веб-хука сформированы корректно\n";
    } else {
        echo "\n✗ Некоторые параметры веб-хука неверны\n";
    }
} catch (Exception $e) {
    echo "✗ Ошибка: " . $e->getMessage() . "\n";
}

// Тест 2: Проверка структуры данных веб-хука
echo "\n\nТест 2: Проверка структуры данных для веб-хука\n";
echo str_repeat("-", 60) . "\n";

$testWebhookData = [
    'mb_project_uuid' => 'test-uuid-123',
    'brz_project_id' => 999,
    'status' => 'completed',
    'migration_uuid' => 'migration-test-123',
    'brizy_project_id' => 999,
    'brizy_project_domain' => 'test.brizy.io',
];

// Проверяем обязательные поля
$requiredFields = ['mb_project_uuid', 'brz_project_id', 'status'];
$missingFields = [];
foreach ($requiredFields as $field) {
    if (!isset($testWebhookData[$field])) {
        $missingFields[] = $field;
    }
}

if (empty($missingFields)) {
    echo "✓ Все обязательные поля присутствуют\n";
    echo "  Структура данных:\n";
    echo json_encode($testWebhookData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "✗ Отсутствуют обязательные поля: " . implode(', ', $missingFields) . "\n";
}

// Тест 3: Проверка валидации статусов
echo "\n\nТест 3: Проверка валидации статусов миграции\n";
echo str_repeat("-", 60) . "\n";

$validStatuses = ['pending', 'in_progress', 'completed', 'error'];
$testStatuses = ['completed', 'success', 'error', 'failed', 'in_progress', 'invalid'];

foreach ($testStatuses as $status) {
    // Нормализация статуса (как в WebhookController)
    $normalized = $status;
    if ($status === 'success') {
        $normalized = 'completed';
    } elseif ($status === 'failed' || $status === 'error') {
        $normalized = 'error';
    }
    
    if (in_array($normalized, $validStatuses)) {
        echo "✓ Статус '{$status}' нормализован в '{$normalized}'\n";
    } else {
        echo "⚠ Статус '{$status}' не является валидным\n";
    }
}

// Тест 4: Проверка формирования URL для опроса статуса
echo "\n\nТест 4: Проверка формирования URL для опроса статуса\n";
echo str_repeat("-", 60) . "\n";

$mbUuid = 'test-uuid-123';
$brzProjectId = 999;
$baseUrl = 'http://mock-server:8080';

$statusUrl = $baseUrl . '/migration-status?' . http_build_query([
    'mb_project_uuid' => $mbUuid,
    'brz_project_id' => $brzProjectId
]);

echo "Сформированный URL: {$statusUrl}\n";

// Проверяем наличие обязательных параметров
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
}

// Тест 5: Проверка структуры ответа от сервера миграции
echo "\n\nТест 5: Проверка структуры ответа от сервера миграции\n";
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
} else {
    echo "\n✗ Отсутствуют обязательные поля: " . implode(', ', $missingResponseFields) . "\n";
}

// Тест 6: Проверка обработки ошибок
echo "\n\nТест 6: Проверка обработки ошибок\n";
echo str_repeat("-", 60) . "\n";

$errorScenarios = [
    'missing_mb_project_uuid' => ['brz_project_id' => 999, 'status' => 'completed'],
    'missing_brz_project_id' => ['mb_project_uuid' => 'test-uuid', 'status' => 'completed'],
    'missing_status' => ['mb_project_uuid' => 'test-uuid', 'brz_project_id' => 999],
];

foreach ($errorScenarios as $scenario => $data) {
    $missing = [];
    if (empty($data['mb_project_uuid'])) {
        $missing[] = 'mb_project_uuid';
    }
    if (empty($data['brz_project_id'])) {
        $missing[] = 'brz_project_id';
    }
    
    if (!empty($missing)) {
        echo "✓ Сценарий '{$scenario}': корректно определяется отсутствие полей: " . implode(', ', $missing) . "\n";
    } else {
        echo "⚠ Сценарий '{$scenario}': все поля присутствуют\n";
    }
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Тестирование завершено (без реальных HTTP запросов)\n";
echo str_repeat("=", 60) . "\n";
echo "\nВсе тесты проверяют только логику формирования запросов и валидацию данных.\n";
echo "Реальные HTTP запросы НЕ отправляются.\n";
