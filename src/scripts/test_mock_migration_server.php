<?php
/**
 * Mock-сервер миграции для тестирования веб-хуков
 * 
 * Этот скрипт имитирует сервер миграции для тестирования интеграции
 * 
 * Использование:
 * php -S localhost:8080 src/scripts/test_mock_migration_server.php
 * 
 * Или через встроенный веб-сервер PHP:
 * php src/scripts/test_mock_migration_server.php
 */

// Устанавливаем заголовки для JSON
header('Content-Type: application/json');

// Получаем метод и путь запроса
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
parse_str($query, $queryParams);

// Логирование запроса
error_log("[Mock Migration Server] {$method} {$path}?" . $query);

// Health check endpoint
if ($path === '/health' && $method === 'GET') {
    echo json_encode([
        'status' => 'ok',
        'message' => 'Mock migration server is running',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// Endpoint для запуска миграции
if ($path === '/' && $method === 'GET') {
    // Проверяем обязательные параметры
    $required = ['mb_project_uuid', 'brz_project_id', 'mb_site_id', 'mb_secret', 'webhook_url'];
    $missing = [];
    foreach ($required as $param) {
        if (empty($queryParams[$param])) {
            $missing[] = $param;
        }
    }
    
    if (!empty($missing)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Missing required parameters: ' . implode(', ', $missing),
            'missing' => $missing
        ]);
        exit;
    }
    
    // Сохраняем параметры миграции (в реальности это должно быть в БД или файле)
    $migrationData = [
        'mb_project_uuid' => $queryParams['mb_project_uuid'],
        'brz_project_id' => (int)$queryParams['brz_project_id'],
        'webhook_url' => $queryParams['webhook_url'],
        'webhook_mb_project_uuid' => $queryParams['webhook_mb_project_uuid'] ?? $queryParams['mb_project_uuid'],
        'webhook_brz_project_id' => (int)($queryParams['webhook_brz_project_id'] ?? $queryParams['brz_project_id']),
        'status' => 'in_progress',
        'started_at' => date('Y-m-d H:i:s'),
        'progress' => [
            'total_pages' => 10,
            'processed_pages' => 0,
            'progress_percent' => 0
        ]
    ];
    
    // Сохраняем в файл (для тестирования)
    $storageFile = __DIR__ . '/../../var/tmp/mock_migrations.json';
    $migrations = [];
    if (file_exists($storageFile)) {
        $migrations = json_decode(file_get_contents($storageFile), true) ?: [];
    }
    
    $migrationKey = $migrationData['mb_project_uuid'] . '-' . $migrationData['brz_project_id'];
    $migrations[$migrationKey] = $migrationData;
    file_put_contents($storageFile, json_encode($migrations, JSON_PRETTY_PRINT));
    
    // Запускаем "миграцию" в фоне (симуляция)
    // В реальности здесь должен быть реальный процесс миграции
    // Для теста мы просто запустим симуляцию через несколько секунд
    
    echo json_encode([
        'status' => 'in_progress',
        'message' => 'Migration started',
        'mb_project_uuid' => $migrationData['mb_project_uuid'],
        'brz_project_id' => $migrationData['brz_project_id']
    ]);
    
    // Симулируем завершение миграции через 3 секунды
    // В реальности это должно быть в отдельном процессе
    register_shutdown_function(function() use ($migrationData, $storageFile) {
        sleep(3);
        
        // Обновляем статус на completed
        $migrations = [];
        if (file_exists($storageFile)) {
            $migrations = json_decode(file_get_contents($storageFile), true) ?: [];
        }
        
        $migrationKey = $migrationData['mb_project_uuid'] . '-' . $migrationData['brz_project_id'];
        if (isset($migrations[$migrationKey])) {
            $migrations[$migrationKey]['status'] = 'completed';
            $migrations[$migrationKey]['completed_at'] = date('Y-m-d H:i:s');
            $migrations[$migrationKey]['progress'] = [
                'total_pages' => 10,
                'processed_pages' => 10,
                'progress_percent' => 100
            ];
            file_put_contents($storageFile, json_encode($migrations, JSON_PRETTY_PRINT));
            
            // Вызываем веб-хук
            $webhookData = [
                'mb_project_uuid' => $migrationData['mb_project_uuid'],
                'brz_project_id' => $migrationData['brz_project_id'],
                'status' => 'completed',
                'migration_uuid' => 'mock-migration-' . time(),
                'brizy_project_id' => $migrationData['brz_project_id'],
                'brizy_project_domain' => 'test-' . $migrationData['brz_project_id'] . '.brizy.io',
                'migration_id' => 'mig-mock-' . time(),
                'date' => date('Y-m-d'),
                'theme' => 'default',
                'mb_product_name' => 'Test Product',
                'mb_site_id' => (int)$queryParams['mb_site_id'],
                'mb_project_domain' => 'test.com',
                'progress' => [
                    'total_pages' => 10,
                    'processed_pages' => 10,
                    'progress_percent' => 100
                ]
            ];
            
            $ch = curl_init($migrationData['webhook_url']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($webhookData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            error_log("[Mock Migration Server] Webhook called: HTTP {$httpCode}");
        }
    });
    
    exit;
}

// Endpoint для опроса статуса миграции
if ($path === '/migration-status' && $method === 'GET') {
    if (empty($queryParams['mb_project_uuid']) || empty($queryParams['brz_project_id'])) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Missing required parameters: mb_project_uuid, brz_project_id'
        ]);
        exit;
    }
    
    $storageFile = __DIR__ . '/../../var/tmp/mock_migrations.json';
    $migrations = [];
    if (file_exists($storageFile)) {
        $migrations = json_decode(file_get_contents($storageFile), true) ?: [];
    }
    
    $migrationKey = $queryParams['mb_project_uuid'] . '-' . $queryParams['brz_project_id'];
    
    if (!isset($migrations[$migrationKey])) {
        http_response_code(404);
        echo json_encode([
            'error' => 'Migration not found',
            'mb_project_uuid' => $queryParams['mb_project_uuid'],
            'brz_project_id' => $queryParams['brz_project_id']
        ]);
        exit;
    }
    
    $migration = $migrations[$migrationKey];
    
    $response = [
        'status' => $migration['status'],
        'mb_project_uuid' => $migration['mb_project_uuid'],
        'brz_project_id' => $migration['brz_project_id'],
        'started_at' => $migration['started_at'],
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    if (isset($migration['progress'])) {
        $response['progress'] = $migration['progress'];
    }
    
    if ($migration['status'] === 'completed') {
        $response['completed_at'] = $migration['completed_at'] ?? date('Y-m-d H:i:s');
        $response['brizy_project_id'] = $migration['brz_project_id'];
        $response['brizy_project_domain'] = 'test-' . $migration['brz_project_id'] . '.brizy.io';
        $response['migration_id'] = 'mig-mock-' . time();
    }
    
    echo json_encode($response);
    exit;
}

// 404 для неизвестных endpoints
http_response_code(404);
echo json_encode([
    'error' => 'Endpoint not found',
    'path' => $path,
    'method' => $method
]);
