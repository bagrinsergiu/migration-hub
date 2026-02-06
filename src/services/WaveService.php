<?php

namespace Dashboard\Services;

use Exception;
use MBMigration\Core\Config;
use MBMigration\Core\Logger;
use Dashboard\Core\BrizyConfig;
use Dashboard\Services\BrizyApiService;
use Dashboard\Services\MigrationExecutionService;
use Dashboard\Services\MigrationService; // Added for monitoring migrations
use Dashboard\Services\WaveLogger;

/**
 * WaveService
 * 
 * Сервис для работы с волнами миграций
 */
class WaveService
{
    /** @var DatabaseService */
    private $dbService;

    public function __construct()
    {
        $this->dbService = new DatabaseService();
    }

    /**
     * Создать новую волну миграций
     * 
     * @param string $name Название волны
     * @param array $projectUuids Массив UUID проектов
     * @param int $batchSize Размер батча
     * @param bool $mgrManual Флаг ручной миграции
     * @return array
     * @throws Exception
     */
    public function createWave(
        string $name,
        array $projectUuids,
        int $batchSize = 3,
        bool $mgrManual = false,
        bool $enableCloning = false
    ): array {
        WaveLogger::startOperation('createWave', [
            'name' => $name,
            'projects_count' => count($projectUuids),
            'batch_size' => $batchSize,
            'mgr_manual' => $mgrManual
        ]);
        error_log("[WaveService::createWave] Начало создания волны: name={$name}, projects=" . count($projectUuids) . ", batchSize={$batchSize}, mgrManual=" . ($mgrManual ? 'true' : 'false'));
        
        // Валидация
        if (empty($name)) {
            WaveLogger::error("Название волны пустое");
            error_log("[WaveService::createWave] ОШИБКА: Название волны пустое");
            throw new Exception('Название волны обязательно');
        }
        
        if (empty($projectUuids)) {
            WaveLogger::error("Список UUID проектов пустой");
            error_log("[WaveService::createWave] ОШИБКА: Список UUID проектов пустой");
            throw new Exception('Список UUID проектов не может быть пустым');
        }

        // Генерируем уникальный ID волны
        $waveId = time() . '_' . random_int(1000, 9999);
        WaveLogger::info("Сгенерирован waveId", ['wave_id' => $waveId]);
        error_log("[WaveService::createWave] Сгенерирован waveId: {$waveId}");

        // Инициализируем Logger перед использованием BrizyAPI
        // Проверяем, инициализирован ли Logger, и если нет - инициализируем
        if (!Logger::isInitialized()) {
            $projectRoot = dirname(__DIR__, 3);
            // Нормализуем путь, чтобы избежать двойных слешей
            $projectRoot = rtrim($projectRoot, '/');
            if (empty($projectRoot) || $projectRoot === '/') {
                $projectRoot = __DIR__ . '/../../..';
                $projectRoot = realpath($projectRoot) ?: dirname(__DIR__, 3);
                $projectRoot = rtrim($projectRoot, '/');
            }
            $logDir = $projectRoot . '/var/log';
            $logPath = $logDir . '/wave_' . $waveId . '.log';
            // Создаем директорию с правильными правами
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0777, true);
            }
            // Убеждаемся, что директория доступна для записи
            if (!is_writable($logDir)) {
                @chmod($logDir, 0777);
            }
            Logger::initialize(
                'WaveService',
                \Monolog\Logger::DEBUG,
                $logPath
            );
            WaveLogger::debug("Logger инициализирован", ['log_path' => $logPath]);
            error_log("[WaveService::createWave] Logger инициализирован: {$logPath}");
        } else {
            WaveLogger::debug("Logger уже инициализирован");
            error_log("[WaveService::createWave] Logger уже инициализирован");
        }

        // Создаем или находим workspace
        WaveLogger::info("Поиск workspace", ['name' => $name]);
        error_log("[WaveService::createWave] Поиск workspace с именем: {$name}");
        $brizyApi = $this->getBrizyApiService();
        $workspaceId = $brizyApi->getWorkspaces($name);
        WaveLogger::info("Результат поиска workspace", ['workspace_id' => $workspaceId, 'found' => !empty($workspaceId)]);
        error_log("[WaveService::createWave] Результат поиска workspace: " . ($workspaceId ? "найден ID={$workspaceId}" : "не найден"));
        
        if (!$workspaceId) {
            // Создаем новый workspace
            WaveLogger::info("Workspace не найден, создаем новый", ['name' => $name]);
            error_log("[WaveService::createWave] Workspace не найден, создаем новый...");
            try {
                $workspaceResult = $brizyApi->createWorkspace($name);
                WaveLogger::debug("Результат создания workspace", ['result' => $workspaceResult]);
                error_log("[WaveService::createWave] Результат создания workspace: " . json_encode($workspaceResult));
                
                if (empty($workspaceResult)) {
                    throw new Exception('Пустой ответ от API при создании workspace');
                }
                
                // Новый API возвращает уже декодированный массив
                if (is_array($workspaceResult)) {
                    if (isset($workspaceResult['id'])) {
                        $workspaceId = $workspaceResult['id'];
                    } elseif (isset($workspaceResult[0]['id'])) {
                        $workspaceId = $workspaceResult[0]['id'];
                    } elseif (isset($workspaceResult['error'])) {
                        throw new Exception('Ошибка создания workspace: ' . $workspaceResult['error']);
                    }
                }
                
                // Если не получили ID из ответа, пытаемся найти созданный workspace
                if (!$workspaceId) {
                    // Небольшая задержка для синхронизации
                    sleep(1);
                    $workspaceId = $brizyApi->getWorkspaces($name);
                    if (!$workspaceId) {
                        throw new Exception('Workspace создан, но не найден. Попробуйте создать волну еще раз.');
                    }
                }
            } catch (Exception $e) {
                // Если это уже наше исключение, пробрасываем дальше
                if (strpos($e->getMessage(), 'Ошибка создания workspace') !== false || 
                    strpos($e->getMessage(), 'Workspace создан') !== false ||
                    strpos($e->getMessage(), 'Пустой ответ') !== false ||
                    strpos($e->getMessage(), 'Неверный формат') !== false) {
                    throw $e;
                }
                // Иначе оборачиваем в более понятное сообщение
                throw new Exception('Ошибка при создании workspace: ' . $e->getMessage());
            }
        }

        // Сохраняем волну в БД
        WaveLogger::info("Сохранение волны в БД", ['wave_id' => $waveId, 'workspace_id' => $workspaceId]);
        error_log("[WaveService::createWave] Сохранение волны в БД: waveId={$waveId}, workspaceId={$workspaceId}");
        try {
            $this->dbService->createWave(
                $waveId,
                $name,
                $projectUuids,
                $workspaceId,
                $name, // workspace_name = name волны
                $batchSize,
                $mgrManual,
                $enableCloning
            );
            WaveLogger::info("Волна успешно сохранена в БД", ['wave_id' => $waveId]);
            error_log("[WaveService::createWave] Волна успешно сохранена в БД");
        } catch (Exception $e) {
            WaveLogger::error("ОШИБКА сохранения волны в БД", ['wave_id' => $waveId, 'error' => $e->getMessage()]);
            error_log("[WaveService::createWave] ОШИБКА сохранения волны в БД: " . $e->getMessage());
            throw $e;
        }

        // Запускаем выполнение волны в фоне
        WaveLogger::info("Запуск выполнения волны в фоне", ['wave_id' => $waveId]);
        error_log("[WaveService::createWave] Запуск выполнения волны в фоне: waveId={$waveId}");
        try {
            $this->runWaveInBackground($waveId);
            WaveLogger::info("Волна успешно запущена в фоне", ['wave_id' => $waveId]);
            error_log("[WaveService::createWave] Волна успешно запущена в фоне");
        } catch (Exception $e) {
            WaveLogger::error("ОШИБКА запуска волны в фоне", [
                'wave_id' => $waveId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            error_log("[WaveService::createWave] ОШИБКА запуска волны в фоне: " . $e->getMessage());
            error_log("[WaveService::createWave] Stack trace: " . $e->getTraceAsString());
            throw $e;
        }

        WaveLogger::endOperation('createWave', [
            'wave_id' => $waveId,
            'workspace_id' => $workspaceId,
            'status' => 'in_progress'
        ]);

        return [
            'wave_id' => $waveId,
            'workspace_id' => $workspaceId,
            'workspace_name' => $name,
            'status' => 'in_progress',
        ];
    }

    /**
     * Запустить выполнение волны в фоне
     * Использует MigrationExecutionService для запуска миграций через HTTP
     * 
     * @param string $waveId ID волны
     * @return void
     * @throws Exception
     */
    private function runWaveInBackground(string $waveId): void
    {
        WaveLogger::startOperation('runWaveInBackground', ['wave_id' => $waveId]);
        error_log("[WaveService::runWaveInBackground] Начало запуска волны в фоне: waveId={$waveId}");
        
        // Получаем данные волны из БД
        WaveLogger::debug("Получение данных волны из БД", ['wave_id' => $waveId]);
        $dbService = new DatabaseService();
        $wave = $dbService->getWave($waveId);
        if (!$wave) {
            $errorMsg = "ERROR: Wave not found: {$waveId}";
            WaveLogger::error($errorMsg, ['wave_id' => $waveId]);
            error_log("[WaveService::runWaveInBackground] {$errorMsg}");
            throw new Exception($errorMsg);
        }
        
        WaveLogger::info("Данные волны получены", [
            'wave_id' => $waveId,
            'workspace_id' => $wave['workspace_id'] ?? null,
            'projects_count' => count($wave['project_uuids'] ?? [])
        ]);
        error_log("[WaveService::runWaveInBackground] Данные волны получены: workspaceId=" . ($wave['workspace_id'] ?? 'null') . ", projects=" . count($wave['project_uuids'] ?? []));
        
        // Загружаем настройки по умолчанию
        WaveLogger::debug("Загрузка настроек");
        $settings = $dbService->getSettings();
        $mbSiteId = $settings['mb_site_id'] ?? null;
        $mbSecret = $settings['mb_secret'] ?? null;
        
        if (empty($mbSiteId) || empty($mbSecret)) {
            $errorMsg = "MB Site ID or Secret not configured";
            WaveLogger::error($errorMsg, ['mb_site_id' => $mbSiteId, 'mb_secret_set' => !empty($mbSecret)]);
            error_log("[WaveService::runWaveInBackground] ОШИБКА: {$errorMsg}");
            $dbService->updateWaveProgress($waveId, $wave['progress'] ?? ['total' => 0, 'completed' => 0, 'failed' => 0], $wave['migrations'] ?? [], 'error');
            throw new Exception($errorMsg);
        }
        
        WaveLogger::debug("Настройки загружены", ['mb_site_id' => $mbSiteId]);
        
        // Обновляем статус волны на in_progress
        WaveLogger::info("Обновление статуса волны на in_progress", ['wave_id' => $waveId]);
        $dbService->updateWaveProgress($waveId, $wave['progress'] ?? ['total' => 0, 'completed' => 0, 'failed' => 0], $wave['migrations'] ?? [], 'in_progress');
        
        // Подготавливаем миграции для запуска
        $projectUuids = $wave['project_uuids'] ?? [];
        $workspaceId = $wave['workspace_id'];
        $batchSize = $wave['batch_size'] ?? 3;
        $mgrManual = $wave['mgr_manual'] ?? false;
        
        WaveLogger::info("Подготовка миграций", [
            'wave_id' => $waveId,
            'projects_count' => count($projectUuids),
            'batch_size' => $batchSize,
            'mgr_manual' => $mgrManual
        ]);
        
        // КРИТИЧНО: Сначала создаем проекты для всех миграций
        // Это гарантирует, что у каждой миграции будет brz_project_id перед запуском
        WaveLogger::info("🔨 [ЭТАП 0] Создание проектов для миграций", [
            'wave_id' => $waveId,
            'projects_count' => count($projectUuids),
            'workspace_id' => $workspaceId
        ]);
        
        $migrations = [];
        foreach ($projectUuids as $index => $mbUuid) {
            try {
                // Создаем проект в workspace
                $brzProjectId = $this->createOrGetProject($mbUuid, $workspaceId, $waveId);
                
                if ($brzProjectId <= 0) {
                    throw new Exception("Не удалось создать или найти проект для {$mbUuid}");
                }
                
                WaveLogger::info("✅ [ЭТАП 0] Проект создан/найден", [
                    'wave_id' => $waveId,
                    'mb_uuid' => $mbUuid,
                    'brz_project_id' => $brzProjectId,
                    'workspace_id' => $workspaceId,
                    'position' => $index + 1
                ]);
                
                $migrationParams = [
                    'mb_project_uuid' => $mbUuid,
                    'brz_project_id' => $brzProjectId, // Теперь у нас есть brz_project_id!
                    'brz_workspaces_id' => $workspaceId,
                    'mb_site_id' => $mbSiteId,
                    'mb_secret' => $mbSecret,
                    'mgr_manual' => $mgrManual ? 1 : 0,
                    'quality_analysis' => false,
                    'wave_id' => $waveId // Добавляем wave_id для логирования
                ];
                $migrations[] = $migrationParams;
                
                WaveLogger::info("📝 Подготовка миграции #" . ($index + 1), [
                    'wave_id' => $waveId,
                    'mb_uuid' => $mbUuid,
                    'brz_project_id' => $brzProjectId,
                    'workspace_id' => $workspaceId,
                    'mb_site_id' => $mbSiteId,
                    'mgr_manual' => $mgrManual,
                    'total_in_wave' => count($projectUuids),
                    'position' => $index + 1
                ]);
            } catch (Exception $e) {
                WaveLogger::error("❌ [ОШИБКА] Не удалось создать проект для миграции", [
                    'wave_id' => $waveId,
                    'mb_uuid' => $mbUuid,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // Добавляем миграцию с ошибкой
                $migrations[] = [
                    'mb_project_uuid' => $mbUuid,
                    'brz_project_id' => 0,
                    'brz_workspaces_id' => $workspaceId,
                    'mb_site_id' => $mbSiteId,
                    'mb_secret' => $mbSecret,
                    'mgr_manual' => $mgrManual ? 1 : 0,
                    'quality_analysis' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        WaveLogger::info("Подготовлено миграций", ['count' => count($migrations), 'batch_size' => $batchSize]);
        error_log("[WaveService::runWaveInBackground] Подготовлено миграций: " . count($migrations) . ", batchSize: {$batchSize}");
        
        // Запускаем миграции через MigrationExecutionService
        try {
            // Принудительно пишем в лог перед вызовом executeBatch
            $logFile = dirname(__DIR__, 3) . '/var/log/wave_dashboard.log';
            @file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] [INFO] 🔄 ПЕРЕД вызовом executeBatch для wave_id={$waveId}, migrations=" . count($migrations) . ", batch_size={$batchSize}\n", FILE_APPEND);
            
            WaveLogger::info("Инициализация MigrationExecutionService", ['wave_id' => $waveId]);
            $executionService = new MigrationExecutionService();
            WaveLogger::info("MigrationExecutionService инициализирован", ['wave_id' => $waveId]);
            
            WaveLogger::info("Запуск executeBatch", [
                'wave_id' => $waveId,
                'migrations_count' => count($migrations),
                'batch_size' => $batchSize
            ]);
            
            // Принудительно пишем в лог перед вызовом
            @file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] [INFO] 🚀 ВЫЗОВ executeBatch для wave_id={$waveId}\n", FILE_APPEND);
            
            $result = $executionService->executeBatch($migrations, $batchSize);
            
            // Принудительно пишем в лог после вызова
            @file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] [INFO] ✅ executeBatch завершен для wave_id={$waveId}, results=" . count($result['results'] ?? []) . "\n", FILE_APPEND);
            
            WaveLogger::info("📊 Результат executeBatch получен", [
                'wave_id' => $waveId,
                'total' => $result['total'] ?? 0,
                'processed' => $result['processed'] ?? 0,
                'results_count' => count($result['results'] ?? [])
            ]);
            
            // Обновляем статусы миграций в БД на основе результатов
            WaveLogger::info("🔄 Начало обновления статусов миграций в БД", [
                'wave_id' => $waveId,
                'results_to_process' => count($result['results'] ?? [])
            ]);
            
            $waveMigrations = $wave['migrations'] ?? [];
            $progress = $wave['progress'] ?? ['total' => count($migrations), 'completed' => 0, 'failed' => 0];
            $successCount = 0;
            $failedCount = 0;
            
            foreach ($result['results'] as $resultIndex => $migrationResult) {
                $mbUuid = $migrationResult['migration']['mb_project_uuid'] ?? null;
                if (!$mbUuid) {
                    WaveLogger::warning("⚠️ Миграция без mb_uuid в результате", [
                        'wave_id' => $waveId,
                        'result_index' => $resultIndex,
                        'result' => $migrationResult
                    ]);
                    continue;
                }
                
                $isSuccess = $migrationResult['success'] ?? false;
                $status = $migrationResult['status'] ?? ($isSuccess ? 'in_progress' : 'error');
                
                // brz_project_id уже должен быть известен (создан на этапе 0)
                // Но проверяем ответ на случай, если он был обновлен
                $brzProjectId = $migrationResult['migration']['brz_project_id'] ?? 0;
                if ($brzProjectId <= 0) {
                    // Пытаемся получить из ответа (на случай, если был обновлен)
                    $brzProjectId = $migrationResult['brz_project_id'] ?? 
                                   ($migrationResult['data']['brizy_project_id'] ?? 
                                    ($migrationResult['data']['value']['brizy_project_id'] ?? 0));
                }
                $httpCode = $migrationResult['http_code'] ?? null;
                $errorMessage = $migrationResult['error'] ?? ($migrationResult['message'] ?? null);
                $url = $migrationResult['url'] ?? null;
                
                WaveLogger::info("📋 Обработка результата миграции #" . ($resultIndex + 1), [
                    'wave_id' => $waveId,
                    'mb_uuid' => $mbUuid,
                    'success' => $isSuccess,
                    'status' => $status,
                    'http_code' => $httpCode,
                    'brz_project_id' => $brzProjectId,
                    'url' => $url,
                    'has_error' => !empty($migrationResult['error']),
                    'error_message' => $errorMessage
                ]);
                
                if ($isSuccess) {
                    $successCount++;
                    WaveLogger::info("✅ Миграция успешно запущена и обработана", [
                        'wave_id' => $waveId,
                        'mb_uuid' => $mbUuid,
                        'brz_project_id' => $brzProjectId,
                        'http_code' => $httpCode,
                        'status' => $status,
                        'url' => $url
                    ]);
        } else {
                    $failedCount++;
                    $errorMsg = $migrationResult['error'] ?? $migrationResult['message'] ?? 'Unknown error';
                    
                    // Если была ошибка создания проекта, используем её
                    if (isset($migration['error']) && !empty($migration['error'])) {
                        $errorMsg = $migration['error'];
                    }
                    
                    $errorDetails = [
                        'wave_id' => $waveId,
                        'mb_uuid' => $mbUuid,
                        'status' => $status,
                        'http_code' => $httpCode,
                        'url' => $url,
                        'error' => $errorMsg,
                        'message' => $migrationResult['message'] ?? null,
                        'result_data' => $migrationResult['data'] ?? null,
                        'brz_project_id' => $brzProjectId
                    ];
                    WaveLogger::error("❌ Миграция НЕ запущена - ОШИБКА", $errorDetails);
                    error_log("[WaveService::runWaveInBackground] Ошибка запуска миграции {$mbUuid}: " . $errorMsg);
                }
                
                // Находим или создаем запись миграции
                $migrationIndex = array_search($mbUuid, array_column($waveMigrations, 'mb_project_uuid'));
                
                // Определяем ошибку для сохранения
                $errorToSave = null;
                if (!$isSuccess) {
                    // Если была ошибка создания проекта, используем её
                    if (isset($migration['error']) && !empty($migration['error'])) {
                        $errorToSave = $migration['error'];
                    } else {
                        $errorToSave = $migrationResult['error'] ?? $migrationResult['message'] ?? 'Unknown error';
                    }
                }
                
                if ($migrationIndex === false) {
                    $waveMigrations[] = [
                        'mb_project_uuid' => $mbUuid,
                        'brz_project_id' => $brzProjectId,
                        'status' => $status,
                        'error' => $errorToSave
                    ];
                } else {
                    $waveMigrations[$migrationIndex]['status'] = $status;
                    $waveMigrations[$migrationIndex]['brz_project_id'] = $brzProjectId;
                    if ($errorToSave) {
                        $waveMigrations[$migrationIndex]['error'] = $errorToSave;
                    }
                }

                // Сохраняем миграцию в таблицу migrations
                try {
                    $migrationData = $migrationResult['data'] ?? [];
                    $resultData = is_array($migrationData) ? $migrationData : (isset($migrationData['value']) ? $migrationData['value'] : []);
                    
                    $saveData = [
                        'migration_uuid' => $waveId,
                        'brz_project_id' => $brzProjectId > 0 ? $brzProjectId : null,
                        'brizy_project_domain' => $resultData['brizy_project_domain'] ?? $migrationData['brizy_project_domain'] ?? null,
                        'mb_project_uuid' => $mbUuid,
                        'mb_project_domain' => $resultData['mb_project_domain'] ?? $migrationData['mb_project_domain'] ?? null,
                        'status' => $status,
                        'error' => $errorToSave,
                        'mb_site_id' => $migrationResult['migration']['mb_site_id'] ?? null,
                        'mb_page_slug' => $migrationResult['migration']['mb_page_slug'] ?? null,
                        'mb_product_name' => $resultData['mb_product_name'] ?? $migrationData['mb_product_name'] ?? null,
                        'theme' => $resultData['theme'] ?? $migrationData['theme'] ?? null,
                        'migration_id' => $resultData['migration_id'] ?? $migrationData['migration_id'] ?? null,
                        'date' => $resultData['date'] ?? $migrationData['date'] ?? date('Y-m-d'),
                        'wave_id' => $waveId,
                        'result_json' => json_encode($migrationResult),
                        'started_at' => $status === 'in_progress' ? date('Y-m-d H:i:s') : null,
                        'completed_at' => in_array($status, ['completed', 'error']) ? date('Y-m-d H:i:s') : null
                    ];
                    
                    WaveLogger::info("💾 Сохранение миграции в таблицу migrations", [
                        'wave_id' => $waveId,
                        'mb_uuid' => $mbUuid,
                        'brz_project_id' => $brzProjectId,
                        'status' => $status,
                        'has_brz_id' => $brzProjectId > 0
                    ]);
                    
                    $migrationId = $this->dbService->saveMigration($saveData);
                    
                    WaveLogger::info("✅ Миграция успешно сохранена в таблицу migrations", [
                        'wave_id' => $waveId,
                        'mb_uuid' => $mbUuid,
                        'migration_id' => $migrationId,
                        'brz_project_id' => $brzProjectId
                    ]);
                } catch (Exception $e) {
                    WaveLogger::error("❌ Ошибка сохранения миграции в таблицу migrations", [
                        'wave_id' => $waveId,
                        'mb_uuid' => $mbUuid,
                        'brz_project_id' => $brzProjectId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    error_log("Ошибка сохранения миграции в таблицу migrations: " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                }
            }
            
            // Обновляем прогресс
            $progress['failed'] = $failedCount;
            $waveStatus = ($failedCount === count($migrations)) ? 'error' : 'in_progress';
            
            WaveLogger::info("Обновление прогресса волны", [
                'wave_id' => $waveId,
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'total' => count($migrations),
                'wave_status' => $waveStatus
            ]);
            
            $dbService->updateWaveProgress($waveId, $progress, $waveMigrations, $waveStatus);
            
            WaveLogger::info("Статусы обновлены", [
                'wave_id' => $waveId,
                'success' => $successCount,
                'failed' => $failedCount
            ]);
            error_log("[WaveService::runWaveInBackground] Обновлены статусы: успешно={$successCount}, ошибок={$failedCount}");
            
            WaveLogger::info("Волна успешно запущена, миграции выполняются в фоне", ['wave_id' => $waveId]);
            WaveLogger::endOperation('runWaveInBackground', [
                'wave_id' => $waveId,
                'success_count' => $successCount,
                'failed_count' => $failedCount
            ]);
            error_log("[WaveService::runWaveInBackground] Волна успешно запущена, миграции выполняются в фоне");
            
        } catch (Exception $e) {
            // Принудительно пишем в лог, чтобы убедиться, что ошибка логируется
            $logFile = dirname(__DIR__, 3) . '/var/log/wave_dashboard.log';
            $errorMsg = "[" . date('Y-m-d H:i:s') . "] [ERROR] ❌❌❌ КРИТИЧЕСКАЯ ОШИБКА в runWaveInBackground для wave_id={$waveId}: " . $e->getMessage() . "\n";
            @file_put_contents($logFile, $errorMsg, FILE_APPEND);
            
            WaveLogger::error("ОШИБКА при запуске миграций", [
                'wave_id' => $waveId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            error_log("[WaveService::runWaveInBackground] ОШИБКА при запуске миграций: " . $e->getMessage());
            error_log("[WaveService::runWaveInBackground] Stack trace: " . $e->getTraceAsString());
            
            // Обновляем статус волны на error
            WaveLogger::info("Обновление статуса волны на error", ['wave_id' => $waveId]);
            $dbService->updateWaveProgress($waveId, $wave['progress'] ?? ['total' => 0, 'completed' => 0, 'failed' => 0], $wave['migrations'] ?? [], 'error');
            
            throw $e;
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
        $waves = $this->dbService->getWavesList();
        
        // Добавляем ревьюверов для каждой волны (с обработкой ошибок)
        try {
            $migrationService = new MigrationService();
            foreach ($waves as &$wave) {
                $waveId = $wave['id'] ?? $wave['wave_id'] ?? null;
                if ($waveId) {
                    try {
                        $reviewers = $migrationService->getReviewersByWave($waveId);
                        $wave['reviewers'] = $reviewers;
                        // Формируем строку ревьюверов для отображения
                        if (!empty($reviewers)) {
                            $reviewerNames = array_column($reviewers, 'person_brizy');
                            $wave['reviewers_display'] = implode(', ', array_filter($reviewerNames));
                        } else {
                            $wave['reviewers_display'] = null;
                        }
                    } catch (Exception $e) {
                        // Если ошибка при получении ревьюверов, просто пропускаем их
                        error_log("Ошибка получения ревьюверов для волны {$waveId}: " . $e->getMessage());
                        $wave['reviewers'] = [];
                        $wave['reviewers_display'] = null;
                    }
                } else {
                    $wave['reviewers'] = [];
                    $wave['reviewers_display'] = null;
                }
            }
            unset($wave);
        } catch (Exception $e) {
            // Если критическая ошибка, логируем, но возвращаем волны без ревьюверов
            error_log("Критическая ошибка при получении ревьюверов: " . $e->getMessage());
            foreach ($waves as &$wave) {
                $wave['reviewers'] = [];
                $wave['reviewers_display'] = null;
            }
            unset($wave);
        }
        
        return $waves;
    }

    /**
     * Получить детали волны
     * 
     * @param string $waveId ID волны
     * @return array|null
     * @throws Exception
     */
    public function getWaveDetails(string $waveId): ?array
    {
        $wave = $this->dbService->getWave($waveId);
        
        if (!$wave) {
            return null;
        }

        // КРИТИЧНО: Обновляем статусы миграций на основе lock-файлов и процессов
        $this->updateMigrationStatusesFromMonitoring($waveId);

        $migrations = $this->dbService->getWaveMigrations($waveId);
        
        // Добавляем ревьюверов для волны
        $migrationService = new MigrationService();
        $reviewers = $migrationService->getReviewersByWave($waveId);
        $wave['reviewers'] = $reviewers;
        if (!empty($reviewers)) {
            $reviewerNames = array_column($reviewers, 'person_brizy');
            $wave['reviewers_display'] = implode(', ', $reviewerNames);
        } else {
            $wave['reviewers_display'] = null;
        }
        
        return [
            'wave' => $wave,
            'migrations' => $migrations,
        ];
    }
    
    /**
     * Обновить статусы миграций в волне на основе мониторинга (lock-файлы, процессы)
     * 
     * @param string $waveId ID волны
     * @return void
     */
    private function updateMigrationStatusesFromMonitoring(string $waveId): void
    {
        try {
            $migrations = $this->dbService->getWaveMigrations($waveId);
            if (empty($migrations)) {
                return;
            }
            
            $migrationService = new MigrationService();
            $updatedMigrations = [];
            $hasUpdates = false;
            
            foreach ($migrations as $migration) {
                $mbUuid = $migration['mb_project_uuid'] ?? null;
                $brzProjectId = (int)($migration['brz_project_id'] ?? 0);
                $currentStatus = $migration['status'] ?? 'pending';
                
                if (!$mbUuid) {
                    continue;
                }
                
                // Пропускаем миграции, которые уже завершены или в ошибке
                if ($currentStatus === 'completed' || $currentStatus === 'error') {
                    continue;
                }
                
                // Получаем информацию о процессе через мониторинг
                // ВАЖНО: Если brz_project_id = 0, проверяем все lock-файлы для mb_uuid
                $processInfo = null;
                $lockFileExists = false;
                $processRunning = false;
                $lockFileAge = 999999;
                
                if ($brzProjectId > 0) {
                    // Если brz_project_id известен, используем стандартный мониторинг
                    $processInfo = $migrationService->getMigrationProcessInfo($mbUuid, $brzProjectId);
                    $processRunning = $processInfo['process']['running'] ?? false;
                    $lockFileExists = $processInfo['lock_file_exists'] ?? false;
                    $lockFileAge = $processInfo['process']['lock_file_age'] ?? 999999;
                } else {
                    // Если brz_project_id = 0, ищем lock-файлы по mb_uuid
                    // Проверяем все возможные lock-файлы для этого mb_uuid
                    $projectRoot = dirname(__DIR__, 3);
                    $cachePath = $_ENV['CACHE_PATH'] ?? getenv('CACHE_PATH') ?: $projectRoot . '/var/cache';
                    $lockFilePattern = $cachePath . '/' . $mbUuid . '-*.lock';
                    $lockFiles = glob($lockFilePattern);
                    
                    if (!empty($lockFiles)) {
                        // Найден хотя бы один lock-файл
                        $lockFileExists = true;
                        // Берем самый свежий lock-файл
                        $newestLockFile = null;
                        $newestMtime = 0;
                        foreach ($lockFiles as $lockFile) {
                            $mtime = filemtime($lockFile);
                            if ($mtime > $newestMtime) {
                                $newestMtime = $mtime;
                                $newestLockFile = $lockFile;
                            }
                        }
                        
                        if ($newestLockFile) {
                            $lockFileAge = time() - $newestMtime;
                            
                            // Пытаемся извлечь brz_project_id из имени файла или содержимого
                            if (preg_match('/' . preg_quote($mbUuid, '/') . '-(\d+)\.lock$/', $newestLockFile, $matches)) {
                                $foundBrzProjectId = (int)$matches[1];
                                if ($foundBrzProjectId > 0) {
                                    // Обновляем brz_project_id и проверяем процесс
                                    $brzProjectId = $foundBrzProjectId;
                                    $processInfo = $migrationService->getMigrationProcessInfo($mbUuid, $brzProjectId);
                                    $processRunning = $processInfo['process']['running'] ?? false;
                                }
                            }
                            
                            // Если процесс не найден, проверяем содержимое lock-файла
                            if (!$processRunning) {
                                $lockContent = @file_get_contents($newestLockFile);
                                if ($lockContent) {
                                    $lockData = json_decode($lockContent, true);
                                    if ($lockData && isset($lockData['pid'])) {
                                        $pid = (int)$lockData['pid'];
                                        if ($pid > 0) {
                                            // Проверяем процесс по PID
                                            $command = sprintf('ps -p %d -o pid= 2>/dev/null', $pid);
                                            $psOutput = @shell_exec($command);
                                            $processRunning = !empty(trim($psOutput ?? ''));
                                            
                                            // Если нашли brz_project_id в lock-файле, обновляем
                                            if (isset($lockData['brz_project_id']) && $lockData['brz_project_id'] > 0) {
                                                $brzProjectId = (int)$lockData['brz_project_id'];
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                
                // Определяем новый статус на основе мониторинга
                $newStatus = $currentStatus;
                $error = null;
                
                if ($processRunning) {
                    // Процесс запущен - статус in_progress
                    $newStatus = 'in_progress';
                } elseif ($lockFileExists) {
                    // Lock-файл существует, но процесс не найден
                    // Проверяем возраст lock-файла
                    if ($lockFileAge > 600) {
                        // Lock-файл старый (более 10 минут) - считаем ошибкой
                        $newStatus = 'error';
                        $error = 'Процесс миграции не найден, lock-файл устарел';
                    } else {
                        // Lock-файл свежий - возможно процесс только что запустился
                        $newStatus = 'in_progress';
                    }
                } else {
                    // Lock-файл не существует
                    if ($currentStatus === 'in_progress') {
                        // КРИТИЧНО: Перед установкой статуса error, проверяем логи миграции
                        // Если миграция завершилась успешно, обновляем статус на completed
                        $migrationCompleted = false;
                        
                        try {
                            $migrationCompleted = $migrationService->checkMigrationCompletedFromLogs($brzProjectId);
                        } catch (Exception $e) {
                            // Игнорируем ошибки проверки логов
                            WaveLogger::warning("Ошибка проверки логов миграции", [
                                'wave_id' => $waveId,
                                'mb_uuid' => $mbUuid,
                                'brz_project_id' => $brzProjectId,
                                'error' => $e->getMessage()
                            ]);
                        }
                        
                        if ($migrationCompleted) {
                            // Миграция завершилась успешно
                            $newStatus = 'completed';
                            $error = null;
                        } else {
                            // Миграция не завершилась или завершилась с ошибкой
                            $newStatus = 'error';
                            $error = 'Lock-файл не найден, процесс миграции не запущен';
                        }
                    }
                    // Если статус pending, оставляем как есть
                }
                
                // Обновляем статус, если он изменился, или если нашли brz_project_id
                $foundBrzProjectId = ($brzProjectId > 0 && $brzProjectId !== (int)($migration['brz_project_id'] ?? 0));
                if ($newStatus !== $currentStatus || $foundBrzProjectId) {
                    $updatedMigrations[] = [
                        'mb_project_uuid' => $mbUuid,
                        'brz_project_id' => $brzProjectId, // Обновляем brz_project_id, даже если он был найден из lock-файла
                        'status' => $newStatus,
                        'error' => $error
                    ];
                    $hasUpdates = true;
                    
                    WaveLogger::info("Обновление статуса миграции на основе мониторинга", [
                        'wave_id' => $waveId,
                        'mb_uuid' => $mbUuid,
                        'brz_project_id' => $brzProjectId,
                        'old_status' => $currentStatus,
                        'new_status' => $newStatus,
                        'error' => $error,
                        'process_running' => $processRunning,
                        'lock_file_exists' => $lockFileExists,
                        'lock_file_age' => $lockFileAge
                    ]);
                    
                    // Если нашли brz_project_id из lock-файла, обновляем его
                    if ($brzProjectId > 0 && $brzProjectId !== (int)($migration['brz_project_id'] ?? 0)) {
                        WaveLogger::info("Обнаружен brz_project_id из lock-файла", [
                            'wave_id' => $waveId,
                            'mb_uuid' => $mbUuid,
                            'old_brz_project_id' => $migration['brz_project_id'] ?? 0,
                            'new_brz_project_id' => $brzProjectId
                        ]);
                    }
                }
            }
            
            // Обновляем статусы в БД, если есть изменения
            if ($hasUpdates) {
                $wave = $this->dbService->getWave($waveId);
                $waveMigrations = $wave['migrations'] ?? [];
                
                // Обновляем статусы в массиве миграций
                foreach ($updatedMigrations as $updated) {
                    $mbUuid = $updated['mb_project_uuid'];
                    $migrationIndex = array_search($mbUuid, array_column($waveMigrations, 'mb_project_uuid'));
                    
                    if ($migrationIndex !== false) {
                        $waveMigrations[$migrationIndex]['status'] = $updated['status'];
                        // Обновляем brz_project_id, если он был найден
                        if ($updated['brz_project_id'] > 0) {
                            $waveMigrations[$migrationIndex]['brz_project_id'] = $updated['brz_project_id'];
                        }
                        if ($updated['error']) {
                            $waveMigrations[$migrationIndex]['error'] = $updated['error'];
                        }
                    } else {
                        // Добавляем новую миграцию, если её нет
                        $waveMigrations[] = [
                            'mb_project_uuid' => $mbUuid,
                            'brz_project_id' => $updated['brz_project_id'],
                            'status' => $updated['status'],
                            'error' => $updated['error']
                        ];
                    }
                }
                
                // Обновляем прогресс волны
                $progress = $wave['progress'] ?? ['total' => count($waveMigrations), 'completed' => 0, 'failed' => 0];
                $completed = 0;
                $failed = 0;
                
                foreach ($waveMigrations as $migration) {
                    $status = $migration['status'] ?? 'pending';
                    if ($status === 'completed') {
                        $completed++;
                    } elseif ($status === 'error') {
                        $failed++;
                    }
                }
                
                $progress['completed'] = $completed;
                $progress['failed'] = $failed;
                
                // Определяем общий статус волны
                $totalProcessed = $completed + $failed;
                $waveStatus = 'in_progress';
                if ($totalProcessed === $progress['total']) {
                    $waveStatus = ($failed > 0) ? 'error' : 'completed';
                }
                
                // Обновляем в БД
                $this->dbService->updateWaveProgress($waveId, $progress, $waveMigrations, $waveStatus);
                
                WaveLogger::info("Статусы миграций обновлены на основе мониторинга", [
                    'wave_id' => $waveId,
                    'updated_count' => count($updatedMigrations),
                    'progress' => $progress,
                    'wave_status' => $waveStatus
                ]);
            }
        } catch (Exception $e) {
            // Логируем ошибку, но не прерываем выполнение
            $logFile = dirname(__DIR__, 3) . '/var/log/wave_dashboard.log';
            $errorMsg = "[" . date('Y-m-d H:i:s') . "] [ERROR] ❌ ОШИБКА обновления статусов на основе мониторинга: wave_id={$waveId}, error=" . $e->getMessage() . "\n";
            @file_put_contents($logFile, $errorMsg, FILE_APPEND);
            WaveLogger::error("Ошибка обновления статусов на основе мониторинга", [
                'wave_id' => $waveId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Перезапустить миграцию в волне
     * 
     * @param string $waveId ID волны
     * @param string $mbUuid UUID проекта MB
     * @param array $params Дополнительные параметры
     * @return array
     * @throws Exception
     */
    public function restartMigrationInWave(string $waveId, string $mbUuid, array $params = []): array
    {
        $wave = $this->dbService->getWave($waveId);
        
        if (!$wave) {
            throw new Exception('Волна не найдена');
        }

        $workspaceId = $wave['workspace_id'];
        
        // Получаем миграцию из migration_result_list
        $migrations = $this->dbService->getWaveMigrations($waveId);
        $migration = null;
        foreach ($migrations as $m) {
            if ($m['mb_project_uuid'] === $mbUuid) {
                $migration = $m;
                break;
            }
        }

        if (!$migration) {
            throw new Exception('Миграция не найдена в волне');
        }

        // Если brz_project_id = 0, нужно создать проект в workspace
        $brzProjectId = $migration['brz_project_id'] ?? 0;
        
        if ($brzProjectId == 0) {
            // Инициализируем Config и Logger если нужно
            if (empty(\MBMigration\Core\Config::$mainToken)) {
                $this->initializeConfig();
            }
            if (!\MBMigration\Core\Logger::isInitialized()) {
                $projectRoot = dirname(__DIR__, 3);
                // Нормализуем путь, чтобы избежать двойных слешей
                $projectRoot = rtrim($projectRoot, '/');
                if (empty($projectRoot) || $projectRoot === '/') {
                    $projectRoot = __DIR__ . '/../../..';
                    $projectRoot = realpath($projectRoot) ?: dirname(__DIR__, 3);
                    $projectRoot = rtrim($projectRoot, '/');
                }
                $logDir = $projectRoot . '/var/log';
                $logPath = $logDir . '/wave_' . $waveId . '.log';
                // Создаем директорию с правильными правами
                if (!is_dir($logDir)) {
                    @mkdir($logDir, 0777, true);
                }
                // Убеждаемся, что директория доступна для записи
                if (!is_writable($logDir)) {
                    @chmod($logDir, 0777);
                }
                \MBMigration\Core\Logger::initialize(
                    'WaveService',
                    \Monolog\Logger::DEBUG,
                    $logPath
                );
            }
            
            // Создаем проект в workspace
            $brizyApi = $this->getBrizyApiService();
            $projectName = 'Project_' . $mbUuid;
            $brzProjectId = $brizyApi->createProject($projectName, $workspaceId, 'id');
            
            if (!$brzProjectId) {
                throw new Exception('Не удалось создать проект в workspace');
            }
            
            // Обновляем запись в migration_result_list с новым brz_project_id (но еще не in_progress)
            $this->dbService->updateMigrationResult($waveId, $mbUuid, [
                'brz_project_id' => $brzProjectId,
                'result_json' => [
                    'status' => 'pending',
                    'message' => 'Проект создан, подготовка к миграции'
                ]
            ]);
        }

        // Загружаем настройки по умолчанию
        $settings = $this->dbService->getSettings();
        $mbSiteId = $params['mb_site_id'] ?? $settings['mb_site_id'] ?? null;
        $mbSecret = $params['mb_secret'] ?? $settings['mb_secret'] ?? null;

        if (empty($mbSiteId) || empty($mbSecret)) {
            throw new Exception('mb_site_id и mb_secret должны быть указаны либо в запросе, либо в настройках');
        }

        // Формируем контекст для ApplicationBootstrapper
        $context = $this->buildApplicationContext();
        
        // Выполняем миграцию синхронно (для перезапуска)
        $request = \Symfony\Component\HttpFoundation\Request::create('/', 'GET', [
            'mb_site_id' => $mbSiteId,
            'mb_secret' => $mbSecret
        ]);
        $app = new \MBMigration\ApplicationBootstrapper($context, $request);

        try {
            // Инициализируем Config перед выполнением миграции
            $app->doInnitConfig();
            
            // Обновляем статус на in_progress только когда миграция реально начинается
            $this->dbService->updateMigrationResult($waveId, $mbUuid, [
                'result_json' => [
                    'status' => 'in_progress',
                    'message' => 'Миграция запущена',
                    'started_at' => date('Y-m-d H:i:s')
                ]
            ]);

            // Сохраняем запись о начале миграции в таблицу migrations
            try {
                $this->dbService->saveMigration([
                    'migration_uuid' => $waveId,
                    'brz_project_id' => $brzProjectId > 0 ? $brzProjectId : null,
                    'mb_project_uuid' => $mbUuid,
                    'status' => 'in_progress',
                    'wave_id' => $waveId,
                    'started_at' => date('Y-m-d H:i:s'),
                    'result_json' => json_encode(['status' => 'in_progress', 'message' => 'Миграция запущена'])
                ]);
            } catch (Exception $saveError) {
                error_log("Ошибка сохранения начала миграции в таблицу migrations: " . $saveError->getMessage());
            }
            
            $result = $app->migrationFlow(
                $mbUuid,
                $brzProjectId,
                $workspaceId,
                '',
                false,
                $wave['mgr_manual']
            );

            // Сохраняем данные о страницах в page_quality_analysis даже без анализа качества
            // Это нужно для отображения страниц во вкладке "Страницы"
            try {
                $pageList = $app->getPageList();
                if (!empty($pageList) && isset($result['brizy_project_id'])) {
                    $qualityReport = new \MBMigration\Analysis\QualityReport();
                    $mbProjectDomain = $result['mb_project_domain'] ?? null;
                    $brizyProjectDomain = $result['brizy_project_domain'] ?? null;
                    
                    foreach ($pageList as $page) {
                        $pageSlug = $page['slug'] ?? null;
                        if (empty($pageSlug)) {
                            continue;
                        }
                        
                        // Формируем URLs для страницы
                        $sourceUrl = null;
                        $migratedUrl = null;
                        
                        if ($mbProjectDomain) {
                            $sourceUrl = rtrim($mbProjectDomain, '/') . '/' . ltrim($pageSlug, '/');
                        }
                        
                        if ($brizyProjectDomain) {
                            $migratedUrl = rtrim($brizyProjectDomain, '/') . '/' . ltrim($pageSlug, '/');
                        }
                        
                        // Сохраняем базовую запись о странице без анализа качества
                        $qualityReport->saveReport([
                            'migration_id' => (int)$result['brizy_project_id'],
                            'mb_project_uuid' => $mbUuid,
                            'page_slug' => $pageSlug,
                            'source_url' => $sourceUrl,
                            'migrated_url' => $migratedUrl,
                            'analysis_status' => 'pending', // Статус "pending" означает, что анализ не был выполнен
                            'quality_score' => null,
                            'severity_level' => 'none',
                            'issues_summary' => [],
                            'detailed_report' => [],
                            'screenshots_path' => json_encode([])
                        ]);
                    }
                }
            } catch (\Exception $e) {
                // Логируем ошибку, но не прерываем выполнение миграции
                error_log("Ошибка сохранения данных о страницах: " . $e->getMessage());
            }

            // Обновляем статус миграции в волне
            $migrations = $wave['migrations'];
            $migrationIndex = array_search($mbUuid, array_column($migrations, 'mb_project_uuid'));
            
            if ($migrationIndex !== false) {
                $migrations[$migrationIndex]['status'] = 'completed';
                $migrations[$migrationIndex]['brizy_project_domain'] = $result['brizy_project_domain'] ?? null;
                $migrations[$migrationIndex]['completed_at'] = date('Y-m-d H:i:s');
                unset($migrations[$migrationIndex]['error']);
            }

            $progress = $wave['progress'];
            if ($migration['status'] === 'error') {
                $progress['failed'] = max(0, $progress['failed'] - 1);
            }
            if ($migration['status'] !== 'completed') {
                $progress['completed']++;
            }

            $this->dbService->updateWaveProgress($waveId, $progress, $migrations);

            // Сохраняем результат в новую таблицу migrations
            $finalBrzProjectId = $result['brizy_project_id'] ?? $brzProjectId;
            try {
                $this->dbService->saveMigration([
                    'migration_uuid' => $waveId,
                    'brz_project_id' => $finalBrzProjectId,
                    'brizy_project_domain' => $result['brizy_project_domain'] ?? null,
                    'mb_project_uuid' => $mbUuid,
                    'mb_project_domain' => $result['mb_project_domain'] ?? null,
                    'status' => 'completed',
                    'mb_site_id' => $result['mb_site_id'] ?? null,
                    'mb_product_name' => $result['mb_product_name'] ?? null,
                    'theme' => $result['theme'] ?? null,
                    'migration_id' => $result['migration_id'] ?? null,
                    'date' => $result['date'] ?? date('Y-m-d'),
                    'wave_id' => $waveId,
                    'result_json' => json_encode($result),
                    'completed_at' => date('Y-m-d H:i:s')
                ]);
            } catch (Exception $e) {
                error_log("Ошибка сохранения миграции в новую таблицу: " . $e->getMessage());
            }

            // Сохраняем в migrations_mapping ТОЛЬКО для волн (это специальная таблица для маппинга волн)
            // brz_project_id - это ID проекта бризи (мигрированный проект)
            // mb_project_uuid - это UUID проекта MB (исходный проект)
            $this->dbService->upsertMigrationMapping($finalBrzProjectId, $mbUuid, [
                'status' => 'completed',
                'brizy_project_domain' => $result['brizy_project_domain'] ?? null,
                'brizy_project_id' => $finalBrzProjectId,
                'completed_at' => date('Y-m-d H:i:s'),
            ]);

            // Автоматически включаем cloning_enabled при завершении миграции, если это указано в волне
            try {
                $wave = $this->dbService->getWave($waveId);
                $enableCloning = $wave['enable_cloning'] ?? false;
                
                if ($enableCloning && $finalBrzProjectId > 0) {
                    WaveLogger::info("Автоматическое включение cloning_enabled для проекта", [
                        'wave_id' => $waveId,
                        'brz_project_id' => $finalBrzProjectId,
                        'mb_uuid' => $mbUuid
                    ]);
                    
                    $this->updateCloningEnabled($finalBrzProjectId, true);
                }
            } catch (Exception $e) {
                WaveLogger::warning("Ошибка при автоматическом включении cloning_enabled", [
                    'wave_id' => $waveId,
                    'brz_project_id' => $finalBrzProjectId,
                    'error' => $e->getMessage()
                ]);
            }

            // Обновляем запись в migration_result_list с результатами миграции (для обратной совместимости)
            $this->dbService->updateMigrationResult($waveId, $mbUuid, [
                'brz_project_id' => $finalBrzProjectId,
                'brizy_project_domain' => $result['brizy_project_domain'] ?? '',
                'result_json' => [
                    'value' => $result,
                    'status' => 'completed'
                ]
            ]);

            return [
                'success' => true,
                'data' => $result,
            ];
        } catch (Exception $e) {
            // Сохраняем ошибку в таблицу migrations
            try {
                $this->dbService->saveMigration([
                    'migration_uuid' => $waveId,
                    'brz_project_id' => $brzProjectId > 0 ? $brzProjectId : null,
                    'mb_project_uuid' => $mbUuid,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'wave_id' => $waveId,
                    'result_json' => json_encode(['error' => $e->getMessage(), 'status' => 'error']),
                    'completed_at' => date('Y-m-d H:i:s')
                ]);
            } catch (Exception $saveError) {
                error_log("Ошибка сохранения миграции с ошибкой в таблицу migrations: " . $saveError->getMessage());
            }

            // Обновляем статус на error в migration_result_list
            $this->dbService->updateMigrationResult($waveId, $mbUuid, [
                'result_json' => [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'message' => 'Ошибка при выполнении миграции'
                ]
            ]);

            // Обновляем статус миграции в волне
            $migrations = $wave['migrations'];
            $migrationIndex = array_search($mbUuid, array_column($migrations, 'mb_project_uuid'));
            
            if ($migrationIndex !== false) {
                $migrations[$migrationIndex]['status'] = 'error';
                $migrations[$migrationIndex]['error'] = $e->getMessage();
            }

            $progress = $wave['progress'];
            if ($migration['status'] === 'completed') {
                $progress['completed'] = max(0, $progress['completed'] - 1);
            }
            $progress['failed']++;

            $this->dbService->updateWaveProgress($waveId, $progress, $migrations);

            throw $e;
        }
    }

    /**
     * Получить маппинг проектов для волны
     * 
     * @param string $waveId ID волны
     * @return array
     * @throws Exception
     */
    public function getWaveMapping(string $waveId): array
    {
        $wave = $this->dbService->getWave($waveId);
        
        if (!$wave) {
            throw new Exception('Волна не найдена');
        }

        return $this->dbService->getWaveMapping($waveId);
    }

    /**
     * Обновить параметр cloning_enabled для проекта
     * 
     * @param int $brzProjectId ID проекта Brizy
     * @param bool $cloningEnabled Включено ли клонирование
     * @return array
     * @throws Exception
     */
    public function updateCloningEnabled(int $brzProjectId, bool $cloningEnabled): array
    {
        // Обновляем в БД
        $this->dbService->updateCloningEnabled($brzProjectId, $cloningEnabled);
        
        // Обновляем в Brizy API
        $brizyApi = $this->getBrizyApiService();
        $brizyApi->setCloningLink($brzProjectId, $cloningEnabled);
        
        return [
            'success' => true,
            'brz_project_id' => $brzProjectId,
            'cloning_enabled' => $cloningEnabled
        ];
    }

    /**
     * Массовый перезапуск миграций в волне
     * Очищает кэш, lock-файлы и БД записи, затем перезапускает миграции
     * 
     * @param string $waveId ID волны
     * @param array $mbUuids Массив UUID проектов для перезапуска (если пустой - все миграции)
     * @param array $params Дополнительные параметры (mb_site_id, mb_secret и т.д.)
     * @return array
     * @throws Exception
     */
    public function restartAllMigrationsInWave(string $waveId, array $mbUuids = [], array $params = []): array
    {
        error_log("[WaveService::restartAllMigrationsInWave] Начало массового перезапуска: waveId={$waveId}, mbUuids=" . count($mbUuids) . ", params=" . json_encode($params));
        
        $wave = $this->dbService->getWave($waveId);
        
        if (!$wave) {
            $errorMsg = "Волна не найдена: {$waveId}";
            error_log("[WaveService::restartAllMigrationsInWave] ОШИБКА: {$errorMsg}");
            throw new Exception($errorMsg);
        }

        error_log("[WaveService::restartAllMigrationsInWave] Волна найдена: name=" . ($wave['name'] ?? 'N/A') . ", workspace_id=" . ($wave['workspace_id'] ?? 'N/A'));

        // Получаем workspace_id из волны
        $workspaceId = $wave['workspace_id'] ?? null;
        if (!$workspaceId) {
            $errorMsg = "Workspace ID не найден в волне: {$waveId}";
            error_log("[WaveService::restartAllMigrationsInWave] ОШИБКА: {$errorMsg}");
            throw new Exception($errorMsg);
        }

        error_log("[WaveService::restartAllMigrationsInWave] Workspace ID: {$workspaceId}");

        // Получаем все миграции волны
        $migrations = $this->dbService->getWaveMigrations($waveId);
        error_log("[WaveService::restartAllMigrationsInWave] Найдено миграций в волне: " . count($migrations));
        
        // Фильтруем по переданным UUID, если указаны
        if (!empty($mbUuids)) {
            $migrations = array_filter($migrations, function($m) use ($mbUuids) {
                return in_array($m['mb_project_uuid'], $mbUuids);
            });
            error_log("[WaveService::restartAllMigrationsInWave] После фильтрации по UUID: " . count($migrations) . " миграций");
        }

        if (empty($migrations)) {
            $errorMsg = "Не найдено миграций для перезапуска";
            error_log("[WaveService::restartAllMigrationsInWave] ОШИБКА: {$errorMsg}");
            throw new Exception($errorMsg);
        }

        $migrationService = new \Dashboard\Services\MigrationService();
        $results = [
            'total' => count($migrations),
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'details' => []
        ];

        // Загружаем настройки по умолчанию
        $settings = $this->dbService->getSettings();
        $mbSiteId = $params['mb_site_id'] ?? $settings['mb_site_id'] ?? null;
        $mbSecret = $params['mb_secret'] ?? $settings['mb_secret'] ?? null;

        if (empty($mbSiteId) || empty($mbSecret)) {
            throw new Exception('mb_site_id и mb_secret должны быть указаны либо в запросе, либо в настройках');
        }

        // Обновляем статус волны на in_progress
        error_log("[WaveService::restartAllMigrationsInWave] Обновление статуса волны на in_progress...");
        try {
            $waveProgress = $wave['progress'] ?? ['total' => count($migrations), 'completed' => 0, 'failed' => 0];
            $waveMigrations = $wave['migrations'] ?? [];
            $this->dbService->updateWaveProgress($waveId, $waveProgress, $waveMigrations, 'in_progress');
            error_log("[WaveService::restartAllMigrationsInWave] Статус волны обновлен на in_progress");
        } catch (Exception $e) {
            error_log("[WaveService::restartAllMigrationsInWave] ОШИБКА при обновлении статуса волны: " . $e->getMessage());
        }

        // Очищаем кэш, lock-файлы и сбрасываем статус для каждой миграции (быстро, без запуска миграций)
        error_log("[WaveService::restartAllMigrationsInWave] Начало обработки " . count($migrations) . " миграций...");
        foreach ($migrations as $index => $migration) {
            $mbUuid = $migration['mb_project_uuid'];
            $brzProjectId = $migration['brz_project_id'] ?? 0;
            
            error_log("[WaveService::restartAllMigrationsInWave] Обработка миграции " . ($index + 1) . "/" . count($migrations) . ": mbUuid={$mbUuid}, brzProjectId={$brzProjectId}");
            
            $detail = [
                'mb_uuid' => $mbUuid,
                'brz_project_id' => $brzProjectId,
                'cache_cleared' => false,
                'lock_removed' => false,
                'status_reset' => false,
                'restarted' => false,
                'error' => null
            ];

            try {
                // Если проект уже создан, очищаем кэш и lock-файлы
                if ($brzProjectId > 0) {
                    // 1. Удаляем lock-файл
                    try {
                        $lockResult = $migrationService->removeMigrationLock($mbUuid, $brzProjectId);
                        if ($lockResult['success']) {
                            $detail['lock_removed'] = $lockResult['removed'] ?? false;
                        }
                    } catch (Exception $e) {
                        $detail['error'] = 'Ошибка удаления lock-файла: ' . $e->getMessage();
                    }

                    // 2. Удаляем кэш-файл
                    try {
                        $cacheResult = $migrationService->removeMigrationCache($mbUuid, $brzProjectId);
                        if ($cacheResult['success']) {
                            $detail['cache_cleared'] = $cacheResult['removed'] ?? false;
                        }
                    } catch (Exception $e) {
                        if ($detail['error']) {
                            $detail['error'] .= '; Ошибка удаления кэша: ' . $e->getMessage();
                        } else {
                            $detail['error'] = 'Ошибка удаления кэша: ' . $e->getMessage();
                        }
                    }

                    // 3. Сбрасываем статус в БД
                    try {
                        $statusResult = $migrationService->resetMigrationStatus($mbUuid, $brzProjectId);
                        if ($statusResult['success']) {
                            $detail['status_reset'] = true;
                        }
                    } catch (Exception $e) {
                        if ($detail['error']) {
                            $detail['error'] .= '; Ошибка сброса статуса: ' . $e->getMessage();
                        } else {
                            $detail['error'] = 'Ошибка сброса статуса: ' . $e->getMessage();
                        }
                    }
                }

                // 4. Сбрасываем статус в migration_result_list на pending
                try {
                    $this->dbService->updateMigrationResult($waveId, $mbUuid, [
                        'result_json' => [
                            'status' => 'pending',
                            'message' => 'Подготовка к перезапуску миграции'
                        ]
                    ]);
                } catch (Exception $e) {
                    error_log("Ошибка обновления migration_result_list для $mbUuid: " . $e->getMessage());
                }

                // 5. Запускаем миграцию в фоне через отдельный процесс
                error_log("[WaveService::restartAllMigrationsInWave] Запуск миграции в фоне: mbUuid={$mbUuid}, brzProjectId={$brzProjectId}");
                try {
                    $this->startMigrationInBackground($waveId, $mbUuid, $brzProjectId, $workspaceId, $mbSiteId, $mbSecret, $params);
                    error_log("[WaveService::restartAllMigrationsInWave] Миграция успешно запущена в фоне: mbUuid={$mbUuid}");
                    $detail['restarted'] = true;
                    $results['success']++;
                } catch (Exception $startError) {
                    error_log("[WaveService::restartAllMigrationsInWave] ОШИБКА при запуске миграции в фоне: mbUuid={$mbUuid}, error=" . $startError->getMessage());
                    $detail['error'] = ($detail['error'] ? $detail['error'] . '; ' : '') . 'Ошибка запуска миграции: ' . $startError->getMessage();
                    $results['failed']++;
                }

            } catch (Exception $e) {
                error_log("[WaveService::restartAllMigrationsInWave] ОШИБКА при обработке миграции mbUuid={$mbUuid}: " . $e->getMessage());
                error_log("[WaveService::restartAllMigrationsInWave] Stack trace: " . $e->getTraceAsString());
                $detail['error'] = $e->getMessage();
                $results['failed']++;
                // Обновляем статус на error при ошибке
                try {
                    $this->dbService->updateMigrationResult($waveId, $mbUuid, [
                        'result_json' => [
                            'status' => 'error',
                            'error' => $e->getMessage(),
                            'message' => 'Ошибка при подготовке перезапуска миграции'
                        ]
                    ]);
                } catch (Exception $updateError) {
                    error_log("Ошибка обновления статуса на error для $mbUuid: " . $updateError->getMessage());
                }
            }

            $results['processed']++;
            $results['details'][] = $detail;
        }

        error_log("[WaveService::restartAllMigrationsInWave] Массовый перезапуск завершен: total=" . $results['total'] . ", success=" . $results['success'] . ", failed=" . $results['failed'] . ", processed=" . $results['processed']);
        
        return [
            'success' => $results['failed'] === 0,
            'message' => sprintf(
                'Обработано: %d из %d. Успешно: %d, Ошибок: %d',
                $results['processed'],
                $results['total'],
                $results['success'],
                $results['failed']
            ),
            'results' => $results
        ];
    }

    /**
     * Получить экземпляр BrizyApiService
     * 
     * @return BrizyApiService
     * @throws Exception
     */
    private function getBrizyApiService(): BrizyApiService
    {
        // Убеждаемся, что классы загружены
        if (!class_exists(BrizyConfig::class)) {
            require_once __DIR__ . '/../Core/BrizyConfig.php';
        }
        if (!class_exists(BrizyApiService::class)) {
            require_once __DIR__ . '/BrizyApiService.php';
        }
        
        $config = new BrizyConfig();
        $config->validate();
        
        return new BrizyApiService(
            $config->getApiToken(),
            $config->getBaseUrl()
        );
    }

    /**
     * Инициализировать Config для работы с BrizyAPI
     * Загружает настройки из переменных окружения
     * 
     * @return void
     * @throws Exception
     */
    private function initializeConfig(): void
    {
        $projectRoot = dirname(__DIR__, 3);
        
        // Загружаем переменные окружения
        if (file_exists($projectRoot . '/.env')) {
            $dotenv = \Dotenv\Dotenv::createMutable($projectRoot);
            $dotenv->safeLoad();
        }
        
        $prodEnv = $projectRoot . '/.env.prod.local';
        if (file_exists($prodEnv)) {
            $dotenv = \Dotenv\Dotenv::createMutable($projectRoot, ['.env.prod.local']);
            $dotenv->safeLoad();
        }
        
        // Получаем настройки из переменных окружения
        // Поддерживаем оба варианта переменных окружения для обратной совместимости
        $brizyCloudHost = $_ENV['BRIZY_HOST'] ?? getenv('BRIZY_HOST') 
            ?? $_ENV['BRIZY_CLOUD_HOST'] ?? getenv('BRIZY_CLOUD_HOST') 
            ?? 'https://admin.brizy.io';
        $brizyCloudToken = $_ENV['BRIZY_CLOUD_TOKEN'] ?? getenv('BRIZY_CLOUD_TOKEN');
        $logPath = $_ENV['LOG_PATH'] ?? getenv('LOG_PATH') ?: $projectRoot . '/var/log';
        $cachePath = $_ENV['CACHE_PATH'] ?? getenv('CACHE_PATH') ?: $projectRoot . '/var/cache';
        
        if (empty($brizyCloudToken)) {
            throw new Exception('BRIZY_CLOUD_TOKEN не установлен в переменных окружения');
        }
        
        // Получаем настройки БД из переменных окружения
        $mbDbHost = $_ENV['MB_DB_HOST'] ?? getenv('MB_DB_HOST') ?: 'localhost';
        $mbDbPort = $_ENV['MB_DB_PORT'] ?? getenv('MB_DB_PORT') ?: '3306';
        $mbDbName = $_ENV['MB_DB_NAME'] ?? getenv('MB_DB_NAME') ?: '';
        $mbDbUser = $_ENV['MB_DB_USER'] ?? getenv('MB_DB_USER') ?: '';
        $mbDbPass = $_ENV['MB_DB_PASSWORD'] ?? getenv('MB_DB_PASSWORD') ?: '';
        
        $mgDbHost = $_ENV['MG_DB_HOST'] ?? getenv('MG_DB_HOST') ?: $mbDbHost;
        $mgDbPort = $_ENV['MG_DB_PORT'] ?? getenv('MG_DB_PORT') ?: $mbDbPort;
        $mgDbName = $_ENV['MG_DB_NAME'] ?? getenv('MG_DB_NAME') ?: '';
        $mgDbUser = $_ENV['MG_DB_USER'] ?? getenv('MG_DB_USER') ?: '';
        $mgDbPass = $_ENV['MG_DB_PASS'] ?? getenv('MG_DB_PASS') ?: '';
        
        $mbMediaHost = $_ENV['MB_MEDIA_HOST'] ?? getenv('MB_MEDIA_HOST') ?: '';
        $mbPreviewHost = $_ENV['MB_PREVIEW_HOST'] ?? getenv('MB_PREVIEW_HOST') ?: 'staging.cloversites.com';
        
        // Создаем настройки для Config
        $settings = [
            'devMode' => (bool)($_ENV['DEV_MODE'] ?? getenv('DEV_MODE') ?? false),
            'mgrMode' => (bool)($_ENV['MGR_MODE'] ?? getenv('MGR_MODE') ?? false),
            'db' => [
                'dbHost' => $mbDbHost,
                'dbPort' => $mbDbPort,
                'dbName' => $mbDbName,
                'dbUser' => $mbDbUser,
                'dbPass' => $mbDbPass,
            ],
            'db_mg' => [
                'dbHost' => $mgDbHost,
                'dbPort' => $mgDbPort,
                'dbName' => $mgDbName,
                'dbUser' => $mgDbUser,
                'dbPass' => $mgDbPass,
            ],
            'assets' => [
                'MBMediaStaging' => $mbMediaHost,
            ],
            'previewBaseHost' => $mbPreviewHost,
        ];
        
        // Инициализируем Config
        @mkdir($logPath, 0755, true);
        @mkdir($cachePath, 0755, true);
        
        new Config(
            $brizyCloudHost,
            $logPath,
            $cachePath,
            $brizyCloudToken,
            $settings
        );
    }
    
    /**
     * Создать или получить проект в workspace для миграции
     * 
     * @param string $mbUuid UUID проекта MB
     * @param int $workspaceId ID workspace
     * @param string $waveId ID волны (для логирования)
     * @return int ID созданного или найденного проекта Brizy
     * @throws Exception
     */
    private function createOrGetProject(string $mbUuid, int $workspaceId, string $waveId): int
    {
        WaveLogger::startOperation('WaveService::createOrGetProject', [
            'mb_uuid' => $mbUuid,
            'workspace_id' => $workspaceId,
            'wave_id' => $waveId
        ]);
        
        try {
            // Инициализируем Config и Logger, если нужно
            if (!Logger::isInitialized()) {
                $projectRoot = dirname(__DIR__, 3);
                // Нормализуем путь, чтобы избежать двойных слешей
                $projectRoot = rtrim($projectRoot, '/');
                if (empty($projectRoot) || $projectRoot === '/') {
                    $projectRoot = __DIR__ . '/../../..';
                    $projectRoot = realpath($projectRoot) ?: dirname(__DIR__, 3);
                    $projectRoot = rtrim($projectRoot, '/');
                }
                $logDir = $projectRoot . '/var/log';
                $logPath = $logDir . '/wave_dashboard.log';
                // Создаем директорию с правильными правами
                if (!is_dir($logDir)) {
                    @mkdir($logDir, 0777, true);
                }
                // Убеждаемся, что директория доступна для записи
                if (!is_writable($logDir)) {
                    @chmod($logDir, 0777);
                }
                Logger::initialize(
                    'WaveService',
                    \Monolog\Logger::DEBUG,
                    $logPath
                );
            }
            
            $brizyApi = $this->getBrizyApiService();
            
            // Получаем домен проекта MB для имени проекта
            // Используем UUID как имя проекта, если домен недоступен
            $projectName = $mbUuid; // По умолчанию используем UUID
            
            try {
                // Пытаемся получить домен из MB API (если доступен)
                // Пока используем UUID, можно улучшить позже
            } catch (Exception $e) {
                // Игнорируем ошибки получения домена
            }
            
            // Проверяем, существует ли уже проект с таким именем в workspace
            $existingProjectId = $brizyApi->getProject($workspaceId, $projectName);
            
            if ($existingProjectId) {
                WaveLogger::info("Проект уже существует в workspace", [
                    'wave_id' => $waveId,
                    'mb_uuid' => $mbUuid,
                    'brz_project_id' => $existingProjectId,
                    'workspace_id' => $workspaceId,
                    'project_name' => $projectName
                ]);
                
                WaveLogger::endOperation('WaveService::createOrGetProject', [
                    'success' => true,
                    'brz_project_id' => $existingProjectId,
                    'created' => false
                ]);
                
                return (int)$existingProjectId;
            }
            
            // Создаем новый проект
            WaveLogger::info("Создание нового проекта в workspace", [
                'wave_id' => $waveId,
                'mb_uuid' => $mbUuid,
                'workspace_id' => $workspaceId,
                'project_name' => $projectName
            ]);
            
            $createResult = $brizyApi->createProject($projectName, $workspaceId, 'id');
            
            if (empty($createResult)) {
                throw new Exception('Пустой ответ от API при создании проекта');
            }
            
            // Проверяем статус ответа
            if (is_array($createResult) && isset($createResult['status']) && 
                ($createResult['status'] === false || $createResult['status'] >= 400)) {
                $errorMsg = 'Ошибка создания проекта: ';
                if (isset($createResult['body'])) {
                    $errorBody = json_decode($createResult['body'], true);
                    if (is_array($errorBody)) {
                        $errorMsg .= $errorBody['message'] ?? $errorBody['error'] ?? json_encode($errorBody);
                    } else {
                        $errorMsg .= $createResult['body'];
                    }
                } else {
                    $errorMsg .= 'HTTP ' . ($createResult['status'] === false ? 'Connection failed' : $createResult['status']);
                }
                throw new Exception($errorMsg);
            }
            
            // Парсим ответ
            $projectId = null;
            if (is_numeric($createResult)) {
                $projectId = (int)$createResult;
            } elseif (is_array($createResult) && isset($createResult['id'])) {
                $projectId = (int)$createResult['id'];
            } elseif (isset($createResult['body'])) {
                $bodyData = json_decode($createResult['body'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    if (is_numeric($bodyData)) {
                        $projectId = (int)$bodyData;
                    } elseif (isset($bodyData['id'])) {
                        $projectId = (int)$bodyData['id'];
                    }
                } elseif (is_numeric($createResult['body'])) {
                    $projectId = (int)$createResult['body'];
                }
            }
            
            if (!$projectId || $projectId <= 0) {
                // Пытаемся найти созданный проект
                sleep(1);
                $projectId = $brizyApi->getProject($workspaceId, $projectName);
                if (!$projectId) {
                    throw new Exception('Проект создан, но ID не получен. Попробуйте еще раз.');
                }
            }
            
            WaveLogger::info("Проект успешно создан", [
                'wave_id' => $waveId,
                'mb_uuid' => $mbUuid,
                'brz_project_id' => $projectId,
                'workspace_id' => $workspaceId,
                'project_name' => $projectName
            ]);
            
            WaveLogger::endOperation('WaveService::createOrGetProject', [
                'success' => true,
                'brz_project_id' => $projectId,
                'created' => true
            ]);
            
            return $projectId;
            
        } catch (Exception $e) {
            WaveLogger::error("Ошибка создания/получения проекта", [
                'wave_id' => $waveId,
                'mb_uuid' => $mbUuid,
                'workspace_id' => $workspaceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            WaveLogger::endOperation('WaveService::createOrGetProject', [
                'success' => false,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Получить логи миграции из файла
     * 
     * @param string $waveId ID волны
     * @param string $mbUuid UUID проекта MB
     * @param int $brzProjectId ID проекта Brizy
     * @return array
     * @throws Exception
     */
    public function getMigrationLogs(string $waveId, string $mbUuid, int $brzProjectId): array
    {
        $projectRoot = dirname(__DIR__, 3);
        $logPath = $_ENV['LOG_PATH'] ?? getenv('LOG_PATH') ?: $projectRoot . '/var/log';
        
        // Формируем путь к лог-файлу (как в ApplicationBootstrapper::migrationFlow)
        // LOG_FILE_PATH формируется в buildApplicationContext как $logPath . '/migration_' . time()
        // Но реальный файл создается как LOG_FILE_PATH . '_' . $brz_project_id . '.log'
        // Поэтому ищем файлы по паттерну
        
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
        
        // Вариант 3: Ищем в логах волны
        $waveLogFile = $logPath . '/wave_' . $waveId . '.log';
        if (file_exists($waveLogFile)) {
            $logFiles[] = $waveLogFile;
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
                    // Разбиваем логи по паттерну Monolog: ][ (конец одной записи и начало другой)
                    // Заменяем ][ на ]\n[ чтобы каждая запись была на отдельной строке
                    $content = str_replace('][', "]\n[", $content);
                    
                    // Фильтруем логи по brz_project_id если это общий файл
                    if (strpos($logFile, '_' . $brzProjectId . '.log') !== false || 
                        strpos($logFile, 'wave_') !== false) {
                        $lines = explode("\n", $content);
                        foreach ($lines as $line) {
                            $line = trim($line);
                            if (empty($line)) {
                                continue;
                            }
                            
                            // Фильтруем только строки, связанные с этой миграцией
                            if (strpos($line, "brizy-$brzProjectId") !== false || 
                                strpos($line, $mbUuid) !== false ||
                                strpos($logFile, '_' . $brzProjectId . '.log') !== false ||
                                preg_match('/\[202\d-\d{2}-\d{2}/', $line)) { // Если это запись с датой
                                $allLogs[] = $line;
                            }
                        }
                    } else {
                        // Для специфичных файлов просто разбиваем на строки
                        $lines = explode("\n", $content);
                        foreach ($lines as $line) {
                            $line = trim($line);
                            if (!empty($line)) {
                                $allLogs[] = $line;
                            }
                        }
                    }
                }
            }
        }
        
        // Если не нашли логи в файлах, пробуем через grep (как в ApplicationBootstrapper)
        if (empty($allLogs)) {
            $logFilePath = $logPath . '/migration_*';
            $command = sprintf(
                'grep -h "brizy-%d\|%s" %s/*.log 2>/dev/null | tail -1000',
                $brzProjectId,
                escapeshellarg($mbUuid),
                escapeshellarg($logPath)
            );
            $output = @shell_exec($command);
            if ($output) {
                $lines = explode("\n", trim($output));
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (!empty($line)) {
                        // Разбиваем склеенные записи
                        $line = str_replace('][', "]\n[", $line);
                        $subLines = explode("\n", $line);
                        foreach ($subLines as $subLine) {
                            $subLine = trim($subLine);
                            if (!empty($subLine)) {
                                $allLogs[] = $subLine;
                            }
                        }
                    }
                }
            }
        }
        
        // Убираем дубликаты и сортируем (если есть временные метки)
        $allLogs = array_unique($allLogs);
        $allLogs = array_values($allLogs); // Переиндексируем массив
        
        return [
            'logs' => $allLogs,
            'log_files' => $logFiles,
            'brz_project_id' => $brzProjectId,
            'mb_uuid' => $mbUuid
        ];
    }

    /**
     * Получить логи проекта в волне
     * 
     * @param string $waveId ID волны
     * @param int $brzProjectId ID проекта Brizy
     * @return array Логи проекта
     * @throws Exception
     */
    public function getProjectLogsInWave(string $waveId, int $brzProjectId): array
    {
        $projectRoot = dirname(__DIR__, 3);
        $logPath = $_ENV['LOG_PATH'] ?? getenv('LOG_PATH') ?: $projectRoot . '/var/log';
        
        // Путь к лог-файлу проекта в волне
        $waveLogDir = $logPath . '/wave_' . $waveId;
        $logFilePath = $waveLogDir . '/project_' . $brzProjectId . '.log';
        
        if (!file_exists($logFilePath)) {
            return [
                'logs' => [],
                'log_file' => $logFilePath,
                'exists' => false,
                'message' => 'Лог-файл для проекта не найден'
            ];
        }
        
        if (!is_readable($logFilePath)) {
            throw new Exception('Лог-файл недоступен для чтения: ' . $logFilePath);
        }
        
        $content = file_get_contents($logFilePath);
        if ($content === false) {
            throw new Exception('Не удалось прочитать лог-файл: ' . $logFilePath);
        }
        
        // Разбиваем логи по строкам
        $lines = explode("\n", $content);
        $logs = array_filter(array_map('trim', $lines), function($line) {
            return !empty($line);
        });
        
        return [
            'logs' => array_values($logs),
            'log_file' => $logFilePath,
            'exists' => true,
            'total_lines' => count($logs),
            'file_size' => filesize($logFilePath)
        ];
    }

    /**
     * Получить логи для волны миграций
     * 
     * @param string $waveId ID волны
     * @return string Содержимое лог-файла
     * @throws Exception
     */
    public function getWaveLogs(string $waveId): string
    {
        $projectRoot = dirname(__DIR__, 3);
        $logPath = $_ENV['LOG_PATH'] ?? getenv('LOG_PATH') ?: $projectRoot . '/var/log';
        
        // Ищем все лог-файлы для этой волны
        // Формат: wave_{waveId}_{timestamp}.log или wave_{waveId}.log
        $logFiles = [];
        
        // Сначала ищем файлы с timestamp
        $pattern = $logPath . '/wave_' . $waveId . '_*.log';
        $files = glob($pattern);
        if ($files) {
            $logFiles = array_merge($logFiles, $files);
        }
        
        // Также ищем файл без timestamp
        $simpleLogFile = $logPath . '/wave_' . $waveId . '.log';
        if (file_exists($simpleLogFile)) {
            $logFiles[] = $simpleLogFile;
        }
        
        // Сортируем по времени модификации (новые первыми)
        usort($logFiles, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        if (empty($logFiles)) {
            return 'Лог-файлы для волны не найдены. Ожидаемые файлы: wave_' . $waveId . '_*.log или wave_' . $waveId . '.log';
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
     * Удалить lock-файл миграции
     * 
     * @param string $mbUuid UUID проекта MB
     * @param int $brzProjectId ID проекта Brizy
     * @return array
     * @throws Exception
     */
    public function removeMigrationLock(string $mbUuid, int $brzProjectId): array
    {
        $projectRoot = dirname(__DIR__, 3);
        $cachePath = $_ENV['CACHE_PATH'] ?? getenv('CACHE_PATH') ?: $projectRoot . '/var/cache';
        
        // Формируем путь к lock-файлу (как в ApplicationBootstrapper)
        $lockFile = $cachePath . '/' . $mbUuid . '-' . $brzProjectId . '.lock';
        
        if (!file_exists($lockFile)) {
            return [
                'success' => true,
                'message' => 'Lock-файл не найден (возможно, уже удален)',
                'lock_file' => $lockFile,
                'removed' => false
            ];
        }
        
        if (!is_writable($lockFile) && !is_writable($cachePath)) {
            throw new Exception('Нет прав на удаление lock-файла: ' . $lockFile);
        }
        
        $removed = @unlink($lockFile);
        
        if (!$removed) {
            throw new Exception('Не удалось удалить lock-файл: ' . $lockFile);
        }
        
        return [
            'success' => true,
            'message' => 'Lock-файл успешно удален',
            'lock_file' => $lockFile,
            'removed' => true
        ];
    }

    /**
     * Запустить миграцию в фоне через отдельный процесс
     * 
     * @param string $waveId ID волны
     * @param string $mbUuid UUID проекта MB
     * @param int $brzProjectId ID проекта Brizy (0 если нужно создать)
     * @param int $workspaceId ID workspace
     * @param string $mbSiteId Site ID
     * @param string $mbSecret Secret
     * @param array $params Дополнительные параметры
     * @return void
     * @throws Exception
     */
    private function startMigrationInBackground(string $waveId, string $mbUuid, int $brzProjectId, int $workspaceId, string $mbSiteId, string $mbSecret, array $params = []): void
    {
        error_log("[WaveService::startMigrationInBackground] Начало запуска миграции в фоне: waveId={$waveId}, mbUuid={$mbUuid}, brzProjectId={$brzProjectId}, workspaceId={$workspaceId}");
        
        $projectRoot = dirname(__DIR__, 3);
        $migrationScript = sys_get_temp_dir() . '/wave_restart_migration_' . $waveId . '_' . md5($mbUuid) . '_' . time() . '_' . getmypid() . '.php';
        
        error_log("[WaveService::startMigrationInBackground] Migration script path: {$migrationScript}");
        
        $projectRootEscaped = addslashes($projectRoot);
        $waveIdEscaped = addslashes($waveId);
        $mbUuidEscaped = addslashes($mbUuid);
        $mgrManual = $params['mgr_manual'] ?? false;
        $mgrManualValue = $mgrManual ? 'true' : 'false';
        
        error_log("[WaveService::startMigrationInBackground] Параметры: projectRoot={$projectRoot}, mgrManual={$mgrManualValue}");
        
        $scriptContent = "<?php\n";
        $scriptContent .= "error_log('[RestartMigration] Script started at ' . date('Y-m-d H:i:s'));\n";
        $scriptContent .= "error_log('[RestartMigration] Wave ID: {$waveIdEscaped}');\n";
        $scriptContent .= "error_log('[RestartMigration] MB UUID: {$mbUuidEscaped}');\n";
        $scriptContent .= "error_log('[RestartMigration] Project root: {$projectRootEscaped}');\n";
        $scriptContent .= "chdir('{$projectRootEscaped}');\n";
        $scriptContent .= "error_log('[RestartMigration] Changed directory to: ' . getcwd());\n";
        $scriptContent .= "require_once '{$projectRootEscaped}/vendor/autoload_runtime.php';\n";
        $scriptContent .= "error_log('[RestartMigration] Autoload loaded');\n";
        $scriptContent .= "use Dashboard\\Services\\DatabaseService;\n";
        $scriptContent .= "use Dashboard\\Services\\WaveService;\n";
        $scriptContent .= "use Exception;\n\n";
        $scriptContent .= "try {\n";
        $scriptContent .= "    error_log('[RestartMigration] Initializing services...');\n";
        $scriptContent .= "    \$dbService = new DatabaseService();\n";
        $scriptContent .= "    \$waveService = new WaveService();\n";
        $scriptContent .= "    \$waveId = '{$waveIdEscaped}';\n";
        $scriptContent .= "    \$mbUuid = '{$mbUuidEscaped}';\n\n";
        $scriptContent .= "    // Обновляем статус на in_progress\n";
        $scriptContent .= "    error_log('[RestartMigration] Updating status to in_progress...');\n";
        $scriptContent .= "    \$dbService->updateMigrationResult(\$waveId, \$mbUuid, [\n";
        $scriptContent .= "        'result_json' => [\n";
        $scriptContent .= "            'status' => 'in_progress',\n";
        $scriptContent .= "            'message' => 'Миграция запущена',\n";
        $scriptContent .= "            'started_at' => date('Y-m-d H:i:s')\n";
        $scriptContent .= "        ]\n";
        $scriptContent .= "    ]);\n\n";
        $scriptContent .= "    // Выполняем миграцию через restartMigrationInWave\n";
        $scriptContent .= "    error_log('[RestartMigration] Starting migration restart...');\n";
        $scriptContent .= "    \$restartParams = [\n";
        $scriptContent .= "        'mb_site_id' => '" . addslashes($mbSiteId) . "',\n";
        $scriptContent .= "        'mb_secret' => '" . addslashes($mbSecret) . "',\n";
        $scriptContent .= "        'mgr_manual' => {$mgrManualValue}\n";
        $scriptContent .= "    ];\n";
        // Определяем путь к файлу результата один раз
        $resultFileEscaped = addslashes($migrationScript . '.result');
        
        $scriptContent .= "    \$result = \$waveService->restartMigrationInWave(\$waveId, \$mbUuid, \$restartParams);\n";
        $scriptContent .= "    error_log('[RestartMigration] Migration restart completed: success=' . (isset(\$result['success']) && \$result['success'] ? 'true' : 'false'));\n\n";
        $scriptContent .= "    // Результат уже сохранен в restartMigrationInWave\n";
        $scriptContent .= "    \$resultFile = '{$resultFileEscaped}';\n";
        $scriptContent .= "    if (file_exists(\$resultFile)) {\n";
        $scriptContent .= "        @unlink(\$resultFile);\n";
        $scriptContent .= "    }\n";
        $scriptContent .= "    file_put_contents(\$resultFile, json_encode(['success' => true, 'mb_uuid' => \$mbUuid, 'result' => \$result]));\n";
        $scriptContent .= "} catch (Exception \$e) {\n";
        $scriptContent .= "    try {\n";
        $scriptContent .= "        \$dbService = new DatabaseService();\n";
        $scriptContent .= "        \$dbService->updateMigrationResult('{$waveIdEscaped}', '{$mbUuidEscaped}', [\n";
        $scriptContent .= "            'result_json' => [\n";
        $scriptContent .= "                'status' => 'error',\n";
        $scriptContent .= "                'error' => \$e->getMessage(),\n";
        $scriptContent .= "                'message' => 'Ошибка при выполнении миграции'\n";
        $scriptContent .= "            ]\n";
        $scriptContent .= "        ]);\n";
        $scriptContent .= "    } catch (Exception \$updateError) {\n";
        $scriptContent .= "        error_log('Ошибка обновления статуса: ' . \$updateError->getMessage());\n";
        $scriptContent .= "    }\n";
        $scriptContent .= "    \$resultFile = '{$resultFileEscaped}';\n";
        $scriptContent .= "    file_put_contents(\$resultFile, json_encode(['success' => false, 'mb_uuid' => '{$mbUuidEscaped}', 'error' => \$e->getMessage()]));\n";
        $scriptContent .= "}\n";
        
        error_log("[WaveService::startMigrationInBackground] Сохранение migration script...");
        $writeResult = @file_put_contents($migrationScript, $scriptContent);
        if ($writeResult === false) {
            $errorMsg = "Не удалось сохранить migration script: {$migrationScript}";
            error_log("[WaveService::startMigrationInBackground] ОШИБКА: {$errorMsg}");
            throw new Exception($errorMsg);
        }
        error_log("[WaveService::startMigrationInBackground] Migration script сохранен: {$migrationScript} (размер: " . filesize($migrationScript) . " байт)");
        
        // Запускаем процесс в фоне с перенаправлением в лог-файл волны
        $logFile = dirname(__DIR__, 3) . '/var/log/wave_' . $waveId . '_' . time() . '.log';
        @mkdir(dirname($logFile), 0755, true);
        
        $command = sprintf(
            'cd %s && nohup php -f %s >> %s 2>&1 & echo $!',
            escapeshellarg($projectRoot),
            escapeshellarg($migrationScript),
            escapeshellarg($logFile)
        );
        
        error_log("[WaveService::startMigrationInBackground] Команда запуска: {$command}");
        error_log("[WaveService::startMigrationInBackground] Лог-файл: {$logFile}");
        $pid = trim(shell_exec($command));
        error_log("[WaveService::startMigrationInBackground] Результат выполнения команды: PID=" . ($pid ?: 'NOT SET'));
        
        if (empty($pid) || !is_numeric($pid)) {
            $errorMsg = "Не удалось запустить процесс миграции в фоне. PID: " . ($pid ?: 'empty');
            error_log("[WaveService::startMigrationInBackground] ОШИБКА: {$errorMsg}");
            throw new Exception($errorMsg);
        }
        
        error_log("[WaveService::startMigrationInBackground] Процесс успешно запущен: PID={$pid}");
    }

    /**
     * Построить контекст для ApplicationBootstrapper из переменных окружения
     * 
     * @return array
     * @throws Exception
     */
    private function buildApplicationContext(): array
    {
        $projectRoot = dirname(__DIR__, 3);
        
        // Загружаем переменные окружения
        if (file_exists($projectRoot . '/.env')) {
            $dotenv = \Dotenv\Dotenv::createMutable($projectRoot);
            $dotenv->safeLoad();
        }
        
        $prodEnv = $projectRoot . '/.env.prod.local';
        if (file_exists($prodEnv)) {
            $dotenv = \Dotenv\Dotenv::createMutable($projectRoot, ['.env.prod.local']);
            $dotenv->safeLoad();
        }
        
        $logPath = $_ENV['LOG_PATH'] ?? getenv('LOG_PATH') ?: $projectRoot . '/var/log';
        $cachePath = $_ENV['CACHE_PATH'] ?? getenv('CACHE_PATH') ?: $projectRoot . '/var/cache';
        
        // Создаем директории если их нет
        @mkdir($logPath, 0755, true);
        @mkdir($cachePath, 0755, true);
        
        // Формируем путь к лог-файлу для этой миграции
        $logFilePath = $logPath . '/migration_' . time();
        
        return [
            'LOG_FILE_PATH' => $logFilePath,
            'LOG_LEVEL' => (int)($_ENV['LOG_LEVEL'] ?? getenv('LOG_LEVEL') ?: \Monolog\Logger::DEBUG),
            'LOG_PATH' => $logPath,
            'CACHE_PATH' => $cachePath,
            'DEV_MODE' => (bool)($_ENV['DEV_MODE'] ?? getenv('DEV_MODE') ?? false),
            'MGR_MODE' => (bool)($_ENV['MGR_MODE'] ?? getenv('MGR_MODE') ?? false),
            'MB_DB_HOST' => $_ENV['MB_DB_HOST'] ?? getenv('MB_DB_HOST') ?: 'localhost',
            'MB_DB_PORT' => $_ENV['MB_DB_PORT'] ?? getenv('MB_DB_PORT') ?: '3306',
            'MB_DB_NAME' => $_ENV['MB_DB_NAME'] ?? getenv('MB_DB_NAME') ?: '',
            'MB_DB_USER' => $_ENV['MB_DB_USER'] ?? getenv('MB_DB_USER') ?: '',
            'MB_DB_PASSWORD' => $_ENV['MB_DB_PASSWORD'] ?? getenv('MB_DB_PASSWORD') ?: '',
            'MG_DB_HOST' => $_ENV['MG_DB_HOST'] ?? getenv('MG_DB_HOST') ?: ($_ENV['MB_DB_HOST'] ?? getenv('MB_DB_HOST') ?: 'localhost'),
            'MG_DB_PORT' => $_ENV['MG_DB_PORT'] ?? getenv('MG_DB_PORT') ?: ($_ENV['MB_DB_PORT'] ?? getenv('MB_DB_PORT') ?: '3306'),
            'MG_DB_NAME' => $_ENV['MG_DB_NAME'] ?? getenv('MG_DB_NAME') ?: '',
            'MG_DB_USER' => $_ENV['MG_DB_USER'] ?? getenv('MG_DB_USER') ?: '',
            'MG_DB_PASS' => $_ENV['MG_DB_PASS'] ?? getenv('MG_DB_PASS') ?: '',
            'MB_MEDIA_HOST' => $_ENV['MB_MEDIA_HOST'] ?? getenv('MB_MEDIA_HOST') ?: '',
            'MB_PREVIEW_HOST' => $_ENV['MB_PREVIEW_HOST'] ?? getenv('MB_PREVIEW_HOST') ?: 'staging.cloversites.com',
            'BRIZY_HOST' => $_ENV['BRIZY_HOST'] ?? getenv('BRIZY_HOST') 
                ?? $_ENV['BRIZY_CLOUD_HOST'] ?? getenv('BRIZY_CLOUD_HOST') 
                ?? 'https://admin.brizy.io',
            'BRIZY_CLOUD_HOST' => $_ENV['BRIZY_HOST'] ?? getenv('BRIZY_HOST') 
                ?? $_ENV['BRIZY_CLOUD_HOST'] ?? getenv('BRIZY_CLOUD_HOST') 
                ?? 'https://admin.brizy.io',
            'BRIZY_CLOUD_TOKEN' => $_ENV['BRIZY_CLOUD_TOKEN'] ?? getenv('BRIZY_CLOUD_TOKEN') ?: '',
            'APP_AUTHORIZATION_TOKEN' => $_ENV['APP_AUTHORIZATION_TOKEN'] ?? getenv('APP_AUTHORIZATION_TOKEN') ?: '',
            'MB_MONKCMS_API' => $_ENV['MB_MONKCMS_API'] ?? getenv('MB_MONKCMS_API') ?: '',
            'AWS_BUCKET_ACTIVE' => (bool)($_ENV['AWS_BUCKET_ACTIVE'] ?? getenv('AWS_BUCKET_ACTIVE') ?? false),
            'AWS_KEY' => $_ENV['AWS_KEY'] ?? getenv('AWS_KEY') ?: '',
            'AWS_SECRET' => $_ENV['AWS_SECRET'] ?? getenv('AWS_SECRET') ?: '',
            'AWS_REGION' => $_ENV['AWS_REGION'] ?? getenv('AWS_REGION') ?: '',
            'AWS_BUCKET' => $_ENV['AWS_BUCKET'] ?? getenv('AWS_BUCKET') ?: '',
        ];
    }
}
