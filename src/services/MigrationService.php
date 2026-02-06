<?php

namespace Dashboard\Services;

use Exception;

/**
 * MigrationService
 * 
 * Бизнес-логика для работы с миграциями
 */
class MigrationService
{
    /**
     * @var DatabaseService
     */
    private $dbService;
    /**
     * @var ApiProxyService
     */
    private $apiProxy;

    public function __construct()
    {
        $this->dbService = new DatabaseService();
        $this->apiProxy = new ApiProxyService();
    }

    /**
     * Получить список всех миграций с объединенными данными
     * 
     * @param array $filters
     * @return array
     * @throws Exception
     */
    public function getMigrationsList(array $filters = [], ?int $limit = 1000): array
    {
        $mappings = $this->dbService->getMigrationsList($limit);
        $results = $this->dbService->getMigrationResults($limit ?: 1000);

        // Индекс результатов по (mb_uuid, brz_id) для O(1) поиска вместо O(n) в цикле
        $resultIndex = [];
        foreach ($results as $res) {
            $key = $res['mb_project_uuid'] . '|' . $res['brz_project_id'];
            if (!isset($resultIndex[$key])) {
                $resultIndex[$key] = $res;
            }
        }

        $migrations = [];
        foreach ($mappings as $mapping) {
            $mbUuid = $mapping['mb_project_uuid'];
            $brzId = $mapping['brz_project_id'];
            $result = $resultIndex[$mbUuid . '|' . $brzId] ?? null;

            $resultData = $result ? json_decode($result['result_json'] ?? '{}', true) : null;
            
            $migration = [
                'id' => $brzId,
                'mb_project_uuid' => $mbUuid,
                'brz_project_id' => $brzId,
                'created_at' => $mapping['created_at'],
                'updated_at' => $mapping['updated_at'],
                'changes_json' => json_decode($mapping['changes_json'] ?? '{}', true),
                'status' => $this->determineStatus($result),
                'result' => $resultData,
                'migration_uuid' => $result['migration_uuid'] ?? null,
                // Дополнительные поля из результата миграции
                'brizy_project_domain' => $resultData['brizy_project_domain'] ?? null,
                'mb_project_domain' => $resultData['mb_project_domain'] ?? null,
                'mb_site_id' => $resultData['mb_site_id'] ?? null,
                'mb_product_name' => $resultData['mb_product_name'] ?? null,
                'theme' => $resultData['theme'] ?? null,
                'progress' => $resultData['progress'] ?? null,
                'migration_id' => $resultData['migration_id'] ?? null,
                'date' => $resultData['date'] ?? null,
            ];

            // Применяем фильтры
            if ($this->matchesFilters($migration, $filters)) {
                $migrations[] = $migration;
            }
        }

        return $migrations;
    }

    /**
     * Определить статус миграции
     * 
     * @param array|null $result
     * @return string
     */
    private function determineStatus(?array $result, ?array $mapping = null): string
    {
        // Сначала проверяем статус из changes_json в mapping (это обновляется wrapper скриптом)
        if ($mapping && !empty($mapping['changes_json'])) {
            $changesJson = is_string($mapping['changes_json']) 
                ? json_decode($mapping['changes_json'], true) 
                : $mapping['changes_json'];
            
            if (isset($changesJson['status'])) {
                if ($changesJson['status'] === 'completed') {
                    return 'completed';
                }
                if ($changesJson['status'] === 'in_progress') {
                    return 'in_progress';
                }
                if ($changesJson['status'] === 'error') {
                    return 'error';
                }
            }
        }
        
        // Затем проверяем статус из result (таблица migration_result_list)
        if (!$result) {
            return 'pending';
        }

        $resultData = json_decode($result['result_json'] ?? '{}', true);
        
        // Проверяем статус в данных результата (может быть в value.status)
        $status = null;
        if (isset($resultData['value']['status'])) {
            $status = $resultData['value']['status'];
        } elseif (isset($resultData['status'])) {
            $status = $resultData['status'];
        }
        
        if ($status) {
            if ($status === 'success') {
                // Если status = "success", это означает completed
                return 'completed';
            }
            if ($status === 'completed') {
                return 'completed';
            }
            if ($status === 'error' || $status === 'failed') {
                return 'error';
            }
        }

        // Если есть данные о прогрессе, считаем что в процессе
        if (isset($resultData['value']['progress']) || isset($resultData['progress']) || 
            isset($resultData['value']['brizy_project_id']) || isset($resultData['brizy_project_id'])) {
            return 'in_progress';
        }

        return 'pending';
    }

    /**
     * Проверить соответствие фильтрам
     * 
     * @param array $migration
     * @param array $filters
     * @return bool
     */
    private function matchesFilters(array $migration, array $filters): bool
    {
        if (isset($filters['status']) && $migration['status'] !== $filters['status']) {
            return false;
        }

        if (isset($filters['mb_project_uuid']) && 
            strpos($migration['mb_project_uuid'], $filters['mb_project_uuid']) === false) {
            return false;
        }

        if (isset($filters['brz_project_id']) && 
            $migration['brz_project_id'] != $filters['brz_project_id']) {
            return false;
        }

        return true;
    }

    /**
     * Запустить миграцию
     * 
     * @param array $params
     * @return array
     * @throws Exception
     */
    public function runMigration(array $params): array
    {
        error_log('[MIG] MigrationService::runMigration — вход: mb_project_uuid=' . ($params['mb_project_uuid'] ?? '') . ', brz_project_id=' . ($params['brz_project_id'] ?? ''));
        // Получаем настройки по умолчанию
        $defaultSettings = $this->dbService->getSettings();
        
        // Используем настройки по умолчанию, если параметры не переданы
        if (empty($params['mb_site_id']) && !empty($defaultSettings['mb_site_id'])) {
            $params['mb_site_id'] = $defaultSettings['mb_site_id'];
        }
        if (empty($params['mb_secret']) && !empty($defaultSettings['mb_secret'])) {
            $params['mb_secret'] = $defaultSettings['mb_secret'];
        }
        
        // НЕ создаем запись в migrations_mapping при запуске
        // Запись будет создана только после успешного завершения миграции
        $brzProjectId = (int)($params['brz_project_id'] ?? 0);
        $mbProjectUuid = $params['mb_project_uuid'] ?? '';
        
        try {
            error_log('[MIG] MigrationService::runMigration — вызываем ApiProxyService::runMigration');
            // Запускаем через прокси
            $result = $this->apiProxy->runMigration($params);
            error_log('[MIG] MigrationService::runMigration — ответ прокси: success=' . (isset($result['success']) && $result['success'] ? 'true' : 'false') . ', http_code=' . ($result['http_code'] ?? 'n/a'));
            
            // Проверяем, что результат содержит необходимые данные
            if (!isset($result['success']) || !isset($result['data'])) {
                return [
                    'success' => false,
                    'http_code' => 500,
                    'data' => ['error' => 'Некорректный ответ от API миграции'],
                    'raw_data' => $result
                ];
            }
            
            $migrationData = $result['data'] ?? [];
            
            // Если миграция успешно запущена (даже с предупреждением о подключении), возвращаем успех
            if ($result['success'] && isset($result['data']['status']) && $result['data']['status'] === 'in_progress') {
                return $result;
            }
            
            // Проверяем, что данные миграции есть
            if (empty($migrationData)) {
                // Если миграция не успешна и нет данных, возвращаем ошибку
                if (!$result['success']) {
                    $errorMessage = 'Миграция завершилась с ошибкой';
                    if (isset($result['data']['error'])) {
                        $errorMessage = is_string($result['data']['error']) ? $result['data']['error'] : json_encode($result['data']['error']);
                    } elseif (isset($result['raw_data']['error'])) {
                        $errorMessage = is_string($result['raw_data']['error']) ? $result['raw_data']['error'] : json_encode($result['raw_data']['error']);
                    }
                    
                    return [
                        'success' => false,
                        'http_code' => isset($result['http_code']) ? (int)$result['http_code'] : 400,
                        'data' => ['error' => $errorMessage],
                        'raw_data' => $result['raw_data'] ?? ['error' => $errorMessage]
                    ];
                }
                
                return [
                    'success' => false,
                    'http_code' => 500,
                    'data' => ['error' => 'Пустой ответ от API миграции'],
                    'raw_data' => $result
                ];
            }
            
            // Если миграция не успешна, но есть данные (миграция реально выполнялась)
            // Продолжаем обработку, чтобы создать запись в migrations_mapping
            if (!$result['success']) {
                // Логируем ошибку, но продолжаем обработку
                $errorMessage = 'Миграция завершилась с ошибкой';
                if (isset($migrationData['error'])) {
                    $errorMessage = is_string($migrationData['error']) ? $migrationData['error'] : json_encode($migrationData['error']);
                }
                error_log("Миграция завершилась с ошибкой, но данные есть: " . $errorMessage);
            }
        } catch (Exception $e) {
            // НЕ создаем запись в migrations_mapping при ошибке прокси
            // Запись будет создана только после реального выполнения миграции
            
            // Если произошла ошибка в прокси, возвращаем её
            return [
                'success' => false,
                'http_code' => 400,
                'data' => ['error' => $e->getMessage()],
                'raw_data' => ['error' => $e->getMessage()]
            ];
        }

        // Сохраняем результат в новую таблицу migrations
        // Используем исходный mbProjectUuid из параметров, если mb_uuid отсутствует в результате
        if (isset($migrationData['brizy_project_id'])) {
            $migrationUuid = time() . random_int(100, 999); // Генерируем UUID для миграции
            $mbUuidToUse = $migrationData['mb_uuid'] ?? $mbProjectUuid;
            
            if (!empty($mbUuidToUse)) {
                try {
                    // Сохраняем в новую таблицу migrations
                    $this->dbService->saveMigration([
                        'migration_uuid' => $migrationUuid,
                        'brz_project_id' => (int)$migrationData['brizy_project_id'],
                        'brizy_project_domain' => $migrationData['brizy_project_domain'] ?? '',
                        'mb_project_uuid' => $mbUuidToUse,
                        'status' => $result['success'] ? 'completed' : 'error',
                        'error' => !$result['success'] ? ($migrationData['error'] ?? 'Миграция завершилась с ошибкой') : null,
                        'mb_site_id' => $params['mb_site_id'] ?? null,
                        'mb_page_slug' => $params['mb_page_slug'] ?? null,
                        'mb_product_name' => $migrationData['mb_product_name'] ?? null,
                        'theme' => $migrationData['theme'] ?? null,
                        'migration_id' => $migrationData['migration_id'] ?? null,
                        'date' => $migrationData['date'] ?? date('Y-m-d'),
                        'wave_id' => $params['wave_id'] ?? null,
                        'result_json' => json_encode($migrationData),
                        'completed_at' => date('Y-m-d H:i:s')
                    ]);
                    
                    // Также сохраняем в старую таблицу для обратной совместимости
                    $this->dbService->saveMigrationResult([
                        'migration_uuid' => $migrationUuid,
                        'brz_project_id' => (int)$migrationData['brizy_project_id'],
                        'brizy_project_domain' => $migrationData['brizy_project_domain'] ?? '',
                        'mb_project_uuid' => $mbUuidToUse,
                        'result_json' => json_encode($migrationData)
                    ]);
                } catch (Exception $e) {
                    // Логируем ошибку, но не прерываем выполнение
                    error_log("Ошибка сохранения результата миграции: " . $e->getMessage());
                }
            } else {
                error_log("Ошибка: не удалось определить mb_project_uuid для сохранения результата миграции");
            }
        }

        // НЕ сохраняем в migrations_mapping из MigrationService
        // migrations_mapping используется только для волн (WaveService)
        // Все записи о миграциях сохраняются в новую таблицу migrations

        return $result;
    }

