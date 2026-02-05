<?php

namespace Dashboard\Services;

use Exception;

/**
 * TestMigrationService
 * 
 * Бизнес-логика для работы с тестовыми миграциями
 * Использует отдельную таблицу test_migrations для изоляции данных
 */
class TestMigrationService
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
     * Выполнить UPDATE запрос
     * 
     * @param string $table
     * @param array $data
     * @param array $where
     * @return bool
     * @throws Exception
     */
    private function update(string $table, array $data, array $where): bool
    {
        $db = $this->dbService->getWriteConnection();
        
        $setParts = [];
        $whereParts = [];
        
        foreach ($data as $key => $value) {
            $setParts[] = "{$key} = ?";
        }
        
        foreach ($where as $key => $value) {
            $whereParts[] = "{$key} = ?";
        }
        
        $sql = "UPDATE {$table} SET " . implode(', ', $setParts) . " WHERE " . implode(' AND ', $whereParts);
        
        try {
            // Используем рефлексию для доступа к приватному PDO объекту MySQL драйвера
            $reflection = new \ReflectionClass($db);
            $pdoProperty = $reflection->getProperty('pdo');
            $pdoProperty->setAccessible(true);
            $pdo = $pdoProperty->getValue($db);
            
            if (!$pdo) {
                // Если PDO еще не инициализирован, вызываем doConnect
                $db->doConnect();
                $pdo = $pdoProperty->getValue($db);
            }
            
            // Выполняем UPDATE запрос напрямую через PDO
            $stmt = $pdo->prepare($sql);
            
            // Для больших текстовых полей (section_json, element_result_json) используем PDO::PARAM_STR
            // PDO::PARAM_LOB используется для бинарных данных, для текста используем PARAM_STR
            // Но важно: для MySQL LONGTEXT нужно использовать обычный bindValue с PARAM_STR
            $paramIndex = 1; // PDO параметры начинаются с 1
            foreach ($data as $key => $value) {
                if (in_array($key, ['section_json', 'element_result_json', 'changes_json']) && is_string($value)) {
                    // Логируем длину больших полей
                    $valueLength = strlen($value);
                    error_log("TestMigrationService::update binding large field '{$key}' with length: {$valueLength} bytes");
                    // Для больших текстовых полей используем PARAM_STR (не PARAM_LOB, т.к. это текст, не бинарные данные)
                    $stmt->bindValue($paramIndex, $value, \PDO::PARAM_STR);
                } else {
                    $stmt->bindValue($paramIndex, $value);
                }
                $paramIndex++;
            }
            
            // Привязываем параметры WHERE
            foreach ($where as $key => $value) {
                $stmt->bindValue($paramIndex, $value);
                $paramIndex++;
            }
            
            $result = $stmt->execute();
            
            if (!$result) {
                $errorInfo = $stmt->errorInfo();
                error_log("TestMigrationService::update failed for SQL: {$sql} Error: " . json_encode($errorInfo));
                return false;
            }
            
            $affectedRows = $stmt->rowCount();
            error_log("TestMigrationService::update successful, affected rows: {$affectedRows}");
            return $affectedRows > 0;
        } catch (\PDOException $e) {
            error_log("TestMigrationService::update PDOException: " . $e->getMessage() . " SQL: {$sql} Params: " . json_encode($params));
            throw $e;
        } catch (\Exception $e) {
            error_log("TestMigrationService::update Exception: " . $e->getMessage() . " SQL: {$sql}");
            throw $e;
        }
    }

    /**
     * Проверить существование таблицы test_migrations
     * 
     * @return bool
     * @throws Exception
     */
    private function tableExists(): bool
    {
        try {
            $db = $this->dbService->getWriteConnection();
            
            // Пробуем простой SELECT - если таблица существует, запрос пройдет
            // Если таблицы нет, будет исключение
            try {
                $db->getAllRows("SELECT 1 FROM test_migrations LIMIT 1");
                return true;
            } catch (\PDOException $e) {
                // Проверяем код ошибки MySQL для "таблица не существует"
                $errorCode = $e->getCode();
                $errorMessage = $e->getMessage();
                
                // MySQL error code 42S02 = Table doesn't exist
                if ($errorCode == '42S02' || 
                    strpos($errorMessage, "doesn't exist") !== false || 
                    strpos($errorMessage, "Unknown table") !== false ||
                    strpos($errorMessage, "Table") !== false && strpos($errorMessage, "doesn't exist") !== false) {
                    return false;
                }
                // Для других ошибок пробрасываем исключение
                throw $e;
            }
        } catch (\PDOException $e) {
            error_log("TestMigrationService::tableExists PDOException: " . $e->getMessage() . " Code: " . $e->getCode());
            return false;
        } catch (Exception $e) {
            error_log("TestMigrationService::tableExists Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Получить список всех тестовых миграций
     * 
     * @param array $filters
     * @return array
     * @throws Exception
     */
    public function getTestMigrationsList(array $filters = []): array
    {
        $db = $this->dbService->getWriteConnection();
        
        $where = [];
        $params = [];
        
        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['mb_project_uuid'])) {
            $where[] = 'mb_project_uuid LIKE ?';
            $params[] = '%' . $filters['mb_project_uuid'] . '%';
        }
        
        if (!empty($filters['brz_project_id'])) {
            $where[] = 'brz_project_id = ?';
            $params[] = (int)$filters['brz_project_id'];
        }
        
        $sql = 'SELECT * FROM test_migrations';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC';
        
        try {
            $migrations = $db->getAllRows($sql, $params);
        } catch (\PDOException $e) {
            // Если таблица не существует, выбрасываем понятное исключение
            $errorCode = $e->getCode();
            $errorMessage = $e->getMessage();
            
            if ($errorCode == '42S02' || 
                strpos($errorMessage, "doesn't exist") !== false || 
                strpos($errorMessage, "Unknown table") !== false ||
                (strpos($errorMessage, "Table") !== false && strpos($errorMessage, "doesn't exist") !== false)) {
                throw new Exception(
                    'Таблица test_migrations не найдена. ' .
                    'Необходимо выполнить миграцию базы данных: vendor/bin/phinx migrate. ' .
                    'Или выполните SQL скрипт: db/migrations/create_test_migrations_table.sql'
                );
            }
            // Логируем другие ошибки
            error_log("TestMigrationService::getTestMigrationsList PDOException: " . $errorMessage . " Code: " . $errorCode);
            throw $e;
        }
        
        // Преобразуем данные
        foreach ($migrations as &$migration) {
            $migration['changes_json'] = json_decode($migration['changes_json'] ?? '{}', true);
            $migration['skip_media_upload'] = (bool)($migration['skip_media_upload'] ?? false);
            $migration['skip_cache'] = (bool)($migration['skip_cache'] ?? false);
            $migration['quality_analysis'] = (bool)($migration['quality_analysis'] ?? false);
        }
        
        return $migrations;
    }

    /**
     * Получить детали тестовой миграции
     * 
     * @param int $id
     * @return array|null
     * @throws Exception
     */
    public function getTestMigrationDetails(int $id): ?array
    {
        // Проверяем существование таблицы
        if (!$this->tableExists()) {
            throw new Exception(
                'Таблица test_migrations не найдена. ' .
                'Необходимо выполнить миграцию базы данных: vendor/bin/phinx migrate'
            );
        }
        
        $db = $this->dbService->getWriteConnection();
        
        try {
            $migration = $db->find(
                'SELECT * FROM test_migrations WHERE id = ?',
                [$id]
            );
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), "doesn't exist") !== false || 
                strpos($e->getMessage(), "Unknown table") !== false ||
                $e->getCode() == '42S02') {
                throw new Exception(
                    'Таблица test_migrations не найдена. ' .
                    'Необходимо выполнить миграцию базы данных: vendor/bin/phinx migrate'
                );
            }
            throw $e;
        }
        
        if (!$migration) {
            return null;
        }
        
        // Получаем результат миграции из migration_result_list, если есть
        $result = null;
        if (!empty($migration['brz_project_id'])) {
            try {
                $result = $db->find(
                    'SELECT * FROM migration_result_list WHERE brz_project_id = ? ORDER BY created_at DESC LIMIT 1',
                    [$migration['brz_project_id']]
                );
            } catch (Exception $e) {
                // Игнорируем ошибки при получении результата
            }
        }
        
        $resultData = $result ? json_decode($result['result_json'] ?? '{}', true) : null;
        
        return [
            'id' => $migration['id'],
            'mb_project_uuid' => $migration['mb_project_uuid'],
            'brz_project_id' => $migration['brz_project_id'],
            'mb_site_id' => $migration['mb_site_id'],
            'mb_secret' => $migration['mb_secret'],
            'brz_workspaces_id' => $migration['brz_workspaces_id'],
            'mb_page_slug' => $migration['mb_page_slug'],
            'mb_element_name' => $migration['mb_element_name'],
            'skip_media_upload' => (bool)($migration['skip_media_upload'] ?? false),
            'skip_cache' => (bool)($migration['skip_cache'] ?? false),
            'section_json' => $migration['section_json'] ?? null, // ВАЖНО: Возвращаем section_json из БД
            'element_result_json' => $migration['section_json'] ?? $migration['element_result_json'] ?? null, // Для обратной совместимости
            'mgr_manual' => (int)($migration['mgr_manual'] ?? 0),
            'quality_analysis' => (bool)($migration['quality_analysis'] ?? false),
            'changes_json' => json_decode($migration['changes_json'] ?? '{}', true),
            'status' => $migration['status'] ?? 'pending',
            'created_at' => $migration['created_at'],
            'updated_at' => $migration['updated_at'],
            'result' => $resultData,
            'migration_uuid' => $result['migration_uuid'] ?? null,
            'brizy_project_domain' => $resultData['brizy_project_domain'] ?? null,
            'mb_project_domain' => $resultData['mb_project_domain'] ?? null,
        ];
    }

    /**
     * Создать новую тестовую миграцию
     * 
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function createTestMigration(array $data): array
    {
        // Проверяем существование таблицы
        if (!$this->tableExists()) {
            throw new Exception(
                'Таблица test_migrations не найдена. ' .
                'Необходимо выполнить миграцию базы данных: vendor/bin/phinx migrate'
            );
        }
        
        $db = $this->dbService->getWriteConnection();
        
        // Получаем настройки по умолчанию, если не переданы
        $settings = $this->dbService->getSettings();
        
        $insertData = [
            'brz_project_id' => !empty($data['brz_project_id']) ? (int)$data['brz_project_id'] : null,
            'mb_project_uuid' => $data['mb_project_uuid'] ?? null,
            'mb_site_id' => !empty($data['mb_site_id']) ? (int)$data['mb_site_id'] : ($settings['mb_site_id'] ?? null),
            'mb_secret' => !empty($data['mb_secret']) ? $data['mb_secret'] : ($settings['mb_secret'] ?? null),
            'brz_workspaces_id' => !empty($data['brz_workspaces_id']) ? (int)$data['brz_workspaces_id'] : null,
            'mb_page_slug' => $data['mb_page_slug'] ?? null,
            'mb_element_name' => $data['mb_element_name'] ?? null,
            'skip_media_upload' => isset($data['skip_media_upload']) ? (int)(bool)$data['skip_media_upload'] : 0,
            'skip_cache' => isset($data['skip_cache']) ? (int)(bool)$data['skip_cache'] : 0,
            'mgr_manual' => !empty($data['mgr_manual']) ? (int)$data['mgr_manual'] : 0,
            'quality_analysis' => isset($data['quality_analysis']) ? (int)(bool)$data['quality_analysis'] : 0,
            'status' => 'pending',
            'changes_json' => null,
        ];
        
        try {
            $id = $db->insert('test_migrations', $insertData);
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), "doesn't exist") !== false || 
                strpos($e->getMessage(), "Unknown table") !== false ||
                $e->getCode() == '42S02') {
                throw new Exception(
                    'Таблица test_migrations не найдена. ' .
                    'Необходимо выполнить миграцию базы данных: vendor/bin/phinx migrate'
                );
            }
            throw $e;
        }
        
        return [
            'id' => $id,
            'success' => true
        ];
    }

    /**
     * Запустить тестовую миграцию
     * 
     * @param int $id
     * @return array
     * @throws Exception
     */
    public function runTestMigration(int $id): array
    {
        // Проверяем существование таблицы
        if (!$this->tableExists()) {
            throw new Exception(
                'Таблица test_migrations не найдена. ' .
                'Необходимо выполнить миграцию базы данных: vendor/bin/phinx migrate'
            );
        }
        
        $migration = $this->getTestMigrationDetails($id);
        
        if (!$migration) {
            throw new Exception('Тестовая миграция не найдена');
        }
        
        // ВАЖНО: Обновляем статус на in_progress СРАЗУ при запуске, ДО выполнения миграции
        // Это гарантирует, что пользователь увидит изменение статуса немедленно
        try {
            $this->update('test_migrations', [
                'status' => 'in_progress',
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $id]);
            error_log("TestMigrationService: Status updated to 'in_progress' for migration ID: {$id}");
        } catch (\Exception $statusUpdateEx) {
            error_log("TestMigrationService: Failed to update status to 'in_progress': " . $statusUpdateEx->getMessage());
            // Продолжаем выполнение даже если обновление статуса не удалось
        }
        
        // Подготавливаем параметры для запуска миграции
        $params = [
            'mb_project_uuid' => $migration['mb_project_uuid'],
            'brz_project_id' => $migration['brz_project_id'],
            'mb_site_id' => $migration['mb_site_id'],
            'mb_secret' => $migration['mb_secret'],
        ];
        
        if (!empty($migration['brz_workspaces_id'])) {
            $params['brz_workspaces_id'] = $migration['brz_workspaces_id'];
        }
        
        // Ключевые параметры для тестирования одного элемента
        if (!empty($migration['mb_page_slug'])) {
            $params['mb_page_slug'] = $migration['mb_page_slug'];
        }
        
        if (!empty($migration['mb_element_name'])) {
            $params['mb_element_name'] = $migration['mb_element_name'];
        }
        
        // Параметры для ускорения тестирования
        if ($migration['skip_media_upload']) {
            $params['skip_media_upload'] = true;
        }
        
        if ($migration['skip_cache']) {
            $params['skip_cache'] = true;
        }
        
        if (!empty($migration['mgr_manual'])) {
            $params['mgr_manual'] = $migration['mgr_manual'];
        }
        
        if ($migration['quality_analysis']) {
            $params['quality_analysis'] = true;
        }
        
        // Для тестовых миграций всегда запускаем синхронно (для отладки)
        // Это позволяет использовать брейкпоинты в отладчике
        $params['sync_execution'] = true;
        $params['debug_mode'] = true;
        
        // Запускаем миграцию через ApiProxyService
        $result = $this->apiProxy->runMigration($params);
        
        // Получаем результат transformBlocks из кэша, если тестируем элемент
        $elementResultJson = null;
        if (!empty($migration['mb_element_name'])) {
            try {
                $cache = \MBMigration\Builder\VariableCache::getInstance();
                
                // Приоритет: результат transformBlocks (полный результат секций)
                $transformBlocksKey = 'test_migration_transform_blocks_result_' . $migration['mb_element_name'];
                error_log('Looking for transformBlocks result in cache with key: ' . $transformBlocksKey);
                
                $transformBlocksResult = $cache->get($transformBlocksKey);
                error_log('Cache get result for key ' . $transformBlocksKey . ': ' . (is_null($transformBlocksResult) ? 'NULL' : (is_array($transformBlocksResult) ? 'ARRAY with keys: ' . implode(', ', array_keys($transformBlocksResult)) : gettype($transformBlocksResult))));
                
                if ($transformBlocksResult && isset($transformBlocksResult['section_json'])) {
                    $elementResultJson = $transformBlocksResult['section_json'];
                    error_log('✓ Retrieved transformBlocks result from cache for element: ' . $migration['mb_element_name'] . ', JSON length: ' . strlen($elementResultJson));
                } else {
                    error_log('✗ transformBlocks result not found or missing section_json');
                    
                    // Fallback: результат отдельного элемента (для обратной совместимости)
                    $elementResultKey = 'test_migration_element_result_' . $migration['mb_element_name'];
                    error_log('Trying fallback key: ' . $elementResultKey);
                    $elementResult = $cache->get($elementResultKey);
                    
                    if ($elementResult && isset($elementResult['section_json'])) {
                        $elementResultJson = $elementResult['section_json'];
                        error_log('✓ Retrieved element result from cache (fallback) for element: ' . $migration['mb_element_name'] . ', JSON length: ' . strlen($elementResultJson));
                    } else {
                        error_log('✗ Element result also not found in cache');
                    }
                }
            } catch (\Exception $cacheEx) {
                error_log('❌ Failed to get transformBlocks result from cache: ' . $cacheEx->getMessage());
                error_log('Stack trace: ' . $cacheEx->getTraceAsString());
            }
        } else {
            error_log('No mb_element_name provided, skipping section_json retrieval');
        }
        
        // Обновляем статус и результат
        $status = 'error';
        if ($result['success']) {
            // Проверяем реальный статус из ответа
            if (isset($result['data']['value']['status']) && $result['data']['value']['status'] === 'success') {
                $status = 'completed';
            } elseif (isset($result['data']['status']) && $result['data']['status'] === 'success') {
                $status = 'completed';
            } else {
                $status = 'in_progress';
            }
        }
        
        $updateData = [
            'status' => $status,
            'changes_json' => json_encode($result),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        // Сохраняем результат секции, если есть
        if ($elementResultJson !== null) {
            $jsonLength = strlen($elementResultJson);
            $updateData['section_json'] = $elementResultJson;
            $updateData['element_result_json'] = $elementResultJson; // Для обратной совместимости
            error_log('✓ Saving section_json to database, length: ' . $jsonLength . ' bytes');
            
            // Проверяем, не превышает ли размер максимальный размер TEXT (65535)
            if ($jsonLength >= 65535) {
                error_log('⚠️ WARNING: section_json length (' . $jsonLength . ') is >= 65535, ensure column is LONGTEXT');
            }
        } else {
            error_log('✗ No section_json to save (elementResultJson is null)');
        }
        
        try {
            $this->update('test_migrations', $updateData, ['id' => $id]);
            
            // Проверяем, что данные действительно сохранились полностью
            if (isset($updateData['section_json'])) {
                $savedLength = strlen($updateData['section_json']);
                error_log('✓ Database update successful for test_migration ID: ' . $id . ', section_json saved: YES, length: ' . $savedLength . ' bytes');
                
                // Проверяем в БД, что данные сохранились полностью
                $db = $this->dbService->getWriteConnection();
                $savedData = $db->find('test_migrations', 'id = ?', [$id]);
                if ($savedData && isset($savedData['section_json'])) {
                    $dbLength = strlen($savedData['section_json']);
                    if ($dbLength !== $savedLength) {
                        error_log('❌ ERROR: section_json length mismatch! Saved: ' . $savedLength . ', Retrieved from DB: ' . $dbLength);
                    } else {
                        error_log('✓ Verified: section_json length matches in database (' . $dbLength . ' bytes)');
                    }
                }
            } else {
                error_log('✓ Database update successful for test_migration ID: ' . $id . ', section_json saved: NO');
            }
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), "doesn't exist") !== false || 
                strpos($e->getMessage(), "Unknown table") !== false ||
                $e->getCode() == '42S02') {
                throw new Exception(
                    'Таблица test_migrations не найдена. ' .
                    'Необходимо выполнить миграцию базы данных: vendor/bin/phinx migrate'
                );
            }
            throw $e;
        }
        
        return [
            'success' => $result['success'],
            'data' => $result['data'] ?? null,
            'http_code' => $result['http_code'] ?? 200
        ];
    }

    /**
     * Сбросить статус тестовой миграции на pending
     * 
     * @param int $id
     * @return array
     * @throws Exception
     */
    public function resetTestMigrationStatus(int $id): array
    {
        // Проверяем существование таблицы
        if (!$this->tableExists()) {
            throw new Exception(
                'Таблица test_migrations не найдена. ' .
                'Необходимо выполнить миграцию базы данных: vendor/bin/phinx migrate'
            );
        }
        
        $migration = $this->getTestMigrationDetails($id);
        
        if (!$migration) {
            throw new Exception('Тестовая миграция не найдена');
        }
        
        // Обновляем статус на pending
        $this->update('test_migrations', [
            'status' => 'pending',
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $id]);
        
        return [
            'success' => true,
            'message' => 'Статус тестовой миграции сброшен на "pending"'
        ];
    }

    /**
     * Удалить тестовую миграцию
     * 
     * @param int $id
     * @return bool
     * @throws Exception
     */
    public function deleteTestMigration(int $id): bool
    {
        // Проверяем существование таблицы
        if (!$this->tableExists()) {
            throw new Exception(
                'Таблица test_migrations не найдена. ' .
                'Необходимо выполнить миграцию базы данных: vendor/bin/phinx migrate'
            );
        }
        
        $db = $this->dbService->getWriteConnection();
        
        try {
            $db->delete('test_migrations', 'id = ?', [$id]);
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), "doesn't exist") !== false || 
                strpos($e->getMessage(), "Unknown table") !== false ||
                $e->getCode() == '42S02') {
                throw new Exception(
                    'Таблица test_migrations не найдена. ' .
                    'Необходимо выполнить миграцию базы данных: vendor/bin/phinx migrate'
                );
            }
            throw $e;
        }
        
        return true;
    }

    /**
     * Обновить тестовую миграцию
     * 
     * @param int $id
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function updateTestMigration(int $id, array $data): array
    {
        // Проверяем существование таблицы
        if (!$this->tableExists()) {
            throw new Exception(
                'Таблица test_migrations не найдена. ' .
                'Необходимо выполнить миграцию базы данных: vendor/bin/phinx migrate'
            );
        }
        
        $db = $this->dbService->getWriteConnection();
        
        $updateData = [];
        
        if (isset($data['mb_project_uuid'])) {
            $updateData['mb_project_uuid'] = $data['mb_project_uuid'];
        }
        if (isset($data['brz_project_id'])) {
            $updateData['brz_project_id'] = (int)$data['brz_project_id'];
        }
        if (isset($data['mb_site_id'])) {
            $updateData['mb_site_id'] = (int)$data['mb_site_id'];
        }
        if (isset($data['mb_secret'])) {
            $updateData['mb_secret'] = $data['mb_secret'];
        }
        if (isset($data['brz_workspaces_id'])) {
            $updateData['brz_workspaces_id'] = !empty($data['brz_workspaces_id']) ? (int)$data['brz_workspaces_id'] : null;
        }
        if (isset($data['mb_page_slug'])) {
            $updateData['mb_page_slug'] = $data['mb_page_slug'] ?: null;
        }
        if (isset($data['mb_element_name'])) {
            $updateData['mb_element_name'] = $data['mb_element_name'] ?: null;
        }
        if (isset($data['skip_media_upload'])) {
            $updateData['skip_media_upload'] = (int)(bool)$data['skip_media_upload'];
        }
        if (isset($data['skip_cache'])) {
            $updateData['skip_cache'] = (int)(bool)$data['skip_cache'];
        }
        if (isset($data['mgr_manual'])) {
            $updateData['mgr_manual'] = (int)$data['mgr_manual'];
        }
        if (isset($data['quality_analysis'])) {
            $updateData['quality_analysis'] = (int)(bool)$data['quality_analysis'];
        }
        
        $updateData['updated_at'] = date('Y-m-d H:i:s');
        
        try {
            $this->update('test_migrations', $updateData, ['id' => $id]);
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), "doesn't exist") !== false || 
                strpos($e->getMessage(), "Unknown table") !== false ||
                $e->getCode() == '42S02') {
                throw new Exception(
                    'Таблица test_migrations не найдена. ' .
                    'Необходимо выполнить миграцию базы данных: vendor/bin/phinx migrate'
                );
            }
            throw $e;
        }
        
        return [
            'success' => true,
            'id' => $id
        ];
    }
}
