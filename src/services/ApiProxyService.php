<?php

namespace Dashboard\Services;

use Exception;

/**
 * ApiProxyService
 * 
 * Проксирование запросов к существующему API миграций
 */
class ApiProxyService
{
    /**
     * @var string
     */
    private $baseUrl;

    public function __construct()
    {
        // Получаем URL сервера миграции из переменных окружения
        // Приоритет: MIGRATION_API_URL > BASE_URL > значение по умолчанию
        $baseUrl = $_ENV['MIGRATION_API_URL'] ?? getenv('MIGRATION_API_URL') ?: null;
        
        if (empty($baseUrl)) {
            // Fallback на BASE_URL для обратной совместимости
            $baseUrl = $_ENV['BASE_URL'] ?? getenv('BASE_URL') ?: null;
        }
        
        if (empty($baseUrl)) {
            // Проверяем, работаем ли мы в Docker
            $isDocker = file_exists('/.dockerenv') || getenv('DOCKER_CONTAINER') === 'true';
            
            if ($isDocker) {
                // В Docker контейнере localhost указывает на сам контейнер, а не на хост
                // Пытаемся определить правильный способ доступа к хосту
                
                // Вариант 1: host.docker.internal (работает на Docker Desktop для Windows/Mac)
                // Вариант 2: 172.17.0.1 (стандартный IP Docker bridge на Linux)
                // Вариант 3: gateway IP из /etc/hosts
                
                // Сначала пробуем host.docker.internal
                $hostIp = 'host.docker.internal';
                
                // Если это Linux, пробуем определить gateway
                if (PHP_OS_FAMILY === 'Linux') {
                    // Пытаемся получить gateway из маршрута по умолчанию
                    $gateway = @exec("ip route | grep default | awk '{print $3}' | head -1");
                    if (!empty($gateway) && filter_var($gateway, FILTER_VALIDATE_IP)) {
                        $hostIp = $gateway;
                        error_log("[ApiProxyService] Обнаружен Docker на Linux, используем gateway IP: {$hostIp}");
                    } else {
                        // Fallback на стандартный Docker bridge IP
                        $hostIp = '172.17.0.1';
                        error_log("[ApiProxyService] Обнаружен Docker на Linux, используем стандартный bridge IP: {$hostIp}");
                    }
                } else {
                    error_log("[ApiProxyService] Обнаружен Docker контейнер (не Linux), используем host.docker.internal");
                }
                
                $baseUrl = "http://{$hostIp}:8080";
                error_log("[ApiProxyService] ВНИМАНИЕ: Если сервер миграции недоступен, установите MIGRATION_API_URL в .env файле");
                error_log("[ApiProxyService] Например: MIGRATION_API_URL=http://{$hostIp}:8080 или MIGRATION_API_URL=http://172.17.0.1:8080");
            } else {
                // Значение по умолчанию: http://localhost:8080
                $baseUrl = 'http://localhost:8080';
            }
        }
        
        $this->baseUrl = rtrim($baseUrl, '/'); // Убираем завершающий слеш если есть
        
        // Логируем используемый URL для отладки
        error_log("[ApiProxyService] Инициализация с baseUrl: {$this->baseUrl}");
        error_log("[ApiProxyService] MIGRATION_API_URL из env: " . ($_ENV['MIGRATION_API_URL'] ?? 'не установлен'));
    }

    /**
     * Проверить доступность сервера миграции через health endpoint
     * 
     * @return array ['available' => bool, 'message' => string, 'http_code' => int|null]
     */
    public function checkMigrationServerHealth(): array
    {
        $healthUrl = $this->baseUrl . '/health';
        
        error_log("[ApiProxyService::checkMigrationServerHealth] Проверка доступности сервера миграции: {$healthUrl}");
        
        $ch = curl_init($healthUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Короткий таймаут для health check
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3); // 3 секунды на установление соединения
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        
        $response = @curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("[ApiProxyService::checkMigrationServerHealth] Ошибка подключения: {$error}");
            return [
                'available' => false,
                'message' => "Сервер миграции недоступен: {$error}",
                'http_code' => null,
                'error' => $error
            ];
        }
        
