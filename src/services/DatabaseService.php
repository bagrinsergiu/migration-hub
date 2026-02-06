<?php

namespace Dashboard\Services;

use Exception;
use MBMigration\Layer\DataSource\driver\MySQL;

/**
 * DatabaseService
 * 
 * КРИТИЧЕСКИ ВАЖНО: Все операции записи (INSERT, UPDATE, DELETE) 
 * только в базу mb-migration.cupzc9ey0cip.us-east-1.rds.amazonaws.com
 */
class DatabaseService
{
    // Разрешенный хост для записи (строгая проверка)
    private const ALLOWED_WRITE_HOST = 'mb-migration.cupzc9ey0cip.us-east-1.rds.amazonaws.com';

    /** @var MySQL|null */
    private $writeConnection = null;

    /**
     * Получить настройки подключения из переменных окружения
     * 
     * @return array
     * @throws Exception
     */
    private function getDbConfig(): array
    {
        $host = $_ENV['MG_DB_HOST'] ?? getenv('MG_DB_HOST');
        $dbName = $_ENV['MG_DB_NAME'] ?? getenv('MG_DB_NAME');
        $user = $_ENV['MG_DB_USER'] ?? getenv('MG_DB_USER');
        $pass = $_ENV['MG_DB_PASS'] ?? getenv('MG_DB_PASS');
        $port = $_ENV['MG_DB_PORT'] ?? getenv('MG_DB_PORT') ?? 3306;

        if (empty($host) || empty($dbName) || empty($user) || empty($pass)) {
            throw new Exception(
                'Не настроены переменные окружения для подключения к БД. ' .
                'Требуются: MG_DB_HOST, MG_DB_NAME, MG_DB_USER, MG_DB_PASS'
            );
        }

        return [
            'host' => $host,
            'dbName' => $dbName,
            'user' => $user,
            'pass' => $pass,
            'port' => (int)$port
        ];
    }

    /**
     * Получить подключение к базе для записи
     * 
     * @return MySQL
     * @throws Exception
     */
    public function getWriteConnection(): MySQL
    {
        if ($this->writeConnection === null) {
            $config = $this->getDbConfig();
            
            // КРИТИЧЕСКАЯ ПРОВЕРКА: Разрешен только один хост для записи
            $this->validateWriteHost($config['host']);
            
            $this->writeConnection = new MySQL(
                $config['user'],
                $config['pass'],
                $config['dbName'],
                $config['host']
            );
            $this->writeConnection->doConnect();
        }

        return $this->writeConnection;
    }

    /**
     * Валидация хоста перед записью
     * 
     * @param string $host
     * @return bool
     * @throws Exception
     */
    public function validateWriteHost(string $host): bool
    {
        if ($host !== self::ALLOWED_WRITE_HOST) {
            throw new Exception(
                "КРИТИЧЕСКАЯ ОШИБКА: Запись разрешена только в базу: " . self::ALLOWED_WRITE_HOST . 
                ". Попытка записи в: " . $host . 
                ". Проверьте переменную MG_DB_HOST в .env файле."
            );
        }
        return true;
    }

    /**
     * Получить список миграций из новой таблицы migrations
     * 
     * @return array
     * @throws Exception
     */
    public function getMigrationsList(): array
    {
        $db = $this->getWriteConnection();
        return $db->getAllRows(
            'SELECT * FROM migrations ORDER BY created_at DESC'
        );
    }

    /**
     * Получить миграцию по ID проекта Brizy
     * 
     * @param int $brzProjectId
     * @return array|null
     * @throws Exception
     */
    public function getMigrationById(int $brzProjectId): ?array
    {
        $db = $this->getWriteConnection();
        $result = $db->find(
            'SELECT * FROM migrations WHERE brz_project_id = ? ORDER BY created_at DESC LIMIT 1',
            [$brzProjectId]
        );
        return $result ?: null;
    }

    /**
     * Получить миграцию по MB UUID
     * 
     * @param string $mbProjectUuid
     * @return array|null
     * @throws Exception
     */
    public function getMigrationByUuid(string $mbProjectUuid): ?array
    {
        $db = $this->getWriteConnection();
        $result = $db->find(
            'SELECT * FROM migrations WHERE mb_project_uuid = ? ORDER BY created_at DESC LIMIT 1',
            [$mbProjectUuid]
        );
        return $result ?: null;
    }

    /**
     * Получить результаты миграций из migration_result_list
     * 
     * @param int|null $limit
     * @return array
     * @throws Exception
     */
    public function getMigrationResults(?int $limit = 100): array
    {
        $db = $this->getWriteConnection();
        $sql = 'SELECT * FROM migration_result_list ORDER BY migration_uuid DESC';
        if ($limit) {
            $sql .= ' LIMIT ' . (int)$limit;
        }
        return $db->getAllRows($sql);
    }

    /**
     * Получить результат миграции по MB UUID
     * 
     * @param string $mbProjectUuid
     * @return array|null
     * @throws Exception
     */
    public function getMigrationResultByUuid(string $mbProjectUuid): ?array
    {
        $db = $this->getWriteConnection();
        $result = $db->find(
            'SELECT * FROM migration_result_list WHERE mb_project_uuid = ? ORDER BY migration_uuid DESC LIMIT 1',
            [$mbProjectUuid]
        );
        return $result ?: null;
    }

    /**
     * Создать или обновить маппинг миграции
     * 
     * @param int $brzProjectId
     * @param string $mbProjectUuid
     * @param array $metaData
     * @return int ID записи
     * @throws Exception
     */
    public function upsertMigrationMapping(int $brzProjectId, string $mbProjectUuid, array $metaData = []): int
    {
        $db = $this->getWriteConnection();
        
        // Проверяем существование
        $existing = $db->find(
            'SELECT * FROM migrations_mapping WHERE brz_project_id = ? AND mb_project_uuid = ?',
            [$brzProjectId, $mbProjectUuid]
        );

        $changesJson = json_encode($metaData);

        if ($existing) {
            // Обновляем существующую запись через прямой SQL
            // Используем рефлексию для доступа к PDO
            $reflection = new \ReflectionClass($db);
            $pdoProperty = $reflection->getProperty('pdo');
            $pdoProperty->setAccessible(true);
            $pdo = $pdoProperty->getValue($db);
            
            $stmt = $pdo->prepare(
                'UPDATE migrations_mapping SET changes_json = ?, updated_at = NOW() WHERE brz_project_id = ? AND mb_project_uuid = ?'
            );
            $stmt->execute([$changesJson, $brzProjectId, $mbProjectUuid]);
            return (int)$existing['brz_project_id'];
        } else {
            // Создаем новую запись
            return $db->insert('migrations_mapping', [
                'brz_project_id' => $brzProjectId,
                'mb_project_uuid' => $mbProjectUuid,
                'changes_json' => $changesJson
            ]);
        }
    }

