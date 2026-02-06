<?php
/**
 * Dashboard API Entry Point
 * Доступен по адресу: http://localhost:8088/api/*
 */

// Определяем корень проекта (если вызвано из public/index.php — используется DASHBOARD_PROJECT_ROOT)
$projectRoot = defined('DASHBOARD_PROJECT_ROOT') ? DASHBOARD_PROJECT_ROOT : dirname(__DIR__);

// Сначала загружаем автозагрузчик Composer (нужен для Dotenv)
require_once $projectRoot . '/vendor/autoload.php';

// Теперь загружаем переменные окружения из .env для определения режима дебага
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

// Определяем режим дебага (по умолчанию выключен)
$debugMode = isset($_ENV['APP_DEBUG']) && $_ENV['APP_DEBUG'] === 'true' || 
             isset($_ENV['DEBUG']) && $_ENV['DEBUG'] === 'true' ||
             (isset($_GET['debug']) && $_GET['debug'] === '1');

// Настраиваем вывод ошибок в зависимости от режима
if ($debugMode) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL | E_STRICT);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
}
ini_set('log_errors', '1');

// Функция formatErrorForResponse уже объявлена в public/index.php
// Если она не объявлена (для прямого вызова src/index.php), объявляем её
// Используем более надежную проверку, чтобы избежать ошибки "Cannot redeclare"
if (!function_exists('formatErrorForResponse')) {
    /**
     * Форматирует ошибку для JSON ответа
     * 
     * @param string $message
     * @param string $file
     * @param int $line
     * @param string|null $trace
     * @param bool $debugMode
     * @return array
     */
    function formatErrorForResponse($message, $file, $line, $trace = null, $debugMode = false) {
        $error = [
            'success' => false,
            'error' => $message,
            'file' => basename($file),
            'line' => $line
        ];
        
        if ($debugMode) {
            $error['debug'] = [
                'full_file' => $file,
                'trace' => $trace ? explode("\n", $trace) : null,
                'timestamp' => date('Y-m-d H:i:s'),
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true)
            ];
        }
        
        return $error;
    }
}

// Устанавливаем обработчик ошибок для перехвата всех ошибок
set_error_handler(function($severity, $message, $file, $line) use ($debugMode) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    
    $errorMsg = "PHP Error: $message in $file on line $line";
    error_log($errorMsg);
    
    // В режиме дебага выводим в stderr
    if ($debugMode) {
        file_put_contents('php://stderr', "[ERROR] $errorMsg\n");
    }
    
    return true;
}, E_ALL | E_STRICT);

// Устанавливаем обработчик исключений
set_exception_handler(function($exception) use ($debugMode) {
    $errorMsg = "Uncaught exception: " . $exception->getMessage();
    $trace = $exception->getTraceAsString();
    
    error_log($errorMsg);
    error_log("Stack trace: " . $trace);
    
    if ($debugMode) {
        file_put_contents('php://stderr', "[EXCEPTION] $errorMsg\n");
        file_put_contents('php://stderr', "[TRACE] $trace\n");
    }
});

// Автозагрузчик уже загружен выше
// Переменные окружения уже загружены выше