    /**
     * Проверить и обновить статус миграции, если процесс не найден
     * 
     * @param string $mbUuid UUID проекта MB
     * @param int $brzProjectId ID проекта Brizy
     * @param array $mapping Данные маппинга
     * @return bool true если статус был обновлен
     */
    private function checkAndUpdateStaleStatus(string $mbUuid, int $brzProjectId, array $mapping): bool
    {
        // Парсим changes_json
        $changesJson = [];
        if (!empty($mapping['changes_json'])) {
            $changesJson = is_string($mapping['changes_json']) 
                ? json_decode($mapping['changes_json'], true) 
                : $mapping['changes_json'];
        }
        
        // Проверяем, если статус in_progress
        if (!isset($changesJson['status']) || $changesJson['status'] !== 'in_progress') {
            return false;
        }
        
        // Проверяем процесс
        $processInfo = $this->findMigrationProcess($mbUuid, $brzProjectId);
        
        // Если процесс не запущен и lock-файл старый (более 10 минут) или не существует
        if (!$processInfo['running']) {
            $lockFile = $this->getLockFilePath($mbUuid, $brzProjectId);
            $lockFileAge = file_exists($lockFile) ? (time() - filemtime($lockFile)) : 999999;
            
            // Если lock-файл не существует или очень старый (более 10 минут)
            if (!file_exists($lockFile) || $lockFileAge > 600) {
                // КРИТИЧНО: Перед установкой статуса error, проверяем логи миграции
                // Если миграция завершилась успешно, обновляем статус на completed
                $migrationCompleted = $this->checkMigrationCompletedFromLogs($brzProjectId);
                
                try {
                    if ($migrationCompleted) {
                        // Миграция завершилась успешно
                        $changesJson['status'] = 'completed';
                        $changesJson['completed_at'] = date('Y-m-d H:i:s');
                        $changesJson['status_updated_at'] = date('Y-m-d H:i:s');
                        $changesJson['status_source'] = 'log_file_check';
                    } else {
                        // Миграция не завершилась или завершилась с ошибкой
                        $changesJson['status'] = 'error';
                        $changesJson['error'] = 'Процесс миграции был прерван или завершился некорректно. Статус обновлен автоматически.';
                        $changesJson['status_updated_at'] = date('Y-m-d H:i:s');
                    }
                    
                    $this->dbService->upsertMigrationMapping(
                        $brzProjectId,
                        $mbUuid,
                        $changesJson
                    );
                    
                    if ($migrationCompleted) {
                        $url = $changesJson['brizy_project_domain'] ?? null;
                        if (empty($url)) {
                            $result = $this->dbService->getMigrationResultByUuid($mbUuid);
                            $url = $result['brizy_project_domain'] ?? null;
                        }
                        if (!empty($url)) {
                            try {
                                $googleSheetsService = new GoogleSheetsService();
                                $googleSheetsService->updateWebsiteBrizyForMigration($mbUuid, $url);
                            } catch (Exception $e) {
                                error_log("[MigrationService::checkAndUpdateStaleStatus] updateWebsiteBrizyForMigration: " . $e->getMessage());
                            }
                        }
                    }
                    
                    return true;
                } catch (Exception $e) {
                    error_log("Ошибка автоматического обновления статуса: " . $e->getMessage());
                    return false;
                }
            }
        }
        
        return false;
    }

    /**
     * Получить детали миграции
     * 
     * @param int $brzProjectId
     * @return array|null
     * @throws Exception
     */
    public function getMigrationDetails(int $brzProjectId): ?array
    {
        $mapping = $this->dbService->getMigrationById($brzProjectId);
        if (!$mapping) {
            return null;
        }

        // Проверяем и обновляем статус, если процесс не найден
        $this->checkAndUpdateStaleStatus($mapping['mb_project_uuid'], $brzProjectId, $mapping);
        
        // Перезагружаем маппинг после возможного обновления
        $mapping = $this->dbService->getMigrationById($brzProjectId);

        $result = $this->dbService->getMigrationResultByUuid($mapping['mb_project_uuid']);

        $resultData = $result ? json_decode($result['result_json'] ?? '{}', true) : null;
        
        // Парсим changes_json из mapping
        $changesJson = [];
        if (!empty($mapping['changes_json'])) {
            $changesJson = is_string($mapping['changes_json']) 
                ? json_decode($mapping['changes_json'], true) 
                : $mapping['changes_json'];
        }
        
        // Определяем статус: сначала проверяем changes_json из mapping, потом result
        $status = $this->determineStatus($result, $mapping);
        
        // Извлекаем данные из value, если они там находятся
        $migrationValue = null;
        if ($resultData) {
            $migrationValue = $resultData['value'] ?? $resultData;
        }
        
        // Получаем информацию о ревьюере
        $reviewer = null;
        $migrationId = $this->getMigrationIdFromBrzProjectId($brzProjectId);
        if ($migrationId) {
            $reviewer = $this->getMigrationReviewer($migrationId);
        }
        
        return [
            'mapping' => $mapping,
            'result' => $result ? [
                'migration_uuid' => $result['migration_uuid'] ?? null,
                'result_json' => $resultData,
            ] : null,
            'result_data' => $migrationValue, // Добавляем извлеченные данные из value
            'status' => $status,
            'migration_uuid' => $result['migration_uuid'] ?? null,
            'brizy_project_domain' => $migrationValue['brizy_project_domain'] ?? $resultData['brizy_project_domain'] ?? $changesJson['brizy_project_domain'] ?? null,
            'mb_project_domain' => $migrationValue['mb_project_domain'] ?? $resultData['mb_project_domain'] ?? $changesJson['mb_project_domain'] ?? null,
            'progress' => $migrationValue['progress'] ?? $resultData['progress'] ?? null,
            'warnings' => $migrationValue['message']['warning'] ?? $resultData['message']['warning'] ?? [],
            'reviewer' => $reviewer, // Информация о ревьюере
        ];
    }