    /**
     * Удалить маппинг миграции
     * 
     * @param int $brzProjectId
     * @param string $mbProjectUuid
     * @return bool
     * @throws Exception
     */
    public function deleteMigrationMapping(int $brzProjectId, string $mbProjectUuid): bool
    {
        $db = $this->getWriteConnection();
        return $db->delete(
            'migrations_mapping',
            'brz_project_id = ? AND mb_project_uuid = ?',
            [$brzProjectId, $mbProjectUuid]
        );
    }

    /**
     * Сохранить результат миграции в migration_result_list (старая таблица, для обратной совместимости)
     * И также сохранить в новую таблицу migrations
     * 
     * @param array $data
     * @return int ID записи
     * @throws Exception
     */
    public function saveMigrationResult(array $data): int
    {
        $db = $this->getWriteConnection();
        
        // Проверяем обязательные поля
        $required = ['migration_uuid', 'brz_project_id', 'mb_project_uuid', 'result_json'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                throw new Exception("Обязательное поле отсутствует: {$field}");
            }
        }

        // Сохраняем в старую таблицу для обратной совместимости
        $oldId = $db->insert('migration_result_list', [
            'migration_uuid' => $data['migration_uuid'],
            'brz_project_id' => (int)$data['brz_project_id'],
            'brizy_project_domain' => $data['brizy_project_domain'] ?? '',
            'mb_project_uuid' => $data['mb_project_uuid'],
            'result_json' => is_string($data['result_json']) ? $data['result_json'] : json_encode($data['result_json'])
        ]);

        // Сохраняем в новую таблицу migrations
        $this->saveMigration($data);

