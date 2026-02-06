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
     * Отслеживание изменений имени Google таблицы
     * 
     * @param string $spreadsheetId ID Google таблицы
     * @param string|null $currentName Текущее название (если уже получено, чтобы избежать повторного запроса)
     * @return array|null Информация об изменении или null, если не изменилось
     * @throws Exception
     */
    public function trackSheetName(string $spreadsheetId, ?string $currentName = null): ?array
    {
        try {
            // Получаем текущее название таблицы через Google API (если не передано)
            if (!$this->isAuthenticated()) {
                // Если не авторизованы, пропускаем отслеживание
                return null;
            }

            // Если название не передано, получаем его напрямую из API (без вызова getSpreadsheet, чтобы избежать рекурсии)
            if ($currentName === null) {
                try {
                    $this->service = new Google_Service_Sheets($this->client);
                    $spreadsheet = $this->service->spreadsheets->get($spreadsheetId);
                    $currentName = $spreadsheet->getProperties()->getTitle();
                } catch (Exception $e) {
                    // Если не удалось получить название, пропускаем отслеживание
                    error_log("[GoogleSheetsService::trackSheetName] Не удалось получить название таблицы: " . $e->getMessage());
                    return null;
                }
            }

            if (!$currentName) {
                return null;
            }

            // Находим запись в таблице google_sheets
            $db = $this->dbService->getWriteConnection();
            $existing = $db->find(
                "SELECT id, spreadsheet_name FROM google_sheets WHERE spreadsheet_id = ?",
                [$spreadsheetId]
            );

            if (!$existing) {
                // Таблица не найдена в БД - это нормально, может быть еще не подключена
                return null;
            }

            $savedName = $existing['spreadsheet_name'];

            // Сравниваем названия
            if ($savedName !== $currentName) {
                // Название изменилось - обновляем в БД
                $reflection = new \ReflectionClass($db);
                $pdoProperty = $reflection->getProperty('pdo');
                $pdoProperty->setAccessible(true);
                $pdo = $pdoProperty->getValue($db);

                $stmt = $pdo->prepare(
                    "UPDATE google_sheets 
                     SET spreadsheet_name = ?, updated_at = NOW() 
                     WHERE id = ?"
                );
                $stmt->execute([$currentName, $existing['id']]);

                // Логируем изменение
                $changeInfo = [
                    'spreadsheet_id' => $spreadsheetId,
                    'old_name' => $savedName,
                    'new_name' => $currentName,
                    'changed_at' => date('Y-m-d H:i:s')
                ];

                error_log("[GoogleSheetsService::trackSheetName] Название таблицы изменено: " . json_encode($changeInfo, JSON_UNESCAPED_UNICODE));

                return $changeInfo;
            }

            // Название не изменилось
            return null;

        } catch (Exception $e) {
            // Обрабатываем ошибки gracefully - не прерываем основную операцию
            error_log("[GoogleSheetsService::trackSheetName] Ошибка отслеживания названия для {$spreadsheetId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Получить информацию о таблице по ID
     * 
     * @param string $spreadsheetId
     * @param int $maxRetries Максимальное количество попыток при rate limit
     * @return array
     * @throws Exception
     */
    public function getSpreadsheet(string $spreadsheetId, int $maxRetries = 3): array
    {
        if (!$this->isAuthenticated()) {
            throw new Exception('Требуется авторизация. Вызовите authenticate() для получения URL авторизации.');
        }

        $lastException = null;
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $this->service = new Google_Service_Sheets($this->client);
                $spreadsheet = $this->service->spreadsheets->get($spreadsheetId);

                $currentTitle = $spreadsheet->getProperties()->getTitle();
                
                $result = [
                    'spreadsheet_id' => $spreadsheetId,
                    'title' => $currentTitle,
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

                // Отслеживаем изменения названия (передаем уже полученное название, чтобы избежать рекурсии)
                try {
                    $this->trackSheetName($spreadsheetId, $currentTitle);
                } catch (Exception $e) {
                    // Игнорируем ошибки отслеживания
                    error_log("[GoogleSheetsService::getSpreadsheet] Ошибка отслеживания названия: " . $e->getMessage());
                }

                return $result;
                
            } catch (Exception $e) {
                $lastException = $e;
                
                // Проверяем, является ли это ошибкой rate limit
                if ($this->isRateLimitError($e)) {
                    if ($attempt < $maxRetries) {
                        // Вычисляем задержку: экспоненциальная задержка с базовой задержкой 60 секунд
                        $delay = 60 + ($attempt * 10); // 60, 70, 80 секунд
                        error_log("[GoogleSheetsService::getSpreadsheet] Rate limit превышен (попытка {$attempt}/{$maxRetries}). Ожидание {$delay} секунд...");
                        sleep($delay);
                        continue; // Повторяем попытку
                    } else {
                        // Превышено количество попыток
                        error_log("[GoogleSheetsService::getSpreadsheet] Rate limit: превышено количество попыток ({$maxRetries})");
                        throw new Exception(
                            'Превышен лимит запросов к Google Sheets API (60 запросов в минуту). ' .
                            'Пожалуйста, подождите минуту и попробуйте снова. ' .
                            'Или запросите увеличение квоты в Google Cloud Console: ' .
                            'https://cloud.google.com/docs/quotas/help/request_increase'
                        );
                    }
                }
                
                // Если это не rate limit ошибка, пробрасываем исключение
                error_log("[GoogleSheetsService::getSpreadsheet] Ошибка: " . $e->getMessage());
                throw new Exception('Ошибка получения информации о таблице: ' . $e->getMessage());
            }
        }
        
        // Если дошли сюда, значит все попытки исчерпаны
        if ($lastException) {
            throw $lastException;
        }
        
        throw new Exception('Не удалось получить информацию о таблице после ' . $maxRetries . ' попыток');
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
     * Проверить, является ли ошибка rate limit (429)
     * 
     * @param Exception $e
     * @return bool
     */
    private function isRateLimitError(Exception $e): bool
    {
        $message = $e->getMessage();
        return strpos($message, '429') !== false ||
               strpos($message, 'rateLimitExceeded') !== false ||
               strpos($message, 'RATE_LIMIT_EXCEEDED') !== false ||
               strpos($message, 'Quota exceeded') !== false ||
               strpos($message, 'RESOURCE_EXHAUSTED') !== false;
    }

    /**
     * Получить данные конкретного листа с обработкой rate limit
     * 
     * @param string $spreadsheetId
     * @param string $sheetName Название листа или диапазон (например, "Sheet1" или "Sheet1!A1:Z100")
     * @param int $maxRetries Максимальное количество попыток при rate limit
     * @return array Массив строк с данными
     * @throws Exception
     */
    public function getSheetData(string $spreadsheetId, string $sheetName, int $maxRetries = 3): array
    {
        if (!$this->isAuthenticated()) {
            throw new Exception('Требуется авторизация. Вызовите authenticate() для получения URL авторизации.');
        }

        $lastException = null;
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
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
                $lastException = $e;
                
                // Проверяем, является ли это ошибкой rate limit
                if ($this->isRateLimitError($e)) {
                    if ($attempt < $maxRetries) {
                        // Вычисляем задержку: экспоненциальная задержка с базовой задержкой 60 секунд
                        // (так как лимит 60 запросов в минуту)
                        $delay = 60 + ($attempt * 10); // 60, 70, 80 секунд
                        error_log("[GoogleSheetsService::getSheetData] Rate limit превышен (попытка {$attempt}/{$maxRetries}). Ожидание {$delay} секунд...");
                        sleep($delay);
                        continue; // Повторяем попытку
                    } else {
                        // Превышено количество попыток
                        error_log("[GoogleSheetsService::getSheetData] Rate limit: превышено количество попыток ({$maxRetries})");
                        throw new Exception(
                            'Превышен лимит запросов к Google Sheets API (60 запросов в минуту). ' .
                            'Пожалуйста, подождите минуту и попробуйте снова. ' .
                            'Или запросите увеличение квоты в Google Cloud Console: ' .
                            'https://cloud.google.com/docs/quotas/help/request_increase'
                        );
                    }
                }
                
                // Если это не rate limit ошибка, пробрасываем исключение
                error_log("[GoogleSheetsService::getSheetData] Ошибка: " . $e->getMessage());
                throw new Exception('Ошибка получения данных листа: ' . $e->getMessage());
            }
        }
        
        // Если дошли сюда, значит все попытки исчерпаны
        if ($lastException) {
            throw $lastException;
        }
        
        throw new Exception('Не удалось получить данные листа после ' . $maxRetries . ' попыток');
    }

    /**
     * Парсинг данных листа Google Sheets
     * Извлекает UUID и Person Brizy из данных листа
     * 
     * @param array $data Массив строк из Google Sheets API
     * @return array Массив объектов с полями uuid и person_brizy
     * @throws Exception
     */
    public function parseSheetData(array $data): array
    {
        if (empty($data)) {
            return [];
        }

        // Первая строка - заголовки
        $headers = array_map('trim', $data[0]);
        
        // Находим индексы колонок UUID и Person Brizy
        $uuidIndex = null;
        $personBrizyIndex = null;
        
        // Выводим заголовки для отладки
        error_log("[GoogleSheetsService::parseSheetData] Заголовки: " . json_encode($headers, JSON_UNESCAPED_UNICODE));
        
        foreach ($headers as $index => $header) {
            $normalizedHeader = strtolower(trim($header));
            
            // Поиск колонки UUID (поддержка разных регистров)
            if ($uuidIndex === null && (
                $normalizedHeader === 'uuid' || 
                $normalizedHeader === 'mb_uuid' ||
                $normalizedHeader === 'mb project uuid' ||
                $normalizedHeader === 'mb-project-uuid' ||
                strpos($normalizedHeader, 'uuid') !== false
            )) {
                $uuidIndex = $index;
                error_log("[GoogleSheetsService::parseSheetData] Найдена колонка UUID на индексе {$index}: '{$header}'");
            }
            
            // Поиск колонки Person Brizy (поддержка разных форматов)
            if ($personBrizyIndex === null && (
                $normalizedHeader === 'person brizy' ||
                $normalizedHeader === 'personbrizy' ||
                $normalizedHeader === 'person_brizy' ||
                $normalizedHeader === 'person-brizy' ||
                $normalizedHeader === 'reviewer' ||
                $normalizedHeader === 'reviewer name' ||
                $normalizedHeader === 'reviewer_name' ||
                strpos($normalizedHeader, 'person') !== false && strpos($normalizedHeader, 'brizy') !== false
            )) {
                $personBrizyIndex = $index;
                error_log("[GoogleSheetsService::parseSheetData] Найдена колонка Person Brizy на индексе {$index}: '{$header}'");
            }
        }
        
        // Проверяем наличие обязательной колонки UUID
        if ($uuidIndex === null) {
            throw new Exception('Колонка UUID не найдена в таблице. Проверьте заголовки листа.');
        }
        
        // Если Person Brizy не найдена, используем null (не критично)
        if ($personBrizyIndex === null) {
            error_log("[GoogleSheetsService::parseSheetData] Предупреждение: колонка 'Person Brizy' не найдена, будет использовано null");
        }
        
        // Извлекаем данные из строк (начиная со второй строки)
        $result = [];
        for ($i = 1; $i < count($data); $i++) {
            $row = $data[$i];
            
            // Пропускаем пустые строки
            if (empty($row) || (count($row) === 1 && empty(trim($row[0] ?? '')))) {
                continue;
            }
            
            $uuid = isset($row[$uuidIndex]) ? trim($row[$uuidIndex] ?? '') : '';
            $personBrizy = ($personBrizyIndex !== null && isset($row[$personBrizyIndex])) 
                ? trim($row[$personBrizyIndex] ?? '') 
                : null;
            
            // Пропускаем строки с пустым UUID
            if (empty($uuid)) {
                continue;
            }
            
            // Валидация UUID (базовая проверка формата)
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
                error_log("[GoogleSheetsService::parseSheetData] Предупреждение: UUID '{$uuid}' не соответствует формату UUID v4, строка " . ($i + 1));
                // Не пропускаем, так как это может быть валидный UUID в другом формате
            }
            
            $result[] = [
                'uuid' => $uuid,
                'person_brizy' => !empty($personBrizy) ? $personBrizy : null
            ];
        }
        
        return $result;
    }

    /**
     * Найти индекс колонки по заголовкам (нормализация: нижний регистр, пробелы/дефисы/подчёркивания).
     * 
     * @param array $headers Первая строка листа (заголовки)
     * @param array $normalizedNames Список допустимых нормализованных имён (например ['website brizy', 'website-brizy'])
     * @return int|null Индекс колонки или null если не найдена
     */
    private function findColumnIndexByHeaders(array $headers, array $normalizedNames): ?int
    {
        foreach ($headers as $index => $header) {
            $normalized = strtolower(trim(preg_replace('/[\s_\-]+/', ' ', (string)$header)));
            foreach ($normalizedNames as $name) {
                if ($normalized === $name || strpos($normalized, $name) !== false) {
                    return $index;
                }
            }
        }
        return null;
    }

    /**
     * Преобразовать индекс колонки (0-based) в букву A1 (A, B, ..., Z, AA, ...)
     */
    private function columnIndexToA1Letter(int $index): string
    {
        $letter = '';
        while ($index >= 0) {
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = (int)floor($index / 26) - 1;
        }
        return $letter;
    }

    /**
     * Обновить одну ячейку в листе через Google Sheets API (с учётом rate limit).
     *
     * @param string $spreadsheetId
     * @param string $sheetName Название листа
     * @param string $rangeA1 Диапазон в формате A1, например "E5"
     * @param string $value Значение для записи
     * @param int $maxRetries
     * @throws Exception
     */
    private function updateSheetCell(string $spreadsheetId, string $sheetName, string $rangeA1, string $value, int $maxRetries = 3): void
    {
        if (!$this->isAuthenticated()) {
            throw new Exception('Требуется авторизация. Вызовите authenticate() для получения URL авторизации.');
        }

        $fullRange = strpos($sheetName, '!') !== false ? $sheetName : $sheetName . '!' . $rangeA1;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $this->service = new Google_Service_Sheets($this->client);
                $body = new \Google_Service_Sheets_ValueRange([
                    'values' => [[$value]],
                ]);
                $params = ['valueInputOption' => 'USER_ENTERED'];
                $this->service->spreadsheets_values->update($spreadsheetId, $fullRange, $body, $params);
                return;
            } catch (Exception $e) {
                $lastException = $e;
                if ($this->isRateLimitError($e) && $attempt < $maxRetries) {
                    $delay = 60 + ($attempt * 10);
                    error_log("[GoogleSheetsService::updateSheetCell] Rate limit (попытка {$attempt}/{$maxRetries}). Ожидание {$delay} с...");
                    sleep($delay);
                    continue;
                }
                throw new Exception('Ошибка обновления ячейки: ' . $e->getMessage());
            }
        }

        if ($lastException) {
            throw $lastException;
        }
    }

    /**
     * При успешном завершении миграции записать URL сайта в колонку "Website-Brizy" строки с данным UUID.
     * Ищет миграцию по mb_project_uuid → wave_id, листы по wave_id, в листе — строку по колонке UUID, обновляет ячейку Website-Brizy.
     *
     * @param string $mbProjectUuid UUID проекта (mb_project_uuid)
     * @param string $websiteUrl URL сайта миграции (brizy_project_domain); при отсутствии схемы дополняется https://
     */
    public function updateWebsiteBrizyForMigration(string $mbProjectUuid, string $websiteUrl): void
    {
        $mbProjectUuid = trim($mbProjectUuid);
        $websiteUrl = trim($websiteUrl);
        if ($mbProjectUuid === '' || $websiteUrl === '') {
            return;
        }

        if (!preg_match('#^https?://#i', $websiteUrl)) {
            $websiteUrl = 'https://' . $websiteUrl;
        }

        try {
            $migration = $this->dbService->getMigrationByUuid($mbProjectUuid);
            if (!$migration || empty($migration['wave_id'])) {
                error_log("[GoogleSheetsService::updateWebsiteBrizyForMigration] Миграция не найдена или без wave_id: {$mbProjectUuid}");
                return;
            }

            $sheets = $this->getSheetsByWave($migration['wave_id']);
            if (empty($sheets)) {
                error_log("[GoogleSheetsService::updateWebsiteBrizyForMigration] Нет листов для волны: " . $migration['wave_id']);
                return;
            }

            if (!$this->isAuthenticated()) {
                error_log("[GoogleSheetsService::updateWebsiteBrizyForMigration] Google Sheets не авторизован.");
                return;
            }

            $uuidNormalizedNames = ['uuid', 'mb uuid', 'mb-uuid', 'mb_uuid'];
            $websiteBrizyNormalizedNames = ['website brizy', 'website-brizy', 'website_brizy', 'websitebrizy'];

            foreach ($sheets as $sheet) {
                $spreadsheetId = $sheet['spreadsheet_id'] ?? null;
                $sheetName = $sheet['sheet_name'] ?? null;
                if (!$spreadsheetId || !$sheetName) {
                    continue;
                }

                try {
                    $data = $this->getSheetData($spreadsheetId, $sheetName);
                    if (empty($data)) {
                        continue;
                    }

                    $headers = array_map('trim', $data[0]);
                    $uuidIndex = $this->findColumnIndexByHeaders($headers, $uuidNormalizedNames);
                    $websiteBrizyIndex = $this->findColumnIndexByHeaders($headers, $websiteBrizyNormalizedNames);

                    if ($uuidIndex === null) {
                        error_log("[GoogleSheetsService::updateWebsiteBrizyForMigration] Колонка UUID не найдена в листе: {$sheetName}");
                        continue;
                    }
                    if ($websiteBrizyIndex === null) {
                        error_log("[GoogleSheetsService::updateWebsiteBrizyForMigration] Колонка Website-Brizy не найдена в листе: {$sheetName}");
                        continue;
                    }

                    $dataRowIndex = null;
                    for ($i = 1; $i < count($data); $i++) {
                        $row = $data[$i];
                        $cellUuid = isset($row[$uuidIndex]) ? trim((string)($row[$uuidIndex] ?? '')) : '';
                        if ($cellUuid === $mbProjectUuid) {
                            $dataRowIndex = $i;
                            break;
                        }
                    }

                    if ($dataRowIndex === null) {
                        error_log("[GoogleSheetsService::updateWebsiteBrizyForMigration] Строка с UUID {$mbProjectUuid} не найдена в листе: {$sheetName}");
                        continue;
                    }

                    $sheetRowNumber = $dataRowIndex + 2; // 1-based, row 1 = headers
                    $colLetter = $this->columnIndexToA1Letter($websiteBrizyIndex);
                    $rangeA1 = $colLetter . $sheetRowNumber;

                    $this->updateSheetCell($spreadsheetId, $sheetName, $rangeA1, $websiteUrl);
                    error_log("[GoogleSheetsService::updateWebsiteBrizyForMigration] Обновлена ячейка {$rangeA1} в листе {$sheetName} для UUID {$mbProjectUuid}");
                } catch (Exception $e) {
                    error_log("[GoogleSheetsService::updateWebsiteBrizyForMigration] Ошибка для листа {$sheetName}: " . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            error_log("[GoogleSheetsService::updateWebsiteBrizyForMigration] Ошибка: " . $e->getMessage());
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
                    
                    // Отслеживание названия уже выполнено в getSpreadsheet, не нужно вызывать повторно
                    
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
