<?php

namespace Dashboard\Services;

use Exception;
use Google_Client;
use Google_Service_Sheets;

/**
 * GoogleSheetsService
 * 
 * Сервис для работы с Google Sheets API
 */
class GoogleSheetsService
{
    /** @var Google_Client|null */
    private $client = null;

    /** @var Google_Service_Sheets|null */
    private $service = null;

    /** @var DatabaseService */
    private $dbService;

    /**
     * Конструктор
     * 
     * @throws Exception
     */
    public function __construct()
    {
        $this->dbService = new DatabaseService();
        $this->initializeClient();
    }

    /**
     * Инициализация Google Client
     * 
     * @throws Exception
     */
    private function initializeClient(): void
    {
        $clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? getenv('GOOGLE_CLIENT_ID');
        $clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? getenv('GOOGLE_CLIENT_SECRET');
        $redirectUri = $_ENV['GOOGLE_REDIRECT_URI'] ?? getenv('GOOGLE_REDIRECT_URI') 
            ?? 'http://localhost:8088/api/google-sheets/oauth/callback';

        if (empty($clientId) || empty($clientSecret)) {
            throw new Exception(
                'Google Sheets API credentials не настроены. ' .
                'Требуются переменные окружения: GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET'
            );
        }

        $this->client = new Google_Client();
        $this->client->setClientId($clientId);
        $this->client->setClientSecret($clientSecret);
        $this->client->setRedirectUri($redirectUri);
        $this->client->addScope(Google_Service_Sheets::SPREADSHEETS_READONLY);
        $this->client->addScope(Google_Service_Sheets::SPREADSHEETS);
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');

        // Загружаем сохраненный токен, если есть
        $this->loadToken();
    }

    /**
     * Загрузить токен из БД
     */
    private function loadToken(): void
    {
        try {
            $db = $this->dbService->getWriteConnection();
            $tokenData = $db->getAllRows(
                "SELECT access_token, refresh_token, expires_in, created_at 
                 FROM google_sheets_tokens 
                 ORDER BY created_at DESC 
                 LIMIT 1"
            );

            if (!empty($tokenData) && isset($tokenData[0])) {
                $token = $tokenData[0];
                $this->client->setAccessToken([
                    'access_token' => $token['access_token'],
                    'refresh_token' => $token['refresh_token'] ?? null,
                    'expires_in' => $token['expires_in'] ?? 3600,
                    'created' => strtotime($token['created_at'])
                ]);

                // Если токен истек, обновляем его
                if ($this->client->isAccessTokenExpired()) {
                    $this->refreshToken();
                }
            }
        } catch (Exception $e) {
            // Если таблицы токенов нет или ошибка - игнорируем, пользователь должен авторизоваться
            error_log("[GoogleSheetsService] Не удалось загрузить токен: " . $e->getMessage());
        }
    }

    /**
     * Сохранить токен в БД
     * 
     * @param array $token
     */
    private function saveToken(array $token): void
    {
        try {
            $db = $this->dbService->getWriteConnection();
            
            // Создаем таблицу для токенов, если её нет
            $this->createTokensTableIfNotExists($db);

            $accessToken = $token['access_token'] ?? null;
            $refreshToken = $token['refresh_token'] ?? null;
            $expiresIn = $token['expires_in'] ?? 3600;

            // Удаляем старые токены
            $db->getAllRows("DELETE FROM google_sheets_tokens");

            // Сохраняем новый токен
            $db->getAllRows(
                "INSERT INTO google_sheets_tokens (access_token, refresh_token, expires_in, created_at) 
                 VALUES (?, ?, ?, NOW())",
                [$accessToken, $refreshToken, $expiresIn]
            );
            
            error_log("[GoogleSheetsService::saveToken] Токен успешно сохранен в БД");
            error_log("[GoogleSheetsService::saveToken] Access token сохранен: " . (strlen($accessToken ?? '') > 0 ? 'да' : 'нет'));
            error_log("[GoogleSheetsService::saveToken] Refresh token сохранен: " . (strlen($refreshToken ?? '') > 0 ? 'да' : 'нет'));
            error_log("[GoogleSheetsService::saveToken] Expires in: {$expiresIn} секунд");
        } catch (Exception $e) {
            error_log("[GoogleSheetsService::saveToken] Ошибка сохранения токена: " . $e->getMessage());
            error_log("[GoogleSheetsService::saveToken] Stack trace: " . $e->getTraceAsString());
            throw $e; // Пробрасываем исключение, чтобы знать о проблеме
        }
    }