    /**
     * Синхронизировать статус миграции из ответа сервера миграции (при опросе API).
     * Обновляет migrations_mapping, чтобы getMigrationDetails показывал актуальный статус.
     *
     * @param string $mbUuid
     * @param int $brzProjectId
     * @param array $serverData Ответ GET /migration-status (status, progress, started_at, completed_at, error и т.д.)
     */
    public function syncMigrationStatusFromServer(string $mbUuid, int $brzProjectId, array $serverData): void
    {
        $mapping = $this->dbService->getMigrationById($brzProjectId);
        if (!$mapping) {
            return;
        }

        $changesJson = [];
        if (!empty($mapping['changes_json'])) {
            $changesJson = is_string($mapping['changes_json'])
                ? json_decode($mapping['changes_json'], true)
                : $mapping['changes_json'];
            if (!is_array($changesJson)) {
                $changesJson = [];
            }
        }

        $status = $serverData['status'] ?? $changesJson['status'] ?? 'pending';
        if ($status === 'success') {
            $status = 'completed';
        }
        if (in_array($status, ['failed'], true)) {
            $status = 'error';
        }

        $changesJson['status'] = $status;
        $changesJson['updated_at'] = date('Y-m-d H:i:s');
        if (!empty($serverData['progress']) && is_array($serverData['progress'])) {
            $changesJson['progress'] = $serverData['progress'];
        }
        if (array_key_exists('error', $serverData) && $serverData['error'] !== null && $serverData['error'] !== '') {
            $changesJson['error'] = is_string($serverData['error']) ? $serverData['error'] : json_encode($serverData['error']);
        }
        if (!empty($serverData['completed_at'])) {
            $changesJson['completed_at'] = $serverData['completed_at'];
        }
        if (in_array($status, ['completed', 'error'], true) && empty($changesJson['completed_at'])) {
            $changesJson['completed_at'] = date('Y-m-d H:i:s');
        }

        $this->dbService->upsertMigrationMapping($brzProjectId, $mbUuid, $changesJson);
    }

    /**
     * Получить детали миграции по mb_project_uuid
     * 
     * @param string $mbUuid
     * @return array|null
     * @throws Exception
     */
    public function getMigrationDetailsByUuid(string $mbUuid): ?array
    {
        $mapping = $this->dbService->getMigrationByUuid($mbUuid);
        if (!$mapping) {
            return null;
        }

        $brzProjectId = $mapping['brz_project_id'];
        
        // Используем существующий метод getMigrationDetails
        return $this->getMigrationDetails($brzProjectId);
    }

    /**
     * Получить детали миграции по ID
     * 
     * @param int $migrationId ID миграции из таблицы migrations
     * @return array|null
     * @throws Exception
     */
    public function getMigrationDetailsById(int $migrationId): ?array
    {
        $db = $this->dbService->getWriteConnection();
        
        // Получаем миграцию по ID
        $migration = $db->find(
            'SELECT * FROM migrations WHERE id = ?',
            [$migrationId]
        );
        
        if (!$migration) {
            return null;
        }
        
        $brzProjectId = $migration['brz_project_id'];
        if (!$brzProjectId) {
            return null;
        }
        
        // Используем существующий метод getMigrationDetails
        $details = $this->getMigrationDetails($brzProjectId);
        
        // Добавляем ID миграции и mb_uuid в результат
        if ($details) {
            $details['migration_id'] = $migrationId;
            $details['mb_project_uuid'] = $migration['mb_project_uuid'];
            // Добавляем mb_site_id из записи миграции, если он есть
            if (!empty($migration['mb_site_id'])) {
                $details['mb_site_id'] = $migration['mb_site_id'];
            }
        }
        
        return $details;
    }

    /**
     * Получить путь к lock файлу для миграции
     * 
     * @param string $mbUuid UUID проекта MB
     * @param int $brzProjectId ID проекта Brizy
     * @return string
     */
    private function getLockFilePath(string $mbUuid, int $brzProjectId): string
    {
        $projectRoot = dirname(__DIR__, 3);
        $cachePath = $_ENV['CACHE_PATH'] ?? getenv('CACHE_PATH') ?: $projectRoot . '/var/cache';
        
        return $cachePath . '/' . $mbUuid . '-' . $brzProjectId . '.lock';
    }

    /**
     * Удалить lock-файл миграции
     * 
     * @param string $mbUuid UUID проекта MB
     * @param int $brzProjectId ID проекта Brizy
     * @return array
     * @throws Exception
     */
    public function removeMigrationLock(string $mbUuid, int $brzProjectId): array
    {
        $lockFile = $this->getLockFilePath($mbUuid, $brzProjectId);
        
        if (!file_exists($lockFile)) {
            return [
                'success' => true,
                'message' => 'Lock-файл не найден (возможно, уже удален)',
                'lock_file' => $lockFile,
                'removed' => false
            ];
        }
        
        $cachePath = dirname($lockFile);
        if (!is_writable($lockFile) && !is_writable($cachePath)) {
            throw new Exception('Нет прав на удаление lock-файла: ' . $lockFile);
        }
        
        $removed = @unlink($lockFile);
        
        if (!$removed) {
            throw new Exception('Не удалось удалить lock-файл: ' . $lockFile);
        }
        
        // Обновляем статус миграции в БД, если она была в процессе
        try {
            $mapping = $this->dbService->getMigrationById($brzProjectId);
            if ($mapping && isset($mapping['changes_json'])) {
                $changesJson = is_string($mapping['changes_json']) 
                    ? json_decode($mapping['changes_json'], true) 
                    : $mapping['changes_json'];
                
                // Если статус был in_progress, обновляем на error или pending
                if (isset($changesJson['status']) && $changesJson['status'] === 'in_progress') {
                    $this->dbService->upsertMigrationMapping(
                        $brzProjectId,
                        $mbUuid,
                        [
                            'status' => 'error',
                            'error' => 'Lock-файл удален вручную. Процесс был прерван.',
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]
                    );
                }
            }
        } catch (Exception $e) {
            // Логируем ошибку, но не прерываем выполнение
            error_log("Ошибка обновления статуса миграции при удалении lock-файла: " . $e->getMessage());
        }
        
        return [
            'success' => true,
            'message' => 'Lock-файл успешно удален',
            'lock_file' => $lockFile,
            'removed' => true
        ];
    }