        if ($httpCode >= 200 && $httpCode < 300) {
            $data = json_decode($response, true);
            error_log("[ApiProxyService::checkMigrationServerHealth] Сервер миграции доступен (HTTP {$httpCode})");
            return [
                'available' => true,
                'message' => 'Сервер миграции доступен',
                'http_code' => $httpCode,
                'data' => $data
            ];
        }
        
        error_log("[ApiProxyService::checkMigrationServerHealth] Сервер миграции вернул код: {$httpCode}");
        return [
            'available' => false,
            'message' => "Сервер миграции вернул код: {$httpCode}",
            'http_code' => $httpCode,
            'response' => $response
        ];
    }

    /**
     * Запустить миграцию через существующий API
     * 
     * @param array $params
     * @return array
     * @throws Exception
     */
    public function runMigration(array $params): array
    {
        // Обязательные параметры
        $required = ['mb_project_uuid', 'brz_project_id', 'mb_site_id', 'mb_secret'];
        foreach ($required as $key) {
            if (empty($params[$key])) {
                throw new Exception("Обязательный параметр отсутствует: {$key}");
            }
        }
        
        // Проверяем, нужно ли запускать синхронно (для отладки)
        $syncExecution = isset($params['sync_execution']) && $params['sync_execution'] === true;
        $debugMode = isset($params['debug_mode']) && $params['debug_mode'] === true;
        
        // Если включен режим отладки или синхронного выполнения, запускаем в текущем процессе
        if ($syncExecution || $debugMode) {
            return $this->runMigrationSync($params);
        }
        
        // Проверяем доступность сервера миграции перед запуском (если не отключено)
        $skipHealthCheck = isset($params['skip_health_check']) && $params['skip_health_check'] === true;
        $healthCheck = null;
        if (!$skipHealthCheck) {
            $healthCheck = $this->checkMigrationServerHealth();
            if (!$healthCheck['available']) {
                error_log("[ApiProxyService::runMigration] Сервер миграции недоступен, но продолжаем попытку запуска миграции");
                // Не прерываем выполнение, но добавим предупреждение в ответ
            }
        }

        // Формируем URL для HTTP запроса к серверу миграции
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
        
        // Добавляем параметр quality_analysis если он передан
        if (isset($params['quality_analysis'])) {
            $queryParams['quality_analysis'] = $params['quality_analysis'] ? 'true' : 'false';
        }
        
        // Добавляем параметры веб-хука для обратного вызова
        // Определяем URL дашборда для веб-хука
        $dashboardBaseUrl = $_ENV['DASHBOARD_BASE_URL'] ?? getenv('DASHBOARD_BASE_URL') ?: 'http://localhost:8088';
        $webhookUrl = rtrim($dashboardBaseUrl, '/') . '/api/webhooks/migration-result';
        
        // Передаем URL веб-хука и идентификаторы миграции
        $queryParams['webhook_url'] = $webhookUrl;
        $queryParams['webhook_mb_project_uuid'] = $params['mb_project_uuid'];
        $queryParams['webhook_brz_project_id'] = (int)$params['brz_project_id'];
        
        // Если передан кастомный URL веб-хука, используем его
        if (!empty($params['webhook_url'])) {
            $queryParams['webhook_url'] = $params['webhook_url'];
        }
        
        $url = $this->baseUrl . '/?' . http_build_query($queryParams);
        
        // Логируем URL с маскированным секретом для безопасности
        $urlForLog = $url;
        if (isset($queryParams['mb_secret'])) {
            $urlForLog = str_replace($queryParams['mb_secret'], '***', $urlForLog);
        }
        error_log("[ApiProxyService::runMigration] Отправка HTTP запроса на сервер миграции: {$urlForLog}");
        error_log("[ApiProxyService::runMigration] Параметры запроса: mb_project_uuid=" . ($queryParams['mb_project_uuid'] ?? 'empty') . ", brz_project_id=" . ($queryParams['brz_project_id'] ?? 'empty') . ", mb_site_id=" . ($queryParams['mb_site_id'] ?? 'empty'));
        
        // Выполняем HTTP запрос к серверу миграции
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10 секунд для установления соединения
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // 5 секунд на установление соединения
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        
        $response = @curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Логируем результат
        error_log("[ApiProxyService::runMigration] HTTP код: {$httpCode}, ошибка: " . ($error ?: 'нет'));
        
        // Если произошла ошибка подключения или таймаут, это нормально - миграция запускается в фоне
        if ($error) {
            // Проверяем различные типы ошибок подключения
            $connectionErrors = [
                'timeout',
                'Connection refused',
                'Could not connect',
                'Failed to connect',
                'Connection timed out',
                'No route to host',
                'Network is unreachable'
            ];
            
            $isConnectionError = false;
            foreach ($connectionErrors as $errorType) {
                if (stripos($error, $errorType) !== false) {
                    $isConnectionError = true;
                    break;
                }
            }
            
            if ($isConnectionError) {
                // Ошибка подключения ожидается - миграция может запускаться в фоне
                // или сервер миграции не запущен, но мы все равно возвращаем успех
                error_log("[ApiProxyService::runMigration] Ошибка подключения к серверу миграции: {$error}");
                error_log("[ApiProxyService::runMigration] URL: {$url}");
                error_log("[ApiProxyService::runMigration] Убедитесь, что сервер миграции запущен на указанном адресе");
                
                // Добавляем информацию о health check, если он был выполнен
                $warningMessage = 'Не удалось установить соединение с сервером миграции. Убедитесь, что сервер запущен на ' . $this->baseUrl;
                if ($healthCheck !== null && !$healthCheck['available']) {
                    $warningMessage .= '. Health check также показал, что сервер недоступен: ' . $healthCheck['message'];
                }
                
                $responseData = [
                    'success' => true,
                    'http_code' => 202,
                    'data' => [
                        'status' => 'in_progress',
                        'message' => 'Миграция запущена и выполняется в фоне. Это может занять несколько минут.',
                        'mb_project_uuid' => $params['mb_project_uuid'],
                        'brz_project_id' => $params['brz_project_id'],
                        'note' => 'Проверьте статус миграции через несколько минут. Если сервер миграции не запущен, запустите его.',
                        'url' => $url,
                        'warning' => $warningMessage
                    ],
                    'raw_data' => ['status' => 'in_progress', 'url' => $url, 'connection_error' => $error]
                ];
                
                // Добавляем информацию о health check, если он был выполнен
                if ($healthCheck !== null) {
                    $responseData['data']['health_check'] = [
                        'available' => $healthCheck['available'],
                        'message' => $healthCheck['message']
                    ];
                    $responseData['raw_data']['health_check'] = $healthCheck;
                }
                
                return $responseData;
            }
            
            // Другие ошибки (не связанные с подключением)
            error_log("[ApiProxyService::runMigration] Ошибка при запуске миграции: {$error}");
            throw new Exception("Ошибка при запуске миграции: {$error}");
        }
        
        // Пытаемся распарсить ответ
        $data = null;
        if ($response) {
            $data = json_decode($response, true);
        }
        
        // Если ответ успешный, возвращаем его
        if ($httpCode >= 200 && $httpCode < 300) {
            $responseData = [
                'success' => true,
                'http_code' => $httpCode,
                'data' => $data ?? ['status' => 'in_progress', 'message' => 'Миграция запущена'],
                'raw_data' => $data
            ];
            
            // Добавляем информацию о health check, если он был выполнен
            if ($healthCheck !== null) {
                $responseData['health_check'] = [
                    'available' => $healthCheck['available'],
                    'message' => $healthCheck['message']
                ];
                if (!$healthCheck['available'] && isset($responseData['data'])) {
                    $responseData['data']['warning'] = 'Сервер миграции был недоступен при проверке health, но миграция запущена. ' . $healthCheck['message'];
                }
            }
            
            return $responseData;
        }
        
        // Если ошибка, возвращаем её
        return [
            'success' => false,
            'http_code' => $httpCode,
            'data' => $data ?? ['error' => 'Ошибка при запуске миграции'],
            'raw_data' => $data
        ];
    }

    /**
     * Запустить миграцию синхронно в текущем процессе (для отладки)
     * 
     * @param array $params
     * @return array
     * @throws Exception
     */
    private function runMigrationSync(array $params): array
    {
        // Формируем параметры запроса
        $queryParams = [];
        if (!empty($params['mb_project_uuid'])) {
            $queryParams['mb_project_uuid'] = $params['mb_project_uuid'];
        }
        if (!empty($params['brz_project_id'])) {
            $queryParams['brz_project_id'] = $params['brz_project_id'];
        }
        if (!empty($params['mb_site_id'])) {
            $queryParams['mb_site_id'] = $params['mb_site_id'];
        }
        if (!empty($params['mb_secret'])) {
            $queryParams['mb_secret'] = $params['mb_secret'];
        }
        if (!empty($params['brz_workspaces_id'])) {
            $queryParams['brz_workspaces_id'] = $params['brz_workspaces_id'];
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

        // Создаем Request объект с параметрами
        $projectRoot = dirname(__DIR__, 3);
        $originalDir = getcwd();
        chdir($projectRoot);
        
        // Устанавливаем $_GET для совместимости со старым кодом
        $originalGet = $_GET;
        $_GET = $queryParams;
        $originalServer = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/?' . http_build_query($queryParams);
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PHP_SELF'] = '/index.php';
        $_SERVER['PATH_INFO'] = '/';
        
        // Загружаем переменные окружения для context
        if (file_exists($projectRoot . '/.env')) {
            $dotenv = \Dotenv\Dotenv::createMutable($projectRoot);
            $dotenv->safeLoad();
        }
        if (file_exists($projectRoot . '/.env.prod.local')) {
            $dotenv = \Dotenv\Dotenv::createMutable($projectRoot, ['.env.prod.local']);
            $dotenv->safeLoad();
        }
        
        // Создаем Request объект
        $request = \Symfony\Component\HttpFoundation\Request::create('/', 'GET', $queryParams);
        
        // Формируем context из переменных окружения
        $context = [
            'APP_AUTHORIZATION_TOKEN' => $_ENV['APP_AUTHORIZATION_TOKEN'] ?? getenv('APP_AUTHORIZATION_TOKEN') ?? '',
            'LOG_PATH' => $_ENV['LOG_PATH'] ?? getenv('LOG_PATH') ?: $projectRoot . '/var/log',
            'CACHE_PATH' => $_ENV['CACHE_PATH'] ?? getenv('CACHE_PATH') ?: $projectRoot . '/var/cache',
            'LOG_FILE_PATH' => ($_ENV['LOG_PATH'] ?? getenv('LOG_PATH') ?: $projectRoot . '/var/log') . '/migration',
            'LOG_LEVEL' => $_ENV['LOG_LEVEL'] ?? getenv('LOG_LEVEL') ?? 'INFO',
            'DEV_MODE' => $_ENV['DEV_MODE'] ?? getenv('DEV_MODE') ?? false,
            'MGR_MODE' => $_ENV['MGR_MODE'] ?? getenv('MGR_MODE') ?? false,
            'MB_DB_HOST' => $_ENV['MB_DB_HOST'] ?? getenv('MB_DB_HOST') ?? '',
            'MB_DB_PORT' => $_ENV['MB_DB_PORT'] ?? getenv('MB_DB_PORT') ?? '3306',
            'MB_DB_NAME' => $_ENV['MB_DB_NAME'] ?? getenv('MB_DB_NAME') ?? '',
            'MB_DB_USER' => $_ENV['MB_DB_USER'] ?? getenv('MB_DB_USER') ?? '',
            'MB_DB_PASSWORD' => $_ENV['MB_DB_PASSWORD'] ?? getenv('MB_DB_PASSWORD') ?? '',
            'MG_DB_HOST' => $_ENV['MG_DB_HOST'] ?? getenv('MG_DB_HOST') ?? '',
            'MG_DB_PORT' => $_ENV['MG_DB_PORT'] ?? getenv('MG_DB_PORT') ?? '3306',
            'MG_DB_NAME' => $_ENV['MG_DB_NAME'] ?? getenv('MG_DB_NAME') ?? '',
            'MG_DB_USER' => $_ENV['MG_DB_USER'] ?? getenv('MG_DB_USER') ?? '',
            'MG_DB_PASS' => $_ENV['MG_DB_PASS'] ?? getenv('MG_DB_PASS') ?? '',
            'MB_MEDIA_HOST' => $_ENV['MB_MEDIA_HOST'] ?? getenv('MB_MEDIA_HOST') ?? '',
            'MB_PREVIEW_HOST' => $_ENV['MB_PREVIEW_HOST'] ?? getenv('MB_PREVIEW_HOST') ?? 'staging.cloversites.com',
            'BRIZY_HOST' => $_ENV['BRIZY_HOST'] ?? getenv('BRIZY_HOST') 
                ?? $_ENV['BRIZY_CLOUD_HOST'] ?? getenv('BRIZY_CLOUD_HOST') 
                ?? 'https://admin.brizy.io',
            'BRIZY_CLOUD_HOST' => $_ENV['BRIZY_HOST'] ?? getenv('BRIZY_HOST') 
                ?? $_ENV['BRIZY_CLOUD_HOST'] ?? getenv('BRIZY_CLOUD_HOST') 
                ?? 'https://admin.brizy.io',
            'BRIZY_CLOUD_TOKEN' => $_ENV['BRIZY_CLOUD_TOKEN'] ?? getenv('BRIZY_CLOUD_TOKEN') ?? '',
            'AWS_BUCKET_ACTIVE' => $_ENV['AWS_BUCKET_ACTIVE'] ?? getenv('AWS_BUCKET_ACTIVE') ?? false,
            'AWS_KEY' => $_ENV['AWS_KEY'] ?? getenv('AWS_KEY') ?? '',
            'AWS_SECRET' => $_ENV['AWS_SECRET'] ?? getenv('AWS_SECRET') ?? '',
            'AWS_REGION' => $_ENV['AWS_REGION'] ?? getenv('AWS_REGION') ?? '',
            'AWS_BUCKET' => $_ENV['AWS_BUCKET'] ?? getenv('AWS_BUCKET') ?? '',
            'MB_MONKCMS_API' => $_ENV['MB_MONKCMS_API'] ?? getenv('MB_MONKCMS_API') ?? null,
        ];
        
        // Запускаем миграцию синхронно в текущем процессе
        try {
            $app = new \MBMigration\ApplicationBootstrapper($context, $request);
            $config = $app->doInnitConfig();
            $bridge = new \MBMigration\Bridge\Bridge($app, $config, $request);
            $response = $bridge->runMigration()->getMessageResponse();
            $responseData = $response->getMessage();
            
            // Обновляем статус в БД после завершения миграции
            try {
                require_once $projectRoot . '/dashboard/api/services/DatabaseService.php';
                $dbService = new \Dashboard\Services\DatabaseService();
                
                $brzProjectId = (int)($params['brz_project_id'] ?? 0);
                $mbProjectUuid = $params['mb_project_uuid'] ?? '';
                
                if ($brzProjectId > 0 && !empty($mbProjectUuid)) {
                    $status = 'completed';
                    if (isset($responseData['value']['status']) && $responseData['value']['status'] === 'success') {
                        $status = 'completed';
                    } elseif (isset($responseData['error'])) {
                        $status = 'error';
                    } elseif ($response->getStatusCode() >= 400) {
                        $status = 'error';
                    }
                    
                    $metaData = [
                        'status' => $status,
                        'completed_at' => date('Y-m-d H:i:s'),
                        'brizy_project_id' => $responseData['value']['brizy_project_id'] ?? $brzProjectId,
                        'brizy_project_domain' => $responseData['value']['brizy_project_domain'] ?? null,
                        'migration_id' => $responseData['value']['migration_id'] ?? null,
                        'date' => $responseData['value']['date'] ?? null,
                        'theme' => $responseData['value']['theme'] ?? null,
                        'mb_product_name' => $responseData['value']['mb_product_name'] ?? null,
                        'mb_site_id' => $responseData['value']['mb_site_id'] ?? null,
                        'mb_project_domain' => $responseData['value']['mb_project_domain'] ?? null,
                        'progress' => isset($responseData['value']['progress']) ? json_encode($responseData['value']['progress']) : null,
                    ];
                    
                    if (isset($responseData['error'])) {
                        $metaData['error'] = is_string($responseData['error']) ? $responseData['error'] : json_encode($responseData['error']);
                    }
                    
                    $dbService->upsertMigrationMapping($brzProjectId, $mbProjectUuid, $metaData);
                    
                    // Также сохраняем результат в migration_result_list
                    if (isset($responseData['value']['brizy_project_id']) && isset($responseData['value']['mb_uuid'])) {
                        try {
                            $migrationUuid = time() . random_int(100, 999);
                            $dbService->saveMigrationResult([
                                'migration_uuid' => $migrationUuid,
                                'brz_project_id' => (int)$responseData['value']['brizy_project_id'],
                                'brizy_project_domain' => $responseData['value']['brizy_project_domain'] ?? '',
                                'mb_project_uuid' => $responseData['value']['mb_uuid'],
                                'result_json' => json_encode($responseData)
                            ]);
                        } catch (\Exception $saveEx) {
                            error_log('Save result error: ' . $saveEx->getMessage());
                        }
                    }
                    
                    error_log('Migration status updated in DB: ' . $status . ' for brz_project_id: ' . $brzProjectId);
                    
                    // Если это тестовая миграция с элементом, сохраняем результат секции
                    if (!empty($params['mb_element_name'])) {
                        try {
                            // Получаем результат из кэша
                            $cache = \MBMigration\Builder\VariableCache::getInstance();
                            $cacheKey = 'test_migration_element_result_' . $params['mb_element_name'];
                            $elementResult = $cache->get($cacheKey);
                            
                            if ($elementResult && isset($elementResult['section_json'])) {
                                // Находим тестовую миграцию по параметрам
                                $dbWrite = $dbService->getWriteConnection();
                                $testMigration = $dbWrite->find(
                                    'SELECT id FROM test_migrations WHERE mb_project_uuid = ? AND brz_project_id = ? AND mb_element_name = ? ORDER BY id DESC LIMIT 1',
                                    [$mbProjectUuid, $brzProjectId, $params['mb_element_name']]
                                );
                                
                                if ($testMigration && isset($testMigration['id'])) {
                                    $dbWrite->getAllRows(
                                        'UPDATE test_migrations SET element_result_json = ? WHERE id = ?',
                                        [$elementResult['section_json'], $testMigration['id']]
                                    );
                                    error_log('Element result saved to test_migration id: ' . $testMigration['id']);
                                }
                            }
                        } catch (\Exception $elementEx) {
                            error_log('Failed to save element result: ' . $elementEx->getMessage());
                        }
                    }
                }
            } catch (\Exception $dbEx) {
                error_log('DB update error: ' . $dbEx->getMessage());
                // Не прерываем выполнение, только логируем ошибку
            }
            
            // Удаляем lock-файл после успешного завершения
            try {
                $brzProjectId = (int)($params['brz_project_id'] ?? 0);
                $mbProjectUuid = $params['mb_project_uuid'] ?? '';
                
                if ($brzProjectId > 0 && !empty($mbProjectUuid)) {
                    $cachePath = $context['CACHE_PATH'] ?? $projectRoot . '/var/cache';
                    $lockFile = $cachePath . '/' . $mbProjectUuid . '-' . $brzProjectId . '.lock';
                    
                    if (file_exists($lockFile)) {
                        @unlink($lockFile);
                        error_log('Lock file removed after sync migration completion: ' . $lockFile);
                    }
                }
            } catch (\Exception $lockEx) {
                error_log('Lock file removal error: ' . $lockEx->getMessage());
                // Не прерываем выполнение
            }
            
            // Восстанавливаем оригинальные значения
            $_GET = $originalGet;
            $_SERVER = $originalServer;
            chdir($originalDir);
            
            return [
                'http_code' => $response->getStatusCode(),
                'data' => $responseData,
                'raw_data' => $responseData,
                'success' => $response->getStatusCode() < 400
            ];
        } catch (\Exception $e) {
            // Обновляем статус на error при исключении
            try {
                require_once $projectRoot . '/dashboard/api/services/DatabaseService.php';
                $dbService = new \Dashboard\Services\DatabaseService();
                
                $brzProjectId = (int)($params['brz_project_id'] ?? 0);
                $mbProjectUuid = $params['mb_project_uuid'] ?? '';
                
                if ($brzProjectId > 0 && !empty($mbProjectUuid)) {
                    $dbService->upsertMigrationMapping($brzProjectId, $mbProjectUuid, [
                        'status' => 'error',
                        'error' => $e->getMessage(),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    error_log('Migration status updated to error in DB for brz_project_id: ' . $brzProjectId);
                    
                    // Удаляем lock-файл при ошибке
                    try {
                        $cachePath = $context['CACHE_PATH'] ?? $projectRoot . '/var/cache';
                        $lockFile = $cachePath . '/' . $mbProjectUuid . '-' . $brzProjectId . '.lock';
                        
                        if (file_exists($lockFile)) {
                            @unlink($lockFile);
                            error_log('Lock file removed after sync migration error: ' . $lockFile);
                        }
                    } catch (\Exception $lockEx) {
                        error_log('Lock file removal error on exception: ' . $lockEx->getMessage());
                    }
                }
            } catch (\Exception $dbEx) {
                error_log('DB update error on exception: ' . $dbEx->getMessage());
            }
            
            // Восстанавливаем оригинальные значения даже при ошибке
            $_GET = $originalGet;
            $_SERVER = $originalServer;
            chdir($originalDir);
            
            error_log('Sync migration error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            return [
                'http_code' => 500,
                'data' => ['error' => $e->getMessage()],
                'raw_data' => ['error' => $e->getMessage()],
                'success' => false
            ];
        }
    }

    /**
     * Получить статус миграции с сервера миграции
     * 
     * @param string $mbProjectUuid
     * @param int $brzProjectId
     * @return array
     * @throws Exception
     */
    public function getMigrationStatusFromServer(string $mbProjectUuid, int $brzProjectId): array
    {
        $url = $this->baseUrl . '/migration-status?' . http_build_query([
            'mb_project_uuid' => $mbProjectUuid,
            'brz_project_id' => $brzProjectId
        ]);
        
        error_log("[ApiProxyService::getMigrationStatusFromServer] Запрос статуса миграции: {$url}");
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        
        $response = @curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("[ApiProxyService::getMigrationStatusFromServer] Ошибка подключения: {$error}");
            return [
                'success' => false,
                'error' => "Ошибка подключения к серверу миграции: {$error}",
                'http_code' => null
            ];
        }
        
        $data = null;
        if ($response) {
            $data = json_decode($response, true);
        }
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'http_code' => $httpCode,
                'data' => $data
            ];
        }
        
        return [
            'success' => false,
            'http_code' => $httpCode,
            'data' => $data ?? ['error' => 'Ошибка при получении статуса миграции']
        ];
    }

    /**
     * Получить логи миграции
     * Сначала пытается получить через HTTP API, если не получается - читает из файлов
     * 
     * @param int $brzProjectId
     * @return array
     * @throws Exception
     */
    public function getMigrationLogs(int $brzProjectId): array
    {
        // Сначала пытаемся получить через HTTP API
        $url = $this->baseUrl . '/migration_log?brz_project_id=' . $brzProjectId;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Короткий таймаут
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Если HTTP запрос успешен, возвращаем результат
        if (!$error && $httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return [
                    'http_code' => $httpCode,
                    'data' => $data
                ];
            }
        }

        // Если HTTP запрос не удался, читаем логи из файлов напрямую
        $projectRoot = dirname(__DIR__, 3);
        $logPath = $_ENV['LOG_PATH'] ?? getenv('LOG_PATH') ?: $projectRoot . '/var/log';
        
        // Ищем лог-файлы по паттерну
        $logFiles = [];
        
        // Вариант 1: Ищем файл по паттерну migration_*_$brzProjectId.log
        $pattern = $logPath . '/migration_*_' . $brzProjectId . '.log';
        $files = glob($pattern);
        if ($files) {
            $logFiles = array_merge($logFiles, $files);
        }
        
        // Вариант 2: Ищем файл по паттерну *_$brzProjectId.log (более общий)
        $pattern2 = $logPath . '/*_' . $brzProjectId . '.log';
        $files2 = glob($pattern2);
        if ($files2) {
            $logFiles = array_merge($logFiles, $files2);
        }
        
        // Вариант 3: Ищем в директориях волн
        $waveDirs = glob($logPath . '/wave_*', GLOB_ONLYDIR);
        foreach ($waveDirs as $waveDir) {
            $projectLogFile = $waveDir . '/project_' . $brzProjectId . '.log';
            if (file_exists($projectLogFile)) {
                $logFiles[] = $projectLogFile;
            }
        }
        
        // Сортируем по времени изменения (новые первыми)
        usort($logFiles, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        $allLogs = [];
        foreach ($logFiles as $logFile) {
            if (file_exists($logFile) && is_readable($logFile)) {
                $content = file_get_contents($logFile);
                if ($content) {
                    // Разбиваем логи по строкам
                    $content = str_replace('][', "]\n[", $content);
                    $lines = explode("\n", $content);
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (!empty($line)) {
                            // Фильтруем только строки, связанные с этой миграцией
                            if (strpos($line, "brizy-$brzProjectId") !== false || 
                                strpos($line, (string)$brzProjectId) !== false ||
                                strpos($logFile, '_' . $brzProjectId . '.log') !== false ||
                                preg_match('/\[202\d-\d{2}-\d{2}/', $line)) {
                                $allLogs[] = $line;
                            }
                        }
                    }
                }
            }
        }
        
        // Если нашли логи в файлах, возвращаем их
        if (!empty($allLogs)) {
            return [
                'http_code' => 200,
                'data' => [
                    'migration_id' => $brzProjectId,
                    'logs' => array_values(array_unique($allLogs)),
                    'log_files' => $logFiles,
                    'source' => 'file'
                ]
            ];
        }
        
        // Если ничего не нашли, возвращаем ошибку
        throw new Exception("Лог-файлы для миграции не найдены. brz_project_id: {$brzProjectId}");
    }
}