// Логирование роутера только в режиме отладки (снижает нагрузку на прод)
if (php_sapi_name() !== 'cli' && $debugMode) {
    file_put_contents('php://stderr', "[Router] Router file loaded\n");
    error_log("[Router] REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'not set'));
}

// Инициализация Config
\MBMigration\Core\Config::initializeFromEnv();

// Автозагрузка Dashboard классов
spl_autoload_register(function ($class) {
    $prefix = 'Dashboard\\';
    $baseDir = __DIR__ . '/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    
    if (file_exists($file)) {
        require_once $file;
        return true;
    }
    
    return false;
});

// Предзагрузка всех необходимых классов
$classesToLoad = [
    'Dashboard\\Services\\DatabaseService' => __DIR__ . '/services/DatabaseService.php',
    'Dashboard\\Services\\ApiProxyService' => __DIR__ . '/services/ApiProxyService.php',
    'Dashboard\\Services\\MigrationService' => __DIR__ . '/services/MigrationService.php',
    'Dashboard\\Services\\WaveService' => __DIR__ . '/services/WaveService.php',
    'Dashboard\\Services\\WaveLogger' => __DIR__ . '/services/WaveLogger.php',
    'Dashboard\\Services\\MigrationExecutionService' => __DIR__ . '/services/MigrationExecutionService.php',
    'Dashboard\\Services\\QualityAnalysisService' => __DIR__ . '/services/QualityAnalysisService.php',
    'Dashboard\\Services\\TestMigrationService' => __DIR__ . '/services/TestMigrationService.php',
    'Dashboard\\Services\\AuthService' => __DIR__ . '/services/AuthService.php',
    'Dashboard\\Services\\WaveReviewService' => __DIR__ . '/services/WaveReviewService.php',
    'Dashboard\\Services\\UserService' => __DIR__ . '/services/UserService.php',
    'Dashboard\\Services\\GoogleSheetsService' => __DIR__ . '/services/GoogleSheetsService.php',
    'Dashboard\\Services\\GoogleSheetsSyncService' => __DIR__ . '/services/GoogleSheetsSyncService.php',
    'Dashboard\\Services\\BrizyApiService' => __DIR__ . '/services/BrizyApiService.php',
    'Dashboard\\Services\\ScreenshotService' => __DIR__ . '/services/ScreenshotService.php',
    'Dashboard\\Core\\BrizyConfig' => __DIR__ . '/Core/BrizyConfig.php',
    'Dashboard\\Controllers\\MigrationController' => __DIR__ . '/controllers/MigrationController.php',
    'Dashboard\\Controllers\\LogController' => __DIR__ . '/controllers/LogController.php',
    'Dashboard\\Controllers\\SettingsController' => __DIR__ . '/controllers/SettingsController.php',
    'Dashboard\\Controllers\\GoogleSheetsController' => __DIR__ . '/controllers/GoogleSheetsController.php',
    'Dashboard\\Controllers\\WaveController' => __DIR__ . '/controllers/WaveController.php',
    'Dashboard\\Controllers\\QualityAnalysisController' => __DIR__ . '/controllers/QualityAnalysisController.php',
    'Dashboard\\Controllers\\TestMigrationController' => __DIR__ . '/controllers/TestMigrationController.php',
    'Dashboard\\Controllers\\AuthController' => __DIR__ . '/controllers/AuthController.php',
    'Dashboard\\Controllers\\UserController' => __DIR__ . '/controllers/UserController.php',
    'Dashboard\\Middleware\\AuthMiddleware' => __DIR__ . '/middleware/AuthMiddleware.php',
    'Dashboard\\Middleware\\PermissionMiddleware' => __DIR__ . '/middleware/PermissionMiddleware.php',
];

foreach ($classesToLoad as $class => $file) {
    if (!class_exists($class) && file_exists($file)) {
        require_once $file;
    }
}

use Dashboard\Controllers\MigrationController;
use Dashboard\Controllers\LogController;
use Dashboard\Controllers\WaveController;
use Dashboard\Controllers\QualityAnalysisController;
use Dashboard\Controllers\TestMigrationController;
use Dashboard\Controllers\AuthController;
use Dashboard\Controllers\UserController;
use Dashboard\Services\QualityAnalysisService;
use Dashboard\Services\MigrationService;
use Dashboard\Services\WaveReviewService;
use Dashboard\Middleware\AuthMiddleware;
use Dashboard\Middleware\PermissionMiddleware;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

return function (array $context, Request $request) use ($debugMode): Response {
    if ($debugMode) {
        file_put_contents('php://stderr', "[Router] Request: " . $request->getMethod() . " " . $request->getRequestUri() . "\n");
        error_log("[Router] " . $request->getMethod() . " " . $request->getRequestUri());
    }

    // Handle preflight requests
    if ($request->getMethod() === 'OPTIONS') {
        $response = new Response('', 200);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        return $response;
    }

    $pathInfo = $request->getPathInfo();
    
    // Убираем /api из пути для маршрутизации
    $apiPath = str_replace('/api', '', $pathInfo);
    $apiPath = $apiPath ?: '/';

    // Test endpoint для отладки
    if ($apiPath === '/test') {
        $testResponse = new JsonResponse([
            'success' => true,
            'message' => 'Test endpoint works',
            'timestamp' => date('Y-m-d H:i:s'),
            'debug_mode' => $debugMode
        ], 200);
        $testResponse->headers->set('Content-Type', 'application/json; charset=utf-8');
        $testResponse->prepare($request);
        error_log("[Router] Test endpoint called, content length: " . strlen($testResponse->getContent()));
        return $testResponse;
    }

    // Health check endpoint для dashboard API
    if ($apiPath === '/health' || $apiPath === '/') {
        $healthResponse = new JsonResponse([
            'status' => 'success',
            'message' => 'Dashboard API is running',
            'version' => '1.0.0',
            'endpoints' => [
                '/api/health' => 'GET - Health check Dashboard API',
                '/api/migration-server/health' => 'GET - Health check сервера миграции',
                '/api/migrations' => 'GET - Список миграций',
                '/api/migrations/:id' => 'GET - Детали миграции',
                '/api/migrations/run' => 'POST - Запуск миграции',
                '/api/migrations/:id/restart' => 'POST - Перезапуск миграции',
                '/api/migrations/:id/lock' => 'DELETE - Удалить lock-файл миграции',
                '/api/migrations/:id/kill' => 'POST - Убить процесс миграции',
                '/api/migrations/:id/process' => 'GET - Информация о процессе миграции (мониторинг)',
                '/api/migrations/:id/cache' => 'DELETE - Удалить кэш-файл миграции',
                '/api/migrations/:id/cache-all' => 'DELETE - Удалить все файлы кэша по ID проекта (только .json файлы, не lock-файлы)',
                '/api/migrations/:id/reset-status' => 'POST - Сбросить статус миграции на pending',
                '/api/migrations/:id/hard-reset' => 'POST - Hard reset: удалить lock, cache, убить процесс и сбросить статус',
                '/api/migrations/:id/logs' => 'GET - Логи миграции',
                '/api/logs/:brz_project_id' => 'GET - Логи миграции (старый endpoint)',
                '/api/logs/recent' => 'GET - Последние логи',
                '/api/waves' => 'GET/POST - Список волн / Создать волну',
                '/api/waves/:id' => 'GET - Детали волны',
                '/api/waves/:id/status' => 'GET - Статус волны',
                '/api/waves/:id/reset-status' => 'POST - Сбросить статус волны и миграций на pending',
                '/api/waves/:id/restart-all' => 'POST - Массовый перезапуск всех миграций в волне',
                '/api/waves/:id/migrations/:mb_uuid/restart' => 'POST - Перезапустить миграцию в волне',
                '/api/waves/:id/logs' => 'GET - Логи волны',
                '/api/waves/:id/migrations/:mb_uuid/logs' => 'GET - Логи миграции в волне',
                '/api/waves/:id/projects/:brz_project_id/logs' => 'GET - Логи проекта в волне по brz_project_id',
                '/api/waves/:id/migrations/:mb_uuid/lock' => 'DELETE - Удалить lock-файл миграции',
            ]
        ], 200);
        $healthResponse->headers->set('Content-Type', 'application/json; charset=utf-8');
        $healthResponse->prepare($request);
        return $healthResponse;
    }

    // Health check endpoint для сервера миграции
    if ($apiPath === '/migration-server/health') {
        if ($request->getMethod() === 'GET') {
            try {
                $apiProxy = new \Dashboard\Services\ApiProxyService();
                $healthCheck = $apiProxy->checkMigrationServerHealth();
                
                $response = new JsonResponse([
                    'success' => $healthCheck['available'],
                    'available' => $healthCheck['available'],
                    'message' => $healthCheck['message'],
                    'http_code' => $healthCheck['http_code'] ?? null,
                    'data' => $healthCheck['data'] ?? null,
                    'error' => $healthCheck['error'] ?? null,
                    'timestamp' => date('Y-m-d H:i:s')
                ], $healthCheck['available'] ? 200 : 503);
                $response->headers->set('Content-Type', 'application/json; charset=utf-8');
                return $response;
            } catch (\Exception $e) {
                return new JsonResponse([
                    'success' => false,
                    'available' => false,
                    'message' => 'Ошибка при проверке health сервера миграции: ' . $e->getMessage(),
                    'timestamp' => date('Y-m-d H:i:s')
                ], 500);
            }
        }
    }

    // Маршрутизация API endpoints
    try {
        // Публичный доступ к скриншотам по токену ревью (без авторизации)
        if (preg_match('#^/review/wave/([^/]+)/screenshots/(.+)$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                $token = $matches[1];
                $filename = basename($matches[2]);
                
                // Проверяем токен (но не требуем полной авторизации)
                $reviewService = new WaveReviewService();
                $waveId = $reviewService->validateToken($token);
                
                if (!$waveId) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Недействительный или истекший токен доступа'
                    ], 403);
                }
                
                // Ищем файл в var/tmp/project_*/ директориях
                $currentFile = __FILE__;
                $projectRoot = dirname(dirname(dirname($currentFile)));
                $screenshotsDir = $projectRoot . '/var/tmp/';
                
                $found = false;
                $filePath = null;
                $dirs = [];
                
                if (is_dir($screenshotsDir)) {
                    $dirs = glob($screenshotsDir . 'project_*', GLOB_ONLYDIR);
                    
                    foreach ($dirs as $dir) {
                        $potentialPath = $dir . '/' . $filename;
                        if (file_exists($potentialPath)) {
                            $filePath = $potentialPath;
                            $found = true;
                            break;
                        }
                    }
                }
                
                // Также проверяем корневую директорию var/tmp/
                if (!$found) {
                    $rootPath = $screenshotsDir . $filename;
                    if (file_exists($rootPath)) {
                        $filePath = $rootPath;
                        $found = true;
                    }
                }
                
                if (!$found || !$filePath) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Скриншот не найден: ' . $filename
                    ], 404);
                }
                
                // Определяем MIME тип
                $mimeType = mime_content_type($filePath);
                if (!$mimeType) {
                    $mimeType = 'image/png';
                }
                
                // Возвращаем файл
                $response = new Response(file_get_contents($filePath), 200);
                $response->headers->set('Content-Type', $mimeType);
                $response->headers->set('Content-Disposition', 'inline; filename="' . $filename . '"');
                $response->headers->set('Cache-Control', 'public, max-age=3600');
                return $response;
            }
        }

        // Публичный доступ к ревью волны (без авторизации)
        if (preg_match('#^/review/wave/([^/]+)$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                $token = $matches[1];
                $reviewService = new WaveReviewService();
                $waveId = $reviewService->validateToken($token);
                
                if (!$waveId) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Недействительный или истекший токен доступа'
                    ], 403);
                }
                
                // Получаем информацию о токене и настройках доступа
                $tokenInfo = $reviewService->getTokenInfo($token);
                if (!$tokenInfo) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Токен не найден'
                    ], 403);
                }
                
                // Получаем детали волны
                $waveController = new WaveController();
                $waveResponse = $waveController->getDetails($request, $waveId);
                
                // Если ответ успешный, добавляем информацию о настройках доступа
                if ($waveResponse->getStatusCode() === 200) {
                    $waveData = json_decode($waveResponse->getContent(), true);
                    if ($waveData && $waveData['success']) {
                        // Добавляем настройки доступа и статус ревью для каждого проекта
                        if (isset($waveData['data']['migrations']) && is_array($waveData['data']['migrations'])) {
                            $reviewsByProject = $reviewService->getProjectReviewsByTokenId((int)$tokenInfo['id']);
                            $mbUuids = [];
                            foreach ($waveData['data']['migrations'] as $m) {
                                $uuid = $m['mb_uuid'] ?? $m['mb_project_uuid'] ?? null;
                                if ($uuid) {
                                    $mbUuids[] = $uuid;
                                }
                            }
                            $accessByUuid = $reviewService->getProjectAccessBatch($token, array_unique($mbUuids));
                            foreach ($waveData['data']['migrations'] as &$migration) {
                                $mbUuid = $migration['mb_uuid'] ?? $migration['mb_project_uuid'] ?? null;
                                if ($mbUuid) {
                                    $migration['review_access'] = $accessByUuid[$mbUuid] ?? null;
                                } else {
                                    $migration['review_access'] = ['is_active' => false];
                                }
                                $brzProjectId = $migration['brz_project_id'] ?? $migration['result_data']['brizy_project_id'] ?? null;
                                if ($brzProjectId) {
                                    $migration['project_review'] = $reviewsByProject[(int)$brzProjectId] ?? null;
                                } else {
                                    $migration['project_review'] = null;
                                }
                            }
                            unset($migration);
                        }
                        
                        // Добавляем информацию о токене
                        $waveData['data']['token_info'] = [
                            'name' => $tokenInfo['name'],
                            'description' => $tokenInfo['description'],
                            'settings' => $tokenInfo['settings']
                        ];
                        
                        return new JsonResponse($waveData, 200);
                    }
                }
                
                return $waveResponse;
            }
        }

        // Публичный доступ к деталям миграции по токену (без авторизации)
        // Формат: /review/wave/:token/migration/:brzProjectId
        if (preg_match('#^/review/wave/([^/]+)/migration/(\d+)$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                $token = $matches[1];
                $brzProjectId = (int)$matches[2];
                
                $reviewService = new WaveReviewService();
                $waveId = $reviewService->validateToken($token);
                
                if (!$waveId) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Недействительный или истекший токен доступа'
                    ], 403);
                }
                
                // Получаем детали миграции по brz_project_id
                $migrationService = new MigrationService();
                $migrationDetails = $migrationService->getMigrationDetails($brzProjectId);
                
                if (!$migrationDetails) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Миграция не найдена'
                    ], 404);
                }
                
                $mbUuid = $migrationDetails['mapping']['mb_project_uuid'] ?? null;
                if (!$mbUuid) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Не удалось определить UUID проекта'
                    ], 404);
                }
                
                // Проверяем настройки доступа для проекта
                // Если токен валиден, доступ разрешен по умолчанию
                // Блокируем только если явно установлено is_active = false
                $projectAccess = $reviewService->getProjectAccess($token, $mbUuid);
                
                // Если есть индивидуальные настройки и проект заблокирован
                if ($projectAccess && isset($projectAccess['is_active']) && $projectAccess['is_active'] === false) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Доступ к этому проекту ограничен'
                    ], 403);
                }
                
                // Получаем детали миграции по mb_uuid через контроллер
                $migrationController = new MigrationController();
                $migrationResponse = $migrationController->getDetailsByUuid($request, $mbUuid);
                
                // Если ответ успешный, добавляем информацию о разрешенных вкладках
                if ($migrationResponse->getStatusCode() === 200) {
                    $migrationData = json_decode($migrationResponse->getContent(), true);
                    if ($migrationData && $migrationData['success']) {
                        // Если есть индивидуальные настройки, используем их, иначе все вкладки доступны
                        $allowedTabs = $projectAccess && isset($projectAccess['allowed_tabs']) 
                            ? $projectAccess['allowed_tabs'] 
                            : ['overview', 'details', 'logs', 'screenshots', 'quality', 'analysis']; // Все вкладки по умолчанию
                        
                        // Всегда добавляем 'analysis' в список разрешенных вкладок, если её там нет
                        if (!in_array('analysis', $allowedTabs)) {
                            $allowedTabs[] = 'analysis';
                        }
                        
                        $migrationData['data']['allowed_tabs'] = $allowedTabs;
                        return new JsonResponse($migrationData, 200);
                    }
                }
                
                return $migrationResponse;
            }
        }

        // Публичный доступ к статистике анализа качества миграции по токену
        // Формат: /review/wave/:token/migration/:brzProjectId/analysis/statistics
        if (preg_match('#^/review/wave/([^/]+)/migration/(\d+)/analysis/statistics$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                try {
                    $token = $matches[1];
                    $brzProjectId = (int)$matches[2];
                    
                    $reviewService = new WaveReviewService();
                    $waveId = $reviewService->validateToken($token);
                    
                    if (!$waveId) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Недействительный или истекший токен доступа'
                        ], 403, ['Content-Type' => 'application/json; charset=utf-8']);
                    }
                    
                    // Получаем детали миграции по brz_project_id
                    $migrationService = new MigrationService();
                    $migrationDetails = $migrationService->getMigrationDetails($brzProjectId);
                    
                    if (!$migrationDetails) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Миграция не найдена'
                        ], 404, ['Content-Type' => 'application/json; charset=utf-8']);
                    }
                    
                    $mbUuid = $migrationDetails['mapping']['mb_project_uuid'] ?? null;
                    if (!$mbUuid) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Не удалось определить UUID проекта'
                        ], 404, ['Content-Type' => 'application/json; charset=utf-8']);
                    }
                    
                    // Проверяем настройки доступа для проекта
                    $projectAccess = $reviewService->getProjectAccess($token, $mbUuid);
                    
                    // Если есть индивидуальные настройки и проект заблокирован
                    if ($projectAccess && isset($projectAccess['is_active']) && $projectAccess['is_active'] === false) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Доступ к этому проекту ограничен'
                        ], 403, ['Content-Type' => 'application/json; charset=utf-8']);
                    }
                    
                    // Получаем статистику анализа качества (используем brzProjectId из URL)
                    $qualityService = new QualityAnalysisService();
                    $statistics = $qualityService->getMigrationStatistics($brzProjectId);
                    
                    return new JsonResponse([
                        'success' => true,
                        'data' => $statistics
                    ], 200, ['Content-Type' => 'application/json; charset=utf-8']);
                } catch (\Throwable $e) {
                    error_log("Error in analysis statistics endpoint: " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                    return new JsonResponse([
                        'success' => true,
                        'data' => [
                            'total_pages' => 0,
                            'avg_quality_score' => null,
                            'by_severity' => [
                                'critical' => 0,
                                'high' => 0,
                                'medium' => 0,
                                'low' => 0,
                                'none' => 0
                            ],
                            'token_statistics' => [
                                'total_prompt_tokens' => 0,
                                'total_completion_tokens' => 0,
                                'total_tokens' => 0,
                                'avg_tokens_per_page' => 0,
                                'total_cost_usd' => 0,
                                'avg_cost_per_page_usd' => 0
                            ]
                        ]
                    ], 200, ['Content-Type' => 'application/json; charset=utf-8']);
                }
            }
        }

        // Публичный доступ к отчетам анализа качества миграции по токену
        // Формат: /review/wave/:token/migration/:brzProjectId/analysis/reports
        if (preg_match('#^/review/wave/([^/]+)/migration/(\d+)/analysis/reports$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                try {
                    $token = $matches[1];
                    $brzProjectId = (int)$matches[2];
                    
                    $reviewService = new WaveReviewService();
                    $waveId = $reviewService->validateToken($token);
                    
                    if (!$waveId) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Недействительный или истекший токен доступа'
                        ], 403, ['Content-Type' => 'application/json; charset=utf-8']);
                    }
                    
                    // Получаем детали миграции по brz_project_id
                    $migrationService = new MigrationService();
                    $migrationDetails = $migrationService->getMigrationDetails($brzProjectId);
                    
                    if (!$migrationDetails) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Миграция не найдена'
                        ], 404, ['Content-Type' => 'application/json; charset=utf-8']);
                    }
                    
                    $mbUuid = $migrationDetails['mapping']['mb_project_uuid'] ?? null;
                    if (!$mbUuid) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Не удалось определить UUID проекта'
                        ], 404, ['Content-Type' => 'application/json; charset=utf-8']);
                    }
                    
                    // Проверяем настройки доступа для проекта
                    $projectAccess = $reviewService->getProjectAccess($token, $mbUuid);
                    
                    // Если есть индивидуальные настройки и проект заблокирован
                    if ($projectAccess && isset($projectAccess['is_active']) && $projectAccess['is_active'] === false) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Доступ к этому проекту ограничен'
                        ], 403, ['Content-Type' => 'application/json; charset=utf-8']);
                    }
                    
                    // Получаем список отчетов анализа качества (используем brzProjectId из URL)
                    $qualityService = new QualityAnalysisService();
                    
                    error_log("[Review API] Getting reports for brzProjectId: {$brzProjectId}");
                    $reports = $qualityService->getReportsByMigration($brzProjectId);
                    
                    error_log("[Review API] Reports result: " . json_encode([
                        'count' => is_array($reports) ? count($reports) : 0,
                        'is_array' => is_array($reports),
                        'type' => gettype($reports)
                    ]));
                    
                    // Убеждаемся, что $reports - это массив
                    if (!is_array($reports)) {
                        error_log("[Review API] Warning: reports is not an array, type: " . gettype($reports));
                        $reports = [];
                    }
                    
                    return new JsonResponse([
                        'success' => true,
                        'data' => $reports,
                        'count' => count($reports)
                    ], 200, ['Content-Type' => 'application/json; charset=utf-8']);
                } catch (\Throwable $e) {
                    error_log("Error in analysis reports endpoint: " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Ошибка при получении отчетов анализа: ' . $e->getMessage()
                    ], 500, ['Content-Type' => 'application/json; charset=utf-8']);
                }
            }
        }

        // Публичный доступ к деталям анализа конкретной страницы по токену
        // Формат: /review/wave/:token/migration/:brzProjectId/analysis/:pageSlug
        if (preg_match('#^/review/wave/([^/]+)/migration/(\d+)/analysis/([^/]+)$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                try {
                    $token = $matches[1];
                    $brzProjectId = (int)$matches[2];
                    $pageSlug = urldecode($matches[3]);
                    
                    $reviewService = new WaveReviewService();
                    $waveId = $reviewService->validateToken($token);
                    
                    if (!$waveId) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Недействительный или истекший токен доступа'
                        ], 403, ['Content-Type' => 'application/json; charset=utf-8']);
                    }
                    
                    // Получаем детали миграции по brz_project_id
                    $migrationService = new MigrationService();
                    $migrationDetails = $migrationService->getMigrationDetails($brzProjectId);
                    
                    if (!$migrationDetails) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Миграция не найдена'
                        ], 404, ['Content-Type' => 'application/json; charset=utf-8']);
                    }
                    
                    $mbUuid = $migrationDetails['mapping']['mb_project_uuid'] ?? null;
                    if (!$mbUuid) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Не удалось определить UUID проекта'
                        ], 404, ['Content-Type' => 'application/json; charset=utf-8']);
                    }
                    
                    // Проверяем настройки доступа для проекта
                    $projectAccess = $reviewService->getProjectAccess($token, $mbUuid);
                    
                    // Если есть индивидуальные настройки и проект заблокирован
                    if ($projectAccess && isset($projectAccess['is_active']) && $projectAccess['is_active'] === false) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Доступ к этому проекту ограничен'
                        ], 403, ['Content-Type' => 'application/json; charset=utf-8']);
                    }
                    
                    // Получаем детали анализа страницы (используем brzProjectId из URL)
                    $qualityService = new QualityAnalysisService();
                    $report = $qualityService->getReportBySlug($brzProjectId, $pageSlug, false);
                    
                    if (!$report) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Анализ страницы не найден'
                        ], 404, ['Content-Type' => 'application/json; charset=utf-8']);
                    }
                    
                    // Пытаемся получить скриншоты из нового хранилища дашборда
                    $screenshotService = new \Dashboard\Services\ScreenshotService();
                    $sourceScreenshot = $screenshotService->getScreenshot($mbUuid, $pageSlug, 'source');
                    $migratedScreenshot = $screenshotService->getScreenshot($mbUuid, $pageSlug, 'migrated');
                    
                    // Если скриншоты найдены в новом хранилище, обновляем пути
                    if ($sourceScreenshot || $migratedScreenshot) {
                        $screenshotsPath = [];
                        if ($sourceScreenshot) {
                            $screenshotsPath['source'] = $sourceScreenshot['url'];
                        }
                        if ($migratedScreenshot) {
                            $screenshotsPath['migrated'] = $migratedScreenshot['url'];
                        }
                        
                        // Обновляем или добавляем screenshots_path в отчет
                        if (is_string($report['screenshots_path'])) {
                            $report['screenshots_path'] = json_decode($report['screenshots_path'], true) ?: [];
                        }
                        if (!is_array($report['screenshots_path'])) {
                            $report['screenshots_path'] = [];
                        }
                        
                        // Объединяем с существующими путями, приоритет у новых
                        $report['screenshots_path'] = array_merge($report['screenshots_path'], $screenshotsPath);
                    }
                    
                    return new JsonResponse([
                        'success' => true,
                        'data' => $report
                    ], 200, ['Content-Type' => 'application/json; charset=utf-8']);
                } catch (\Throwable $e) {
                    error_log("Error in page analysis endpoint: " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Ошибка при получении деталей анализа: ' . $e->getMessage()
                    ], 500, ['Content-Type' => 'application/json; charset=utf-8']);
                }
            }
        }

        // Webhook для загрузки скриншотов из миграции
        if (preg_match('#^/webhooks/screenshots$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'POST') {
                try {
                    $data = json_decode($request->getContent(), true);
                    
                    if (!$data) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Неверный формат данных'
                        ], 400, ['Content-Type' => 'application/json; charset=utf-8']);
                    }
                    
                    $mbUuid = $data['mb_uuid'] ?? null;
                    $pageSlug = $data['page_slug'] ?? null;
                    $type = $data['type'] ?? null;
                    $fileContent = $data['file_content'] ?? null;
                    $filename = $data['filename'] ?? null;
                    
                    if (!$mbUuid || !$pageSlug || !$type || !$fileContent) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Отсутствуют обязательные поля: mb_uuid, page_slug, type, file_content'
                        ], 400, ['Content-Type' => 'application/json; charset=utf-8']);
                    }
                    
                    $screenshotService = new \Dashboard\Services\ScreenshotService();
                    $result = $screenshotService->saveScreenshot($mbUuid, $pageSlug, $type, $fileContent, $filename);
                    
                    return new JsonResponse([
                        'success' => true,
                        'data' => $result
                    ], 200, ['Content-Type' => 'application/json; charset=utf-8']);
                } catch (\Throwable $e) {
                    error_log("Error in screenshot webhook: " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Ошибка при сохранении скриншота: ' . $e->getMessage()
                    ], 500, ['Content-Type' => 'application/json; charset=utf-8']);
                }
            }
        }

        // Публичный доступ к скриншотам анализа страницы по токену ревью
        // Формат: /review/wave/:token/migration/:brzProjectId/analysis/:pageSlug/screenshots/:type
        if (preg_match('#^/review/wave/([^/]+)/migration/(\d+)/analysis/([^/]+)/screenshots/(source|migrated)$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                try {
                    $token = $matches[1];
                    $brzProjectId = (int)$matches[2];
                    $pageSlug = urldecode($matches[3]);
                    $type = $matches[4];
                    
                    $reviewService = new WaveReviewService();
                    $waveId = $reviewService->validateToken($token);
                    
                    if (!$waveId) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Недействительный или истекший токен доступа'
                        ], 403, ['Content-Type' => 'application/json; charset=utf-8']);
                    }
                    
                    // Получаем детали миграции по brz_project_id
                    $migrationService = new MigrationService();
                    $migrationDetails = $migrationService->getMigrationDetails($brzProjectId);
                    
                    if (!$migrationDetails) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Миграция не найдена'
                        ], 404, ['Content-Type' => 'application/json; charset=utf-8']);
                    }
                    
                    $mbUuid = $migrationDetails['mapping']['mb_project_uuid'] ?? null;
                    if (!$mbUuid) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Не удалось определить UUID проекта'
                        ], 404, ['Content-Type' => 'application/json; charset=utf-8']);
                    }
                    
                    // Проверяем настройки доступа для проекта
                    $projectAccess = $reviewService->getProjectAccess($token, $mbUuid);
                    
                    // Если есть индивидуальные настройки и проект заблокирован
                    if ($projectAccess && isset($projectAccess['is_active']) && $projectAccess['is_active'] === false) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Доступ к этому проекту ограничен'
                        ], 403, ['Content-Type' => 'application/json; charset=utf-8']);
                    }
                    
                    $screenshotService = new \Dashboard\Services\ScreenshotService();
                    $screenshot = $screenshotService->getScreenshot($mbUuid, $pageSlug, $type);
                    
                    if (!$screenshot || !isset($screenshot['path'])) {
                        http_response_code(404);
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode([
                            'success' => false,
                            'error' => 'Скриншот не найден'
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    
                    $filePath = $screenshot['path'];
                    
                    if (!file_exists($filePath)) {
                        http_response_code(404);
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode([
                            'success' => false,
                            'error' => 'Файл скриншота не найден'
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    
                    // Определяем MIME тип
                    $mimeType = mime_content_type($filePath);
                    if (!$mimeType) {
                        $mimeType = 'image/png'; // По умолчанию PNG
                    }
                    
                    header('Content-Type: ' . $mimeType);
                    header('Content-Length: ' . filesize($filePath));
                    header('Cache-Control: public, max-age=3600');
                    readfile($filePath);
                    exit;
                } catch (\Throwable $e) {
                    error_log("Error in screenshot endpoint: " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Ошибка при получении скриншота: ' . $e->getMessage()
                    ], 500, ['Content-Type' => 'application/json; charset=utf-8']);
                }
            }
        }

        // Прямой доступ к файлам скриншотов через /api/screenshots/
        if (preg_match('#^/api/screenshots/([^/]+)/(.+)$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                try {
                    $mbUuid = $matches[1];
                    $filename = basename($matches[2]);
                    
                    $screenshotService = new \Dashboard\Services\ScreenshotService();
                    $filePath = $screenshotService->getScreenshotFile($mbUuid, $filename);
                    
                    if (!$filePath) {
                        error_log("Screenshot not found: mbUuid=$mbUuid, filename=$filename");
                        http_response_code(404);
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode([
                            'success' => false,
                            'error' => 'Файл скриншота не найден',
                            'debug' => [
                                'mbUuid' => $mbUuid,
                                'filename' => $filename
                            ]
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    
                    if (!file_exists($filePath)) {
                        error_log("Screenshot file does not exist: $filePath (mbUuid=$mbUuid, filename=$filename)");
                        http_response_code(404);
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode([
                            'success' => false,
                            'error' => 'Файл скриншота не найден по пути: ' . $filePath
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    
                    // Определяем MIME тип
                    $mimeType = mime_content_type($filePath);
                    if (!$mimeType) {
                        $mimeType = 'image/png'; // По умолчанию PNG
                    }
                    
                    header('Content-Type: ' . $mimeType);
                    header('Content-Length: ' . filesize($filePath));
                    header('Cache-Control: public, max-age=3600');
                    readfile($filePath);
                    exit;
                } catch (\Throwable $e) {
                    error_log("Error serving screenshot file: " . $e->getMessage());
                    http_response_code(500);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'success' => false,
                        'error' => 'Ошибка при получении файла скриншота'
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }
        }

        // Прямой доступ к файлам скриншотов (старый формат без /api/)
        if (preg_match('#^/screenshots/([^/]+)/(.+)$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                try {
                    $mbUuid = $matches[1];
                    $filename = basename($matches[2]);
                    
                    $screenshotService = new \Dashboard\Services\ScreenshotService();
                    $filePath = $screenshotService->getScreenshotFile($mbUuid, $filename);
                    
                    if (!$filePath || !file_exists($filePath)) {
                        http_response_code(404);
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode([
                            'success' => false,
                            'error' => 'Файл скриншота не найден'
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    
                    // Определяем MIME тип
                    $mimeType = mime_content_type($filePath);
                    if (!$mimeType) {
                        $mimeType = 'image/png'; // По умолчанию PNG
                    }
                    
                    header('Content-Type: ' . $mimeType);
                    header('Content-Length: ' . filesize($filePath));
                    header('Cache-Control: public, max-age=3600');
                    readfile($filePath);
                    exit;
                } catch (\Throwable $e) {
                    error_log("Error serving screenshot file: " . $e->getMessage());
                    http_response_code(500);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'success' => false,
                        'error' => 'Ошибка при получении файла скриншота'
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }
        }

        // Публичный доступ к анализу качества миграции по токену (список страниц)
        // Формат: /review/wave/:token/migration/:brzProjectId/analysis
        if (preg_match('#^/review/wave/([^/]+)/migration/(\d+)/analysis$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                try {
                    $token = $matches[1];
                    $brzProjectId = (int)$matches[2];
                    
                    $reviewService = new WaveReviewService();
                    $waveId = $reviewService->validateToken($token);
                    
                    if (!$waveId) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Недействительный или истекший токен доступа'
                        ], 403);
                    }
                    
                    // Получаем детали миграции по brz_project_id
                    $migrationService = new MigrationService();
                    $migrationDetails = $migrationService->getMigrationDetails($brzProjectId);
                    
                    if (!$migrationDetails) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Миграция не найдена'
                        ], 404);
                    }
                    
                    $mbUuid = $migrationDetails['mapping']['mb_project_uuid'] ?? null;
                    if (!$mbUuid) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Не удалось определить UUID проекта'
                        ], 404);
                    }
                    
                    // Проверяем настройки доступа для проекта
                    $projectAccess = $reviewService->getProjectAccess($token, $mbUuid);
                    
                    // Если есть индивидуальные настройки и проект заблокирован
                    if ($projectAccess && isset($projectAccess['is_active']) && $projectAccess['is_active'] === false) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Доступ к этому проекту ограничен'
                        ], 403);
                    }
                    
                    // Получаем список страниц с анализом качества
                    $qualityService = new QualityAnalysisService();
                    
                    error_log("[Review API] Getting pages list for brzProjectId: {$brzProjectId}");
                    $pages = $qualityService->getPagesList($brzProjectId);
                    
                    error_log("[Review API] Pages list result: " . json_encode([
                        'count' => is_array($pages) ? count($pages) : 0,
                        'is_array' => is_array($pages),
                        'type' => gettype($pages)
                    ]));
                    
                    // Убеждаемся, что $pages - это массив
                    if (!is_array($pages)) {
                        error_log("[Review API] Warning: pages is not an array, type: " . gettype($pages));
                        $pages = [];
                    }
                    
                    return new JsonResponse([
                        'success' => true,
                        'data' => $pages,
                        'count' => count($pages)
                    ], 200, ['Content-Type' => 'application/json; charset=utf-8']);
                } catch (\Throwable $e) {
                    error_log("Error in analysis endpoint: " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Ошибка при получении данных анализа: ' . $e->getMessage()
                    ], 500, ['Content-Type' => 'application/json; charset=utf-8']);
                }
            }
        }

        // Публичный доступ к логам миграции по токену
        // Формат: /review/wave/:token/migration/:brzProjectId/logs
        if (preg_match('#^/review/wave/([^/]+)/migration/(\d+)/logs$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                $token = $matches[1];
                $brzProjectId = (int)$matches[2];
                
                $reviewService = new WaveReviewService();
                $waveId = $reviewService->validateToken($token);
                
                if (!$waveId) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Недействительный или истекший токен доступа'
                    ], 403);
                }
                
                // Получаем детали миграции по brz_project_id
                $migrationService = new MigrationService();
                $migrationDetails = $migrationService->getMigrationDetails($brzProjectId);
                
                if (!$migrationDetails) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Миграция не найдена'
                    ], 404);
                }
                
                $mbUuid = $migrationDetails['mapping']['mb_project_uuid'] ?? null;
                if (!$mbUuid) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Не удалось определить UUID проекта'
                    ], 404);
                }
                
                // Проверяем настройки доступа для проекта
                // Если токен валиден, доступ разрешен по умолчанию
                // Блокируем только если явно установлено is_active = false
                $projectAccess = $reviewService->getProjectAccess($token, $mbUuid);
                
                // Если есть индивидуальные настройки и проект заблокирован
                if ($projectAccess && isset($projectAccess['is_active']) && $projectAccess['is_active'] === false) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Доступ к этому проекту ограничен'
                    ], 403);
                }
                
                // Проверяем, разрешена ли вкладка logs
                // Если нет индивидуальных настроек, все вкладки доступны по умолчанию
                $allowedTabs = $projectAccess && isset($projectAccess['allowed_tabs']) 
                    ? $projectAccess['allowed_tabs'] 
                    : ['overview', 'details', 'logs', 'screenshots', 'quality', 'analysis']; // Все вкладки по умолчанию
                
                // Всегда добавляем 'analysis' в список разрешенных вкладок, если её там нет
                if (!in_array('analysis', $allowedTabs)) {
                    $allowedTabs[] = 'analysis';
                }
                
                if (!in_array('logs', $allowedTabs)) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Доступ к логам не разрешен для этого проекта'
                    ], 403);
                }
                
                // Получаем логи миграции
                $waveController = new WaveController();
                return $waveController->getMigrationLogs($request, $waveId, $mbUuid);
            }
        }

        // Публичный доступ для сохранения ревью проекта
        // Формат: /review/wave/:token/migration/:brzProjectId/review
        if (preg_match('#^/review/wave/([^/]+)/migration/(\d+)/review$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'POST') {
                try {
                    $token = $matches[1];
                    $brzProjectId = (int)$matches[2];
                    
                    $reviewService = new WaveReviewService();
                    $waveId = $reviewService->validateToken($token);
                    
                    if (!$waveId) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Недействительный или истекший токен доступа'
                        ], 403);
                    }
                    
                    // Получаем данные из запроса
                    $data = json_decode($request->getContent(), true);
                    $reviewStatus = $data['review_status'] ?? null;
                    $comment = $data['comment'] ?? null;
                    
                    if (!$reviewStatus) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Статус ревью обязателен'
                        ], 400);
                    }
                    
                    // Сохраняем ревью
                    $result = $reviewService->saveProjectReview($token, $brzProjectId, $reviewStatus, $comment);
                    
                    if ($result) {
                        return new JsonResponse([
                            'success' => true,
                            'message' => 'Ревью успешно сохранено'
                        ], 200);
                    } else {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Не удалось сохранить ревью'
                        ], 500);
                    }
                } catch (Exception $e) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => $e->getMessage()
                    ], 400);
                }
            }
            
            // GET - получить ревью проекта
            if ($request->getMethod() === 'GET') {
                try {
                    $token = $matches[1];
                    $brzProjectId = (int)$matches[2];
                    
                    $reviewService = new WaveReviewService();
                    $waveId = $reviewService->validateToken($token);
                    
                    if (!$waveId) {
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'Недействительный или истекший токен доступа'
                        ], 403);
                    }
                    
                    // Получаем ревью
                    $review = $reviewService->getProjectReview($token, $brzProjectId);
                    
                    return new JsonResponse([
                        'success' => true,
                        'data' => $review
                    ], 200);
                } catch (Exception $e) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => $e->getMessage()
                    ], 400);
                }
            }
        }

        // Авторизация (публичные endpoints)
        if (preg_match('#^/auth/login$#', $apiPath)) {
            error_log("[Router] Auth login route matched");
            if ($request->getMethod() === 'POST') {
                error_log("[Router] POST method confirmed, creating AuthController");
                try {
                    error_log("[Router] Loading AuthController class");
                    if (!class_exists('Dashboard\\Controllers\\AuthController')) {
                        error_log("[Router] ERROR: AuthController class not found!");
                        // Пытаемся загрузить вручную
                        $controllerFile = __DIR__ . '/controllers/AuthController.php';
                        if (file_exists($controllerFile)) {
                            require_once $controllerFile;
                            error_log("[Router] AuthController file loaded manually");
                        } else {
                            error_log("[Router] ERROR: AuthController file not found at: " . $controllerFile);
                        }
                    }
                    $controller = new \Dashboard\Controllers\AuthController();
                    error_log("[Router] AuthController created, calling login method");
                    $response = $controller->login($request);
                    error_log("[Router] AuthController::login returned, type: " . gettype($response));
                    if ($response instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
                        // Подготавливаем ответ перед получением контента
                        $response->prepare($request);
                        $content = $response->getContent();
                        error_log("[Router] Response is JsonResponse, content length: " . strlen($content));
                        
                        // КРИТИЧЕСКАЯ ПРОВЕРКА: ответ не должен быть пустым
                        if (empty($content)) {
                            $errorMsg = "[Router] CRITICAL: AuthController::login returned EMPTY response!";
                            error_log($errorMsg);
                            
                            if ($debugMode) {
                                file_put_contents('php://stderr', "[CRITICAL] $errorMsg\n");
                            }
                            
                            // Создаем ответ с ошибкой вместо пустого ответа
                            $response = new JsonResponse([
                                'success' => false,
                                'error' => 'Empty response from login controller',
                                'debug' => $debugMode ? [
                                    'response_class' => get_class($response),
                                    'headers' => $response->headers->all()
                                ] : null
                            ], 500);
                            $response->prepare($request);
                        } else {
                            error_log("[Router] Response preview: " . substr($content, 0, 100));
                        }
                        
                        // Добавляем CORS заголовки
                        $response->headers->set('Access-Control-Allow-Origin', '*');
                        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
                        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
                    }
                    
                    // Финальная проверка перед возвратом
                    $response->prepare($request);
                    $finalContent = $response->getContent();
                    if (empty($finalContent)) {
                        error_log("[Router] CRITICAL: Final response is empty, creating error response");
                        $response = new JsonResponse([
                            'success' => false,
                            'error' => 'Critical: Empty response detected before return'
                        ], 500);
                    }
                    
                    return $response;
                } catch (\Exception $e) {
                    error_log("[Router] Exception in AuthController::login: " . $e->getMessage());
                    error_log("[Router] Stack trace: " . $e->getTraceAsString());
                    throw $e;
                }
            } else {
                error_log("[Router] Auth login route matched but method is not POST: " . $request->getMethod());
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Method not allowed. Use POST.'
                ], 405);
            }
        }

        if (preg_match('#^/auth/logout$#', $apiPath)) {
            if ($request->getMethod() === 'POST') {
                $controller = new AuthController();
                return $controller->logout($request);
            }
        }

        if (preg_match('#^/auth/check$#', $apiPath)) {
            if ($request->getMethod() === 'GET') {
                $controller = new AuthController();
                return $controller->check($request);
            }
        }

        // Веб-хук для получения результатов миграции (без авторизации, так как вызывается сервером миграции)
        if (preg_match('#^/webhooks/migration-result$#', $apiPath)) {
            if ($request->getMethod() === 'POST') {
                require_once __DIR__ . '/controllers/WebhookController.php';
                $controller = new \Dashboard\Controllers\WebhookController();
                return $controller->migrationResult($request);
            }
        }

        // Проверка авторизации для защищенных endpoints
        $authMiddleware = new AuthMiddleware();
        $authResponse = $authMiddleware->checkAuth($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        // Миграции
        if (preg_match('#^/migrations$#', $apiPath)) {
            $controller = new MigrationController();
            return $controller->list($request);
        }

        if (preg_match('#^/migrations/(\d+)$#', $apiPath, $matches)) {
            $id = (int)$matches[1];
            $controller = new MigrationController();
            
            if ($request->getMethod() === 'GET') {
                return $controller->getDetails($request, $id);
            }
        }

        if (preg_match('#^/migrations/run$#', $apiPath)) {
            if ($request->getMethod() === 'POST') {
                $controller = new MigrationController();
                return $controller->run($request);
            }
        }

        if (preg_match('#^/migrations/(\d+)/restart$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'POST') {
                $id = (int)$matches[1];
                $controller = new MigrationController();
                return $controller->restart($request, $id);
            }
        }

        if (preg_match('#^/migrations/(\d+)/status-from-server$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                $id = (int)$matches[1];
                $controller = new MigrationController();
                return $controller->getStatusFromServer($request, $id);
            }
        }

        if (preg_match('#^/migrations/(\d+)/webhook-info$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                $id = (int)$matches[1];
                $controller = new MigrationController();
                return $controller->getWebhookInfo($request, $id);
            }
        }

        if (preg_match('#^/migrations/(\d+)/lock$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'DELETE') {
                $id = (int)$matches[1];
                $controller = new MigrationController();
                return $controller->removeLock($request, $id);
            }
        }

        if (preg_match('#^/migrations/(\d+)/kill$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'POST') {
                $id = (int)$matches[1];
                $controller = new MigrationController();
                return $controller->killProcess($request, $id);
            }
        }

        if (preg_match('#^/migrations/(\d+)/process$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                $id = (int)$matches[1];
                $controller = new MigrationController();
                return $controller->getProcessInfo($request, $id);
            }
        }

        if (preg_match('#^/migrations/(\d+)/cache$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'DELETE') {
                $id = (int)$matches[1];
                $controller = new MigrationController();
                return $controller->removeCache($request, $id);
            }
        }

        if (preg_match('#^/migrations/(\d+)/cache-all$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'DELETE') {
                $id = (int)$matches[1];
                $controller = new MigrationController();
                return $controller->removeAllCache($request, $id);
            }
        }

        if (preg_match('#^/migrations/(\d+)/reset-status$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'POST') {
                $id = (int)$matches[1];
                $controller = new MigrationController();
                return $controller->resetStatus($request, $id);
            }
        }

        if (preg_match('#^/migrations/(\d+)/hard-reset$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'POST') {
                $id = (int)$matches[1];
                $controller = new MigrationController();
                return $controller->hardReset($request, $id);
            }
        }

        // Анализ качества миграций
        if (preg_match('#^/migrations/(\d+)/quality-analysis$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                $migrationId = (int)$matches[1];
                $controller = new QualityAnalysisController();
                return $controller->getAnalysisList($request, $migrationId);
            }
        }

        if (preg_match('#^/migrations/(\d+)/quality-analysis/statistics$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                $migrationId = (int)$matches[1];
                $controller = new QualityAnalysisController();
                return $controller->getStatistics($request, $migrationId);
            }
        }

        if (preg_match('#^/migrations/(\d+)/pages$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                $migrationId = (int)$matches[1];
                $controller = new QualityAnalysisController();
                return $controller->getPagesList($request, $migrationId);
            }
        }

        if (preg_match('#^/migrations/(\d+)/quality-analysis/archived$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                $migrationId = (int)$matches[1];
                $controller = new QualityAnalysisController();
                return $controller->getArchivedAnalysisList($request, $migrationId);
            }
        }

        if (preg_match('#^/migrations/(\d+)/quality-analysis/([^/]+)$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                $migrationId = (int)$matches[1];
                $pageSlug = urldecode($matches[2]);
                $controller = new QualityAnalysisController();
                return $controller->getPageAnalysis($request, $migrationId, $pageSlug);
            }
        }

        if (preg_match('#^/migrations/(\d+)/quality-analysis/([^/]+)/screenshots/(source|migrated)$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                $migrationId = (int)$matches[1];
                $pageSlug = urldecode($matches[2]);
                $type = $matches[3];
                $controller = new QualityAnalysisController();
                return $controller->getScreenshot($request, $migrationId, $pageSlug, $type);
            }
        }

        if (preg_match('#^/migrations/(\d+)/quality-analysis/([^/]+)/reanalyze$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'POST') {
                error_log("[API] Reanalyze route matched: apiPath={$apiPath}");
                try {
                    $migrationId = (int)$matches[1];
                    $pageSlug = urldecode($matches[2]);
                    error_log("[API] Reanalyze request: migrationId={$migrationId}, pageSlug={$pageSlug}");
                    
                    if (!class_exists('Dashboard\\Controllers\\QualityAnalysisController')) {
                        error_log("[API] QualityAnalysisController class not found!");
                        return new JsonResponse([
                            'success' => false,
                            'error' => 'QualityAnalysisController class not found'
                        ], 500);
                    }
                    
                    error_log("[API] Creating QualityAnalysisController instance...");
                    $controller = new QualityAnalysisController();
                    error_log("[API] Controller created, calling reanalyzePage...");
                    $result = $controller->reanalyzePage($request, $migrationId, $pageSlug);
                    error_log("[API] reanalyzePage returned successfully");
                    return $result;
                } catch (\Throwable $e) {
                    error_log("[API] Fatal error in reanalyze route: " . $e->getMessage());
                    error_log("[API] File: " . $e->getFile() . ", Line: " . $e->getLine());
                    error_log("[API] Stack trace: " . $e->getTraceAsString());
                    return new JsonResponse([
                        'success' => false,
                        'error' => $e->getMessage(),
                        'file' => basename($e->getFile()),
                        'line' => $e->getLine(),
                        'type' => get_class($e)
                    ], 500);
                } catch (\Exception $e) {
                    error_log("[API] Exception in reanalyze route: " . $e->getMessage());
                    error_log("[API] File: " . $e->getFile() . ", Line: " . $e->getLine());
                    return new JsonResponse([
                        'success' => false,
                        'error' => $e->getMessage(),
                        'file' => basename($e->getFile()),
                        'line' => $e->getLine()
                    ], 500);
                }
            }
        }

        if (preg_match('#^/migrations/(\d+)/logs$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                try {
                    $id = (int)$matches[1];
                    error_log("[API] Get migration logs request: migrationId={$id}");
                    $controller = new MigrationController();
                    return $controller->getMigrationLogs($request, $id);
                } catch (\Throwable $e) {
                    error_log("[API] Fatal error in get migration logs route: " . $e->getMessage());
                    error_log("[API] Stack trace: " . $e->getTraceAsString());
                    return new JsonResponse([
                        'success' => false,
                        'error' => $e->getMessage(),
                        'file' => basename($e->getFile()),
                        'line' => $e->getLine(),
                        'type' => get_class($e)
                    ], 500);
                }
            }
        }

        if (preg_match('#^/migrations/(\d+)/rebuild-page$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'POST') {
                $id = (int)$matches[1];
                $controller = new MigrationController();
                return $controller->rebuildPage($request, $id);
            }
        }

        if (preg_match('#^/migrations/(\d+)/rebuild-page-no-analysis$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'POST') {
                try {
                    $id = (int)$matches[1];
                    error_log("[API] Rebuild page (no analysis) request: migrationId={$id}");
                    $controller = new MigrationController();
                    return $controller->rebuildPageNoAnalysis($request, $id);
                } catch (\Throwable $e) {
                    error_log("[API] Fatal error in rebuild-page-no-analysis route: " . $e->getMessage());
                    error_log("[API] Stack trace: " . $e->getTraceAsString());
                    return new JsonResponse([
                        'success' => false,
                        'error' => $e->getMessage(),
                        'file' => basename($e->getFile()),
                        'line' => $e->getLine()
                    ], 500);
                }
            }
        }

        // Прямой доступ к скриншотам по имени файла (без mb_uuid в URL)
        if (preg_match('#^/screenshots/(.+)$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                $filename = basename($matches[1]);
                // Корень проекта: при пустом projectRoot используем каталог этого файла (src/index.php)
                $root = $projectRoot;
                if ($root === null || $root === '') {
                    $root = dirname(__DIR__);
                }
                if (is_dir($root)) {
                    $resolved = realpath($root);
                    if ($resolved !== false) {
                        $root = $resolved;
                    }
                }
                $screenshotsWebhookDir = rtrim($root, '/') . '/var/screenshots/';
                $screenshotsTmpDir = rtrim($root, '/') . '/var/tmp/';

                $found = false;
                $filePath = null;
                $checkedPaths = [];

                // Всегда добавляем запасной корень по расположению src/index.php (работает при projectRoot=null)
                $searchDirs = [$screenshotsWebhookDir];
                $altRoot = dirname(__DIR__);
                if ($altRoot !== $root && $altRoot !== null && $altRoot !== '') {
                    $altResolved = is_dir($altRoot) ? realpath($altRoot) : false;
                    if ($altResolved !== false) {
                        $altScreenshots = rtrim($altResolved, '/') . '/var/screenshots/';
                        if (!in_array($altScreenshots, $searchDirs, true)) {
                            $searchDirs[] = $altScreenshots;
                        }
                    }
                }

                // 1) Ищем в var/screenshots/{mb_uuid}/ (файлы, загруженные через webhook)
                foreach ($searchDirs as $searchDir) {
                    if (!is_dir($searchDir)) {
                        continue;
                    }
                    $uuidDirs = glob($searchDir . '*', GLOB_ONLYDIR) ?: [];
                    foreach ($uuidDirs as $dir) {
                        $potentialPath = $dir . '/' . $filename;
                        $checkedPaths[] = $potentialPath;
                        if (file_exists($potentialPath)) {
                            $filePath = $potentialPath;
                            $found = true;
                            break 2;
                        }
                        $baseWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
                        $altMatches = glob($dir . '/' . $baseWithoutExt . '.*');
                        if (!empty($altMatches) && file_exists($altMatches[0])) {
                            $filePath = $altMatches[0];
                            $found = true;
                            break 2;
                        }
                    }
                }

                // 2) Ищем в var/tmp/project_*/
                if (!$found && is_dir($screenshotsTmpDir)) {
                    $dirs = glob($screenshotsTmpDir . 'project_*', GLOB_ONLYDIR);
                    foreach ($dirs as $dir) {
                        $potentialPath = $dir . '/' . $filename;
                        if (file_exists($potentialPath)) {
                            $filePath = $potentialPath;
                            $found = true;
                            break;
                        }
                    }
                }

                // 3) Корень var/tmp/ (для старых записей)
                if (!$found && is_dir($screenshotsTmpDir)) {
                    $rootPath = $screenshotsTmpDir . $filename;
                    if (file_exists($rootPath)) {
                        $filePath = $rootPath;
                        $found = true;
                    }
                }

                if (!$found || !$filePath) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Скриншот не найден: ' . $filename,
                        'debug' => $debugMode ? [
                            'filename' => $filename,
                            'screenshots_webhook_dir' => $screenshotsWebhookDir,
                            'screenshots_tmp_dir' => $screenshotsTmpDir,
                            'project_root' => $root,
                            'webhook_dir_exists' => is_dir($screenshotsWebhookDir),
                            'webhook_subdirs' => is_dir($screenshotsWebhookDir) ? glob($screenshotsWebhookDir . '*', GLOB_ONLYDIR) : [],
                            'checked_paths' => $checkedPaths,
                            'hint' => 'Файл отсутствует в перечисленных путях. Загрузите скриншот через webhook или проверьте, что отчёт ссылается на существующий файл.',
                        ] : null,
                    ], 404);
                }
                
                // Определяем MIME тип
                $mimeType = mime_content_type($filePath);
                if (!$mimeType) {
                    $mimeType = 'image/png';
                }
                
                // Возвращаем файл
                $response = new Response(file_get_contents($filePath), 200);
                $response->headers->set('Content-Type', $mimeType);
                $response->headers->set('Content-Disposition', 'inline; filename="' . $filename . '"');
                $response->headers->set('Cache-Control', 'public, max-age=3600');
                return $response;
            }
        }

        // Логи
        if (preg_match('#^/logs/(\d+)$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                $brzProjectId = (int)$matches[1];
                $controller = new LogController();
                return $controller->getLogs($request, $brzProjectId);
            }
        }

        if (preg_match('#^/logs/recent$#', $apiPath)) {
            if ($request->getMethod() === 'GET') {
                $controller = new LogController();
                return $controller->getRecent($request);
            }
        }

        // Настройки
        if (preg_match('#^/settings$#', $apiPath)) {
            $controller = new \Dashboard\Controllers\SettingsController();
            if ($request->getMethod() === 'GET') {
                return $controller->get($request);
            }
            if ($request->getMethod() === 'POST') {
                return $controller->save($request);
            }
        }

        // Google Sheets
        if (preg_match('#^/google-sheets/connect$#', $apiPath)) {
            if ($request->getMethod() === 'POST') {
                $controller = new \Dashboard\Controllers\GoogleSheetsController();
                return $controller->connect($request);
            }
        }

        if (preg_match('#^/google-sheets/list$#', $apiPath)) {
            if ($request->getMethod() === 'GET') {
                $controller = new \Dashboard\Controllers\GoogleSheetsController();
                return $controller->list($request);
            }
        }

        if (preg_match('#^/google-sheets/oauth/authorize$#', $apiPath)) {
            if ($request->getMethod() === 'GET') {
                $controller = new \Dashboard\Controllers\GoogleSheetsController();
                return $controller->oauthAuthorize($request);
            }
        }

        if (preg_match('#^/google-sheets/oauth/callback$#', $apiPath)) {
            if ($request->getMethod() === 'GET') {
                $controller = new \Dashboard\Controllers\GoogleSheetsController();
                return $controller->oauthCallback($request);
            }
        }

        if (preg_match('#^/google-sheets/oauth/status$#', $apiPath)) {
            if ($request->getMethod() === 'GET') {
                $controller = new \Dashboard\Controllers\GoogleSheetsController();
                return $controller->oauthStatus($request);
            }
        }

        if (preg_match('#^/google-sheets/link-wave$#', $apiPath)) {
            if ($request->getMethod() === 'POST') {
                $controller = new \Dashboard\Controllers\GoogleSheetsController();
                return $controller->linkWave($request);
            }
        }

        if (preg_match('#^/google-sheets/sync/(\d+)$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'POST') {
                $id = (int)$matches[1];
                $controller = new \Dashboard\Controllers\GoogleSheetsController();
                return $controller->sync($request, $id);
            }
        }

        if (preg_match('#^/google-sheets/sheets/([^/]+)$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                $spreadsheetId = $matches[1];
                $controller = new \Dashboard\Controllers\GoogleSheetsController();
                return $controller->getSheets($request, $spreadsheetId);
            }
        }

        if (preg_match('#^/google-sheets/(\d+)$#', $apiPath, $matches)) {
            $id = (int)$matches[1];
            $controller = new \Dashboard\Controllers\GoogleSheetsController();
            if ($request->getMethod() === 'GET') {
                return $controller->get($request, $id);
            }
            if ($request->getMethod() === 'DELETE') {
                return $controller->delete($request, $id);
            }
        }

        // Волны миграций
        if (preg_match('#^/waves$#', $apiPath)) {
            $controller = new WaveController();
            if ($request->getMethod() === 'GET') {
                return $controller->list($request);
            }
            if ($request->getMethod() === 'POST') {
                return $controller->create($request);
            }
        }

        if (preg_match('#^/waves/([^/]+)$#', $apiPath, $matches)) {
            $waveId = $matches[1];
            $controller = new WaveController();
            
            if ($request->getMethod() === 'GET') {
                return $controller->getDetails($request, $waveId);
            }
        }

        if (preg_match('#^/waves/([^/]+)/status$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                $waveId = $matches[1];
                $controller = new WaveController();
                return $controller->getStatus($waveId);
            }
        }

        if (preg_match('#^/waves/([^/]+)/reset-status$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'POST') {
                $waveId = $matches[1];
                $controller = new WaveController();
                return $controller->resetStatus($request, $waveId);
            }
        }

        if (preg_match('#^/waves/([^/]+)/restart-all$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'POST') {
                $waveId = $matches[1];
                $controller = new WaveController();
                return $controller->restartAllMigrations($request, $waveId);
            }
        }

        if (preg_match('#^/waves/([^/]+)/migrations/([^/]+)/restart$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'POST') {
                $waveId = $matches[1];
                $mbUuid = $matches[2];
                $controller = new WaveController();
                return $controller->restartMigration($request, $waveId, $mbUuid);
            }
        }

        if (preg_match('#^/waves/([^/]+)/logs$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                try {
                    $waveId = $matches[1];
                    error_log("[API] Get wave logs request: waveId={$waveId}");
                    $controller = new WaveController();
                    return $controller->getWaveLogs($request, $waveId);
                } catch (\Throwable $e) {
                    error_log("[API] Fatal error in get wave logs route: " . $e->getMessage());
                    error_log("[API] Stack trace: " . $e->getTraceAsString());
                    return new JsonResponse([
                        'success' => false,
                        'error' => $e->getMessage(),
                        'file' => basename($e->getFile()),
                        'line' => $e->getLine(),
                        'type' => get_class($e)
                    ], 500);
                }
            }
        }

        if (preg_match('#^/waves/([^/]+)/migrations/([^/]+)/logs$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                try {
                    $waveId = $matches[1];
                    $mbUuid = $matches[2];
                    error_log("[API] Get wave migration logs request: waveId={$waveId}, mbUuid={$mbUuid}");
                    $controller = new WaveController();
                    return $controller->getMigrationLogs($request, $waveId, $mbUuid);
                } catch (\Throwable $e) {
                    error_log("[API] Fatal error in get wave migration logs route: " . $e->getMessage());
                    error_log("[API] Stack trace: " . $e->getTraceAsString());
                    return new JsonResponse([
                        'success' => false,
                        'error' => $e->getMessage(),
                        'file' => basename($e->getFile()),
                        'line' => $e->getLine(),
                        'type' => get_class($e)
                    ], 500);
                }
            }
        }

        // Новый endpoint для получения логов проекта в волне по brz_project_id
        if (preg_match('#^/waves/([^/]+)/projects/(\d+)/logs$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                try {
                    $waveId = $matches[1];
                    $brzProjectId = (int)$matches[2];
                    error_log("[API] Get wave project logs request: waveId={$waveId}, brzProjectId={$brzProjectId}");
                    $controller = new WaveController();
                    return $controller->getProjectLogs($request, $waveId, $brzProjectId);
                } catch (\Throwable $e) {
                    error_log("[API] Fatal error in get wave project logs route: " . $e->getMessage());
                    error_log("[API] Stack trace: " . $e->getTraceAsString());
                    return new JsonResponse([
                        'success' => false,
                        'error' => $e->getMessage(),
                        'file' => basename($e->getFile()),
                        'line' => $e->getLine(),
                        'type' => get_class($e)
                    ], 500);
                }
            }
        }

        if (preg_match('#^/waves/([^/]+)/migrations/([^/]+)/lock$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'DELETE') {
                $waveId = $matches[1];
                $mbUuid = $matches[2];
                $controller = new WaveController();
                return $controller->removeMigrationLock($request, $waveId, $mbUuid);
            }
        }

        if (preg_match('#^/waves/([^/]+)/mapping$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                $waveId = $matches[1];
                $controller = new WaveController();
                return $controller->getMapping($waveId);
            }
        }

        if (preg_match('#^/waves/([^/]+)/mapping/(\d+)/cloning$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'PUT') {
                $waveId = $matches[1];
                $brzProjectId = (int)$matches[2];
                $controller = new WaveController();
                return $controller->toggleCloning($request, $waveId, $brzProjectId);
            }
        }

        if (preg_match('#^/waves/([^/]+)/review-token$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'POST') {
                $waveId = $matches[1];
                $controller = new WaveController();
                return $controller->createReviewToken($request, $waveId);
            }
        }

        if (preg_match('#^/waves/([^/]+)/review-tokens/(\d+)/projects/([^/]+)$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'PUT') {
                $waveId = $matches[1];
                $tokenId = (int)$matches[2];
                $mbUuid = $matches[3];
                $controller = new WaveController();
                return $controller->updateProjectAccess($request, $waveId, $tokenId, $mbUuid);
            }
        }

        if (preg_match('#^/waves/([^/]+)/review-tokens/(\d+)$#', $apiPath, $matches)) {
            $waveId = $matches[1];
            $tokenId = (int)$matches[2];
            $controller = new WaveController();
            
            if ($request->getMethod() === 'PUT') {
                return $controller->updateReviewToken($request, $waveId, $tokenId);
            }
            if ($request->getMethod() === 'DELETE') {
                return $controller->deleteReviewToken($request, $waveId, $tokenId);
            }
        }

        if (preg_match('#^/waves/([^/]+)/review-tokens$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                $waveId = $matches[1];
                $controller = new WaveController();
                return $controller->getReviewTokens($request, $waveId);
            }
        }

        // Тестовые миграции
        if (preg_match('#^/test-migrations$#', $apiPath)) {
            $controller = new TestMigrationController();
            if ($request->getMethod() === 'GET') {
                return $controller->list($request);
            }
            if ($request->getMethod() === 'POST') {
                return $controller->create($request);
            }
        }

        if (preg_match('#^/test-migrations/(\d+)$#', $apiPath, $matches)) {
            $id = (int)$matches[1];
            $controller = new TestMigrationController();
            
            if ($request->getMethod() === 'GET') {
                return $controller->getDetails($request, $id);
            }
            if ($request->getMethod() === 'PUT') {
                return $controller->update($request, $id);
            }
            if ($request->getMethod() === 'DELETE') {
                return $controller->delete($request, $id);
            }
        }

        if (preg_match('#^/test-migrations/(\d+)/run$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'POST') {
                $id = (int)$matches[1];
                $controller = new TestMigrationController();
                return $controller->run($request, $id);
            }
        }

        if (preg_match('#^/test-migrations/(\d+)/reset-status$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'POST') {
                $id = (int)$matches[1];
                $controller = new TestMigrationController();
                return $controller->resetStatus($request, $id);
            }
        }

        // Управление пользователями (требует разрешение users.manage)
        if (preg_match('#^/users$#', $apiPath)) {
            $permissionMiddleware = new PermissionMiddleware();
            $permissionCheck = $permissionMiddleware->checkPermission($request, 'users', 'view');
            if ($permissionCheck !== null) {
                return $permissionCheck;
            }

            $controller = new UserController();
            if ($request->getMethod() === 'GET') {
                return $controller->list($request);
            }
            if ($request->getMethod() === 'POST') {
                $permissionCheck = $permissionMiddleware->checkPermission($request, 'users', 'create');
                if ($permissionCheck !== null) {
                    return $permissionCheck;
                }
                return $controller->create($request);
            }
        }

        if (preg_match('#^/users/(\d+)$#', $apiPath, $matches)) {
            $permissionMiddleware = new PermissionMiddleware();
            $permissionCheck = $permissionMiddleware->checkPermission($request, 'users', 'view');
            if ($permissionCheck !== null) {
                return $permissionCheck;
            }

            $id = (int)$matches[1];
            $controller = new UserController();
            
            if ($request->getMethod() === 'GET') {
                return $controller->getDetails($request, $id);
            }
            if ($request->getMethod() === 'PUT') {
                $permissionCheck = $permissionMiddleware->checkPermission($request, 'users', 'edit');
                if ($permissionCheck !== null) {
                    return $permissionCheck;
                }
                return $controller->update($request, $id);
            }
            if ($request->getMethod() === 'DELETE') {
                $permissionCheck = $permissionMiddleware->checkPermission($request, 'users', 'delete');
                if ($permissionCheck !== null) {
                    return $permissionCheck;
                }
                return $controller->delete($request, $id);
            }
        }

        if (preg_match('#^/users/roles$#', $apiPath)) {
            if ($request->getMethod() === 'GET') {
                $permissionMiddleware = new PermissionMiddleware();
                $permissionCheck = $permissionMiddleware->checkPermission($request, 'users', 'view');
                if ($permissionCheck !== null) {
                    return $permissionCheck;
                }
                $controller = new UserController();
                return $controller->getRoles($request);
            }
        }

        if (preg_match('#^/users/permissions$#', $apiPath)) {
            if ($request->getMethod() === 'GET') {
                $permissionMiddleware = new PermissionMiddleware();
                $permissionCheck = $permissionMiddleware->checkPermission($request, 'users', 'view');
                if ($permissionCheck !== null) {
                    return $permissionCheck;
                }
                $controller = new UserController();
                return $controller->getPermissions($request);
            }
        }

        if (preg_match('#^/users/(\d+)/permissions$#', $apiPath, $matches)) {
            if ($request->getMethod() === 'GET') {
                $permissionMiddleware = new PermissionMiddleware();
                $permissionCheck = $permissionMiddleware->checkPermission($request, 'users', 'view');
                if ($permissionCheck !== null) {
                    return $permissionCheck;
                }
                $id = (int)$matches[1];
                $controller = new UserController();
                return $controller->getUserPermissions($request, $id);
            }
        }

        // Если не найден маршрут
        return new JsonResponse([
            'error' => 'Endpoint not found',
            'path' => $apiPath,
            'method' => $request->getMethod(),
            'available_endpoints' => [
                'GET /health',
                'GET /migrations',
                'GET /migrations/:id',
                'POST /migrations/run',
                'POST /migrations/:id/restart',
                'GET /migrations/:id/status',
                'GET /logs/:brz_project_id',
                'GET /logs/recent',
                'GET/POST /settings',
                'GET/POST /waves',
                'GET /waves/:id',
                'GET /waves/:id/status',
                'GET /waves/:id/mapping',
                'PUT /waves/:id/mapping/:brz_project_id/cloning',
                'POST /waves/:id/migrations/:mb_uuid/restart',
                'GET /waves/:id/migrations/:mb_uuid/logs',
                'GET /waves/:id/projects/:brz_project_id/logs',
                'GET /test-migrations',
                'POST /test-migrations',
                'GET /test-migrations/:id',
                'PUT /test-migrations/:id',
                'DELETE /test-migrations/:id',
                'POST /test-migrations/:id/run',
            ]
        ], 404);

    } catch (\Throwable $e) {
        $errorMsg = "[Router] Fatal error: " . $e->getMessage();
        $trace = $e->getTraceAsString();
        
        error_log($errorMsg);
        error_log("[Router] File: " . $e->getFile() . ", Line: " . $e->getLine());
        error_log("[Router] Stack trace: " . $trace);
        
        if ($debugMode) {
            file_put_contents('php://stderr', "[FATAL ERROR] $errorMsg\n");
            file_put_contents('php://stderr', "[TRACE] $trace\n");
        }
        
        $errorResponse = formatErrorForResponse(
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $trace,
            $debugMode
        );
        $errorResponse['type'] = get_class($e);
        
        $response = new JsonResponse($errorResponse, 500);
        
        // Проверяем, что ответ не пустой
        $response->prepare($request ?? \Symfony\Component\HttpFoundation\Request::createFromGlobals());
        $content = $response->getContent();
        if (empty($content)) {
            $response = new JsonResponse([
                'success' => false,
                'error' => 'Critical: Failed to generate error response',
                'original_error' => $e->getMessage()
            ], 500);
        }
        
        return $response;
    } catch (\Exception $e) {
        $errorMsg = "[Router] Exception: " . $e->getMessage();
        $trace = $e->getTraceAsString();
        
        error_log($errorMsg);
        error_log("[Router] File: " . $e->getFile() . ", Line: " . $e->getLine());
        error_log("[Router] Stack trace: " . $trace);
        
        if ($debugMode) {
            file_put_contents('php://stderr', "[EXCEPTION] $errorMsg\n");
            file_put_contents('php://stderr', "[TRACE] $trace\n");
        }
        
        $errorResponse = formatErrorForResponse(
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $trace,
            $debugMode
        );
        
        $response = new JsonResponse($errorResponse, 500);
        
        // Проверяем, что ответ не пустой
        $response->prepare($request ?? \Symfony\Component\HttpFoundation\Request::createFromGlobals());
        $content = $response->getContent();
        if (empty($content)) {
            $response = new JsonResponse([
                'success' => false,
                'error' => 'Critical: Failed to generate error response',
                'original_error' => $e->getMessage()
            ], 500);
        }
        
        return $response;
    }
};

// Если файл вызывается напрямую через FastCGI (не через require), выполняем роутер
if (php_sapi_name() !== 'cli' && !defined('ROUTER_LOADED')) {
    define('ROUTER_LOADED', true);
    $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
    $router = require __FILE__;
    if (is_callable($router)) {
        $response = $router([], $request);
        if ($response instanceof \Symfony\Component\HttpFoundation\Response) {
            $response->send();
            exit;
        }
    }
}