        return $oldId;
    }

    /**
     * Сохранить или обновить миграцию в новой таблице migrations
     * 
     * @param array $data Данные миграции
     * @return int ID записи
     * @throws Exception
     */
    public function saveMigration(array $data): int
    {
        $db = $this->getWriteConnection();
        
        // Парсим result_json если он есть
        $resultJson = $data['result_json'] ?? null;
        if (is_string($resultJson)) {
            $resultData = json_decode($resultJson, true) ?: [];
        } else {
            $resultData = $resultJson ?: [];
        }

        // Извлекаем данные из result_json если они там есть
        $value = $resultData['value'] ?? $resultData;
        
        // Определяем статус
        $status = $data['status'] ?? $value['status'] ?? $resultData['status'] ?? 'pending';
        if ($status === 'success') {
            $status = 'completed';
        }

        // Проверяем существующую запись
        $mbUuid = $data['mb_project_uuid'] ?? '';
        $brzId = $data['brz_project_id'] ?? $value['brizy_project_id'] ?? null;
        $migrationUuid = $data['migration_uuid'] ?? null;
        $waveId = $data['wave_id'] ?? null;

        $existing = null;
        // Ищем по комбинации wave_id + mb_project_uuid (для миграций из волны)
        // или по migration_uuid + mb_project_uuid (для других миграций)
        if ($mbUuid) {
            if ($waveId) {
                // Для миграций из волны ищем по wave_id + mb_project_uuid
                $existing = $db->find(
                    'SELECT * FROM migrations WHERE wave_id = ? AND mb_project_uuid = ? ORDER BY created_at DESC LIMIT 1',
                    [$waveId, $mbUuid]
                );
            } elseif ($migrationUuid) {
                // Для других миграций ищем по migration_uuid + mb_project_uuid
                $existing = $db->find(
                    'SELECT * FROM migrations WHERE migration_uuid = ? AND mb_project_uuid = ? ORDER BY created_at DESC LIMIT 1',
                    [$migrationUuid, $mbUuid]
                );
            }
            
            // Если не нашли и есть brz_project_id, ищем по mb_project_uuid + brz_project_id
            if (!$existing && $brzId) {
                $existing = $db->find(
                    'SELECT * FROM migrations WHERE mb_project_uuid = ? AND brz_project_id = ? ORDER BY created_at DESC LIMIT 1',
                    [$mbUuid, $brzId]
                );
            }
        }

        $migrationData = [
            'mb_project_uuid' => $mbUuid,
            'brz_project_id' => $brzId ? (int)$brzId : null,
            'brizy_project_domain' => $data['brizy_project_domain'] ?? $value['brizy_project_domain'] ?? null,
            'mb_project_domain' => $value['mb_project_domain'] ?? null,
            'status' => $status,
            'error' => $data['error'] ?? $value['error'] ?? ($status === 'error' ? 'Миграция завершилась с ошибкой' : null),
            'mb_site_id' => $data['mb_site_id'] ?? $value['mb_site_id'] ?? null,
            'mb_page_slug' => $data['mb_page_slug'] ?? null,
            'mb_product_name' => $data['mb_product_name'] ?? $value['mb_product_name'] ?? null,
            'theme' => $data['theme'] ?? $value['theme'] ?? null,
            'migration_id' => $data['migration_id'] ?? $value['migration_id'] ?? null,
            'migration_date' => $data['date'] ?? $value['date'] ?? date('Y-m-d'),
            'wave_id' => $data['wave_id'] ?? null,
            'migration_uuid' => $migrationUuid,
            'result_json' => is_string($data['result_json']) ? $data['result_json'] : json_encode($data['result_json'] ?? []),
            'started_at' => $data['started_at'] ?? ($status === 'in_progress' ? date('Y-m-d H:i:s') : null),
            'completed_at' => $data['completed_at'] ?? (in_array($status, ['completed', 'error']) ? date('Y-m-d H:i:s') : null),
        ];

        if ($existing) {
            // Обновляем существующую запись
            $id = (int)$existing['id'];
            $updateFields = [];
            $updateValues = [];
            
            foreach ($migrationData as $key => $value) {
                if ($value !== null) {
                    $updateFields[] = "{$key} = ?";
                    $updateValues[] = $value;
                }
            }
            
            $updateFields[] = "updated_at = NOW()";
            $updateValues[] = $id; // Добавляем id в конец для WHERE
            
            $sql = 'UPDATE migrations SET ' . implode(', ', $updateFields) . ' WHERE id = ?';
            
            $reflection = new \ReflectionClass($db);
            $pdoProperty = $reflection->getProperty('pdo');
            $pdoProperty->setAccessible(true);
            $pdo = $pdoProperty->getValue($db);
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($updateValues);
            
            return $id;
        } else {
            // Создаем новую запись
            return $db->insert('migrations', $migrationData);
        }
    }

    /**
     * Обновить запись в migration_result_list
     * 
     * @param string $migrationUuid UUID миграции
     * @param string $mbProjectUuid UUID проекта MB
     * @param array $data Данные для обновления
     * @return void
     * @throws Exception
     */
    public function updateMigrationResult(string $migrationUuid, string $mbProjectUuid, array $data): void
    {
        $db = $this->getWriteConnection();
        
        $setParts = [];
        $params = [];
        
        if (isset($data['brz_project_id'])) {
            $setParts[] = 'brz_project_id = ?';
            $params[] = (int)$data['brz_project_id'];
        }
        
        if (isset($data['brizy_project_domain'])) {
            $setParts[] = 'brizy_project_domain = ?';
            $params[] = $data['brizy_project_domain'];
        }
        
        if (isset($data['result_json'])) {
            $setParts[] = 'result_json = ?';
            $params[] = is_string($data['result_json']) ? $data['result_json'] : json_encode($data['result_json']);
        }
        
        if (empty($setParts)) {
            return; // Нет данных для обновления
        }
        
        $sql = 'UPDATE migration_result_list SET ' . implode(', ', $setParts) . 
               ' WHERE migration_uuid = ? AND mb_project_uuid = ?';
        $params[] = $migrationUuid;
        $params[] = $mbProjectUuid;
        
        // Используем рефлексию для доступа к PDO
        $reflection = new \ReflectionClass($db);
        $pdoProperty = $reflection->getProperty('pdo');
        $pdoProperty->setAccessible(true);
        $pdo = $pdoProperty->getValue($db);
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Получить настройки дашборда
     * 
     * @return array
     * @throws Exception
     */
    public function getSettings(): array
    {
        $db = $this->getWriteConnection();
        
        // Используем таблицу migrations_mapping с специальным ключом для настроек
        // Или создаем отдельную таблицу dashboard_settings
        // Для простоты используем файл конфигурации
        $settingsFile = dirname(__DIR__, 2) . '/var/config/dashboard_settings.json';
        
        if (file_exists($settingsFile)) {
            $content = file_get_contents($settingsFile);
            $settings = json_decode($content, true);
            if ($settings) {
                return $settings;
            }
        }
        
        return [
            'mb_site_id' => null,
            'mb_secret' => null,
        ];
    }

    /**
     * Сохранить настройки дашборда
     * 
     * @param array $settings
     * @return void
     * @throws Exception
     */
    public function saveSettings(array $settings): void
    {
        $settingsFile = dirname(__DIR__, 2) . '/var/config/dashboard_settings.json';
        $settingsDir = dirname($settingsFile);
        
        // Создаем директорию если не существует
        if (!is_dir($settingsDir)) {
            mkdir($settingsDir, 0755, true);
        }
        
        // Получаем текущие настройки
        $currentSettings = $this->getSettings();
        
        // Обновляем только переданные значения
        foreach ($settings as $key => $value) {
            if ($value === null || $value === '') {
                unset($currentSettings[$key]);
            } else {
                $currentSettings[$key] = $value;
            }
        }
        
        // Сохраняем в файл
        file_put_contents($settingsFile, json_encode($currentSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Создать новую волну миграций
     * 
     * @param string $waveId Уникальный ID волны
     * @param string $name Название волны
     * @param array $projectUuids Массив UUID проектов
     * @param int $workspaceId ID workspace
     * @param string $workspaceName Название workspace
     * @param int $batchSize Размер батча для параллельного выполнения
     * @param bool $mgrManual Флаг ручной миграции
     * @return int ID записи
     * @throws Exception
     */
    public function createWave(
        string $waveId,
        string $name,
        array $projectUuids,
        int $workspaceId,
        string $workspaceName,
        int $batchSize = 3,
        bool $mgrManual = false,
        bool $enableCloning = false
    ): int {
        $db = $this->getWriteConnection();
        
        // Сохраняем список UUID проектов в отдельную таблицу wave_migrations
        // Сначала создаем запись в таблице waves
        $waveData = [
            'wave_id' => $waveId,
            'name' => $name,
            'workspace_id' => $workspaceId,
            'workspace_name' => $workspaceName,
            'status' => 'pending',
            'progress_total' => count($projectUuids),
            'progress_completed' => 0,
            'progress_failed' => 0,
            'batch_size' => $batchSize,
            'mgr_manual' => $mgrManual ? 1 : 0,
            'enable_cloning' => $enableCloning ? 1 : 0,
        ];
        
        try {
            $waveIdDb = $db->insert('waves', $waveData);
        } catch (Exception $e) {
            // Если таблица waves еще не создана, используем старый способ
            $waveUuid = "wave_{$waveId}";
            $changesJson = [
                'wave_id' => $waveId,
                'wave_name' => $name,
                'workspace_id' => $workspaceId,
                'workspace_name' => $workspaceName,
                'project_uuids' => $projectUuids,
                'batch_size' => $batchSize,
                'mgr_manual' => $mgrManual,
                'status' => 'pending',
                'progress' => [
                    'total' => count($projectUuids),
                    'completed' => 0,
                    'failed' => 0,
                ],
                'migrations' => [],
                'created_at' => date('Y-m-d H:i:s'),
            ];
            return $this->upsertMigrationMapping(0, $waveUuid, $changesJson);
        }
        
        // Сохраняем список UUID проектов в wave_migrations (если таблица существует)
        // Пока сохраняем в старую структуру для обратной совместимости
        $waveUuid = "wave_{$waveId}";
        $changesJson = [
            'project_uuids' => $projectUuids,
            'migrations' => [],
        ];
        $this->upsertMigrationMapping(0, $waveUuid, $changesJson);
        
        // Создаем записи в таблице migrations для всех проектов из списка UUID
        // Это позволяет сразу видеть все проекты в деталях волны, даже до запуска миграций
        foreach ($projectUuids as $mbUuid) {
            try {
                // Проверяем, не существует ли уже запись для этой миграции
                $existing = $db->find(
                    'SELECT * FROM migrations WHERE wave_id = ? AND mb_project_uuid = ? ORDER BY created_at DESC LIMIT 1',
                    [$waveId, $mbUuid]
                );
                
                if (!$existing) {
                    // Создаем новую запись со статусом 'pending'
                    $migrationData = [
                        'mb_project_uuid' => $mbUuid,
                        'brz_project_id' => null, // Будет заполнено при создании проекта
                        'wave_id' => $waveId,
                        'migration_uuid' => $waveId, // Используем wave_id как migration_uuid
                        'status' => 'pending',
                        'result_json' => json_encode(['status' => 'pending', 'message' => 'Миграция не начата']),
                        'migration_date' => date('Y-m-d'),
                    ];
                    
                    $db->insert('migrations', $migrationData);
                }
            } catch (Exception $e) {
                // Логируем ошибку, но не прерываем создание волны
                error_log("Ошибка создания записи миграции в таблице migrations для mb_uuid={$mbUuid}, wave_id={$waveId}: " . $e->getMessage());
            }
        }
        
        return $waveIdDb;
    }

    /**
     * Получить информацию о волне по ID
     * 
     * @param string $waveId ID волны
     * @return array|null
     * @throws Exception
     */
    public function getWave(string $waveId): ?array
    {
        $db = $this->getWriteConnection();
        
        // Пытаемся получить из новой таблицы waves
        try {
            $wave = $db->find(
                'SELECT * FROM waves WHERE wave_id = ?',
                [$waveId]
            );
            
            if (!$wave) {
                return null;
            }
            
            // Получаем migrations из старой структуры для обратной совместимости (если есть)
            $waveUuid = "wave_{$waveId}";
            $mapping = $db->find(
                'SELECT * FROM migrations_mapping WHERE mb_project_uuid = ? AND brz_project_id = 0',
                [$waveUuid]
            );
            $changesJson = $mapping ? json_decode($mapping['changes_json'] ?? '{}', true) : [];
            
            // Получаем список UUID проектов:
            // 1. Сначала из migrations_mapping (где они сохраняются при создании волны)
            // 2. Если их нет, то из migration_result_list (для уже выполненных миграций)
            $projectUuids = $changesJson['project_uuids'] ?? [];
            
            if (empty($projectUuids)) {
                // Если project_uuids нет в migrations_mapping, получаем из migration_result_list
                $migrationResults = $db->getAllRows(
                    'SELECT DISTINCT mb_project_uuid FROM migration_result_list WHERE migration_uuid = ?',
                    [$waveId]
                );
                $projectUuids = array_column($migrationResults, 'mb_project_uuid');
            }
            
            return [
                'id' => $wave['wave_id'],
                'name' => $wave['name'],
                'workspace_id' => $wave['workspace_id'],
                'workspace_name' => $wave['workspace_name'] ?? '',
                'project_uuids' => $projectUuids,
                'batch_size' => (int)($wave['batch_size'] ?? 3),
                'mgr_manual' => (bool)($wave['mgr_manual'] ?? false),
                'enable_cloning' => (bool)($wave['enable_cloning'] ?? false),
                'status' => $wave['status'] ?? 'pending',
                'progress' => [
                    'total' => (int)($wave['progress_total'] ?? 0),
                    'completed' => (int)($wave['progress_completed'] ?? 0),
                    'failed' => (int)($wave['progress_failed'] ?? 0),
                ],
                'migrations' => $changesJson['migrations'] ?? [],
                'created_at' => $wave['created_at'],
                'updated_at' => $wave['updated_at'],
                'completed_at' => $wave['completed_at'] ?? null,
            ];
        } catch (Exception $e) {
            // Если таблица waves не существует, используем старый способ
            $waveUuid = "wave_{$waveId}";
            
            $mapping = $db->find(
                'SELECT * FROM migrations_mapping WHERE mb_project_uuid = ? AND brz_project_id = 0',
                [$waveUuid]
            );

            if (!$mapping) {
                return null;
            }

            $changesJson = json_decode($mapping['changes_json'] ?? '{}', true);
            
            return [
                'id' => $waveId,
                'name' => $changesJson['wave_name'] ?? '',
                'workspace_id' => $changesJson['workspace_id'] ?? null,
                'workspace_name' => $changesJson['workspace_name'] ?? '',
                'project_uuids' => $changesJson['project_uuids'] ?? [],
                'batch_size' => $changesJson['batch_size'] ?? 3,
                'mgr_manual' => $changesJson['mgr_manual'] ?? false,
                'status' => $changesJson['status'] ?? 'pending',
                'progress' => [
                    'total' => $changesJson['progress']['total'] ?? 0,
                    'completed' => $changesJson['progress']['completed'] ?? 0,
                    'failed' => $changesJson['progress']['failed'] ?? 0,
                ],
                'migrations' => $changesJson['migrations'] ?? [],
                'created_at' => $mapping['created_at'],
                'updated_at' => $mapping['updated_at'],
                'completed_at' => $changesJson['completed_at'] ?? null,
            ];
        }
    }

    /**
     * Обновить прогресс волны
     * 
     * @param string $waveId ID волны
     * @param array $progress Прогресс: ['total' => int, 'completed' => int, 'failed' => int]
     * @param array $migrations Массив миграций
     * @param string|null $status Статус волны
     * @return void
     * @throws Exception
     */
    public function updateWaveProgress(
        string $waveId,
        array $progress,
        array $migrations = [],
        ?string $status = null
    ): void {
        $db = $this->getWriteConnection();
        
        // Пытаемся обновить в новой таблице waves
        try {
            $reflection = new \ReflectionClass($db);
            $pdoProperty = $reflection->getProperty('pdo');
            $pdoProperty->setAccessible(true);
            $pdo = $pdoProperty->getValue($db);
            
            $updateFields = [
                'progress_total' => $progress['total'] ?? 0,
                'progress_completed' => $progress['completed'] ?? 0,
                'progress_failed' => $progress['failed'] ?? 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            
            if ($status !== null) {
                $updateFields['status'] = $status;
                if ($status === 'completed' || $status === 'error') {
                    $updateFields['completed_at'] = date('Y-m-d H:i:s');
                }
            }
            
            $setClause = [];
            $values = [];
            foreach ($updateFields as $field => $value) {
                $setClause[] = "{$field} = ?";
                $values[] = $value;
            }
            $values[] = $waveId;
            
            $sql = 'UPDATE waves SET ' . implode(', ', $setClause) . ' WHERE wave_id = ?';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
        } catch (Exception $e) {
            // КРИТИЧНО: Логируем ошибку при обновлении таблицы waves
            $logFile = dirname(__DIR__, 3) . '/var/log/wave_dashboard.log';
            $errorMsg = "[" . date('Y-m-d H:i:s') . "] [ERROR] ❌ ОШИБКА обновления таблицы waves: wave_id={$waveId}, error=" . $e->getMessage() . ", переключение на старый способ\n";
            @file_put_contents($logFile, $errorMsg, FILE_APPEND);
            error_log("DatabaseService::updateWaveProgress - Ошибка обновления waves для {$waveId}: " . $e->getMessage());
            
            // Если таблица waves не существует, используем старый способ
            $waveUuid = "wave_{$waveId}";
            
            $mapping = $db->find(
                'SELECT * FROM migrations_mapping WHERE mb_project_uuid = ? AND brz_project_id = 0',
                [$waveUuid]
            );

            if (!$mapping) {
                throw new Exception("Волна с ID {$waveId} не найдена");
            }

            $changesJson = json_decode($mapping['changes_json'] ?? '{}', true);
            
            // Обновляем прогресс
            $changesJson['progress'] = $progress;
            
            // Обновляем миграции
            if (!empty($migrations)) {
                $changesJson['migrations'] = $migrations;
            }
            
            // Обновляем статус если указан
            if ($status !== null) {
                $changesJson['status'] = $status;
                if ($status === 'completed' || $status === 'error') {
                    $changesJson['completed_at'] = date('Y-m-d H:i:s');
                }
            }

            // Обновляем запись
            $reflection = new \ReflectionClass($db);
            $pdoProperty = $reflection->getProperty('pdo');
            $pdoProperty->setAccessible(true);
            $pdo = $pdoProperty->getValue($db);
            
            $stmt = $pdo->prepare(
                'UPDATE migrations_mapping SET changes_json = ?, updated_at = NOW() WHERE mb_project_uuid = ? AND brz_project_id = 0'
            );
            $stmt->execute([json_encode($changesJson), $waveUuid]);
        } catch (Exception $fallbackError) {
            // КРИТИЧНО: Логируем ошибку при обновлении через старый способ
            $logFile = dirname(__DIR__, 3) . '/var/log/wave_dashboard.log';
            $errorMsg = "[" . date('Y-m-d H:i:s') . "] [ERROR] ❌❌❌ КРИТИЧЕСКАЯ ОШИБКА в updateWaveProgress (старый способ): wave_id={$waveId}, error=" . $fallbackError->getMessage() . ", trace=" . $fallbackError->getTraceAsString() . "\n";
            @file_put_contents($logFile, $errorMsg, FILE_APPEND);
            error_log("DatabaseService::updateWaveProgress - КРИТИЧЕСКАЯ ОШИБКА (старый способ): " . $fallbackError->getMessage());
            error_log("DatabaseService::updateWaveProgress - Stack trace: " . $fallbackError->getTraceAsString());
            throw $fallbackError; // Пробрасываем дальше, так как это критическая ошибка
        }
        
        // КРИТИЧНО: Обновляем статусы миграций в migration_result_list
        // Это нужно, чтобы getWaveMigrations возвращал актуальные статусы
        if (!empty($migrations)) {
            $logFile = dirname(__DIR__, 3) . '/var/log/wave_dashboard.log';
            
            try {
                $reflection = new \ReflectionClass($db);
                $pdoProperty = $reflection->getProperty('pdo');
                $pdoProperty->setAccessible(true);
                $pdo = $pdoProperty->getValue($db);
                
                foreach ($migrations as $migration) {
                    $mbUuid = $migration['mb_project_uuid'] ?? null;
                    $migrationStatus = $migration['status'] ?? 'pending';
                    $brzProjectId = $migration['brz_project_id'] ?? 0;
                    
                    if (!$mbUuid) {
                        $errorMsg = "[" . date('Y-m-d H:i:s') . "] [ERROR] ❌ updateWaveProgress: миграция без mb_project_uuid, wave_id={$waveId}\n";
                        @file_put_contents($logFile, $errorMsg, FILE_APPEND);
                        continue;
                    }
                    
                    try {
                        // Обновляем или создаем запись в migration_result_list
                        // Используем migration_uuid = wave_id для связи с волной
                        $stmt = $pdo->prepare(
                            'SELECT id FROM migration_result_list WHERE migration_uuid = ? AND mb_project_uuid = ? LIMIT 1'
                        );
                        $stmt->execute([$waveId, $mbUuid]);
                        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
                        
                        if ($existing) {
                            // Обновляем существующую запись
                            // ВАЖНО: В таблице migration_result_list НЕТ колонки status!
                            // Статус хранится в result_json
                            $updateFields = [];
                            
                            if ($brzProjectId > 0) {
                                $updateFields['brz_project_id'] = $brzProjectId;
                            }
                            
                            // Обновляем result_json с статусом и ошибкой (если есть)
                            $resultJsonData = [];
                            try {
                                $existingResultJson = $pdo->prepare('SELECT result_json FROM migration_result_list WHERE id = ?');
                                $existingResultJson->execute([$existing['id']]);
                                $existingRow = $existingResultJson->fetch(\PDO::FETCH_ASSOC);
                                if ($existingRow && !empty($existingRow['result_json'])) {
                                    $resultJsonData = json_decode($existingRow['result_json'], true) ?: [];
                                }
                            } catch (Exception $e) {
                                // Игнорируем ошибку чтения существующего JSON
                            }
                            
                            $resultJsonData['status'] = $migrationStatus;
                            if (isset($migration['error']) && $migration['error']) {
                                $resultJsonData['error'] = $migration['error'];
                            }
                            
                            $updateFields['result_json'] = json_encode($resultJsonData);
                            
                            $setParts = [];
                            $values = [];
                            foreach ($updateFields as $field => $value) {
                                $setParts[] = "{$field} = ?";
                                $values[] = $value;
                            }
                            $values[] = $existing['id'];
                            
                            $sql = 'UPDATE migration_result_list SET ' . implode(', ', $setParts) . ' WHERE id = ?';
                            $updateStmt = $pdo->prepare($sql);
                            $updateStmt->execute($values);
                        } else {
                            // Создаем новую запись
                            // ВАЖНО: В таблице migration_result_list НЕТ колонки status!
                            // Статус хранится в result_json
                            $insertFields = [
                                'migration_uuid' => $waveId,
                                'mb_project_uuid' => $mbUuid,
                                'brz_project_id' => $brzProjectId,
                                'brizy_project_domain' => '', // Обязательное поле
                            ];
                            
                            // Статус и ошибка хранятся в result_json
                            $resultJsonData = ['status' => $migrationStatus];
                            if (isset($migration['error']) && $migration['error']) {
                                $resultJsonData['error'] = $migration['error'];
                            }
                            $insertFields['result_json'] = json_encode($resultJsonData);
                            
                            $fields = implode(', ', array_keys($insertFields));
                            $placeholders = implode(', ', array_fill(0, count($insertFields), '?'));
                            $sql = "INSERT INTO migration_result_list ({$fields}) VALUES ({$placeholders})";
                            $insertStmt = $pdo->prepare($sql);
                            $insertStmt->execute(array_values($insertFields));
                        }
                    } catch (Exception $migrationError) {
                        // КРИТИЧНО: Логируем ошибку для каждой миграции
                        $errorMsg = "[" . date('Y-m-d H:i:s') . "] [ERROR] ❌ ОШИБКА обновления migration_result_list: wave_id={$waveId}, mb_uuid={$mbUuid}, error=" . $migrationError->getMessage() . "\n";
                        @file_put_contents($logFile, $errorMsg, FILE_APPEND);
                        error_log("DatabaseService::updateWaveProgress - Ошибка обновления migration_result_list для {$mbUuid}: " . $migrationError->getMessage());
                    }
                }
            } catch (Exception $e) {
                // КРИТИЧНО: Логируем общую ошибку при обновлении migration_result_list
                $errorMsg = "[" . date('Y-m-d H:i:s') . "] [ERROR] ❌❌❌ КРИТИЧЕСКАЯ ОШИБКА в updateWaveProgress при обновлении migration_result_list: wave_id={$waveId}, error=" . $e->getMessage() . ", trace=" . $e->getTraceAsString() . "\n";
                @file_put_contents($logFile, $errorMsg, FILE_APPEND);
                error_log("DatabaseService::updateWaveProgress - КРИТИЧЕСКАЯ ОШИБКА: " . $e->getMessage());
                error_log("DatabaseService::updateWaveProgress - Stack trace: " . $e->getTraceAsString());
            }
        }
        
        // Также обновляем миграции в старой структуре для обратной совместимости
        if (!empty($migrations)) {
            try {
                $waveUuid = "wave_{$waveId}";
                $mapping = $db->find(
                    'SELECT * FROM migrations_mapping WHERE mb_project_uuid = ? AND brz_project_id = 0',
                    [$waveUuid]
                );
                
                if ($mapping) {
                    $changesJson = json_decode($mapping['changes_json'] ?? '{}', true);
                    $changesJson['migrations'] = $migrations;
                    
                    $reflection = new \ReflectionClass($db);
                    $pdoProperty = $reflection->getProperty('pdo');
                    $pdoProperty->setAccessible(true);
                    $pdo = $pdoProperty->getValue($db);
                    
                    $stmt = $pdo->prepare(
                        'UPDATE migrations_mapping SET changes_json = ?, updated_at = NOW() WHERE mb_project_uuid = ? AND brz_project_id = 0'
                    );
                    $stmt->execute([json_encode($changesJson), $waveUuid]);
                }
            } catch (Exception $compatError) {
                // Логируем ошибку, но не прерываем выполнение (это для обратной совместимости)
                $logFile = dirname(__DIR__, 3) . '/var/log/wave_dashboard.log';
                $errorMsg = "[" . date('Y-m-d H:i:s') . "] [ERROR] ⚠️ ОШИБКА обновления migrations_mapping (обратная совместимость): wave_id={$waveId}, error=" . $compatError->getMessage() . "\n";
                @file_put_contents($logFile, $errorMsg, FILE_APPEND);
                error_log("DatabaseService::updateWaveProgress - Ошибка обновления migrations_mapping для {$waveId}: " . $compatError->getMessage());
            }
        }
    }

    /**
     * Получить список всех волн
     * 
     * @return array
     * @throws Exception
     */
    public function getWavesList(): array
    {
        $db = $this->getWriteConnection();
        
        // Пытаемся получить из новой таблицы waves
        try {
            $waves = $db->getAllRows(
                "SELECT * FROM waves ORDER BY created_at DESC"
            );
            
            $result = [];
            foreach ($waves as $wave) {
                $progressTotal = (int)($wave['progress_total'] ?? 0);
                $progressCompleted = (int)($wave['progress_completed'] ?? 0);
                $progressFailed = (int)($wave['progress_failed'] ?? 0);
                $dbStatus = $wave['status'] ?? 'pending';
                
                // Пересчитываем статус на основе прогресса, если он не соответствует
                $calculatedStatus = $dbStatus;
                if ($progressTotal > 0) {
                    $totalProcessed = $progressCompleted + $progressFailed;
                    if ($totalProcessed >= $progressTotal) {
                        // Все миграции завершены
                        $calculatedStatus = $progressFailed > 0 ? 'error' : 'completed';
                    } elseif ($totalProcessed > 0) {
                        // Есть прогресс, но не все завершено
                        if ($dbStatus === 'pending') {
                            $calculatedStatus = 'in_progress';
                        }
                    }
                }
                
                // Используем пересчитанный статус, если он отличается от БД и БД статус не завершен
                // Это позволяет исправить случаи, когда статус в БД не обновился
                $finalStatus = ($calculatedStatus !== $dbStatus && 
                               ($dbStatus === 'in_progress' || $dbStatus === 'pending')) 
                    ? $calculatedStatus 
                    : $dbStatus;
                
                $result[] = [
                    'id' => $wave['wave_id'],
                    'name' => $wave['name'],
                    'workspace_id' => $wave['workspace_id'],
                    'workspace_name' => $wave['workspace_name'] ?? '',
                    'status' => $finalStatus,
                    'progress' => [
                        'total' => $progressTotal,
                        'completed' => $progressCompleted,
                        'failed' => $progressFailed,
                    ],
                    'created_at' => $wave['created_at'],
                    'updated_at' => $wave['updated_at'],
                    'completed_at' => $wave['completed_at'] ?? null,
                ];
            }
            
            return $result;
        } catch (Exception $e) {
            // Если таблица waves не существует, используем старый способ
            $mappings = $db->getAllRows(
                "SELECT * FROM migrations_mapping WHERE mb_project_uuid LIKE 'wave_%' AND brz_project_id = 0 ORDER BY created_at DESC"
            );

            $waves = [];
            foreach ($mappings as $mapping) {
                $changesJson = json_decode($mapping['changes_json'] ?? '{}', true);
                
                // Извлекаем wave_id из mb_project_uuid (формат: wave_{waveId})
                $waveId = str_replace('wave_', '', $mapping['mb_project_uuid']);
                
                $progress = $changesJson['progress'] ?? ['total' => 0, 'completed' => 0, 'failed' => 0];
                $progressTotal = (int)($progress['total'] ?? 0);
                $progressCompleted = (int)($progress['completed'] ?? 0);
                $progressFailed = (int)($progress['failed'] ?? 0);
                $dbStatus = $changesJson['status'] ?? 'pending';
                
                // Пересчитываем статус на основе прогресса, если он не соответствует
                $calculatedStatus = $dbStatus;
                if ($progressTotal > 0) {
                    $totalProcessed = $progressCompleted + $progressFailed;
                    if ($totalProcessed >= $progressTotal) {
                        // Все миграции завершены
                        $calculatedStatus = $progressFailed > 0 ? 'error' : 'completed';
                    } elseif ($totalProcessed > 0) {
                        // Есть прогресс, но не все завершено
                        if ($dbStatus === 'pending') {
                            $calculatedStatus = 'in_progress';
                        }
                    }
                }
                
                // Используем пересчитанный статус, если он отличается от БД и БД статус не завершен
                $finalStatus = ($calculatedStatus !== $dbStatus && 
                               ($dbStatus === 'in_progress' || $dbStatus === 'pending')) 
                    ? $calculatedStatus 
                    : $dbStatus;
                
                $waves[] = [
                    'id' => $waveId,
                    'name' => $changesJson['wave_name'] ?? '',
                    'workspace_id' => $changesJson['workspace_id'] ?? null,
                    'workspace_name' => $changesJson['workspace_name'] ?? '',
                    'status' => $finalStatus,
                    'progress' => [
                        'total' => $progressTotal,
                        'completed' => $progressCompleted,
                        'failed' => $progressFailed,
                    ],
                    'created_at' => $mapping['created_at'],
                    'updated_at' => $mapping['updated_at'],
                    'completed_at' => $changesJson['completed_at'] ?? null,
                ];
            }

            return $waves;
        }
    }

    /**
     * Получить все миграции, связанные с волной
     * Получает миграции напрямую из migration_result_list по migration_uuid = wave_id
     * 
     * @param string $waveId ID волны (совпадает с migration_uuid в migration_result_list)
     * @return array Массив миграций с деталями
     * @throws Exception
     */
    public function getWaveMigrations(string $waveId): array
    {
        $wave = $this->getWave($waveId);
        
        if (!$wave) {
            return [];
        }

        $db = $this->getWriteConnection();
        $migrations = [];
        $processedMbUuids = []; // Для отслеживания уже обработанных UUID
        
        // Получаем все миграции из migration_result_list по migration_uuid = wave_id
        $migrationResults = $db->getAllRows(
            'SELECT * FROM migration_result_list WHERE migration_uuid = ? ORDER BY created_at ASC',
            [$waveId]
        );
        
        // Также получаем миграции из таблицы migrations по wave_id
        // Это нужно для отображения миграций, которые были созданы при создании волны, но еще не запущены
        $migrationsFromTable = $db->getAllRows(
            'SELECT * FROM migrations WHERE wave_id = ? ORDER BY created_at ASC',
            [$waveId]
        );
        
        // Объединяем результаты: сначала из migration_result_list (запущенные миграции),
        // затем из migrations (незапущенные миграции со статусом pending)
        $allMigrationResults = [];
        $migrationsFromTableIndex = []; // Индекс миграций из таблицы migrations по mb_uuid
        
        // Создаем индекс миграций из таблицы migrations для быстрого поиска
        foreach ($migrationsFromTable as $migration) {
            $mbUuid = $migration['mb_project_uuid'];
            $migrationsFromTableIndex[$mbUuid] = $migration;
        }
        
        // Добавляем миграции из migration_result_list
        foreach ($migrationResults as $result) {
            $allMigrationResults[] = [
                'source' => 'migration_result_list',
                'data' => $result,
                'migration_from_table' => $migrationsFromTableIndex[$result['mb_project_uuid']] ?? null
            ];
            $processedMbUuids[$result['mb_project_uuid']] = true;
        }
        
        // Добавляем миграции из таблицы migrations, которых еще нет в migration_result_list
        foreach ($migrationsFromTable as $migration) {
            $mbUuid = $migration['mb_project_uuid'];
            if (!isset($processedMbUuids[$mbUuid])) {
                // Создаем структуру, совместимую с migration_result_list
                $allMigrationResults[] = [
                    'source' => 'migrations',
                    'data' => [
                        'mb_project_uuid' => $mbUuid,
                        'brz_project_id' => $migration['brz_project_id'] ?? 0,
                        'brizy_project_domain' => $migration['brizy_project_domain'] ?? null,
                        'migration_uuid' => $waveId,
                        'result_json' => $migration['result_json'] ?? json_encode(['status' => 'pending', 'message' => 'Миграция не начата']),
                        'created_at' => $migration['created_at'] ?? date('Y-m-d H:i:s'),
                    ],
                    'migration_from_table' => $migration
                ];
                $processedMbUuids[$mbUuid] = true;
            }
        }
        
        if (empty($allMigrationResults)) {
            return [];
        }
        
        // Оптимизация: получаем все migrations_mapping одним запросом (избегаем N+1)
        $brzProjectIds = array_filter(array_column(array_column($allMigrationResults, 'data'), 'brz_project_id'));
        $migrationsMapping = [];
        if (!empty($brzProjectIds)) {
            $placeholders = implode(',', array_fill(0, count($brzProjectIds), '?'));
            $mappings = $db->getAllRows(
                "SELECT * FROM migrations_mapping WHERE brz_project_id IN ($placeholders)",
                $brzProjectIds
            );
            // Создаем индекс по brz_project_id для быстрого поиска
            foreach ($mappings as $mapping) {
                $migrationsMapping[$mapping['brz_project_id']] = $mapping;
            }
        }
        
        // Для каждой миграции собираем полную информацию
        foreach ($allMigrationResults as $migrationItem) {
            $migrationResult = $migrationItem['data'];
            $source = $migrationItem['source'];
            $mbUuid = $migrationResult['mb_project_uuid'];
            $brzProjectId = $migrationResult['brz_project_id'];
            
            // ВАЖНО: Если brz_project_id из migration_result_list равен 0 или очень маленькому числу (< 1000),
            // это может быть неправильное значение. Пытаемся найти правильный brz_project_id из migrations_mapping по mb_uuid
            if (empty($brzProjectId) || $brzProjectId == 0 || ($brzProjectId < 1000 && $brzProjectId > 0)) {
                // Ищем правильный brz_project_id в migrations_mapping по mb_uuid
                $correctMapping = $db->find(
                    'SELECT brz_project_id FROM migrations_mapping WHERE mb_project_uuid = ? AND brz_project_id > 0 ORDER BY updated_at DESC LIMIT 1',
                    [$mbUuid]
                );
                if ($correctMapping && !empty($correctMapping['brz_project_id']) && $correctMapping['brz_project_id'] > 1000) {
                    $oldBrzProjectId = $brzProjectId;
                    $brzProjectId = (int)$correctMapping['brz_project_id'];
                    error_log("[getWaveMigrations] Fixed brz_project_id for mb_uuid={$mbUuid}: {$oldBrzProjectId} -> {$brzProjectId}");
                } else {
                    error_log("[getWaveMigrations] Warning: brz_project_id is suspicious for mb_uuid={$mbUuid}: brz_project_id={$brzProjectId}");
                }
            }
            
            // Получаем данные из migrations_mapping (из кеша)
            $migrationMapping = $migrationsMapping[$brzProjectId] ?? null;
            
            // Если не нашли в кеше, пытаемся найти по mb_uuid
            if (!$migrationMapping && $mbUuid) {
                $mappingByUuid = $db->find(
                    'SELECT * FROM migrations_mapping WHERE mb_project_uuid = ? AND brz_project_id = ? ORDER BY updated_at DESC LIMIT 1',
                    [$mbUuid, $brzProjectId]
                );
                if ($mappingByUuid) {
                    $migrationMapping = $mappingByUuid;
                    // Обновляем кеш
                    $migrationsMapping[$brzProjectId] = $mappingByUuid;
                }
            }
            
            // Парсим result_json (с защитой от больших/поврежденных JSON)
            $resultJson = $migrationResult['result_json'] ?? '{}';
            $resultData = null;
            $resultValue = null;
            if (!empty($resultJson) && is_string($resultJson)) {
                // Проверяем, не обрезан ли JSON
                $trimmed = trim($resultJson);
                if (!empty($trimmed) && (substr($trimmed, -1) === '}' || substr($trimmed, -1) === ']')) {
                    try {
                        $resultData = json_decode($trimmed, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $resultValue = $resultData['value'] ?? $resultData ?? null;
                        }
                    } catch (Exception $e) {
                        // Игнорируем ошибки парсинга JSON
                    }
                }
            }
            if ($resultData === null) {
                $resultData = [];
            }
            
            // Объединяем данные из разных источников
            $migrationChanges = $migrationMapping 
                ? json_decode($migrationMapping['changes_json'] ?? '{}', true) 
                : [];

            // Определяем статус: приоритет у данных из result_json (status напрямую), затем из result value, затем из mapping
            // Если миграция из таблицы migrations и статус там 'pending', используем его
            $migrationFromTable = $migrationItem['migration_from_table'] ?? null;
            if ($source === 'migrations' && $migrationFromTable && isset($migrationFromTable['status'])) {
                $status = $migrationFromTable['status'];
            } elseif ($migrationFromTable && isset($migrationFromTable['status']) && $migrationFromTable['status'] === 'pending') {
                // Если миграция из migration_result_list, но в таблице migrations есть запись со статусом pending, используем его
                $status = $migrationFromTable['status'];
            } else {
                $status = $resultData['status'] 
                    ?? $resultValue['status'] 
                    ?? $migrationChanges['status'] 
                    ?? ($source === 'migrations' ? 'pending' : 'completed');
            }

            // Определяем domain: приоритет у данных из result, затем из migration_result_list, затем из mapping
            $brizyProjectDomain = $migrationResult['brizy_project_domain'] 
                ?? $resultValue['brizy_project_domain'] 
                ?? $migrationChanges['brizy_project_domain'] 
                ?? null;

            // Определяем completed_at
            $completedAt = $migrationResult['created_at'] 
                ?? ($migrationMapping ? $migrationMapping['updated_at'] : null);

            // Определяем error
            $error = $resultValue['error'] 
                ?? $migrationChanges['error'] 
                ?? null;

            // Получаем ID миграции из таблицы migrations
            $migrationId = null;
            if ($mbUuid && $brzProjectId) {
                // Ищем миграцию по mb_uuid и brz_project_id, с приоритетом для миграций из этой волны
                $migrationRecord = $db->find(
                    'SELECT id FROM migrations WHERE mb_project_uuid = ? AND brz_project_id = ? AND (wave_id = ? OR wave_id IS NULL) ORDER BY (wave_id = ?) DESC, created_at DESC LIMIT 1',
                    [$mbUuid, $brzProjectId, $waveId, $waveId]
                );
                if ($migrationRecord) {
                    $migrationId = (int)$migrationRecord['id'];
                }
            }
            
            // Если brz_project_id равен 0 или null, пытаемся получить его из result_json
            if (empty($brzProjectId) || $brzProjectId == 0) {
                if (isset($resultValue['brizy_project_id']) && !empty($resultValue['brizy_project_id'])) {
                    $brzProjectId = (int)$resultValue['brizy_project_id'];
                } elseif (isset($migrationChanges['brizy_project_id']) && !empty($migrationChanges['brizy_project_id'])) {
                    $brzProjectId = (int)$migrationChanges['brizy_project_id'];
                }
            }
            
            // Собираем полную информацию о миграции
            $migrationData = [
                'mb_project_uuid' => $mbUuid,
                'brz_project_id' => $brzProjectId ? (int)$brzProjectId : null,
                'status' => $status,
                'brizy_project_domain' => $brizyProjectDomain,
                'error' => $error,
                'completed_at' => $completedAt,
                'migration_uuid' => $migrationResult['migration_uuid'],
                'migration_id' => $migrationId,
                'cloning_enabled' => (bool)($migrationMapping['cloning_enabled'] ?? false),
            ];

            // Добавляем дополнительные данные из result_json если есть
            if ($resultValue) {
                $migrationData['result_data'] = [
                    'migration_id' => $resultValue['migration_id'] ?? null,
                    'date' => $resultValue['date'] ?? null,
                    'theme' => $resultValue['theme'] ?? null,
                    'mb_product_name' => $resultValue['mb_product_name'] ?? null,
                    'mb_site_id' => $resultValue['mb_site_id'] ?? null,
                    'progress' => $resultValue['progress'] ?? null,
                    'DEV_MODE' => $resultValue['DEV_MODE'] ?? null,
                    'mb_project_domain' => $resultValue['mb_project_domain'] ?? null,
                    'warnings' => $resultValue['message']['warning'] ?? ($resultValue['message'] ?? []),
                ];
                
                // Если brizy_project_domain не найден в других источниках, берем из result
                if (!$brizyProjectDomain && isset($resultValue['brizy_project_domain'])) {
                    $migrationData['brizy_project_domain'] = $resultValue['brizy_project_domain'];
                }
                
                // Если brz_project_id все еще не найден, берем из result (дополнительная проверка)
                if (empty($migrationData['brz_project_id']) && isset($resultValue['brizy_project_id']) && !empty($resultValue['brizy_project_id'])) {
                    $migrationData['brz_project_id'] = (int)$resultValue['brizy_project_id'];
                }
            }

            $migrations[] = $migrationData;
        }

        return $migrations;
    }

    /**
     * Получить маппинг проектов для волны
     * Получает все записи из migrations_mapping для проектов этой волны
     * 
     * @param string $waveId ID волны (совпадает с migration_uuid в migration_result_list)
     * @return array Массив маппингов с деталями
     * @throws Exception
     */
    public function getWaveMapping(string $waveId): array
    {
        $db = $this->getWriteConnection();
        
        // Получаем все миграции из migration_result_list по migration_uuid = wave_id
        $migrationResults = $db->getAllRows(
            'SELECT * FROM migration_result_list WHERE migration_uuid = ? ORDER BY created_at ASC',
            [$waveId]
        );
        
        if (empty($migrationResults)) {
            return [];
        }
        
        // Получаем все brz_project_id из результатов
        $brzProjectIds = array_filter(array_column($migrationResults, 'brz_project_id'));
        
        if (empty($brzProjectIds)) {
            return [];
        }
        
        // Получаем все записи из migrations_mapping для этих проектов
        $placeholders = implode(',', array_fill(0, count($brzProjectIds), '?'));
        $mappings = $db->getAllRows(
            "SELECT * FROM migrations_mapping WHERE brz_project_id IN ($placeholders) ORDER BY created_at DESC",
            $brzProjectIds
        );
        
        // Создаем индекс по brz_project_id для связи с migration_result_list
        $mappingsByBrzId = [];
        foreach ($mappings as $mapping) {
            $mappingsByBrzId[$mapping['brz_project_id']] = $mapping;
        }
        
        // Объединяем данные из migration_result_list и migrations_mapping
        $result = [];
        foreach ($migrationResults as $migrationResult) {
            $brzProjectId = $migrationResult['brz_project_id'];
            $mapping = $mappingsByBrzId[$brzProjectId] ?? null;
            
            if (!$mapping) {
                // Если маппинга нет, создаем базовую запись из migration_result_list
                $result[] = [
                    'id' => null,
                    'brz_project_id' => $brzProjectId,
                    'mb_project_uuid' => $migrationResult['mb_project_uuid'],
                    'brizy_project_domain' => $migrationResult['brizy_project_domain'] ?? null,
                    'changes_json' => null,
                    'cloning_enabled' => false,
                    'created_at' => $migrationResult['created_at'],
                    'updated_at' => $migrationResult['created_at'],
                ];
                continue;
            }
            
            // Парсим changes_json
            $changesJson = json_decode($mapping['changes_json'] ?? '{}', true);
            
            $result[] = [
                'id' => $mapping['id'] ?? null,
                'brz_project_id' => $mapping['brz_project_id'],
                'mb_project_uuid' => $mapping['mb_project_uuid'],
                'brizy_project_domain' => $migrationResult['brizy_project_domain'] 
                    ?? $changesJson['brizy_project_domain'] 
                    ?? null,
                'changes_json' => $changesJson,
                'cloning_enabled' => (bool)($mapping['cloning_enabled'] ?? false),
                'created_at' => $mapping['created_at'],
                'updated_at' => $mapping['updated_at'],
            ];
        }
        
        return $result;
    }

    /**
     * Обновить параметр cloning_enabled для проекта в migrations_mapping
     * 
     * @param int $brzProjectId ID проекта Brizy
     * @param bool $cloningEnabled Включено ли клонирование
     * @return bool
     * @throws Exception
     */
    public function updateCloningEnabled(int $brzProjectId, bool $cloningEnabled): bool
    {
        $db = $this->getWriteConnection();
        
        try {
            $db->update(
                'migrations_mapping',
                ['cloning_enabled' => $cloningEnabled ? 1 : 0],
                ['brz_project_id' => $brzProjectId]
            );
            
            return true;
        } catch (Exception $e) {
            error_log("Ошибка обновления cloning_enabled для проекта {$brzProjectId}: " . $e->getMessage());
            throw $e;
        }
    }
}
