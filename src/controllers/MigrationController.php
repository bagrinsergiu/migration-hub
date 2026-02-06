<?php

namespace Dashboard\Controllers;

use Dashboard\Services\MigrationService;
use Dashboard\Services\ApiProxyService;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class MigrationController
{
    /**
     * @var MigrationService
     */
    private $migrationService;
    /**
     * @var ApiProxyService
     */
    private $apiProxy;

    public function __construct()
    {
        $this->migrationService = new MigrationService();
        $this->apiProxy = new ApiProxyService();
    }

    /**
     * GET /api/migrations
     * Получить список миграций
     */
    public function list(Request $request): JsonResponse
    {
        try {
            $filters = [
                'status' => $request->query->get('status'),
                'mb_project_uuid' => $request->query->get('mb_project_uuid'),
                'brz_project_id' => $request->query->get('brz_project_id'),
            ];

            // Убираем пустые фильтры
            $filters = array_filter($filters, function($value) {
                return $value !== null && $value !== '';
            });

            $migrations = $this->migrationService->getMigrationsList($filters);

            return new JsonResponse([
                'success' => true,
                'data' => $migrations,
                'count' => count($migrations)
            ], 200);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/migrations/:id
     * Получить детали миграции по brz_project_id
     */
    public function getDetails(Request $request, int $id): JsonResponse
    {
        try {
            $brzProjectId = $id;
            
            // Сначала пытаемся найти миграцию в migrations_mapping
            $details = $this->migrationService->getMigrationDetails($id);

            if (!$details) {
                // Миграция не найдена в migrations_mapping
                // Пытаемся найти mb_uuid из lock-файла или migration_result_list
                $mbUuid = null;
                $migrationResult = null;
                
                // Пытаемся найти mb_uuid из lock-файла по brz_project_id
                $projectRoot = dirname(__DIR__, 3);
                $cachePath = $_ENV['CACHE_PATH'] ?? getenv('CACHE_PATH') ?: $projectRoot . '/var/cache';
                $lockFilePattern = $cachePath . '/*-' . $brzProjectId . '.lock';
                $lockFiles = glob($lockFilePattern);
                $lockFile = null;
                
                if (!empty($lockFiles)) {
                    // Берем первый найденный lock-файл
                    $lockFile = $lockFiles[0];
                    // Извлекаем mb_uuid из имени файла: {mb_uuid}-{brz_id}.lock
                    if (preg_match('#/([^/]+)-' . preg_quote($brzProjectId, '#') . '\.lock$#', $lockFile, $matches)) {
                        $mbUuid = $matches[1];
                    }
                }
                
                // Пытаемся найти в migration_result_list
                try {
                    $dbService = new \Dashboard\Services\DatabaseService();
                    $db = $dbService->getWriteConnection();
                    $migrationResult = $db->find(
                        'SELECT mb_project_uuid, result_json, migration_uuid, brizy_project_domain FROM migration_result_list WHERE brz_project_id = ? ORDER BY created_at DESC LIMIT 1',
                        [$brzProjectId]
                    );
                    
                    if ($migrationResult && isset($migrationResult['mb_project_uuid'])) {
                        $mbUuid = $migrationResult['mb_project_uuid'];
                    }
                } catch (Exception $e) {
                    // Игнорируем ошибки БД
                }
                
                // Если mb_uuid найден (из lock-файла или migration_result_list), формируем детали
                if ($mbUuid) {
                    $resultJson = [];
                    $resultData = null;
                    
                    if ($migrationResult && isset($migrationResult['result_json'])) {
                        $resultJson = json_decode($migrationResult['result_json'] ?? '{}', true);
                        $resultData = $resultJson['value'] ?? $resultJson;
                    }
                    
                    // Если есть lock-файл, пытаемся прочитать информацию из него
                    $lockData = null;
                    if ($lockFile && file_exists($lockFile)) {
                        $lockContent = @file_get_contents($lockFile);
                        if ($lockContent) {
                            $lockData = json_decode($lockContent, true);
                        }
                    }
                    
                    // Определяем статус
                    $status = 'unknown';
                    if ($lockData && isset($lockData['current_stage'])) {
                        // Если есть lock-файл с информацией о стадии, статус in_progress
                        $status = 'in_progress';
                    } elseif ($resultData && isset($resultData['status'])) {
                        $status = $resultData['status'];
                    } elseif ($resultJson && isset($resultJson['status'])) {
                        $status = $resultJson['status'];
                    }
                    
                    // Формируем детали миграции
                    $details = [
                        'mapping' => [
                            'brz_project_id' => $brzProjectId,
                            'mb_project_uuid' => $mbUuid,
                            'changes_json' => json_encode([
                                'status' => $status,
                                'brizy_project_domain' => $migrationResult['brizy_project_domain'] ?? $resultData['brizy_project_domain'] ?? null,
                                'current_stage' => $lockData['current_stage'] ?? null,
                                'stage_updated_at' => $lockData['stage_updated_at'] ?? null,
                                'total_pages' => $lockData['total_pages'] ?? null,
                                'processed_pages' => $lockData['processed_pages'] ?? null,
                                'progress_percent' => $lockData['progress_percent'] ?? null
                            ])
                        ],
                        'result' => $migrationResult ? [
                            'migration_uuid' => $migrationResult['migration_uuid'] ?? null,
                            'result_json' => $resultJson
                        ] : null,
                        'result_data' => $resultData,
                        'status' => $status,
                        'migration_uuid' => $migrationResult['migration_uuid'] ?? null,
                        'brizy_project_domain' => $migrationResult['brizy_project_domain'] ?? $resultData['brizy_project_domain'] ?? null,
                        'mb_project_domain' => $resultData['mb_project_domain'] ?? null,
                        'progress' => $resultData['progress'] ?? ($lockData ? [
                            'total_pages' => $lockData['total_pages'] ?? null,
                            'processed_pages' => $lockData['processed_pages'] ?? null,
                            'progress_percent' => $lockData['progress_percent'] ?? null
                        ] : null),
                        'warnings' => $resultData['message']['warning'] ?? [],
                        'lock_file_info' => $lockData ? [
                            'current_stage' => $lockData['current_stage'] ?? null,
                            'started_at' => $lockData['started_at'] ?? null,
                            'pid' => $lockData['pid'] ?? null
                        ] : null
                    ];
                } else {
                    // Если mb_uuid не найден, возвращаем ошибку
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Миграция не найдена. Не удалось определить mb_project_uuid для brz_project_id: ' . $brzProjectId,
                        'debug' => [
                            'brz_project_id' => $brzProjectId,
                            'cache_path' => $cachePath ?? null,
                            'lock_files_found' => count($lockFiles ?? []),
                            'mb_uuid_found' => false
                        ]
                    ], 404);
                }
            }

            return new JsonResponse([
                'success' => true,
                'data' => $details
            ], 200);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/migrations/by-uuid/:mbUuid
     * Получить детали миграции по mb_project_uuid
     */
    public function getDetailsByUuid(Request $request, string $mbUuid): JsonResponse
    {
        try {
            $details = $this->migrationService->getMigrationDetailsByUuid($mbUuid);
            
            if (!$details) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Миграция не найдена'
                ], 404);
            }

            // Формируем ответ в том же формате, что и getDetails
            $responseData = [
                'id' => $details['mapping']['brz_project_id'],
                'mb_project_uuid' => $details['mapping']['mb_project_uuid'],
                'brz_project_id' => $details['mapping']['brz_project_id'],
                'status' => $details['status'],
                'brizy_project_domain' => $details['brizy_project_domain'],
                'mb_project_domain' => $details['mb_project_domain'],
                'progress' => $details['progress'],
                'created_at' => $details['mapping']['created_at'],
                'updated_at' => $details['mapping']['updated_at'],
                'result_data' => $details['result_data'],
                'migration_uuid' => $details['migration_uuid'],
            ];

            // Добавляем ошибки и предупреждения
            if ($details['result_data']) {
                if (isset($details['result_data']['error'])) {
                    $responseData['error'] = $details['result_data']['error'];
                }
                if (isset($details['warnings']) && !empty($details['warnings'])) {
                    $responseData['result_data']['warnings'] = $details['warnings'];
                }
            }

            return new JsonResponse([
                'success' => true,
                'data' => $responseData
            ], 200);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/migrations/run
     * Запустить миграцию
     */
    public function run(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                $data = $request->request->all();
            }

            // Валидация обязательных полей (mb_site_id и mb_secret могут быть из настроек)
            $required = ['mb_project_uuid', 'brz_project_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => "Обязательное поле отсутствует: {$field}"
                    ], 400);
                }
            }
            
            // Проверяем, что mb_site_id и mb_secret либо переданы, либо есть в настройках
            $dbService = new \Dashboard\Services\DatabaseService();
            $settings = $dbService->getSettings();
            if (empty($data['mb_site_id']) && empty($settings['mb_site_id'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => "mb_site_id должен быть указан либо в запросе, либо в настройках"
                ], 400);
            }
            if (empty($data['mb_secret']) && empty($settings['mb_secret'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => "mb_secret должен быть указан либо в запросе, либо в настройках"
                ], 400);
            }

            $result = $this->migrationService->runMigration($data);

            // Если миграция не успешна, возвращаем ошибку
            if (!$result['success']) {
                $errorMessage = 'Миграция завершилась с ошибкой';
                if (isset($result['data']['error'])) {
                    $errorMessage = is_string($result['data']['error']) ? $result['data']['error'] : json_encode($result['data']['error']);
                } elseif (isset($result['data']['message'])) {
                    $errorMessage = is_string($result['data']['message']) ? $result['data']['message'] : json_encode($result['data']['message']);
                } elseif (isset($result['raw_data']['error'])) {
                    $errorMessage = is_string($result['raw_data']['error']) ? $result['raw_data']['error'] : json_encode($result['raw_data']['error']);
                }
                
                $httpCode = isset($result['http_code']) ? (int)$result['http_code'] : 400;
                return new JsonResponse([
                    'success' => false,
                    'error' => $errorMessage,
                    'data' => $result['data'] ?? null
                ], $httpCode);
            }

            $httpCode = isset($result['http_code']) ? (int)$result['http_code'] : 200;
            
            // Формируем ответ
            $responseData = [
                'success' => $result['success'] ?? false,
                'data' => $result['data'] ?? null
            ];
            
            // Убеждаемся, что данные есть
            if ($result['success'] && empty($responseData['data'])) {
                $responseData['data'] = $result['raw_data'] ?? null;
            }
            
            // Создаем и возвращаем JsonResponse
            $jsonResponse = new JsonResponse($responseData, $httpCode);
            return $jsonResponse;
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/migrations/:id/restart
     * Перезапустить миграцию
     */
    public function restart(Request $request, int $id): JsonResponse
    {
        try {
            // Пытаемся получить миграцию по ID (migration_id из таблицы migrations)
            $details = $this->migrationService->getMigrationDetailsById($id);
            
            // Если не найдено по ID, пробуем как brz_project_id (для обратной совместимости)
            if (!$details) {
                $details = $this->migrationService->getMigrationDetails($id);
            }
            
            if (!$details) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Миграция не найдена'
                ], 404);
            }

            $mapping = $details['mapping'] ?? [];
            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                $data = $request->request->all();
            }

            // Получаем настройки по умолчанию
            $dbService = new \Dashboard\Services\DatabaseService();
            $defaultSettings = $dbService->getSettings();
            
            // Получаем mb_site_id из данных миграции (если есть в details)
            $migrationMbSiteId = $details['mb_site_id'] ?? null;
            
            // Получаем mb_project_uuid и brz_project_id из details, если они не в mapping
            $mbProjectUuid = $details['mb_project_uuid'] ?? $mapping['mb_project_uuid'] ?? null;
            // brz_project_id может быть в mapping или в details напрямую (если details получен через getMigrationDetailsById)
            $brzProjectId = $mapping['brz_project_id'] ?? null;
            
            // Если не найдено в details или mapping, пробуем получить из таблицы migrations
            if (empty($migrationMbSiteId) || empty($mbProjectUuid) || empty($brzProjectId)) {
                $db = $dbService->getWriteConnection();
                $migrationRecord = $db->find('SELECT * FROM migrations WHERE id = ?', [$id]);
                if (!$migrationRecord) {
                    $migrationRecord = $db->find('SELECT * FROM migrations WHERE brz_project_id = ? ORDER BY created_at DESC LIMIT 1', [$id]);
                }
                if ($migrationRecord) {
                    if (empty($migrationMbSiteId)) {
                        $migrationMbSiteId = $migrationRecord['mb_site_id'] ?? null;
                    }
                    if (empty($mbProjectUuid)) {
                        $mbProjectUuid = $migrationRecord['mb_project_uuid'] ?? null;
                    }
                    if (empty($brzProjectId)) {
                        $brzProjectId = $migrationRecord['brz_project_id'] ?? null;
                    }
                }
            }

            // Используем данные из маппинга, если не переданы новые
            // Для mb_site_id используем: запрос > настройки > данные миграции
            // Для mb_secret используем: запрос > настройки
            $params = [
                'mb_project_uuid' => $data['mb_project_uuid'] ?? $mbProjectUuid,
                'brz_project_id' => $data['brz_project_id'] ?? $brzProjectId,
                'mb_site_id' => !empty($data['mb_site_id']) 
                    ? (int)$data['mb_site_id'] 
                    : (!empty($defaultSettings['mb_site_id']) 
                        ? (int)$defaultSettings['mb_site_id'] 
                        : (!empty($migrationMbSiteId) ? (int)$migrationMbSiteId : null)),
                'mb_secret' => !empty($data['mb_secret']) ? $data['mb_secret'] : ($defaultSettings['mb_secret'] ?? null),
                'brz_workspaces_id' => !empty($data['brz_workspaces_id']) ? (int)$data['brz_workspaces_id'] : null,
                'mb_page_slug' => !empty($data['mb_page_slug']) ? $data['mb_page_slug'] : null,
                'mb_element_name' => !empty($data['mb_element_name']) ? $data['mb_element_name'] : null,
                'skip_media_upload' => isset($data['skip_media_upload']) ? (bool)$data['skip_media_upload'] : false,
                'skip_cache' => isset($data['skip_cache']) ? (bool)$data['skip_cache'] : false,
                'mgr_manual' => !empty($data['mgr_manual']) ? (int)$data['mgr_manual'] : 0,
                'quality_analysis' => isset($data['quality_analysis']) ? (bool)$data['quality_analysis'] : false,
            ];
            
            // Если перезапускаем с анализом качества, помечаем старые результаты как устаревшие
            if (!empty($params['quality_analysis'])) {
                try {
                    $qualityReport = new \MBMigration\Analysis\QualityReport();
                    $qualityReport->archiveOldReports($id);
                } catch (Exception $e) {
                    // Логируем, но не прерываем перезапуск миграции
                    error_log("Failed to archive old quality reports: " . $e->getMessage());
                }
            }

            // Проверяем, что mb_site_id и mb_secret либо переданы, либо есть в настройках, либо в данных миграции
            if (empty($params['mb_site_id'])) {
                error_log("[MigrationController::restart] mb_site_id не найден: request=" . json_encode($data) . ", settings=" . json_encode($defaultSettings) . ", migration=" . json_encode(['mb_site_id' => $migrationMbSiteId]));
                return new JsonResponse([
                    'success' => false,
                    'error' => "mb_site_id должен быть указан либо в запросе, либо в настройках, либо сохранен в данных миграции"
                ], 400);
            }
            if (empty($params['mb_secret'])) {
                error_log("[MigrationController::restart] mb_secret не найден: request=" . json_encode($data) . ", settings=" . json_encode($defaultSettings));
                return new JsonResponse([
                    'success' => false,
                    'error' => "mb_secret должен быть указан либо в запросе, либо в настройках"
                ], 400);
            }
            
            error_log("[MigrationController::restart] Параметры для перезапуска: mb_project_uuid=" . ($params['mb_project_uuid'] ?? 'empty') . ", brz_project_id=" . ($params['brz_project_id'] ?? 'empty') . ", mb_site_id=" . $params['mb_site_id'] . ", mb_secret=" . (!empty($params['mb_secret']) ? '***' : 'empty'));

            // Проверяем, что mb_project_uuid и brz_project_id присутствуют
            if (empty($params['mb_project_uuid'])) {
                error_log("[MigrationController::restart] ОШИБКА: mb_project_uuid отсутствует. mapping=" . json_encode($mapping) . ", data=" . json_encode($data));
                return new JsonResponse([
                    'success' => false,
                    'error' => "mb_project_uuid должен быть указан в данных миграции"
                ], 400);
            }
            if (empty($params['brz_project_id'])) {
                error_log("[MigrationController::restart] ОШИБКА: brz_project_id отсутствует. mapping=" . json_encode($mapping) . ", data=" . json_encode($data));
                return new JsonResponse([
                    'success' => false,
                    'error' => "brz_project_id должен быть указан в данных миграции"
                ], 400);
            }

            $result = $this->migrationService->runMigration($params);

            return new JsonResponse([
                'success' => $result['success'],
                'data' => $result['data'],
                'http_code' => $result['http_code'],
                'message' => 'Миграция перезапущена'
            ], $result['http_code']);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/migrations/:id/status
     * Получить статус миграции
     */
    public function getStatus(int $id): JsonResponse
    {
        try {
            $details = $this->migrationService->getMigrationDetails($id);

            if (!$details) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Миграция не найдена'
                ], 404);
            }

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'status' => $details['status'],
                    'mapping' => $details['mapping'],
                    'result' => $details['result']
                ]
            ], 200);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/migrations/:id/webhook-info
     * Получить информацию о веб-хуке для миграции
     */
    public function getWebhookInfo(Request $request, int $id): JsonResponse
    {
        try {
            $details = $this->migrationService->getMigrationDetails($id);
            
            if (!$details) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Миграция не найдена'
                ], 404);
            }
            
            $mbUuid = $details['mapping']['mb_project_uuid'];
            $brzProjectId = $details['mapping']['brz_project_id'];
            
            // Определяем URL веб-хука
            $dashboardBaseUrl = $_ENV['DASHBOARD_BASE_URL'] ?? getenv('DASHBOARD_BASE_URL') ?: 'http://localhost:8088';
            $webhookUrl = rtrim($dashboardBaseUrl, '/') . '/api/webhooks/migration-result';
            
            // Проверяем, был ли получен веб-хук (проверяем наличие результата в БД)
            $dbService = new \Dashboard\Services\DatabaseService();
            $migrationResult = $dbService->getWriteConnection()->find(
                'SELECT * FROM migration_result_list WHERE mb_project_uuid = ? AND brz_project_id = ? ORDER BY created_at DESC LIMIT 1',
                [$mbUuid, $brzProjectId]
            );
            
            $webhookReceived = !empty($migrationResult);
            $webhookReceivedAt = $migrationResult['created_at'] ?? null;
            
            // Получаем последние логи веб-хука из PHP логов (опционально)
            $webhookLogs = [];
            $logPath = $_ENV['LOG_PATH'] ?? getenv('LOG_PATH') ?: dirname(__DIR__, 3) . '/var/log';
            $logFile = $logPath . '/php/php-errors.log';
            
            if (file_exists($logFile)) {
                // Читаем последние 100 строк лога и ищем записи о веб-хуке
                $logLines = file($logFile);
                $recentLines = array_slice($logLines, -100);
                
                foreach ($recentLines as $line) {
                    if (strpos($line, 'WebhookController::migrationResult') !== false && 
                        strpos($line, "mb_project_uuid={$mbUuid}") !== false) {
                        $webhookLogs[] = trim($line);
                    }
                }
                $webhookLogs = array_slice($webhookLogs, -5); // Последние 5 записей
            }
            
            // Проверяем статус с сервера миграции (опционально)
            $serverStatus = null;
            try {
                $statusResult = $this->apiProxy->getMigrationStatusFromServer($mbUuid, $brzProjectId);
                if ($statusResult['success']) {
                    $serverStatus = $statusResult['data'];
                }
            } catch (Exception $e) {
                // Игнорируем ошибки при опросе сервера
            }
            
            return new JsonResponse([
                'success' => true,
                'data' => [
                    'webhook_url' => $webhookUrl,
                    'webhook_registered' => true, // Веб-хук всегда регистрируется при запуске
                    'webhook_received' => $webhookReceived,
                    'webhook_received_at' => $webhookReceivedAt,
                    'webhook_params' => [
                        'mb_project_uuid' => $mbUuid,
                        'brz_project_id' => $brzProjectId,
                        'webhook_url' => $webhookUrl
                    ],
                    'webhook_logs' => $webhookLogs,
                    'server_status' => $serverStatus,
                    'migration_status' => $details['status'],
                    'last_result' => $migrationResult ? [
                        'migration_uuid' => $migrationResult['migration_uuid'] ?? null,
                        'created_at' => $migrationResult['created_at'] ?? null,
                        'status' => json_decode($migrationResult['result_json'] ?? '{}', true)['status'] ?? null
                    ] : null
                ]
            ], 200);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DELETE /api/migrations/:id/lock
     * Удалить lock-файл миграции
     */
    public function removeLock(Request $request, int $id): JsonResponse
    {
        try {
            $details = $this->migrationService->getMigrationDetails($id);
            
            if (!$details) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Миграция не найдена'
                ], 404);
            }

            $mbUuid = $details['mapping']['mb_project_uuid'];
            $brzProjectId = $details['mapping']['brz_project_id'];

            $result = $this->migrationService->removeMigrationLock($mbUuid, $brzProjectId);

            return new JsonResponse([
                'success' => $result['success'],
                'data' => $result
            ], 200);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/migrations/:id/kill
     * Убить процесс миграции
     */
    public function killProcess(Request $request, int $id): JsonResponse
    {
        try {
            $details = $this->migrationService->getMigrationDetails($id);
            
            if (!$details) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Миграция не найдена'
                ], 404);
            }

            $mbUuid = $details['mapping']['mb_project_uuid'];
            $brzProjectId = $details['mapping']['brz_project_id'];

            $data = json_decode($request->getContent(), true);
            $force = isset($data['force']) ? (bool)$data['force'] : false;

            $result = $this->migrationService->killMigrationProcess($mbUuid, $brzProjectId, $force);

            return new JsonResponse([
                'success' => $result['success'],
                'data' => $result
            ], 200);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/migrations/:id/process
     * Получить информацию о процессе миграции (мониторинг)
     */
    public function getProcessInfo(Request $request, int $id): JsonResponse
    {
        try {
            $brzProjectId = $id; // Используем id как brz_project_id
            $mbUuid = null;
            
            // Сначала пытаемся найти миграцию в migrations_mapping
            $details = $this->migrationService->getMigrationDetails($id);
            
            if ($details && isset($details['mapping'])) {
                // Миграция найдена в migrations_mapping
                $mbUuid = $details['mapping']['mb_project_uuid'];
            } else {
                // Миграция не найдена в migrations_mapping
                // Пытаемся найти mb_uuid из lock-файла по brz_project_id
                $projectRoot = dirname(__DIR__, 3);
                $cachePath = $_ENV['CACHE_PATH'] ?? getenv('CACHE_PATH') ?: $projectRoot . '/var/cache';
                $lockFilePattern = $cachePath . '/*-' . $brzProjectId . '.lock';
                $lockFiles = glob($lockFilePattern);
                
                if (!empty($lockFiles)) {
                    // Берем первый найденный lock-файл
                    $lockFile = $lockFiles[0];
                    // Извлекаем mb_uuid из имени файла: {mb_uuid}-{brz_id}.lock
                    if (preg_match('#/([^/]+)-' . preg_quote($brzProjectId, '#') . '\.lock$#', $lockFile, $matches)) {
                        $mbUuid = $matches[1];
                    }
                }
                
                // Если не нашли в lock-файле, пытаемся найти в migration_result_list
                if (!$mbUuid) {
                    try {
                        $dbService = new \Dashboard\Services\DatabaseService();
                        $db = $dbService->getWriteConnection();
                        $migrationResult = $db->find(
                            'SELECT mb_project_uuid FROM migration_result_list WHERE brz_project_id = ? LIMIT 1',
                            [$brzProjectId]
                        );
                        
                        if ($migrationResult && isset($migrationResult['mb_project_uuid'])) {
                            $mbUuid = $migrationResult['mb_project_uuid'];
                        }
                    } catch (Exception $e) {
                        // Игнорируем ошибки БД
                    }
                }
            }
            
            // Если mbUuid все еще не найден, возвращаем ошибку
            if (!$mbUuid) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Миграция не найдена. Не удалось определить mb_project_uuid для brz_project_id: ' . $brzProjectId,
                    'debug' => [
                        'brz_project_id' => $brzProjectId,
                        'cache_path' => $cachePath ?? null,
                        'lock_files_found' => count($lockFiles ?? [])
                    ]
                ], 404);
            }

            $result = $this->migrationService->getMigrationProcessInfo($mbUuid, $brzProjectId);

            return new JsonResponse([
                'success' => $result['success'],
                'data' => $result
            ], 200);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DELETE /api/migrations/:id/cache
     * Удалить кэш-файл миграции
     */
    public function removeCache(Request $request, int $id): JsonResponse
    {
        try {
            $details = $this->migrationService->getMigrationDetails($id);
            
            if (!$details) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Миграция не найдена'
                ], 404);
            }

            $mbUuid = $details['mapping']['mb_project_uuid'];
            $brzProjectId = $details['mapping']['brz_project_id'];

            $result = $this->migrationService->removeMigrationCache($mbUuid, $brzProjectId);

            return new JsonResponse([
                'success' => $result['success'],
                'data' => $result
            ], 200);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/migrations/:id/reset-status
     * Сбросить статус миграции на pending
     */
    public function resetStatus(Request $request, int $id): JsonResponse
    {
        try {
            $details = $this->migrationService->getMigrationDetails($id);
            
            if (!$details) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Миграция не найдена'
                ], 404);
            }

            $mbUuid = $details['mapping']['mb_project_uuid'];
            $brzProjectId = $details['mapping']['brz_project_id'];

            $result = $this->migrationService->resetMigrationStatus($mbUuid, $brzProjectId);

            return new JsonResponse([
                'success' => $result['success'],
                'data' => $result
            ], 200);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/migrations/:id/hard-reset
     * Hard reset миграции: удаляет lock-файл, cache-файл, убивает процесс и сбрасывает статус
     */
    public function hardReset(Request $request, int $id): JsonResponse
    {
        try {
            $details = $this->migrationService->getMigrationDetails($id);
            
            if (!$details) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Миграция не найдена'
                ], 404);
            }

            $mbUuid = $details['mapping']['mb_project_uuid'];
            $brzProjectId = $details['mapping']['brz_project_id'];

            $result = $this->migrationService->hardResetMigration($mbUuid, $brzProjectId);

            return new JsonResponse([
                'success' => $result['success'],
                'data' => $result
            ], $result['success'] ? 200 : 500);
        } catch (Exception $e) {
            error_log("Hard reset controller exception: " . $e->getMessage());
            error_log("Hard reset controller stack trace: " . $e->getTraceAsString());
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'exception' => [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], 500);
        } catch (\Throwable $e) {
            error_log("Hard reset controller throwable: " . $e->getMessage());
            error_log("Hard reset controller stack trace: " . $e->getTraceAsString());
            return new JsonResponse([
                'success' => false,
                'error' => 'Критическая ошибка: ' . $e->getMessage(),
                'exception' => [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    /**
     * POST /api/migrations/:id/rebuild-page
     * Пересобрать конкретную страницу
     */
    public function rebuildPage(Request $request, int $id): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                $data = $request->request->all();
            }

            $pageSlug = $data['page_slug'] ?? null;
            
            if (!$pageSlug) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Параметр page_slug обязателен'
                ], 400);
            }

            $details = $this->migrationService->getMigrationDetails($id);
            
            if (!$details) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Миграция не найдена'
                ], 404);
            }

            $mbUuid = $details['mapping']['mb_project_uuid'];
            $brzProjectId = $details['mapping']['brz_project_id'];

            // Получаем параметры из настроек или из данных миграции
            $params = [
                'mb_project_uuid' => $mbUuid,
                'brz_project_id' => $brzProjectId,
                'mb_page_slug' => $pageSlug,
                'mgr_manual' => 0,
                'quality_analysis' => true, // Включаем анализ качества при пересборке
            ];

            // Получаем настройки из БД или используем значения по умолчанию
            $dbService = new \Dashboard\Services\DatabaseService();
            $settings = $dbService->getSettings();
            
            if ($settings) {
                $params['mb_site_id'] = $settings['mb_site_id'] ?? null;
                $params['mb_secret'] = $settings['mb_secret'] ?? null;
                $params['brz_workspaces_id'] = $settings['brz_workspaces_id'] ?? null;
            }

            // Если параметры переданы в запросе, используем их
            if (isset($data['mb_site_id'])) {
                $params['mb_site_id'] = $data['mb_site_id'];
            }
            if (isset($data['mb_secret'])) {
                $params['mb_secret'] = $data['mb_secret'];
            }
            if (isset($data['brz_workspaces_id'])) {
                $params['brz_workspaces_id'] = $data['brz_workspaces_id'];
            }

            // Запускаем пересборку страницы через ApiProxyService
            $result = $this->apiProxy->runMigration($params);

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'message' => 'Пересборка страницы запущена',
                    'page_slug' => $pageSlug,
                    'migration_id' => $id
                ]
            ], 200);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/migrations/:id/logs
     * Получить логи миграции
     */
    public function getMigrationLogs(Request $request, int $id): JsonResponse
    {
        try {
            $migrationService = new \Dashboard\Services\MigrationService();
            $logs = $migrationService->getMigrationLogs($id);
            
            return new JsonResponse([
                'success' => true,
                'data' => ['logs' => $logs],
            ], 200);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/migrations/:id/status-from-server
     * Получить статус миграции напрямую с сервера миграции
     */
    public function getStatusFromServer(Request $request, int $id): JsonResponse
    {
        try {
            $details = $this->migrationService->getMigrationDetails($id);
            
            if (!$details) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Миграция не найдена'
                ], 404);
            }
            
            $mbUuid = $details['mapping']['mb_project_uuid'];
            $brzProjectId = $details['mapping']['brz_project_id'];
            
            // Получаем статус с сервера миграции
            $result = $this->apiProxy->getMigrationStatusFromServer($mbUuid, $brzProjectId);
            
            if (!$result['success']) {
                return new JsonResponse([
                    'success' => false,
                    'error' => $result['error'] ?? 'Ошибка при получении статуса с сервера миграции',
                    'http_code' => $result['http_code'] ?? null
                ], $result['http_code'] ?? 500);
            }
            
            return new JsonResponse([
                'success' => true,
                'data' => $result['data']
            ], 200);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/migrations/:id/rebuild-page-no-analysis
     * Пересобрать конкретную страницу миграции без анализа качества
     */
    public function rebuildPageNoAnalysis(Request $request, int $id): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                $data = $request->request->all();
            }

            $pageSlug = $data['page_slug'] ?? null;
            
            if (!$pageSlug) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Параметр page_slug обязателен'
                ], 400);
            }

            $details = $this->migrationService->getMigrationDetails($id);
            
            if (!$details) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Миграция не найдена'
                ], 404);
            }

            $mbUuid = $details['mapping']['mb_project_uuid'];
            $brzProjectId = $details['mapping']['brz_project_id'];

            // Получаем параметры из настроек или из данных миграции
            $params = [
                'mb_project_uuid' => $mbUuid,
                'brz_project_id' => $brzProjectId,
                'mb_page_slug' => $pageSlug,
                'mgr_manual' => 0,
                'quality_analysis' => false, // Отключаем анализ качества
            ];

            // Получаем настройки из БД или используем значения по умолчанию
            $dbService = new \Dashboard\Services\DatabaseService();
            $settings = $dbService->getSettings();
            
            if ($settings) {
                $params['mb_site_id'] = $settings['mb_site_id'] ?? null;
                $params['mb_secret'] = $settings['mb_secret'] ?? null;
                $params['brz_workspaces_id'] = $settings['brz_workspaces_id'] ?? null;
            }

            // Если параметры переданы в запросе, используем их
            if (isset($data['mb_site_id'])) {
                $params['mb_site_id'] = $data['mb_site_id'];
            }
            if (isset($data['mb_secret'])) {
                $params['mb_secret'] = $data['mb_secret'];
            }
            if (isset($data['brz_workspaces_id'])) {
                $params['brz_workspaces_id'] = $data['brz_workspaces_id'];
            }

            // Запускаем пересборку страницы через ApiProxyService без анализа
            $result = $this->apiProxy->runMigration($params);

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'message' => 'Пересборка страницы запущена (без анализа качества)',
                    'page_slug' => $pageSlug,
                    'migration_id' => $id
                ]
            ], 200);
        } catch (Exception $e) {
            error_log("Error rebuilding page without analysis: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'details' => [
                    'migration_id' => $id,
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine(),
                    'type' => get_class($e)
                ]
            ], 500);
        }
    }
}
