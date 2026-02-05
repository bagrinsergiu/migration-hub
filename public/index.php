<?php
/**
 * Dashboard Public Entry Point
 * Доступен по адресу: http://localhost:8088/
 */

// Регистрируем обработчик фатальных ошибок ПЕРЕД всем остальным
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_RECOVERABLE_ERROR])) {
        // Отключаем буферизацию
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Отправляем JSON ответ с ошибкой
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
        }
        
        echo json_encode([
            'success' => false,
            'error' => 'Fatal error: ' . $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line'],
            'type' => 'FatalError'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
});

// Определяем корень проекта
$projectRoot = dirname(__DIR__);

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
             isset($_GET['debug']) && $_GET['debug'] === '1';

// Настраиваем вывод ошибок в зависимости от режима
if ($debugMode) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL | E_STRICT);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
}
ini_set('log_errors', '1');

// Функция для безопасного форматирования ошибки для JSON
// Проверяем, что функция еще не объявлена (на случай, если public/index.php загружается повторно)
if (!function_exists('formatErrorForResponse')) {
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

// Устанавливаем обработчик ошибок
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

// Инициализация Config
\MBMigration\Core\Config::initializeFromEnv();

// Проверяем, запрашивается ли API
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$pathInfo = parse_url($requestUri, PHP_URL_PATH);

// Если запрос к API, перенаправляем в src/index.php
if (strpos($pathInfo, '/api') === 0) {
    try {
        error_log("API request detected: " . $pathInfo);
        
        // Создаем Request объект для передачи в роутер
        try {
            $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
            error_log("Request created: Method=" . $request->getMethod() . ", PathInfo=" . $request->getPathInfo());
        } catch (\Throwable $e) {
            error_log("CRITICAL: Failed to create Request: " . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'Failed to create request object',
                'debug' => $debugMode ? ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()] : null
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Загружаем роутер (он возвращает функцию)
        try {
            $router = require $projectRoot . '/src/index.php';
        } catch (\Throwable $e) {
            error_log("CRITICAL: Failed to load router: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'Failed to load router',
                'debug' => $debugMode ? [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => explode("\n", $e->getTraceAsString())
                ] : null
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        if (!is_callable($router)) {
            throw new \Exception('Router is not callable. src/index.php must return a callable function.');
        }
        
        error_log("Router loaded and callable");
        file_put_contents('php://stderr', "Router loaded and callable\n");
        
        // Вызываем роутер с контекстом и запросом
        file_put_contents('php://stderr', "Calling router function\n");
        error_log("Calling router function");
        
        try {
            $response = $router([], $request);
        } catch (\Throwable $e) {
            error_log("CRITICAL: Router execution failed: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            
            if ($debugMode) {
                file_put_contents('php://stderr', "[CRITICAL] Router execution failed: " . $e->getMessage() . "\n");
            }
            
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
            echo json_encode([
                'success' => false,
                'error' => 'Router execution failed: ' . $e->getMessage(),
                'debug' => $debugMode ? [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => explode("\n", $e->getTraceAsString())
                ] : null
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        file_put_contents('php://stderr', "Router executed, response type: " . gettype($response) . "\n");
        error_log("Router executed, response type: " . gettype($response));
        
        if (!$response instanceof \Symfony\Component\HttpFoundation\Response) {
            throw new \Exception('Router must return a Response object. Got: ' . gettype($response));
        }
        
        // Отключаем буферизацию вывода перед отправкой ответа
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Убеждаемся, что нет буферизации вывода ПЕРЕД получением контента
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Подготавливаем ответ перед получением контента (важно для JsonResponse)
        $response->prepare($request);
        
        // Логируем для отладки
        $content = $response->getContent();
        $contentType = $response->headers->get('Content-Type');
        $contentLength = strlen($content);
        
        file_put_contents('php://stderr', "API Response: Status=" . $response->getStatusCode() . ", Content-Type=" . ($contentType ?: 'not set') . ", Content-Length=" . $contentLength . "\n");
        error_log("API Response: Status=" . $response->getStatusCode() . ", Content-Type=" . ($contentType ?: 'not set') . ", Content-Length=" . $contentLength);
        
        if ($contentLength > 0) {
            file_put_contents('php://stderr', "Response preview: " . substr($content, 0, 100) . "\n");
        } else {
            file_put_contents('php://stderr', "WARNING: Response content is EMPTY!\n");
            error_log("WARNING: Response content is empty but status is " . $response->getStatusCode());
            error_log("WARNING: Response class: " . get_class($response));
            error_log("WARNING: Response headers: " . json_encode($response->headers->all()));
        }
        
        // Убеждаемся, что Content-Type установлен правильно для JSON ответов
        if ($response instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
            if (!$contentType || strpos($contentType, 'application/json') === false) {
                $response->headers->set('Content-Type', 'application/json');
                file_put_contents('php://stderr', "Content-Type set to application/json\n");
            }
        }
        
        // Проверяем, что контент не пустой - КРИТИЧЕСКАЯ ПРОВЕРКА
        if (empty($content)) {
            $statusCode = $response->getStatusCode();
            $errorMsg = "CRITICAL: Response content is EMPTY! Status: $statusCode";
            
            error_log($errorMsg);
            error_log("Response class: " . get_class($response));
            error_log("Response headers: " . json_encode($response->headers->all()));
            
            if ($debugMode) {
                file_put_contents('php://stderr', "[CRITICAL] $errorMsg\n");
                file_put_contents('php://stderr', "[DEBUG] Response class: " . get_class($response) . "\n");
                file_put_contents('php://stderr', "[DEBUG] Headers: " . json_encode($response->headers->all()) . "\n");
            }
            
            // Создаем ответ с ошибкой, НИКОГДА не возвращаем пустой ответ
            $errorData = [
                'success' => false,
                'error' => 'Empty response from controller',
                'status_code' => $statusCode,
                'response_class' => get_class($response)
            ];
            
            if ($debugMode) {
                $errorData['debug'] = [
                    'headers' => $response->headers->all(),
                    'timestamp' => date('Y-m-d H:i:s'),
                    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
                ];
            }
            
            $response = new \Symfony\Component\HttpFoundation\JsonResponse($errorData, 500);
            $response->prepare($request);
            $content = $response->getContent();
            
            // Двойная проверка - если все еще пусто, создаем минимальный ответ
            if (empty($content)) {
                $minimalResponse = json_encode([
                    'success' => false,
                    'error' => 'Critical: Failed to generate error response'
                ], JSON_UNESCAPED_UNICODE);
                $response = new \Symfony\Component\HttpFoundation\Response($minimalResponse, 500, [
                    'Content-Type' => 'application/json; charset=utf-8'
                ]);
                $response->prepare($request);
                $content = $response->getContent();
            }
        }
        
        // Добавляем CORS заголовки к ответу
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        
        file_put_contents('php://stderr', "Sending response, final content length: " . strlen($content) . "\n");
        
        // ФИНАЛЬНАЯ ПРОВЕРКА: ответ не должен быть пустым перед отправкой
        if (empty($content)) {
            $errorMsg = "CRITICAL: Response is empty right before send()!";
            error_log($errorMsg);
            
            if ($debugMode) {
                file_put_contents('php://stderr', "[CRITICAL] $errorMsg\n");
                file_put_contents('php://stderr', "[DEBUG] Response class: " . get_class($response) . "\n");
                file_put_contents('php://stderr', "[DEBUG] Status code: " . $response->getStatusCode() . "\n");
                file_put_contents('php://stderr', "[DEBUG] Headers: " . json_encode($response->headers->all()) . "\n");
            }
            
            // Создаем минимальный ответ
            $response = new \Symfony\Component\HttpFoundation\JsonResponse([
                'success' => false,
                'error' => 'Critical: Empty response detected before send',
                'debug' => $debugMode ? [
                    'response_class' => get_class($response),
                    'status_code' => $response->getStatusCode(),
                    'headers' => $response->headers->all(),
                    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
                ] : null
            ], 500);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            $response->prepare($request);
            $content = $response->getContent();
            
            if ($debugMode) {
                file_put_contents('php://stderr', "[DEBUG] New response content length: " . strlen($content) . "\n");
                file_put_contents('php://stderr', "[DEBUG] New response content: " . substr($content, 0, 200) . "\n");
            }
            
            // Если все еще пусто, отправляем вручную
            if (empty($content)) {
                error_log("CRITICAL: Even error response is empty! Sending manually.");
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                header('Access-Control-Allow-Origin: *');
                echo json_encode([
                    'success' => false,
                    'error' => 'Critical: Failed to generate any response',
                    'debug' => $debugMode ? ['timestamp' => date('Y-m-d H:i:s')] : null
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        
        // В режиме дебага проверяем контент перед отправкой
        if ($debugMode) {
            file_put_contents('php://stderr', "[DEBUG] Before send() - Content length: " . strlen($content) . "\n");
            file_put_contents('php://stderr', "[DEBUG] Before send() - Content preview: " . substr($content, 0, 100) . "\n");
            file_put_contents('php://stderr', "[DEBUG] Before send() - Status code: " . $response->getStatusCode() . "\n");
            file_put_contents('php://stderr', "[DEBUG] Before send() - Content-Type: " . $response->headers->get('Content-Type') . "\n");
        }
        
        // Используем стандартный метод send() для отправки ответа
        // Это гарантирует правильную отправку всех заголовков и cookies
        try {
            // Дополнительная проверка перед send()
            $contentBeforeSend = $response->getContent();
            if (empty($contentBeforeSend)) {
                error_log("CRITICAL: Content is empty right before send()!");
                if ($debugMode) {
                    file_put_contents('php://stderr', "[CRITICAL] Content is empty right before send()!\n");
                }
                // Отправляем вручную
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                header('Access-Control-Allow-Origin: *');
                echo json_encode([
                    'success' => false,
                    'error' => 'Critical: Response content is empty before send',
                    'debug' => $debugMode ? ['timestamp' => date('Y-m-d H:i:s')] : null
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            $response->send();
            
            if ($debugMode) {
                file_put_contents('php://stderr', "[DEBUG] send() completed successfully\n");
            }
        } catch (\Throwable $sendError) {
            // Если даже send() падает, отправляем ответ вручную
            error_log("CRITICAL: send() failed: " . $sendError->getMessage());
            error_log("Stack trace: " . $sendError->getTraceAsString());
            
            if ($debugMode) {
                file_put_contents('php://stderr', "[CRITICAL] send() failed: " . $sendError->getMessage() . "\n");
                file_put_contents('php://stderr', "[TRACE] " . $sendError->getTraceAsString() . "\n");
            }
            
            // Отключаем буферизацию
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            
            http_response_code($response->getStatusCode() ?: 500);
            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');
            
            $finalContent = $response->getContent();
            if (empty($finalContent)) {
                $finalContent = json_encode([
                    'success' => false,
                    'error' => 'Critical: Failed to send response',
                    'original_error' => $sendError->getMessage(),
                    'debug' => $debugMode ? [
                        'timestamp' => date('Y-m-d H:i:s'),
                        'trace' => explode("\n", $sendError->getTraceAsString())
                    ] : null
                ], JSON_UNESCAPED_UNICODE);
            }
            
            echo $finalContent;
            flush();
        }
        exit;
    } catch (\Throwable $e) {
        $errorMsg = "API routing error: " . $e->getMessage();
        $trace = $e->getTraceAsString();
        
        error_log($errorMsg);
        error_log("Stack trace: " . $trace);
        
        if ($debugMode) {
            file_put_contents('php://stderr', "[FATAL ERROR] $errorMsg\n");
            file_put_contents('php://stderr', "[TRACE] $trace\n");
        }
        
        // Отключаем буферизацию
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        
        $errorResponse = formatErrorForResponse(
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $trace,
            $debugMode
        );
        $errorResponse['type'] = get_class($e);
        
        $jsonResponse = json_encode($errorResponse, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        // Проверяем, что ответ не пустой
        if (empty($jsonResponse)) {
            $jsonResponse = json_encode([
                'success' => false,
                'error' => 'Critical error: Failed to encode error response',
                'original_error' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        
        // Дополнительная проверка перед выводом
        if (empty($jsonResponse)) {
            // Последняя попытка - минимальный ответ
            $jsonResponse = '{"success":false,"error":"Critical: Unable to generate any response"}';
        }
        
        // Убеждаемся, что заголовки отправлены
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');
        }
        
        echo $jsonResponse;
        flush();
        exit;
    }
}

// Если запрос к публичному ревью (без авторизации)
// Формат: /review/:token или /review/:token/project/:brzProjectId
if (preg_match('#^/review/([^/]+)(?:/project/(\d+))?$#', $pathInfo, $matches)) {
    $indexHtmlPath = $projectRoot . '/frontend/dist/index.html';
    if (file_exists($indexHtmlPath)) {
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-cache, must-revalidate');
        echo file_get_contents($indexHtmlPath);
        exit;
    }
}

// Если запрос к статическим файлам фронтенда
$distPath = $projectRoot . '/frontend/dist';
if (file_exists($distPath) && is_dir($distPath)) {
    if (preg_match('#^/assets/#', $pathInfo)) {
        $filePath = preg_replace('#^/#', '', $pathInfo);
        $staticFile = $distPath . '/' . $filePath;
        
        if (file_exists($staticFile) && is_file($staticFile)) {
            $mimeTypes = [
                'js' => 'application/javascript',
                'mjs' => 'application/javascript',
                'css' => 'text/css',
                'json' => 'application/json',
                'png' => 'image/png',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'svg' => 'image/svg+xml',
                'ico' => 'image/x-icon',
                'woff' => 'font/woff',
                'woff2' => 'font/woff2',
                'ttf' => 'font/ttf',
                'eot' => 'application/vnd.ms-fontobject',
            ];
            $ext = strtolower(pathinfo($staticFile, PATHINFO_EXTENSION));
            $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';
            header('Content-Type: ' . $mimeType);
            header('Cache-Control: public, max-age=31536000');
            readfile($staticFile);
            exit;
        }
    }
}

// Иначе отдаем HTML страницу React приложения
$indexHtmlPath = $projectRoot . '/frontend/dist/index.html';
if (file_exists($indexHtmlPath)) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-cache, must-revalidate');
    echo file_get_contents($indexHtmlPath);
    exit;
}

// Fallback: если фронтенд не собран
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MB Migration Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .dashboard-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 40px;
            max-width: 800px;
            width: 90%;
        }
        h1 { color: #333; margin-bottom: 10px; font-size: 32px; }
        .subtitle { color: #666; margin-bottom: 30px; font-size: 16px; }
        .status {
            background: #f0f9ff;
            border-left: 4px solid #3b82f6;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 30px;
        }
        .warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 30px;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <h1>🚀 MB Migration Dashboard</h1>
        <p class="subtitle">Веб-панель управления миграцией проектов</p>
        
        <div class="warning">
            <h2>⚠️ Фронтенд не собран</h2>
            <p>
                Для работы дашборда необходимо собрать фронтенд:<br>
                <code>cd frontend && npm install && npm run build</code>
            </p>
        </div>

        <div class="status">
            <h2>✅ API работает</h2>
            <p>
                API endpoints доступны по адресу <strong>http://localhost:8088/api</strong><br>
                <a href="/api/health" style="color: #3b82f6;">Проверить API</a>
            </p>
        </div>
    </div>
</body>
</html>