    /**
     * Найти PID процесса миграции по lock файлу
     * 
     * @param string $mbUuid UUID проекта MB
     * @param int $brzProjectId ID проекта Brizy
     * @return array
     */
    public function findMigrationProcess(string $mbUuid, int $brzProjectId): array
    {
        $lockFile = $this->getLockFilePath($mbUuid, $brzProjectId);
        
        // Если lock файл не существует, процесс не запущен
        if (!file_exists($lockFile)) {
            return [
                'success' => true,
                'running' => false,
                'message' => 'Lock-файл не найден, процесс не запущен',
                'pid' => null
            ];
        }

        // Пытаемся прочитать данные из lock-файла (может быть JSON или старый формат)
        $lockContent = @file_get_contents($lockFile);
        $lockData = null;
        $pid = null;
        
        if ($lockContent) {
            // Пытаемся распарсить как JSON
            $decoded = json_decode($lockContent, true);
            if ($decoded && isset($decoded['pid'])) {
                $lockData = $decoded;
                $pid = (int)$decoded['pid'];
            } else {
                // Старый формат - просто текст
                // Пытаемся извлечь PID из старого формата или использовать время модификации файла
            }
        }

        // Проверяем время модификации lock-файла
        $lockFileMtime = filemtime($lockFile);
        $lockFileAge = time() - $lockFileMtime;
        $isRecent = $lockFileAge < 600; // 10 минут
        
        // Если есть PID в lock-файле, проверяем процесс напрямую
        if ($pid && $pid > 0) {
            if ($this->isProcessRunning($pid)) {
                return [
                    'success' => true,
                    'running' => true,
                    'pid' => $pid,
                    'message' => 'Процесс найден по PID из lock-файла',
                    'detected_by' => 'lock_file_pid',
                    'lock_file_age' => $lockFileAge,
                    'started_at' => $lockData['started_at'] ?? null,
                    'current_stage' => $lockData['current_stage'] ?? null,
                    'stage_updated_at' => $lockData['stage_updated_at'] ?? null,
                    'total_pages' => $lockData['total_pages'] ?? null,
                    'processed_pages' => $lockData['processed_pages'] ?? null,
                    'progress_percent' => $lockData['progress_percent'] ?? null
                ];
            } else {
                // PID есть, но процесс не запущен
                return [
                    'success' => true,
                    'running' => false,
                    'pid' => $pid,
                    'message' => sprintf('Процесс с PID %d не найден (возможно, завершен)', $pid),
                    'lock_file_exists' => true,
                    'lock_file_age' => $lockFileAge,
                    'should_update_status' => true
                ];
            }
        }

        // Проверяем статус миграции в БД
        $mapping = null;
        try {
            $mapping = $this->dbService->getMigrationById($brzProjectId);
        } catch (Exception $e) {
            // Игнорируем ошибки БД
        }

        $dbStatusInProgress = false;
        if ($mapping && isset($mapping['changes_json'])) {
            $changesJson = is_string($mapping['changes_json']) 
                ? json_decode($mapping['changes_json'], true) 
                : $mapping['changes_json'];
            $dbStatusInProgress = isset($changesJson['status']) && $changesJson['status'] === 'in_progress';
        }

        // Если lock-файл недавно обновлялся И статус в БД in_progress, считаем процесс запущенным
        if ($isRecent && $dbStatusInProgress) {
            return [
                'success' => true,
                'running' => true,
                'pid' => null, // PID неизвестен, но процесс работает
                'message' => 'Процесс активен (lock-файл недавно обновлен, статус в БД: in_progress)',
                'lock_file_age' => $lockFileAge,
                'detected_by' => 'lock_file_timestamp_and_db_status',
                'current_stage' => $lockData['current_stage'] ?? null,
                'stage_updated_at' => $lockData['stage_updated_at'] ?? null,
                'total_pages' => $lockData['total_pages'] ?? null,
                'processed_pages' => $lockData['processed_pages'] ?? null,
                'progress_percent' => $lockData['progress_percent'] ?? null
            ];
        }

        // Пытаемся найти процесс по lock файлу через lsof
        $lockFilePath = realpath($lockFile);
        if ($lockFilePath) {
            $command = sprintf('lsof -t "%s" 2>/dev/null', escapeshellarg($lockFilePath));
            $output = shell_exec($command);
            if ($output) {
                $pids = array_filter(array_map('trim', explode("\n", trim($output))));
                if (!empty($pids)) {
                    $pid = (int)$pids[0];
                    // Проверяем, что процесс действительно существует
                    if ($this->isProcessRunning($pid)) {
                        return [
                            'success' => true,
                            'running' => true,
                            'pid' => $pid,
                            'message' => 'Процесс найден через lsof',
                            'detected_by' => 'lsof'
                        ];
                    }
                }
            }
        }

        // Поиск процессов PHP, которые могут выполнять миграцию
        // Ищем процессы PHP, которые работают с migration или содержат brz_project_id
        $searchPatterns = [
            sprintf('migration.*%d', $brzProjectId),
            sprintf('brz_project_id.*%d', $brzProjectId),
            sprintf('migration_wrapper.*%d', $brzProjectId),
        ];

        foreach ($searchPatterns as $pattern) {
            $command = sprintf('ps aux | grep -E "%s" | grep -v grep 2>/dev/null', escapeshellarg($pattern));
            $output = shell_exec($command);
            
            if ($output) {
                $lines = array_filter(explode("\n", trim($output)));
                foreach ($lines as $line) {
                    if (preg_match('/^\s*(\d+)/', $line, $matches)) {
                        $pid = (int)$matches[1];
                        if ($this->isProcessRunning($pid)) {
                            return [
                                'success' => true,
                                'running' => true,
                                'pid' => $pid,
                                'message' => 'Процесс найден по команде',
                                'detected_by' => 'ps_grep'
                            ];
                        }
                    }
                }
            }
        }

        // Поиск процессов PHP, которые работают с lock-файлом через fuser
        $command = sprintf('fuser "%s" 2>/dev/null', escapeshellarg($lockFilePath));
        $output = shell_exec($command);
        if ($output && preg_match('/(\d+)/', $output, $matches)) {
            $pid = (int)$matches[1];
            if ($this->isProcessRunning($pid)) {
                return [
                    'success' => true,
                    'running' => true,
                    'pid' => $pid,
                    'message' => 'Процесс найден через fuser',
                    'detected_by' => 'fuser'
                ];
            }
        }

        // Если lock-файл существует и статус в БД in_progress, но процесс не найден,
        // проверяем возраст lock-файла
        if ($dbStatusInProgress) {
            // Если lock-файл недавно обновлялся (менее 10 минут), считаем процесс активным
            // Это может быть синхронный запрос через веб-сервер (PHP-FPM/Apache)
            if ($isRecent) {
                return [
                    'success' => true,
                    'running' => true,
                    'pid' => null,
                    'message' => 'Процесс активен (статус в БД: in_progress, lock-файл недавно обновлен)',
                    'lock_file_exists' => true,
                    'lock_file_age' => $lockFileAge,
                    'detected_by' => 'db_status_and_recent_lock',
                    'current_stage' => $lockData['current_stage'] ?? null,
                    'stage_updated_at' => $lockData['stage_updated_at'] ?? null,
                    'total_pages' => $lockData['total_pages'] ?? null,
                    'processed_pages' => $lockData['processed_pages'] ?? null,
                    'progress_percent' => $lockData['progress_percent'] ?? null
                ];
            } else {
                // Lock-файл старый, процесс скорее всего не запущен
                return [
                    'success' => true,
                    'running' => false,
                    'pid' => null,
                    'message' => 'Lock-файл существует, но процесс не найден. Lock-файл не обновлялся более ' . round($lockFileAge / 60) . ' минут.',
                    'lock_file_exists' => true,
                    'lock_file_age' => $lockFileAge,
                    'should_update_status' => true
                ];
            }
        }

        // Lock файл существует, но процесс не найден и статус не in_progress
        return [
            'success' => true,
            'running' => false,
            'message' => 'Lock-файл существует, но процесс не найден (возможно, процесс был прерван)',
            'pid' => null,
            'lock_file_exists' => true,
            'lock_file_age' => $lockFileAge
        ];
    }