    /**
     * Создать таблицу для токенов, если её нет
     * 
     * @param mixed $db
     */
    private function createTokensTableIfNotExists($db): void
    {
        try {
            $db->getAllRows(
                "CREATE TABLE IF NOT EXISTS `google_sheets_tokens` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `access_token` TEXT NOT NULL,
                  `refresh_token` TEXT NULL,
                  `expires_in` INT DEFAULT 3600,
                  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                  INDEX `idx_created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Exception $e) {
            // Игнорируем, если таблица уже существует
        }
    }

    /**
     * Обновить access token используя refresh token
     */
    private function refreshToken(): void
    {
        try {
            $refreshToken = $this->client->getRefreshToken();
            if (!$refreshToken) {
                // Пытаемся загрузить refresh token из БД
                $db = $this->dbService->getWriteConnection();
                $tokenData = $db->getAllRows(
                    "SELECT refresh_token FROM google_sheets_tokens ORDER BY created_at DESC LIMIT 1"
                );
                
                if (!empty($tokenData) && !empty($tokenData[0]['refresh_token'])) {
                    $refreshToken = $tokenData[0]['refresh_token'];
                    $this->client->setRefreshToken($refreshToken);
                } else {
                    throw new Exception("Refresh token отсутствует в БД");
                }
            }
            
            if ($refreshToken) {
                $newToken = $this->client->fetchAccessTokenWithRefreshToken($refreshToken);
                
                if (isset($newToken['error'])) {
                    throw new Exception('Ошибка обновления токена: ' . ($newToken['error_description'] ?? $newToken['error']));
                }
                
                $this->client->setAccessToken($newToken);
                $this->saveToken($newToken);
                error_log("[GoogleSheetsService::refreshToken] Токен успешно обновлен");
            } else {
                throw new Exception("Refresh token отсутствует");
            }
        } catch (Exception $e) {
            error_log("[GoogleSheetsService::refreshToken] Ошибка обновления токена: " . $e->getMessage());
            error_log("[GoogleSheetsService::refreshToken] Stack trace: " . $e->getTraceAsString());
            throw new Exception("Не удалось обновить токен доступа. Требуется повторная авторизация.");
        }
    }

    /**
     * OAuth 2.0 аутентификация
     * 
     * @param string|null $authCode Код авторизации от Google
     * @return array|string URL для авторизации или данные токена
     * @throws Exception
     */
    public function authenticate(?string $authCode = null)
    {
        if ($authCode !== null) {
            // Обмениваем код на токен
            try {
                $token = $this->client->fetchAccessTokenWithAuthCode($authCode);
                
                if (isset($token['error'])) {
                    throw new Exception('Ошибка получения токена: ' . $token['error_description'] ?? $token['error']);
                }

                $this->client->setAccessToken($token);
                $this->saveToken($token);
                
                error_log("[GoogleSheetsService::authenticate] Токен успешно получен и сохранен");
                error_log("[GoogleSheetsService::authenticate] Access token: " . substr($token['access_token'] ?? '', 0, 20) . "...");
                error_log("[GoogleSheetsService::authenticate] Refresh token: " . (isset($token['refresh_token']) ? substr($token['refresh_token'], 0, 20) . "..." : 'не предоставлен'));
                error_log("[GoogleSheetsService::authenticate] Expires in: " . ($token['expires_in'] ?? 'N/A') . " секунд");

                return [
                    'success' => true,
                    'message' => 'Авторизация успешна',
                    'token' => $token,
                    'token_saved' => true,
                    'expires_at' => isset($token['created']) && isset($token['expires_in']) 
                        ? date('Y-m-d H:i:s', $token['created'] + $token['expires_in'])
                        : null
                ];
            } catch (Exception $e) {
                error_log("[GoogleSheetsService::authenticate] Ошибка: " . $e->getMessage());
                throw new Exception('Ошибка авторизации: ' . $e->getMessage());
            }
        } else {
            // Возвращаем URL для авторизации
            return $this->client->createAuthUrl();
        }
    }

    /**
     * Проверить, есть ли валидный access token
     * 
     * @return bool
     */
    public function isAuthenticated(): bool
    {
        try {
            if (!$this->client) {
                return false;
            }

            $token = $this->client->getAccessToken();
            if (!$token) {
                return false;
            }

            // Проверяем, не истек ли токен
            if ($this->client->isAccessTokenExpired()) {
                // Пытаемся обновить
                try {
                    $this->refreshToken();
                    return true;
                } catch (Exception $e) {
                    return false;
                }
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Получить информацию о таблице по ID
     * 
     * @param string $spreadsheetId
     * @return array
     * @throws Exception
     */
    public function getSpreadsheet(string $spreadsheetId): array
    {
        if (!$this->isAuthenticated()) {
            throw new Exception('Требуется авторизация. Вызовите authenticate() для получения URL авторизации.');
        }

        try {
            $this->service = new Google_Service_Sheets($this->client);
            $spreadsheet = $this->service->spreadsheets->get($spreadsheetId);

            return [
                'spreadsheet_id' => $spreadsheetId,
                'title' => $spreadsheet->getProperties()->getTitle(),
                'locale' => $spreadsheet->getProperties()->getLocale(),
                'time_zone' => $spreadsheet->getProperties()->getTimeZone(),
                'sheets' => array_map(function($sheet) {
                    return [
                        'id' => (string)$sheet->getProperties()->getSheetId(),
                        'name' => $sheet->getProperties()->getTitle(),
                        'sheet_id' => $sheet->getProperties()->getSheetId(),
                        'title' => $sheet->getProperties()->getTitle(),
                        'index' => $sheet->getProperties()->getIndex()
                    ];
                }, $spreadsheet->getSheets())
            ];
        } catch (Exception $e) {
            error_log("[GoogleSheetsService::getSpreadsheet] Ошибка: " . $e->getMessage());
            throw new Exception('Ошибка получения информации о таблице: ' . $e->getMessage());
        }
    }

    /**
     * Получить список всех листов в таблице
     * 
     * @param string $spreadsheetId
     * @return array
     * @throws Exception
     */
    public function getSheetsList(string $spreadsheetId): array
    {
        $spreadsheet = $this->getSpreadsheet($spreadsheetId);
        return $spreadsheet['sheets'] ?? [];
    }

    /**
     * Получить данные конкретного листа
     * 
     * @param string $spreadsheetId
     * @param string $sheetName Название листа или диапазон (например, "Sheet1" или "Sheet1!A1:Z100")
     * @return array Массив строк с данными
     * @throws Exception
     */
    public function getSheetData(string $spreadsheetId, string $sheetName): array
    {
        if (!$this->isAuthenticated()) {
            throw new Exception('Требуется авторизация. Вызовите authenticate() для получения URL авторизации.');
        }

        try {
            $this->service = new Google_Service_Sheets($this->client);
            
            // Если указан диапазон, используем его, иначе берем весь лист
            $range = $sheetName;
            if (strpos($sheetName, '!') === false) {
                $range = $sheetName . '!A:Z'; // По умолчанию берем колонки A-Z
            }

            $response = $this->service->spreadsheets_values->get($spreadsheetId, $range);
            $values = $response->getValues();

            return $values ?? [];
        } catch (Exception $e) {
            error_log("[GoogleSheetsService::getSheetData] Ошибка: " . $e->getMessage());
            throw new Exception('Ошибка получения данных листа: ' . $e->getMessage());
        }
    }

    /**
     * Привязать лист к волне миграции
     * 
     * @param string $spreadsheetId ID Google таблицы
     * @param string $sheetName Название листа
     * @param string $waveId ID волны
     * @return array Информация о привязке
     * @throws Exception
     */
    public function linkSheetToWave(string $spreadsheetId, string $sheetName, string $waveId): array
    {
        try {
            $db = $this->dbService->getWriteConnection();

            // 1. Проверяем существование волны
            $wave = $db->find(
                "SELECT wave_id, name FROM waves WHERE wave_id = ?",
                [$waveId]
            );

            if (!$wave) {
                throw new Exception("Волна с ID '{$waveId}' не найдена");
            }

            // 2. Получаем информацию о листе из Google Sheets API (если авторизованы)
            $sheetId = null;
            $spreadsheetName = null;
            
            try {
                if ($this->isAuthenticated()) {
                    $spreadsheet = $this->getSpreadsheet($spreadsheetId);
                    $spreadsheetName = $spreadsheet['title'] ?? null;
                    
                    // Находим sheet_id по названию листа
                    foreach ($spreadsheet['sheets'] ?? [] as $sheet) {
                        if ($sheet['name'] === $sheetName || $sheet['title'] === $sheetName) {
                            $sheetId = (string)($sheet['id'] ?? $sheet['sheet_id'] ?? null);
                            break;
                        }
                    }
                }
            } catch (Exception $e) {
                // Если не авторизованы или ошибка API - продолжаем без sheet_id
                error_log("[GoogleSheetsService::linkSheetToWave] Не удалось получить информацию о листе: " . $e->getMessage());
            }

            // 3. Проверяем, существует ли уже запись для этой комбинации spreadsheet_id и sheet_name
            $existing = $db->find(
                "SELECT id, wave_id, sheet_id FROM google_sheets WHERE spreadsheet_id = ? AND sheet_name = ?",
                [$spreadsheetId, $sheetName]
            );

            if ($existing) {
                // Обновляем существующую запись
                $db->getAllRows(
                    "UPDATE google_sheets 
                     SET wave_id = ?, 
                         sheet_id = COALESCE(?, sheet_id),
                         spreadsheet_name = COALESCE(?, spreadsheet_name),
                         updated_at = NOW()
                     WHERE id = ?",
                    [$waveId, $sheetId, $spreadsheetName, $existing['id']]
                );

                error_log("[GoogleSheetsService::linkSheetToWave] Обновлена запись ID={$existing['id']} для spreadsheet_id={$spreadsheetId}, sheet_name={$sheetName}, wave_id={$waveId}");
            } else {
                // Пытаемся создать новую запись
                // Используем INSERT ... ON DUPLICATE KEY UPDATE для обработки случая, 
                // когда уже есть запись с таким spreadsheet_id (из-за UNIQUE KEY)
                try {
                    $db->getAllRows(
                        "INSERT INTO google_sheets (spreadsheet_id, spreadsheet_name, sheet_id, sheet_name, wave_id, created_at, updated_at) 
                         VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                         ON DUPLICATE KEY UPDATE 
                         sheet_name = VALUES(sheet_name),
                         wave_id = VALUES(wave_id),
                         sheet_id = COALESCE(VALUES(sheet_id), sheet_id),
                         spreadsheet_name = COALESCE(VALUES(spreadsheet_name), spreadsheet_name),
                         updated_at = NOW()",
                        [$spreadsheetId, $spreadsheetName, $sheetId, $sheetName, $waveId]
                    );

                    // Получаем ID записи (либо вставленной, либо обновленной)
                    $record = $db->find(
                        "SELECT id FROM google_sheets WHERE spreadsheet_id = ? AND sheet_name = ?",
                        [$spreadsheetId, $sheetName]
                    );
                    
                    $recordId = $record['id'] ?? null;
                    error_log("[GoogleSheetsService::linkSheetToWave] Создана/обновлена запись ID={$recordId} для spreadsheet_id={$spreadsheetId}, sheet_name={$sheetName}, wave_id={$waveId}");
                } catch (Exception $insertError) {
                    // Если ошибка из-за UNIQUE KEY, пытаемся обновить существующую запись
                    if (strpos($insertError->getMessage(), 'Duplicate entry') !== false || 
                        strpos($insertError->getMessage(), '1062') !== false) {
                        // Находим существующую запись по spreadsheet_id
                        $existingBySpreadsheet = $db->find(
                            "SELECT id FROM google_sheets WHERE spreadsheet_id = ?",
                            [$spreadsheetId]
                        );
                        
                        if ($existingBySpreadsheet) {
                            // Обновляем существующую запись
                            $db->getAllRows(
                                "UPDATE google_sheets 
                                 SET sheet_name = ?,
                                     wave_id = ?,
                                     sheet_id = COALESCE(?, sheet_id),
                                     spreadsheet_name = COALESCE(?, spreadsheet_name),
                                     updated_at = NOW()
                                 WHERE id = ?",
                                [$sheetName, $waveId, $sheetId, $spreadsheetName, $existingBySpreadsheet['id']]
                            );
                            error_log("[GoogleSheetsService::linkSheetToWave] Обновлена существующая запись ID={$existingBySpreadsheet['id']} для spreadsheet_id={$spreadsheetId}, sheet_name={$sheetName}, wave_id={$waveId}");
                        } else {
                            throw $insertError;
                        }
                    } else {
                        throw $insertError;
                    }
                }
            }

            return [
                'success' => true,
                'spreadsheet_id' => $spreadsheetId,
                'spreadsheet_name' => $spreadsheetName,
                'sheet_id' => $sheetId,
                'sheet_name' => $sheetName,
                'wave_id' => $waveId,
                'wave_name' => $wave['name']
            ];
        } catch (Exception $e) {
            error_log("[GoogleSheetsService::linkSheetToWave] Ошибка: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Получить все листы, привязанные к волне
     * 
     * @param string $waveId ID волны
     * @return array Массив листов
     * @throws Exception
     */
    public function getSheetsByWave(string $waveId): array
    {
        try {
            $db = $this->dbService->getWriteConnection();
            
            $sheets = $db->getAllRows(
                "SELECT id, spreadsheet_id, spreadsheet_name, sheet_id, sheet_name, 
                        wave_id, last_synced_at, created_at, updated_at
                 FROM google_sheets
                 WHERE wave_id = ?
                 ORDER BY created_at DESC",
                [$waveId]
            );

            return $sheets ?? [];
        } catch (Exception $e) {
            error_log("[GoogleSheetsService::getSheetsByWave] Ошибка: " . $e->getMessage());
            throw new Exception('Ошибка получения листов волны: ' . $e->getMessage());
        }
    }

    /**
     * Отвязать лист от волны
     * 
     * @param string $spreadsheetId ID Google таблицы
     * @param string $sheetName Название листа
     * @return array Результат операции
     * @throws Exception
     */
    public function unlinkSheetFromWave(string $spreadsheetId, string $sheetName): array
    {
        try {
            $db = $this->dbService->getWriteConnection();
            
            $updated = $db->getAllRows(
                "UPDATE google_sheets 
                 SET wave_id = NULL, updated_at = NOW()
                 WHERE spreadsheet_id = ? AND sheet_name = ?",
                [$spreadsheetId, $sheetName]
            );

            $affectedRows = $db->getAllRows("SELECT ROW_COUNT() as count")[0]['count'] ?? 0;

            if ($affectedRows === 0) {
                throw new Exception("Лист '{$sheetName}' в таблице '{$spreadsheetId}' не найден или уже отвязан");
            }

            return [
                'success' => true,
                'message' => "Лист '{$sheetName}' успешно отвязан от волны",
                'spreadsheet_id' => $spreadsheetId,
                'sheet_name' => $sheetName
            ];
        } catch (Exception $e) {
            error_log("[GoogleSheetsService::unlinkSheetFromWave] Ошибка: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Получить Google Client (для расширенного использования)
     * 
     * @return Google_Client
     */
    public function getClient(): Google_Client
    {
        return $this->client;
    }
}
