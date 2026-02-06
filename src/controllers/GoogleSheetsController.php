<?php

namespace Dashboard\Controllers;

use Dashboard\Services\DatabaseService;
use Dashboard\Services\GoogleSheetsService;
use Dashboard\Services\GoogleSheetsSyncService;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class GoogleSheetsController
{
    /** @var DatabaseService */
    private $dbService;

    /** @var GoogleSheetsService|null */
    private $googleSheetsService = null;

    public function __construct()
    {
        $this->dbService = new DatabaseService();
        try {
            $this->googleSheetsService = new GoogleSheetsService();
        } catch (Exception $e) {
            // Сервис не инициализирован (нет credentials) - будет использоваться только для некоторых операций
            error_log("[GoogleSheetsController] GoogleSheetsService не инициализирован: " . $e->getMessage());
        }
    }

    /**
     * POST /api/google-sheets/connect
     * Подключить Google таблицу
     */
    public function connect(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                $data = $request->request->all();
            }

            // Валидация
            if (empty($data['spreadsheet_id'])) {
            $response = new JsonResponse([
                'success' => false,
                'error' => 'Обязательное поле отсутствует: spreadsheet_id'
            ], 400);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            return $response;
            }

            $spreadsheetId = trim($data['spreadsheet_id']);
            $spreadsheetName = isset($data['spreadsheet_name']) ? trim($data['spreadsheet_name']) : null;

            if (!$this->googleSheetsService) {
                $response = new JsonResponse([
                    'success' => false,
                    'error' => 'Google Sheets Service не настроен. Проверьте переменные окружения GOOGLE_CLIENT_ID и GOOGLE_CLIENT_SECRET'
                ], 500);
                $response->headers->set('Content-Type', 'application/json; charset=utf-8');
                return $response;
            }

            // Получаем информацию о таблице
            $spreadsheetInfo = $this->googleSheetsService->getSpreadsheet($spreadsheetId);
            
            // Сохраняем в БД
            $db = $this->dbService->getWriteConnection();
            $db->getAllRows(
                "INSERT INTO google_sheets (spreadsheet_id, spreadsheet_name, created_at, updated_at) 
                 VALUES (?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE 
                 spreadsheet_name = VALUES(spreadsheet_name),
                 updated_at = NOW()",
                [$spreadsheetId, $spreadsheetName ?? $spreadsheetInfo['title']]
            );

            $response = new JsonResponse([
                'success' => true,
                'data' => [
                    'spreadsheet_id' => $spreadsheetId,
                    'spreadsheet_name' => $spreadsheetName ?? $spreadsheetInfo['title'],
                    'sheets' => $spreadsheetInfo['sheets'] ?? []
                ]
            ], 200);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            return $response;

        } catch (Exception $e) {
            error_log("[GoogleSheetsController::connect] Error: " . $e->getMessage());
            $response = new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            return $response;
        }
    }

    /**
     * GET /api/google-sheets/list
     * Список всех подключенных таблиц с информацией о волнах
     */
    public function list(Request $request): JsonResponse
    {
        try {
            $db = $this->dbService->getWriteConnection();
            $sheets = $db->getAllRows(
                "SELECT 
                    gs.id,
                    gs.spreadsheet_id,
                    gs.spreadsheet_name,
                    gs.sheet_id,
                    gs.sheet_name,
                    gs.wave_id,
                    gs.last_synced_at,
                    gs.created_at,
                    gs.updated_at,
                    w.name as wave_name,
                    w.status as wave_status,
                    w.workspace_name
                 FROM google_sheets gs
                 LEFT JOIN waves w ON BINARY gs.wave_id = BINARY w.wave_id
                 ORDER BY gs.created_at DESC"
            );

            $response = new JsonResponse([
                'success' => true,
                'data' => $sheets ?? []
            ], 200);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            return $response;

        } catch (Exception $e) {
            error_log("[GoogleSheetsController::list] Error: " . $e->getMessage());
            $response = new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            return $response;
        }
    }

    /**
     * GET /api/google-sheets/:id
     * Получить информацию о конкретной таблице
     */
    public function get(Request $request, int $id): JsonResponse
    {
        try {
            $db = $this->dbService->getWriteConnection();
            $sheet = $db->getAllRows(
                "SELECT id, spreadsheet_id, spreadsheet_name, sheet_id, sheet_name, 
                        wave_id, last_synced_at, created_at, updated_at
                 FROM google_sheets
                 WHERE id = ?",
                [$id]
            );

            if (empty($sheet)) {
                $response = new JsonResponse([
                    'success' => false,
                    'error' => 'Таблица не найдена'
                ], 404);
                $response->headers->set('Content-Type', 'application/json; charset=utf-8');
                return $response;
            }

            $response = new JsonResponse([
                'success' => true,
                'data' => $sheet[0]
            ], 200);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            return $response;

        } catch (Exception $e) {
            error_log("[GoogleSheetsController::get] Error: " . $e->getMessage());
            $response = new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            return $response;
        }
    }

    /**
     * POST /api/google-sheets/sync/:id
     * Синхронизировать таблицу
     */
    public function sync(Request $request, int $id): JsonResponse
    {
        error_log("═══════════════════════════════════════════════════════════════");
        error_log("[GoogleSheetsController::sync] 📥 ПОЛУЧЕН ЗАПРОС НА СИНХРОНИЗАЦИЮ");
        error_log("  ID таблицы: {$id}");
        error_log("  URL: " . ($request->getUri() ?? 'N/A'));
        error_log("  Method: " . $request->getMethod());
        error_log("═══════════════════════════════════════════════════════════════");

        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                $data = $request->request->all();
            }

            error_log("[GoogleSheetsController::sync] 📋 Шаг 1: Получение информации о таблице из БД...");
            // Получаем информацию о таблице из БД
            $db = $this->dbService->getWriteConnection();
            $sheet = $db->find(
                "SELECT spreadsheet_id, sheet_name, wave_id 
                 FROM google_sheets 
                 WHERE id = ?",
                [$id]
            );

            if (!$sheet) {
                error_log("[GoogleSheetsController::sync] ❌ Таблица с ID {$id} не найдена");
                $response = new JsonResponse([
                    'success' => false,
                    'error' => 'Таблица не найдена'
                ], 404);
                $response->headers->set('Content-Type', 'application/json; charset=utf-8');
                return $response;
            }

            error_log("[GoogleSheetsController::sync] ✓ Таблица найдена:");
            error_log("  Spreadsheet ID: {$sheet['spreadsheet_id']}");
            error_log("  Sheet Name: " . ($sheet['sheet_name'] ?? 'не указан'));
            error_log("  Wave ID: " . ($sheet['wave_id'] ?? 'не указан'));

            // Получаем sheet_name из запроса или из БД
            $sheetName = isset($data['sheet_name']) && !empty(trim($data['sheet_name'])) 
                ? trim($data['sheet_name']) 
                : ($sheet['sheet_name'] ?? null);

            if (isset($data['sheet_name'])) {
                error_log("[GoogleSheetsController::sync] 📝 Sheet name из запроса: " . $data['sheet_name']);
            }

            if (empty($sheetName)) {
                error_log("[GoogleSheetsController::sync] ❌ Название листа не указано");
                $response = new JsonResponse([
                    'success' => false,
                    'error' => 'Название листа не указано. Укажите sheet_name в теле запроса или привяжите лист к таблице через /api/google-sheets/link-wave.'
                ], 400);
                $response->headers->set('Content-Type', 'application/json; charset=utf-8');
                return $response;
            }

            error_log("[GoogleSheetsController::sync] ✓ Используется лист: {$sheetName}");
            error_log("[GoogleSheetsController::sync] 🚀 Шаг 2: Запуск синхронизации...");

            // Используем GoogleSheetsSyncService для синхронизации
            $syncService = new GoogleSheetsSyncService();
            $stats = $syncService->syncSheet(
                $sheet['spreadsheet_id'],
                $sheetName,
                $sheet['wave_id'] ?? null
            );

            error_log("[GoogleSheetsController::sync] ✅ Синхронизация завершена успешно");
            error_log("[GoogleSheetsController::sync] 📊 Статистика:");
            error_log("  Всего строк: {$stats['total_rows']}");
            error_log("  Обработано: {$stats['processed']}");
            error_log("  Создано: {$stats['created']}");
            error_log("  Обновлено: {$stats['updated']}");
            error_log("  Не найдено: {$stats['not_found']}");
            error_log("  Ошибок: {$stats['errors']}");

            $response = new JsonResponse([
                'success' => true,
                'data' => [
                    'sheet_id' => $id,
                    'spreadsheet_id' => $sheet['spreadsheet_id'],
                    'sheet_name' => $sheetName,
                    'wave_id' => $sheet['wave_id'] ?? null,
                    'stats' => $stats
                ]
            ], 200);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            return $response;

        } catch (Exception $e) {
            error_log("═══════════════════════════════════════════════════════════════");
            error_log("[GoogleSheetsController::sync] ❌ ОШИБКА СИНХРОНИЗАЦИИ");
            error_log("  Сообщение: " . $e->getMessage());
            error_log("  Файл: " . $e->getFile());
            error_log("  Строка: " . $e->getLine());
            error_log("  Stack trace: " . $e->getTraceAsString());
            error_log("═══════════════════════════════════════════════════════════════");
            
            $response = new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'debug' => [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], 500);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            return $response;
        }
    }

    /**
     * POST /api/google-sheets/link-wave
     * Привязать лист к волне
     */
    public function linkWave(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                $data = $request->request->all();
            }

            // Валидация
            if (empty($data['spreadsheet_id']) || empty($data['sheet_name']) || empty($data['wave_id'])) {
            $response = new JsonResponse([
                'success' => false,
                'error' => 'Обязательные поля отсутствуют: spreadsheet_id, sheet_name, wave_id'
            ], 400);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            return $response;
            }

            if (!$this->googleSheetsService) {
                $response = new JsonResponse([
                    'success' => false,
                    'error' => 'Google Sheets Service не настроен. Проверьте переменные окружения GOOGLE_CLIENT_ID и GOOGLE_CLIENT_SECRET'
                ], 500);
                $response->headers->set('Content-Type', 'application/json; charset=utf-8');
                return $response;
            }

            $result = $this->googleSheetsService->linkSheetToWave(
                trim($data['spreadsheet_id']),
                trim($data['sheet_name']),
                trim($data['wave_id'])
            );

            $response = new JsonResponse([
                'success' => true,
                'data' => $result
            ], 200);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            return $response;

        } catch (Exception $e) {
            error_log("[GoogleSheetsController::linkWave] Error: " . $e->getMessage());
            $response = new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            return $response;
        }
    }

    /**
     * GET /api/google-sheets/sheets/:spreadsheetId
     * Получить список листов таблицы
     */
    public function getSheets(Request $request, string $spreadsheetId): JsonResponse
    {
        try {
            if (!$this->googleSheetsService) {
                $response = new JsonResponse([
                    'success' => false,
                    'error' => 'Google Sheets Service не настроен. Проверьте переменные окружения GOOGLE_CLIENT_ID и GOOGLE_CLIENT_SECRET'
                ], 500);
                $response->headers->set('Content-Type', 'application/json; charset=utf-8');
                return $response;
            }

            $sheets = $this->googleSheetsService->getSheetsList($spreadsheetId);
            
            $response = new JsonResponse([
                'success' => true,
                'data' => $sheets
            ], 200);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            return $response;

        } catch (Exception $e) {
            error_log("[GoogleSheetsController::getSheets] Error: " . $e->getMessage());
            $response = new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            return $response;
        }
    }

    /**
     * GET /api/google-sheets/oauth/authorize
     * Получить URL для OAuth авторизации
     */
    public function oauthAuthorize(Request $request): JsonResponse
    {
        try {
            if (!$this->googleSheetsService) {
                $response = new JsonResponse([
                    'success' => false,
                    'error' => 'Google Sheets Service не настроен. Проверьте переменные окружения GOOGLE_CLIENT_ID и GOOGLE_CLIENT_SECRET'
                ], 500);
                $response->headers->set('Content-Type', 'application/json; charset=utf-8');
                return $response;
            }

            // Получаем redirect_uri из запроса или используем значение по умолчанию
            $redirectUri = $request->query->get('redirect_uri') ?? '/google-sheets';
            
            // Добавляем redirect_uri в state параметр, чтобы передать его в callback
            $authUrl = $this->googleSheetsService->authenticate();
            
            // Добавляем redirect_uri как параметр в callback URL
            $separator = strpos($authUrl, '?') !== false ? '&' : '?';
            $authUrl .= $separator . 'state=' . urlencode(json_encode(['redirect_uri' => $redirectUri]));
            
            $response = new JsonResponse([
                'success' => true,
                'data' => [
                    'url' => $authUrl,
                    'redirect_uri' => $redirectUri
                ]
            ], 200);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            return $response;

        } catch (Exception $e) {
            error_log("[GoogleSheetsController::oauthAuthorize] Error: " . $e->getMessage());
            $response = new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            return $response;
        }
    }

    /**
     * GET /api/google-sheets/oauth/callback
     * Callback для OAuth
     * 
     * @return JsonResponse|Response
     */
    public function oauthCallback(Request $request)
    {
        try {
            $code = $request->query->get('code');
            
            if (empty($code)) {
            $response = new JsonResponse([
                'success' => false,
                'error' => 'Код авторизации отсутствует'
            ], 400);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            return $response;
            }

            if (!$this->googleSheetsService) {
                $response = new JsonResponse([
                    'success' => false,
                    'error' => 'Google Sheets Service не настроен. Проверьте переменные окружения GOOGLE_CLIENT_ID и GOOGLE_CLIENT_SECRET'
                ], 500);
                $response->headers->set('Content-Type', 'application/json; charset=utf-8');
                return $response;
            }

            error_log("[GoogleSheetsController::oauthCallback] Получен код авторизации: " . substr($code, 0, 20) . "...");
            
            // Получаем redirect_uri из state параметра (если был передан)
            $state = $request->query->get('state');
            $redirectUrl = '/google-sheets';
            
            if ($state) {
                try {
                    $stateData = json_decode(urldecode($state), true);
                    if (isset($stateData['redirect_uri'])) {
                        $redirectUrl = $stateData['redirect_uri'];
                    }
                } catch (Exception $e) {
                    // Игнорируем ошибки парсинга state
                }
            }
            
            // Также проверяем параметр redirect_uri напрямую (для обратной совместимости)
            if ($request->query->has('redirect_uri')) {
                $redirectUrl = $request->query->get('redirect_uri');
            }
            
            // Если не указан, используем значение из env или по умолчанию
            if ($redirectUrl === '/google-sheets') {
                $redirectUrl = $_ENV['GOOGLE_OAUTH_REDIRECT_URI'] 
                    ?? getenv('GOOGLE_OAUTH_REDIRECT_URI')
                    ?? '/google-sheets';
            }
            
            $result = $this->googleSheetsService->authenticate($code);
            
            error_log("[GoogleSheetsController::oauthCallback] Авторизация успешна");
            error_log("[GoogleSheetsController::oauthCallback] Токен сохранен: " . ($result['token_saved'] ?? false ? 'да' : 'нет'));
            error_log("[GoogleSheetsController::oauthCallback] Редирект на: {$redirectUrl}");
            
            // Проверяем, что токен действительно сохранен в БД
            $db = $this->dbService->getWriteConnection();
            $savedToken = $db->getAllRows(
                "SELECT id, created_at, expires_in FROM google_sheets_tokens ORDER BY created_at DESC LIMIT 1"
            );
            
            if (!empty($savedToken)) {
                error_log("[GoogleSheetsController::oauthCallback] Подтверждение: токен найден в БД, создан: " . $savedToken[0]['created_at']);
                $result['token_verified_in_db'] = true;
                $result['token_created_at'] = $savedToken[0]['created_at'];
            } else {
                error_log("[GoogleSheetsController::oauthCallback] ВНИМАНИЕ: токен не найден в БД после сохранения!");
                $result['token_verified_in_db'] = false;
            }
            
            // Если это запрос через браузер (не API), делаем HTML редирект
            $acceptHeader = $request->headers->get('Accept', '');
            $isBrowserRequest = strpos($acceptHeader, 'text/html') !== false || 
                               empty($request->headers->get('X-Requested-With'));
            
            if ($isBrowserRequest) {
                // HTML редирект для браузера
                $fullRedirectUrl = rtrim($_ENV['DASHBOARD_BASE_URL'] ?? getenv('DASHBOARD_BASE_URL') ?: 'http://localhost:8088', '/') . $redirectUrl;
                $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Авторизация успешна</title>
    <meta http-equiv="refresh" content="2;url={$fullRedirectUrl}">
    <style>
        body { 
            font-family: Arial, sans-serif; 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            height: 100vh; 
            margin: 0;
            background: #f5f5f5;
        }
        .container {
            text-align: center;
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .success { color: #28a745; font-size: 48px; margin-bottom: 1rem; }
        .message { color: #333; margin-bottom: 1rem; }
        .redirect { color: #6c757d; font-size: 0.875rem; }
        a { color: #007bff; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="success">✓</div>
        <div class="message">Авторизация успешна! Токен сохранен.</div>
        <div class="redirect">
            Перенаправление на <a href="{$fullRedirectUrl}">страницу настроек</a>...
        </div>
    </div>
    <script>
        setTimeout(function() {
            window.location.href = '{$fullRedirectUrl}';
        }, 2000);
    </script>
</body>
</html>
HTML;
                return new Response($html, 200, [
                    'Content-Type' => 'text/html; charset=utf-8'
                ]);
            }
            
            // JSON ответ для API запросов
            $response = new JsonResponse([
                'success' => true,
                'data' => $result,
                'message' => 'Авторизация успешна. Токен сохранен в базе данных.',
                'redirect_url' => $redirectUrl
            ], 200);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            return $response;

        } catch (Exception $e) {
            error_log("[GoogleSheetsController::oauthCallback] Error: " . $e->getMessage());
            error_log("[GoogleSheetsController::oauthCallback] Stack trace: " . $e->getTraceAsString());
            $response = new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            return $response;
        }
    }

    /**
     * GET /api/google-sheets/oauth/status
     * Проверить статус авторизации
     */
    public function oauthStatus(Request $request): JsonResponse
    {
        try {
            if (!$this->googleSheetsService) {
                $response = new JsonResponse([
                    'success' => false,
                    'error' => 'Google Sheets Service не настроен'
                ], 500);
                $response->headers->set('Content-Type', 'application/json; charset=utf-8');
                return $response;
            }

            $isAuthenticated = $this->googleSheetsService->isAuthenticated();
            
            // Получаем информацию о токене из БД
            $db = $this->dbService->getWriteConnection();
            $tokenData = $db->getAllRows(
                "SELECT id, created_at, expires_in, 
                        DATE_ADD(created_at, INTERVAL expires_in SECOND) as expires_at
                 FROM google_sheets_tokens 
                 ORDER BY created_at DESC 
                 LIMIT 1"
            );

            $tokenInfo = null;
            if (!empty($tokenData) && isset($tokenData[0])) {
                $token = $tokenData[0];
                $expiresAt = $token['expires_at'];
                $now = new \DateTime();
                $expires = new \DateTime($expiresAt);
                $isExpired = $now > $expires;
                
                $tokenInfo = [
                    'has_token' => true,
                    'created_at' => $token['created_at'],
                    'expires_at' => $expiresAt,
                    'expires_in' => $token['expires_in'],
                    'is_expired' => $isExpired,
                    'has_refresh_token' => true // Проверяем наличие refresh_token
                ];
                
                // Проверяем наличие refresh_token
                $fullToken = $db->getAllRows(
                    "SELECT refresh_token FROM google_sheets_tokens WHERE id = ?",
                    [$token['id']]
                );
                if (!empty($fullToken) && !empty($fullToken[0]['refresh_token'])) {
                    $tokenInfo['has_refresh_token'] = true;
                } else {
                    $tokenInfo['has_refresh_token'] = false;
                }
            } else {
                $tokenInfo = [
                    'has_token' => false
                ];
            }

            $response = new JsonResponse([
                'success' => true,
                'data' => [
                    'authenticated' => $isAuthenticated,
                    'token_info' => $tokenInfo
                ]
            ], 200);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            return $response;

        } catch (Exception $e) {
            error_log("[GoogleSheetsController::oauthStatus] Error: " . $e->getMessage());
            $response = new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            return $response;
        }
    }

    /**
     * DELETE /api/google-sheets/:id
     * Удалить подключенную таблицу
     */
    public function delete(Request $request, int $id): JsonResponse
    {
        try {
            // TODO: Реализовать через DatabaseService->deleteGoogleSheet($id)
            $response = new JsonResponse([
                'success' => false,
                'error' => 'Удаление таблиц не реализовано'
            ], 501);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            return $response;

        } catch (Exception $e) {
            error_log("[GoogleSheetsController::delete] Error: " . $e->getMessage());
            $response = new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            return $response;
        }
    }
}