    /**
     * Проверить, запущен ли процесс
     * 
     * @param int $pid
     * @return bool
     */
    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        
        // Используем posix_kill с сигналом 0 для проверки существования процесса
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        
        // Альтернативный способ через ps
        $command = sprintf('ps -p %d > /dev/null 2>&1', $pid);
        exec($command, $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Убить процесс миграции
     * 
     * @param string $mbUuid UUID проекта MB
     * @param int $brzProjectId ID проекта Brizy
     * @param bool $force Принудительное завершение (SIGKILL вместо SIGTERM)
     * @return array
     * @throws Exception
     */
    public function killMigrationProcess(string $mbUuid, int $brzProjectId, bool $force = false): array
    {
        $processInfo = $this->findMigrationProcess($mbUuid, $brzProjectId);
        
        if (!$processInfo['running'] || !$processInfo['pid']) {
            return [
                'success' => true,
                'killed' => false,
                'message' => 'Процесс не найден или не запущен',
                'pid' => null
            ];
        }
        
        $pid = $processInfo['pid'];
        // Используем числовые значения сигналов вместо констант
        $signal = $force ? 9 : 15; // SIGKILL = 9, SIGTERM = 15
        
        // Пытаемся убить процесс
        if (function_exists('posix_kill')) {
            $killed = @posix_kill($pid, $signal);
        } else {
            // Альтернативный способ через kill команду
            $signalName = $force ? 'KILL' : 'TERM';
            $command = sprintf('kill -%s %d 2>&1', $signalName, $pid);
            exec($command, $output, $returnCode);
            $killed = $returnCode === 0;
        }
        
        if (!$killed) {
            throw new Exception('Не удалось завершить процесс ' . $pid);
        }
        
        // Ждем немного, чтобы процесс завершился
        usleep(500000); // 0.5 секунды
        
        // Проверяем, завершился ли процесс
        $stillRunning = $this->isProcessRunning($pid);
        
        if ($stillRunning && !$force) {
            // Если процесс все еще работает, пробуем принудительно
            return $this->killMigrationProcess($mbUuid, $brzProjectId, true);
        }
        
        // Обновляем статус миграции в БД, если она была в процессе
        if (!$stillRunning) {
            try {
                $mapping = $this->dbService->getMigrationById($brzProjectId);
                if ($mapping && isset($mapping['changes_json'])) {
                    $changesJson = is_string($mapping['changes_json']) 
                        ? json_decode($mapping['changes_json'], true) 
                        : $mapping['changes_json'];
                    
                    // Если статус был in_progress, обновляем на error
                    if (isset($changesJson['status']) && $changesJson['status'] === 'in_progress') {
                        $this->dbService->upsertMigrationMapping(
                            $brzProjectId,
                            $mbUuid,
                            [
                                'status' => 'error',
                                'error' => 'Процесс миграции был завершен вручную (PID: ' . $pid . ')',
                                'updated_at' => date('Y-m-d H:i:s'),
                            ]
                        );
                    }
                }
            } catch (Exception $e) {
                // Логируем ошибку, но не прерываем выполнение
                error_log("Ошибка обновления статуса миграции при завершении процесса: " . $e->getMessage());
            }
        }
        
        return [
            'success' => true,
            'killed' => !$stillRunning,
            'pid' => $pid,
            'force' => $force,
            'message' => $stillRunning ? 'Процесс не завершился' : 'Процесс успешно завершен'
        ];
    }

    /**
     * Получить информацию о процессе миграции (мониторинг)
     * 
     * @param string $mbUuid UUID проекта MB
     * @param int $brzProjectId ID проекта Brizy
     * @return array
     */
    public function getMigrationProcessInfo(string $mbUuid, int $brzProjectId): array
    {
        // Получаем маппинг для проверки статуса
        $mapping = null;
        try {
            $mapping = $this->dbService->getMigrationById($brzProjectId);
        } catch (Exception $e) {
            // Игнорируем ошибки БД
        }
        
        $lockFile = $this->getLockFilePath($mbUuid, $brzProjectId);
        $processInfo = $this->findMigrationProcess($mbUuid, $brzProjectId);
        
        // Если lock-файл не существует и процесс не запущен, проверяем логи
        // чтобы определить, завершилась ли миграция успешно
        if (!file_exists($lockFile) && !$processInfo['running']) {
            // Проверяем и обновляем статус, если процесс не найден
            if ($mapping) {
                $this->checkAndUpdateStaleStatus($mbUuid, $brzProjectId, $mapping);
            } else {
                // Если маппинга нет, но есть lock-файл был, проверяем логи напрямую
                // и обновляем статус в migration_result_list
                $migrationCompleted = $this->checkMigrationCompletedFromLogs($brzProjectId);
                if ($migrationCompleted) {
                    try {
                        // Обновляем статус в migration_result_list
                        $dbService = new \Dashboard\Services\DatabaseService();
                        $db = $dbService->getWriteConnection();
                        $migrationResult = $db->find(
                            'SELECT * FROM migration_result_list WHERE brz_project_id = ? ORDER BY created_at DESC LIMIT 1',
                            [$brzProjectId]
                        );
                        
                        if ($migrationResult) {
                            $resultJson = json_decode($migrationResult['result_json'] ?? '{}', true);
                            if (!isset($resultJson['status']) || $resultJson['status'] !== 'completed') {
                                $resultJson['status'] = 'completed';
                                $resultJson['completed_at'] = date('Y-m-d H:i:s');
                                $resultJson['status_source'] = 'log_file_check';
                                
                                // Используем рефлексию для доступа к PDO
                                $reflection = new \ReflectionClass($db);
                                $pdoProperty = $reflection->getProperty('pdo');
                                $pdoProperty->setAccessible(true);
                                $pdo = $pdoProperty->getValue($db);
                                
                                $stmt = $pdo->prepare(
                                    'UPDATE migration_result_list SET result_json = ? WHERE brz_project_id = ?'
                                );
                                $stmt->execute([json_encode($resultJson), $brzProjectId]);
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Ошибка обновления статуса из логов: " . $e->getMessage());
                    }
                }
            }
        } elseif ($mapping) {
            // Проверяем и обновляем статус, если процесс не найден
            $this->checkAndUpdateStaleStatus($mbUuid, $brzProjectId, $mapping);
        }
        
        $result = [
            'success' => true,
            'lock_file_exists' => file_exists($lockFile),
            'lock_file' => $lockFile,
            'process' => $processInfo
        ];
        
        // Если процесс запущен, получаем дополнительную информацию
        if ($processInfo['running'] && $processInfo['pid']) {
            $pid = $processInfo['pid'];
            
            // Получаем информацию о процессе через ps
            $command = sprintf('ps -p %d -o pid,ppid,user,start,time,cmd --no-headers 2>/dev/null', $pid);
            $output = shell_exec($command);
            
            if ($output) {
                $parts = preg_split('/\s+/', trim($output), 6);
                if (count($parts) >= 6) {
                    $result['process_details'] = [
                        'pid' => (int)$parts[0],
                        'ppid' => (int)$parts[1],
                        'user' => $parts[2],
                        'start' => $parts[3],
                        'time' => $parts[4],
                        'command' => $parts[5]
                    ];
                }
            }
        }
        
        // Добавляем информацию о том, был ли статус обновлен
        if (isset($processInfo['should_update_status']) && $processInfo['should_update_status']) {
            $result['status_updated'] = true;
            $result['message'] = 'Статус миграции был автоматически обновлен, так как процесс не найден.';
        }
        
        return $result;
    }

    /**
     * Получить путь к кэш-файлу миграции
     * 
     * @param string $mbUuid UUID проекта MB
     * @param int $brzProjectId ID проекта Brizy
     * @return string
     */
    private function getCacheFilePath(string $mbUuid, int $brzProjectId): string
    {
        $projectRoot = dirname(__DIR__, 3);
        $cachePath = $_ENV['CACHE_PATH'] ?? getenv('CACHE_PATH') ?: $projectRoot . '/var/cache';
        
        // Формат кэш-файла: md5($mbUuid.$brzProjectId)-$brzProjectId.json
        $cacheFileName = md5($mbUuid . $brzProjectId) . '-' . $brzProjectId . '.json';
        
        return $cachePath . '/' . $cacheFileName;
    }

    /**
     * Удалить кэш-файл миграции
     * 
     * @param string $mbUuid UUID проекта MB
     * @param int $brzProjectId ID проекта Brizy
     * @return array
     * @throws Exception
     */
    public function removeMigrationCache(string $mbUuid, int $brzProjectId): array
    {
        $cacheFile = $this->getCacheFilePath($mbUuid, $brzProjectId);
        
        if (!file_exists($cacheFile)) {
            return [
                'success' => true,
                'message' => 'Кэш-файл не найден (возможно, уже удален)',
                'cache_file' => $cacheFile,
                'removed' => false
            ];
        }
        
        $cachePath = dirname($cacheFile);
        if (!is_writable($cacheFile) && !is_writable($cachePath)) {
            throw new Exception('Нет прав на удаление кэш-файла: ' . $cacheFile);
        }
        
        $removed = @unlink($cacheFile);
        
        if (!$removed) {
            throw new Exception('Не удалось удалить кэш-файл: ' . $cacheFile);
        }
        
        return [
            'success' => true,
            'message' => 'Кэш-файл успешно удален',
            'cache_file' => $cacheFile,
            'removed' => true
        ];
    }

    /**
     * Сбросить статус миграции
     * 
     * @param string $mbUuid UUID проекта MB
     * @param int $brzProjectId ID проекта Brizy
     * @return array
     * @throws Exception
     */
    public function resetMigrationStatus(string $mbUuid, int $brzProjectId): array
    {
        try {
            // Получаем текущий маппинг
            $mapping = $this->dbService->getMigrationById($brzProjectId);
            
            if (!$mapping) {
                throw new Exception('Миграция не найдена');
            }

            // Парсим changes_json
            $changesJson = [];
            if (!empty($mapping['changes_json'])) {
                $changesJson = is_string($mapping['changes_json']) 
                    ? json_decode($mapping['changes_json'], true) 
                    : $mapping['changes_json'];
            }

            // Сбрасываем статус на pending
            $changesJson['status'] = 'pending';
            unset($changesJson['error']);
            unset($changesJson['started_at']);
            $changesJson['reset_at'] = date('Y-m-d H:i:s');

            // Обновляем в БД
            $this->dbService->upsertMigrationMapping(
                $brzProjectId,
                $mbUuid,
                $changesJson
            );

            return [
                'success' => true,
                'message' => 'Статус миграции успешно сброшен на pending',
                'new_status' => 'pending'
            ];
        } catch (Exception $e) {
            throw new Exception('Ошибка при сбросе статуса: ' . $e->getMessage());
        }
    }

    /**
     * Hard reset миграции: удаляет lock-файл, cache-файл и сбрасывает статус в БД
     * 
     * @param string $mbUuid UUID проекта MB
     * @param int $brzProjectId ID проекта Brizy
     * @return array
     * @throws Exception
     */
    public function hardResetMigration(string $mbUuid, int $brzProjectId): array
    {
        $results = [
            'lock_removed' => false,
            'cache_removed' => false,
            'status_reset' => false,
            'process_killed' => false,
            'messages' => []
        ];

        try {
            // Записываем в лог начало операции Hard Reset
            $this->writeToMigrationLog($brzProjectId, $mbUuid, 
                "Начало операции Hard Reset из панели управления. Будет выполнено: завершение процесса, удаление lock-файла, удаление cache-файла, сброс статуса.");
            
            // 1. Ищем и убиваем все процессы, связанные с миграцией
            $lockFile = $this->getLockFilePath($mbUuid, $brzProjectId);
            $pidsToKill = [];
            
            // 1.1. Проверяем процесс по PID из lock-файла
            if (file_exists($lockFile)) {
                $lockContent = @file_get_contents($lockFile);
                if ($lockContent) {
                    $lockData = json_decode($lockContent, true);
                    if ($lockData && isset($lockData['pid']) && $lockData['pid'] > 0) {
                        $pid = (int)$lockData['pid'];
                        if ($this->isProcessRunning($pid)) {
                            $pidsToKill[] = $pid;
                        }
                    }
                }
            }
            
            // 1.2. Ищем процессы через lsof по lock-файлу
            if (file_exists($lockFile)) {
                $lockFilePath = realpath($lockFile);
                if ($lockFilePath) {
                    $command = sprintf('lsof -t "%s" 2>/dev/null', escapeshellarg($lockFilePath));
                    $output = shell_exec($command);
                    if ($output) {
                        $lsofPids = array_filter(array_map('trim', explode("\n", trim($output))));
                        foreach ($lsofPids as $lsofPid) {
                            $pid = (int)$lsofPid;
                            if ($pid > 0 && $this->isProcessRunning($pid) && !in_array($pid, $pidsToKill)) {
                                $pidsToKill[] = $pid;
                            }
                        }
                    }
                }
            }
            
            // 1.3. Ищем процессы через fuser по lock-файлу
            if (file_exists($lockFile)) {
                $command = sprintf('fuser "%s" 2>/dev/null', escapeshellarg($lockFile));
                $output = shell_exec($command);
                if ($output) {
                    preg_match_all('/(\d+)/', $output, $matches);
                    if (!empty($matches[1])) {
                        foreach ($matches[1] as $fuserPid) {
                            $pid = (int)$fuserPid;
                            if ($pid > 0 && $this->isProcessRunning($pid) && !in_array($pid, $pidsToKill)) {
                                $pidsToKill[] = $pid;
                            }
                        }
                    }
                }
            }
            
            // 1.4. Ищем процессы по команде (migration wrapper)
            $searchPatterns = [
                sprintf('migration.*%d', $brzProjectId),
                sprintf('migration_wrapper.*%d', $brzProjectId),
                sprintf('%s.*%d', preg_quote($mbUuid, '/'), $brzProjectId),
            ];
            
            foreach ($searchPatterns as $pattern) {
                $command = sprintf('ps aux | grep -E "%s" | grep -v grep 2>/dev/null', escapeshellarg($pattern));
                $output = shell_exec($command);
                if ($output) {
                    $lines = array_filter(explode("\n", trim($output)));
                    foreach ($lines as $line) {
                        if (preg_match('/^\S+\s+(\d+)/', $line, $matches)) {
                            $pid = (int)$matches[1];
                            if ($pid > 0 && $this->isProcessRunning($pid) && !in_array($pid, $pidsToKill)) {
                                $pidsToKill[] = $pid;
                            }
                        }
                    }
                }
            }
            
            // 1.4.1. Ищем процессы по wrapper скрипту напрямую (если есть в lock-файле)
            if (file_exists($lockFile)) {
                $lockContent = @file_get_contents($lockFile);
                if ($lockContent) {
                    $lockData = json_decode($lockContent, true);
                    if ($lockData && isset($lockData['wrapper_script']) && file_exists($lockData['wrapper_script'])) {
                        $wrapperScript = $lockData['wrapper_script'];
                        $command = sprintf('ps aux | grep -F "%s" | grep -v grep 2>/dev/null', escapeshellarg($wrapperScript));
                        $output = shell_exec($command);
                        if ($output) {
                            $lines = array_filter(explode("\n", trim($output)));
                            foreach ($lines as $line) {
                                if (preg_match('/^\S+\s+(\d+)/', $line, $matches)) {
                                    $pid = (int)$matches[1];
                                    if ($pid > 0 && $this->isProcessRunning($pid) && !in_array($pid, $pidsToKill)) {
                                        $pidsToKill[] = $pid;
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
            // 1.5. Убиваем все найденные процессы
            if (!empty($pidsToKill)) {
                foreach ($pidsToKill as $pid) {
                    try {
                        // Сначала пробуем SIGTERM
                        $sigtermSent = false;
                        if (function_exists('posix_kill')) {
                            $sigtermSent = @posix_kill($pid, 15); // SIGTERM = 15
                        } else {
                            exec(sprintf('kill -TERM %d 2>/dev/null', $pid), $killOutput, $killReturnCode);
                            $sigtermSent = ($killReturnCode === 0);
                        }
                        
                        // Ждем немного
                        usleep(500000); // 0.5 секунды
                        
                        // Если процесс все еще работает, убиваем принудительно
                        if ($this->isProcessRunning($pid)) {
                            $sigkillSent = false;
                            if (function_exists('posix_kill')) {
                                $sigkillSent = @posix_kill($pid, 9); // SIGKILL = 9
                            } else {
                                exec(sprintf('kill -KILL %d 2>/dev/null', $pid), $killOutput2, $killReturnCode2);
                                $sigkillSent = ($killReturnCode2 === 0);
                            }
                            usleep(200000); // 0.2 секунды
                        }
                        
                        // Проверяем результат
                        if (!$this->isProcessRunning($pid)) {
                            $results['process_killed'] = true;
                            $results['messages'][] = 'Процесс миграции завершен (PID: ' . $pid . ')';
                            
                            // Записываем в лог миграции
                            $this->writeToMigrationLog($brzProjectId, $mbUuid, 
                                "Процесс миграции принудительно завершен из панели управления (Hard Reset). PID: $pid");
                        } else {
                            $results['messages'][] = 'Не удалось завершить процесс (PID: ' . $pid . ') - процесс все еще работает';
                            
                            // Записываем в лог миграции
                            $this->writeToMigrationLog($brzProjectId, $mbUuid, 
                                "Попытка принудительного завершения процесса из панели управления (Hard Reset) не удалась. PID: $pid - процесс все еще работает");
                        }
                    } catch (Exception $e) {
                        $results['messages'][] = 'Ошибка при завершении процесса (PID: ' . $pid . '): ' . $e->getMessage();
                        error_log("Hard reset: ошибка завершения процесса PID $pid: " . $e->getMessage());
                    } catch (\Throwable $e) {
                        $results['messages'][] = 'Критическая ошибка при завершении процесса (PID: ' . $pid . '): ' . $e->getMessage();
                        error_log("Hard reset: критическая ошибка завершения процесса PID $pid: " . $e->getMessage());
                    }
                }
            } else {
                $results['messages'][] = 'Процесс миграции не найден (возможно, уже завершен)';
            }

            // 2. Удаляем lock-файл
            try {
                $lockResult = $this->removeMigrationLock($mbUuid, $brzProjectId);
                if ($lockResult['success']) {
                    $results['lock_removed'] = $lockResult['removed'] ?? false;
                    if ($results['lock_removed']) {
                        $results['messages'][] = 'Lock-файл удален';
                        
                        // Записываем в лог миграции
                        $this->writeToMigrationLog($brzProjectId, $mbUuid, 
                            "Lock-файл удален из панели управления (Hard Reset)");
                    } else {
                        $results['messages'][] = 'Lock-файл не найден (уже удален)';
                    }
                }
            } catch (Exception $e) {
                $results['messages'][] = 'Ошибка удаления lock-файла: ' . $e->getMessage();
            }

            // 3. Удаляем cache-файл
            try {
                $cacheResult = $this->removeMigrationCache($mbUuid, $brzProjectId);
                if ($cacheResult['success']) {
                    $results['cache_removed'] = $cacheResult['removed'] ?? false;
                    if ($results['cache_removed']) {
                        $results['messages'][] = 'Кэш-файл удален';
                        
                        // Записываем в лог миграции
                        $this->writeToMigrationLog($brzProjectId, $mbUuid, 
                            "Cache-файл удален из панели управления (Hard Reset)");
                    } else {
                        $results['messages'][] = 'Кэш-файл не найден (уже удален)';
                    }
                }
            } catch (Exception $e) {
                $results['messages'][] = 'Ошибка удаления кэш-файла: ' . $e->getMessage();
            }

            // 4. Сбрасываем статус в БД
            try {
                $statusResult = $this->resetMigrationStatus($mbUuid, $brzProjectId);
                if ($statusResult['success']) {
                    $results['status_reset'] = true;
                    $results['messages'][] = 'Статус миграции сброшен на "pending"';
                    
                    // Записываем в лог миграции
                    $this->writeToMigrationLog($brzProjectId, $mbUuid, 
                        "Статус миграции сброшен на 'pending' из панели управления (Hard Reset)");
                }
            } catch (Exception $e) {
                $results['messages'][] = 'Ошибка сброса статуса: ' . $e->getMessage();
            }

            // Записываем в лог завершение операции Hard Reset
            $summary = "Hard Reset завершен. ";
            $summary .= "Процесс: " . ($results['process_killed'] ? 'завершен' : 'не найден') . ". ";
            $summary .= "Lock-файл: " . ($results['lock_removed'] ? 'удален' : 'не найден') . ". ";
            $summary .= "Cache-файл: " . ($results['cache_removed'] ? 'удален' : 'не найден') . ". ";
            $summary .= "Статус: " . ($results['status_reset'] ? 'сброшен' : 'не изменен') . ".";
            $this->writeToMigrationLog($brzProjectId, $mbUuid, $summary);

            return [
                'success' => true,
                'message' => 'Hard reset выполнен успешно',
                'results' => $results
            ];

        } catch (Exception $e) {
            error_log("Hard reset exception: " . $e->getMessage());
            error_log("Hard reset stack trace: " . $e->getTraceAsString());
            return [
                'success' => false,
                'error' => 'Ошибка при выполнении hard reset: ' . $e->getMessage(),
                'results' => $results,
                'exception' => [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ];
        } catch (\Throwable $e) {
            error_log("Hard reset throwable: " . $e->getMessage());
            error_log("Hard reset stack trace: " . $e->getTraceAsString());
            return [
                'success' => false,
                'error' => 'Критическая ошибка при выполнении hard reset: ' . $e->getMessage(),
                'results' => $results,
                'exception' => [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ];
        }
    }

    /**
     * Записать сообщение в лог миграции
     * 
     * @param int $brzProjectId ID проекта Brizy
     * @param string $mbUuid UUID проекта MB
     * @param string $message Сообщение для записи
     */
    private function writeToMigrationLog(int $brzProjectId, string $mbUuid, string $message): void
    {
        try {
            $projectRoot = dirname(__DIR__, 3);
            $logPath = $_ENV['LOG_FILE_PATH'] ?? getenv('LOG_FILE_PATH') ?: $projectRoot . '/var/log';
            
            // Формат пути к логу: LOG_FILE_PATH . '_' . $brz_project_id . '.log'
            // Ищем лог-файл по паттерну
            $pattern = $logPath . '/migration_*_' . $brzProjectId . '.log';
            $logFiles = glob($pattern);
            
            if (!empty($logFiles)) {
                // Используем последний созданный лог-файл
                $logFile = end($logFiles);
            } else {
                // Создаем новый лог-файл, если не найден
                $logFile = $logPath . '/migration_' . time() . '_' . $brzProjectId . '.log';
            }
            
            // Создаем директорию, если не существует
            $logDir = dirname($logFile);
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0777, true);
            }
            
            // Форматируем сообщение в стиле логов миграции
            $timestamp = date('Y-m-d H:i:s');
            $logEntry = "[$timestamp] [UMID: " . ($mbUuid ?: 'unknown') . "] [INFO] : [HardReset] $message\n";
            
            // Записываем в лог
            @file_put_contents($logFile, $logEntry, FILE_APPEND);
            
        } catch (Exception $e) {
            // Логируем ошибку записи в лог, но не прерываем выполнение
            error_log("Ошибка записи в лог миграции: " . $e->getMessage());
        }
    }

    /**
     * Получить логи миграции по brz_project_id
     * 
     * @param int $brzProjectId ID проекта Brizy
     * @return string Содержимое лог-файла
     * @throws Exception
     */
    public function getMigrationLogs(int $brzProjectId): string
    {
        $projectRoot = dirname(__DIR__, 3);
        $logPath = $_ENV['LOG_PATH'] ?? getenv('LOG_PATH') ?: $projectRoot . '/var/log';
        
        // Ищем все лог-файлы для этой миграции
        // Формат: migration_*_{brzProjectId}.log или brizy-{brzProjectId}.log
        $logFiles = [];
        
        // Вариант 1: Ищем файлы по паттерну migration_*_{brzProjectId}.log
        $pattern1 = $logPath . '/migration_*_' . $brzProjectId . '.log';
        $files1 = glob($pattern1);
        if ($files1) {
            $logFiles = array_merge($logFiles, $files1);
        }
        
        // Вариант 2: Ищем файл brizy-{brzProjectId}.log
        $brizyLogFile = $logPath . '/brizy-' . $brzProjectId . '.log';
        if (file_exists($brizyLogFile)) {
            $logFiles[] = $brizyLogFile;
        }
        
        // Вариант 3: Ищем файлы по паттерну *_${brzProjectId}.log (более общий)
        $pattern3 = $logPath . '/*_' . $brzProjectId . '.log';
        $files3 = glob($pattern3);
        if ($files3) {
            foreach ($files3 as $file) {
                if (!in_array($file, $logFiles)) {
                    $logFiles[] = $file;
                }
            }
        }
        
        // Вариант 4: Ищем файл migration_ApplicationBootstrapper.log (общий лог)
        // Логи пишутся в формат: LOG_FILE_PATH_ApplicationBootstrapper.log
        $appBootstrapLog = $logPath . '/migration_ApplicationBootstrapper.log';
        if (file_exists($appBootstrapLog)) {
            // Проверяем, содержит ли файл логи для этой миграции
            // Обычно в общем логе есть упоминание brz_project_id или brizy-{id}
            $content = @file_get_contents($appBootstrapLog);
            if ($content && (strpos($content, (string)$brzProjectId) !== false || 
                strpos($content, 'brizy-' . $brzProjectId) !== false ||
                preg_match('/brizy[_-]' . preg_quote($brzProjectId, '/') . '/i', $content))) {
                if (!in_array($appBootstrapLog, $logFiles)) {
                    $logFiles[] = $appBootstrapLog;
                }
            }
        }
        
        // Сортируем по времени модификации (новые первыми)
        usort($logFiles, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        if (empty($logFiles)) {
            return 'Лог-файлы для миграции не найдены. Ожидаемые файлы: migration_*_' . $brzProjectId . '.log или brizy-' . $brzProjectId . '.log';
        }
        
        // Объединяем содержимое всех найденных файлов (начиная с самого нового)
        $allLogs = [];
        foreach ($logFiles as $logFile) {
            if (file_exists($logFile) && is_readable($logFile)) {
                $content = file_get_contents($logFile);
                if ($content) {
                    $allLogs[] = "=== " . basename($logFile) . " ===\n" . $content;
                }
            }
        }
        
        if (empty($allLogs)) {
            return 'Лог-файлы найдены, но не удалось прочитать их содержимое';
        }
        
        return implode("\n\n", $allLogs);
    }
    
    /**
     * Проверить, завершилась ли миграция успешно
     * Проверяет migration_result_list на наличие value.status = "success" и логи
     * 
     * @param int $brzProjectId ID проекта Brizy
     * @return bool true если миграция завершилась успешно
     */
    public function checkMigrationCompletedFromLogs(int $brzProjectId): bool
    {
        try {
            // СНАЧАЛА проверяем migration_result_list на наличие value.status = "success"
            // Это более надежный способ, чем проверка логов
            try {
                $db = $this->dbService->getWriteConnection();
                $migrationResult = $db->find(
                    'SELECT * FROM migration_result_list WHERE brz_project_id = ? ORDER BY created_at DESC LIMIT 1',
                    [$brzProjectId]
                );
                
                if ($migrationResult && isset($migrationResult['result_json'])) {
                    $resultJson = json_decode($migrationResult['result_json'] ?? '{}', true);
                    
                    // Проверяем value.status = "success"
                    if (isset($resultJson['value']['status']) && $resultJson['value']['status'] === 'success') {
                        return true;
                    }
                    
                    // Также проверяем корневой status
                    if (isset($resultJson['status']) && ($resultJson['status'] === 'success' || $resultJson['status'] === 'completed')) {
                        return true;
                    }
                }
            } catch (Exception $e) {
                // Игнорируем ошибки БД, продолжаем проверку логов
            }
            
            // Если в БД нет успешного статуса, проверяем логи
            $logs = $this->getMigrationLogs($brzProjectId);
            
            if (empty($logs) || strpos($logs, 'Лог-файлы для миграции не найдены') !== false) {
                return false;
            }
            
            // Ищем признаки успешного завершения в логах
            $successPatterns = [
                '/Project migration completed successfully/i',
                '/migration completed successfully/i',
                '/Migration completed successfully/i',
                '/Migration finished successfully/i',
                '/Migration process completed/i',
                '/finalSuccess.*status.*success/i',
                '/Status.*Total.*Success/i'
            ];
            
            foreach ($successPatterns as $pattern) {
                if (preg_match($pattern, $logs)) {
                    return true;
                }
            }
            
            // Также проверяем отсутствие критических ошибок
            $errorPatterns = [
                '/Fatal error/i',
                '/Critical error/i',
                '/Migration failed/i',
                '/Exception.*migration/i'
            ];
            
            $hasCriticalErrors = false;
            foreach ($errorPatterns as $pattern) {
                if (preg_match($pattern, $logs)) {
                    $hasCriticalErrors = true;
                    break;
                }
            }
            
            // Если в конце логов нет критических ошибок и есть информация о завершении, считаем успешной
            // Берем последние 1000 символов лога для проверки
            $lastPart = substr($logs, -1000);
            if (!$hasCriticalErrors && (strpos($lastPart, 'completed') !== false || strpos($lastPart, 'finished') !== false)) {
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Ошибка проверки статуса миграции: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Получить ID миграции из таблицы migrations по brz_project_id
     * 
     * @param int $brzProjectId
     * @return int|null
     * @throws Exception
     */
    private function getMigrationIdFromBrzProjectId(int $brzProjectId): ?int
    {
        try {
            $db = $this->dbService->getWriteConnection();
            $migration = $db->find(
                'SELECT id FROM migrations WHERE brz_project_id = ? ORDER BY created_at DESC LIMIT 1',
                [$brzProjectId]
            );
            return $migration ? (int)$migration['id'] : null;
        } catch (Exception $e) {
            // Если таблица migrations не существует или ошибка, возвращаем null
            return null;
        }
    }

    /**
     * Получить информацию о ревьюере для конкретной миграции
     * 
     * @param int $migrationId ID миграции из таблицы migrations
     * @return array|null Объект с полями person_brizy, uuid или null, если ревьюер не назначен
     * @throws Exception
     */
    public function getMigrationReviewer(int $migrationId): ?array
    {
        try {
            $db = $this->dbService->getWriteConnection();
            $reviewer = $db->find(
                'SELECT person_brizy, uuid FROM migration_reviewers WHERE migration_id = ? LIMIT 1',
                [$migrationId]
            );
            
            if (!$reviewer) {
                return null;
            }
            
            return [
                'person_brizy' => $reviewer['person_brizy'],
                'uuid' => $reviewer['uuid']
            ];
        } catch (Exception $e) {
            // Если таблица migration_reviewers не существует, возвращаем null
            if (strpos($e->getMessage(), "doesn't exist") !== false || 
                strpos($e->getMessage(), "Table") !== false) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Получить список миграций с информацией о ревьюерах
     * 
     * @param array $filters Фильтры (как в getMigrationsList)
     * @return array Массив миграций с полем reviewer
     * @throws Exception
     */
    public function getMigrationsWithReviewers(array $filters = []): array
    {
        // Получаем список миграций
        $migrations = $this->getMigrationsList($filters);
        
        if (empty($migrations)) {
            return [];
        }
        
        // Получаем все migration_id из списка миграций
        $db = $this->dbService->getWriteConnection();
        $brzProjectIds = array_column($migrations, 'brz_project_id');
        $brzProjectIds = array_filter($brzProjectIds);
        
        if (empty($brzProjectIds)) {
            // Если нет brz_project_id, просто возвращаем миграции без ревьюеров
            foreach ($migrations as &$migration) {
                $migration['reviewer'] = null;
            }
            return $migrations;
        }
        
        // Получаем все migration_id для этих brz_project_id
        $placeholders = implode(',', array_fill(0, count($brzProjectIds), '?'));
        $migrationsFromTable = $db->getAllRows(
            "SELECT id, brz_project_id FROM migrations WHERE brz_project_id IN ($placeholders)",
            $brzProjectIds
        );
        
        // Создаем индекс: brz_project_id => migration_id
        $migrationIdMap = [];
        foreach ($migrationsFromTable as $migration) {
            $migrationIdMap[$migration['brz_project_id']] = $migration['id'];
        }
        
        // Получаем все ревьюеры для найденных migration_id
        $migrationIds = array_values($migrationIdMap);
        if (empty($migrationIds)) {
            // Если нет migration_id, просто возвращаем миграции без ревьюеров
            foreach ($migrations as &$migration) {
                $migration['reviewer'] = null;
            }
            return $migrations;
        }
        
        $reviewersPlaceholders = implode(',', array_fill(0, count($migrationIds), '?'));
        $reviewers = $db->getAllRows(
            "SELECT migration_id, person_brizy, uuid FROM migration_reviewers WHERE migration_id IN ($reviewersPlaceholders)",
            $migrationIds
        );
        
        // Создаем индекс: migration_id => reviewer
        $reviewersMap = [];
        foreach ($reviewers as $reviewer) {
            $reviewersMap[$reviewer['migration_id']] = [
                'person_brizy' => $reviewer['person_brizy'],
                'uuid' => $reviewer['uuid']
            ];
        }
        
        // Добавляем информацию о ревьюерах к миграциям
        foreach ($migrations as &$migration) {
            $brzProjectId = $migration['brz_project_id'] ?? null;
            if ($brzProjectId && isset($migrationIdMap[$brzProjectId])) {
                $migrationId = $migrationIdMap[$brzProjectId];
                $migration['reviewer'] = $reviewersMap[$migrationId] ?? null;
            } else {
                $migration['reviewer'] = null;
            }
        }
        
        return $migrations;
    }

    /**
     * Получить всех ревьюеров для миграций в волне
     * 
     * @param string $waveId ID волны
     * @return array Массив уникальных ревьюеров с количеством миграций
     * @throws Exception
     */
    public function getReviewersByWave(string $waveId): array
    {
        try {
            $db = $this->dbService->getWriteConnection();
            
            // Получаем все миграции волны с ревьюерами
            $reviewers = $db->getAllRows(
                'SELECT mr.person_brizy, mr.uuid, COUNT(DISTINCT mr.migration_id) as migration_count
                 FROM migration_reviewers mr
                 INNER JOIN migrations m ON mr.migration_id = m.id
                 WHERE m.wave_id = ?
                 GROUP BY mr.person_brizy, mr.uuid
                 ORDER BY migration_count DESC, mr.person_brizy ASC',
                [$waveId]
            );
            
            $result = [];
            foreach ($reviewers as $reviewer) {
                $result[] = [
                    'person_brizy' => $reviewer['person_brizy'],
                    'uuid' => $reviewer['uuid'],
                    'migration_count' => (int)$reviewer['migration_count']
                ];
            }
            
            return $result;
        } catch (Exception $e) {
            // Если таблица migration_reviewers не существует, возвращаем пустой массив
            if (strpos($e->getMessage(), "doesn't exist") !== false || 
                strpos($e->getMessage(), "Table") !== false) {
                return [];
            }
            throw $e;
        }
    }
}
