<?php

namespace Dashboard\Services;

use Exception;
use Dashboard\Services\MigrationService;
use Dashboard\Services\WaveLogger;

/**
 * MigrationExecutionService
 * 
 * Сервис для выполнения миграций через HTTP запросы к Migration API (порт 8088)
 * Поддерживает параллельное выполнение через curl_multi
 */
class MigrationExecutionService
{
    /**
     * @var string
     */
    private $migrationApiUrl;

    public function __construct()
    {
        // Получаем URL сервера миграции из переменных окружения
        // Приоритет: $_ENV (из .env через Dotenv) > getenv() > значение по умолчанию
        $migrationApiUrl = $_ENV['MIGRATION_API_URL'] ?? getenv('MIGRATION_API_URL') ?: null;
        
        if (empty($migrationApiUrl)) {
            // Значение по умолчанию: http://localhost:8080
            // Проверяем, запущены ли мы внутри Docker (проверка через переменную окружения или доступность порта 80)
            if (file_exists('/.dockerenv') || getenv('DOCKER_CONTAINER')) {
                // Внутри Docker контейнера Migration API доступен на порту 80
                $migrationApiUrl = 'http://127.0.0.1:80';
            } else {
                // Локально по умолчанию используется порт 8080
                $migrationApiUrl = 'http://localhost:8080';
            }
        }
        
        $this->migrationApiUrl = rtrim($migrationApiUrl, '/'); // Убираем завершающий слеш если есть
        
        WaveLogger::debug("MigrationExecutionService инициализирован", [
            'migration_api_url' => $this->migrationApiUrl,
            'is_docker' => file_exists('/.dockerenv') || getenv('DOCKER_CONTAINER'),
            'env_source' => isset($_ENV['MIGRATION_API_URL']) ? '$_ENV' : (getenv('MIGRATION_API_URL') ? 'getenv()' : 'default')
        ]);
    }

    /**
     * Запустить одну миграцию через HTTP запрос
     * 
     * Lock-файл будет создан автоматически при запуске миграции в ApplicationBootstrapper
     * 
     * @param array $params Параметры миграции
     * @return array Результат запуска
     * @throws Exception
     */
    public function executeMigration(array $params): array
    {
        // Обязательные параметры
        $required = ['mb_project_uuid', 'brz_project_id', 'mb_site_id', 'mb_secret'];
        foreach ($required as $key) {
            if (empty($params[$key])) {
                throw new Exception("Обязательный параметр отсутствует: {$key}");
            }
        }

        // Формируем URL для запроса
        $queryParams = [
            'mb_project_uuid' => $params['mb_project_uuid'],
            'brz_project_id' => (int)$params['brz_project_id'],
            'mb_site_id' => (int)$params['mb_site_id'],
            'mb_secret' => $params['mb_secret'],
        ];

        // Опциональные параметры
        if (!empty($params['brz_workspaces_id'])) {
            $queryParams['brz_workspaces_id'] = (int)$params['brz_workspaces_id'];
        }
        if (!empty($params['mb_page_slug'])) {
            $queryParams['mb_page_slug'] = $params['mb_page_slug'];
        }
        $queryParams['mgr_manual'] = $params['mgr_manual'] ?? 0;
        
        if (isset($params['quality_analysis'])) {
            $queryParams['quality_analysis'] = $params['quality_analysis'] ? 'true' : 'false';
        }
        
        // Добавляем wave_id если миграция запускается под управлением волны
        if (!empty($params['wave_id'])) {
            $queryParams['wave_id'] = $params['wave_id'];
        }

        $url = $this->migrationApiUrl . '/?' . http_build_query($queryParams);

        // Выполняем HTTP запрос (GET запрос, как в существующем API)
        // Миграции запускаются асинхронно, поэтому нужен таймаут достаточный для установления соединения
        // но не ждем полного выполнения миграции (она может длиться часами)
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10 секунд для установления соединения и получения подтверждения запуска
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // 5 секунд на установление соединения
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        
        $response = @curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Если произошла ошибка подключения, это нормально - миграция запускается в фоне
        if ($error && strpos($error, 'timeout') !== false) {
            // Таймаут ожидается - миграция запущена в фоне
            return [
                'success' => true,
                'status' => 'in_progress',
                'message' => 'Миграция запущена в фоне',
                'mb_project_uuid' => $params['mb_project_uuid'],
                'brz_project_id' => $params['brz_project_id'],
                'url' => $url
            ];
        }

        if ($error) {
            throw new Exception("Ошибка при запуске миграции: {$error}");
        }

        // Пытаемся распарсить ответ
        $data = null;
        if ($response) {
            $data = json_decode($response, true);
        }

        return [
            'success' => $httpCode === 200 || $httpCode === 202,
            'status' => 'in_progress',
            'http_code' => $httpCode,
            'message' => 'Миграция запущена',
            'mb_project_uuid' => $params['mb_project_uuid'],
            'brz_project_id' => $params['brz_project_id'],
            'data' => $data,
            'url' => $url
        ];
    }

    /**
     * Запустить батч миграций параллельно
     * Использует curl_multi для параллельных HTTP запросов
     * 
     * @param array $migrations Массив параметров миграций
     * @param int $batchSize Размер батча (количество параллельных запросов)
     * @return array Результаты выполнения
     * @throws Exception
     */
    public function executeBatch(array $migrations, int $batchSize = 3): array
    {
        // Принудительно пишем в лог сразу, чтобы убедиться, что логирование работает
        $logFile = dirname(__DIR__, 3) . '/var/log/wave_dashboard.log';
        @file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] [INFO] === START executeBatch === migrations=" . count($migrations) . ", batch_size={$batchSize}\n", FILE_APPEND);
        
        WaveLogger::startOperation('MigrationExecutionService::executeBatch', [
            'migrations_count' => count($migrations),
            'batch_size' => $batchSize
        ]);
        
        if (empty($migrations)) {
            WaveLogger::warning("Пустой список миграций");
            return [
                'success' => true,
                'total' => 0,
                'processed' => 0,
                'results' => []
            ];
        }

        WaveLogger::info("Инициализация curl_multi", ['total' => count($migrations), 'batch_size' => $batchSize]);
        $pending = array_values($migrations);
        $activeHandles = [];
        $multiHandle = curl_multi_init();
        $results = [];
        $migrationMap = []; // Маппинг curl handle -> индекс миграции

        try {
            while (!empty($pending) || !empty($activeHandles)) {
                // Добавляем новые запросы до достижения batch_size
                while (count($activeHandles) < $batchSize && !empty($pending)) {
                    $migration = array_shift($pending);
                    $migrationIndex = count($results);
                    $mbUuid = $migration['mb_project_uuid'] ?? 'unknown';
                    $brzWorkspaceId = $migration['brz_workspaces_id'] ?? 'N/A';
                    $mbSiteId = $migration['mb_site_id'] ?? 'N/A';
                    
                    WaveLogger::info("📋 [ЭТАП 1] Взял проект для миграции", [
                        'mb_uuid' => $mbUuid,
                        'brz_workspace_id' => $brzWorkspaceId,
                        'mb_site_id' => $mbSiteId,
                        'brz_project_id' => $migration['brz_project_id'] ?? 0,
                        'mgr_manual' => $migration['mgr_manual'] ?? 0,
                        'quality_analysis' => $migration['quality_analysis'] ?? false,
                        'active_handles' => count($activeHandles),
                        'pending' => count($pending),
                        'batch_size' => $batchSize
                    ]);
                    
                    try {
                        // Формируем URL для миграции
                        $queryParams = [
                            'mb_project_uuid' => $migration['mb_project_uuid'],
                            'brz_project_id' => (int)$migration['brz_project_id'],
                            'mb_site_id' => (int)$migration['mb_site_id'],
                            'mb_secret' => $migration['mb_secret'],
                        ];

                        if (!empty($migration['brz_workspaces_id'])) {
                            $queryParams['brz_workspaces_id'] = (int)$migration['brz_workspaces_id'];
                        }
                        if (!empty($migration['mb_page_slug'])) {
                            $queryParams['mb_page_slug'] = $migration['mb_page_slug'];
                        }
                        $queryParams['mgr_manual'] = $migration['mgr_manual'] ?? 0;
                        
                        if (isset($migration['quality_analysis'])) {
                            $queryParams['quality_analysis'] = $migration['quality_analysis'] ? 'true' : 'false';
                        }
                        
                        // Добавляем wave_id если миграция запускается под управлением волны
                        if (!empty($migration['wave_id'])) {
                            $queryParams['wave_id'] = $migration['wave_id'];
                        }

                        $url = $this->migrationApiUrl . '/?' . http_build_query($queryParams);

                        WaveLogger::info("🔗 [ЭТАП 2] Сформирован URL для запуска миграции", [
                            'mb_uuid' => $mbUuid,
                            'url' => $url,
                            'query_params' => $queryParams
                        ]);

                        // Создаем curl handle
                        // Миграции запускаются асинхронно, таймаут достаточен для установления соединения
                        // Уменьшаем таймаут до 3 секунд - нам нужно только подтверждение запуска, не полный ответ
                        $ch = curl_init($url);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 3); // 3 секунды - достаточно для подтверждения запуска
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2); // 2 секунды на установление соединения
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
                        // Не ждем полного ответа - миграция запускается в фоне
                        curl_setopt($ch, CURLOPT_NOBODY, false); // Получаем тело ответа, но с коротким таймаутом

                        curl_multi_add_handle($multiHandle, $ch);
                        $activeHandles[] = $ch;
                        // Используем индекс в массиве как ключ, так как spl_object_hash не работает с resource в PHP 7.4
                        $handleKey = count($activeHandles) - 1;
                        $migrationMap[$handleKey] = [
                            'index' => $migrationIndex,
                            'migration' => $migration,
                            'url' => $url,
                            'handle' => $ch
                        ];
                        
                        WaveLogger::info("🚀 [ЭТАП 3] Миграция добавлена в очередь выполнения", [
                            'mb_uuid' => $mbUuid,
                            'url' => $url,
                            'active_handles' => count($activeHandles),
                            'timeout' => '10s (connect: 5s)'
                        ]);
                    } catch (Exception $e) {
                        WaveLogger::error("❌ [ОШИБКА] Ошибка инициализации запроса миграции", [
                            'mb_uuid' => $mbUuid ?? 'unknown',
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                            'migration_params' => $migration ?? []
                        ]);
                        $results[] = [
                            'success' => false,
                            'status' => 'error',
                            'error' => $e->getMessage(),
                            'migration' => $migration,
                            'stage' => 'initialization'
                        ];
                    }
                }

                // Выполняем активные запросы
                if (!empty($activeHandles)) {
                    WaveLogger::debug("Выполнение активных запросов", [
                        'active_handles' => count($activeHandles),
                        'pending' => count($pending)
                    ]);
                    
                    do {
                        $status = curl_multi_exec($multiHandle, $active);
                        if ($status > CURLM_OK && $status !== CURLM_CALL_MULTI_PERFORM) {
                            WaveLogger::error("❌ [ОШИБКА] cURL multi error", [
                                'error_code' => $status,
                                'error_message' => curl_multi_strerror($status)
                            ]);
                            break;
                        }
                    } while ($status === CURLM_CALL_MULTI_PERFORM);

                    // Обрабатываем завершенные запросы через curl_multi_info_read
                    while (($info = curl_multi_info_read($multiHandle)) !== false) {
                        if ($info['msg'] === CURLMSG_DONE) {
                            $ch = $info['handle'];
                            
                            // Находим migrationInfo по handle в массиве
                            $migrationInfo = null;
                            $handleKey = null;
                            foreach ($migrationMap as $key => $info) {
                                if ($info['handle'] === $ch) {
                                    $migrationInfo = $info;
                                    $handleKey = $key;
                                    break;
                                }
                            }
                            
                            if (!$migrationInfo) {
                                WaveLogger::warning("Завершенный запрос без информации о миграции", [
                                    'active_handles_count' => count($activeHandles),
                                    'migration_map_keys' => array_keys($migrationMap)
                                ]);
                                curl_multi_remove_handle($multiHandle, $ch);
                                curl_close($ch);
                                continue;
                            }
                            
                            $migration = $migrationInfo['migration'] ?? null;
                            $mbUuid = $migration['mb_project_uuid'] ?? 'unknown';
                            $url = $migrationInfo['url'] ?? 'unknown';
                            
                            $response = curl_multi_getcontent($ch);
                            $error = curl_error($ch);
                            $info = curl_getinfo($ch);
                            $httpCode = $info['http_code'] ?: 0;
                            $curlErrorNo = curl_errno($ch);
                            
                            WaveLogger::info("📥 [ЭТАП 4] Получен ответ от Migration API", [
                                'mb_uuid' => $mbUuid,
                                'url' => $url,
                                'http_code' => $httpCode,
                                'curl_error_no' => $curlErrorNo,
                                'curl_error' => $error ?: 'none',
                                'response_length' => strlen($response ?? ''),
                                'has_response' => !empty($response)
                            ]);
                            
                            // Определяем успешность запуска
                            // HTTP 200/202 - успешный запуск, миграция выполняется
                            // HTTP 0 или таймаут - возможно запустилась, но ответ не получен
                            // Другие коды - ошибка
                            $isSuccess = false;
                            $status = 'error';
                            $message = '';
                            $resultData = null;
                            $brzProjectId = 0;
                            
                            // Если есть ответ, пытаемся его распарсить
                            if ($response && !$error) {
                                $data = json_decode($response, true);
                                if ($data) {
                                    $resultData = $data;
                                    WaveLogger::info("📄 [ЭТАП 5] Ответ успешно распарсен", [
                                        'mb_uuid' => $mbUuid,
                                        'response_keys' => array_keys($data),
                                        'has_brizy_project_id' => isset($data['brizy_project_id'])
                                    ]);
                                    
                                    // Если в ответе есть brz_project_id, сохраняем его
                                    if (isset($data['brizy_project_id'])) {
                                        $brzProjectId = (int)$data['brizy_project_id'];
                                        WaveLogger::info("✅ [ЭТАП 6] Получен brz_project_id из ответа", [
                                            'mb_uuid' => $mbUuid,
                                            'brz_project_id' => $brzProjectId
                                        ]);
                                    }
                                } else {
                                    WaveLogger::warning("⚠️ [ЭТАП 5] Не удалось распарсить JSON ответ", [
                                        'mb_uuid' => $mbUuid,
                                        'response_preview' => substr($response, 0, 200)
                                    ]);
                                }
                            }

                            // Обработка различных сценариев
                            if ($httpCode === 200 || $httpCode === 202) {
                                $isSuccess = true;
                                $status = 'in_progress';
                                $message = 'Миграция успешно запущена';
                                WaveLogger::info("✅ [ЭТАП 7] Миграция успешно запущена", [
                                    'mb_uuid' => $mbUuid,
                                    'http_code' => $httpCode,
                                    'brz_project_id' => $brzProjectId,
                                    'url' => $url
                                ]);
                            } elseif ($error && (strpos($error, 'timeout') !== false || strpos($error, 'timed out') !== false)) {
                                // Таймаут - это нормально для асинхронных миграций
                                // Если соединение установлено (http_code > 0), считаем успешным запуском
                                // Миграция запускается в фоне и может не ответить сразу
                                if ($httpCode > 0) {
                                    // Соединение установлено, миграция скорее всего запустилась
                                    $isSuccess = true;
                                    $status = 'in_progress';
                                    $message = 'Миграция запущена в фоне (таймаут ожидается для асинхронных операций)';
                                    WaveLogger::info("⏱️ [ЭТАП 7] Миграция запущена (таймаут ожидается)", [
                                        'mb_uuid' => $mbUuid,
                                        'http_code' => $httpCode,
                                        'timeout_error' => $error,
                                        'url' => $url,
                                        'note' => 'Таймаут нормален для асинхронных миграций'
                                    ]);
                                } elseif ($httpCode === 0 && strpos($error, 'Connection refused') === false) {
                                    // Таймаут без отказа в соединении - возможно миграция запустилась
                                    $isSuccess = true;
                                    $status = 'in_progress';
                                    $message = 'Миграция запущена в фоне (таймаут без ответа, но соединение установлено)';
                                    WaveLogger::info("⏱️ [ЭТАП 7] Миграция запущена (таймаут без ответа)", [
                                        'mb_uuid' => $mbUuid,
                                        'http_code' => $httpCode,
                                        'timeout_error' => $error,
                                        'url' => $url,
                                        'note' => 'Таймаут без отказа - миграция может быть запущена'
                                    ]);
                                } else {
                                    // Connection refused или другой критический таймаут
                                    $isSuccess = false;
                                    $status = 'error';
                                    $message = 'Таймаут при запуске миграции: ' . $error;
                                    WaveLogger::error("❌ [ОШИБКА] Критический таймаут при запуске миграции", [
                                        'mb_uuid' => $mbUuid,
                                        'http_code' => $httpCode,
                                        'timeout_error' => $error,
                                        'url' => $url,
                                        'note' => 'Connection refused или критическая ошибка'
                                    ]);
                                }
                            } elseif ($error) {
                                $isSuccess = false;
                                $status = 'error';
                                $message = 'Ошибка запуска миграции: ' . $error;
                                WaveLogger::error("❌ [ОШИБКА] Ошибка cURL при запуске миграции", [
                                    'mb_uuid' => $mbUuid,
                                    'http_code' => $httpCode,
                                    'curl_error' => $error,
                                    'curl_error_no' => $curlErrorNo,
                                    'url' => $url
                                ]);
                            } else {
                                $isSuccess = false;
                                $status = 'error';
                                $message = 'Неизвестный статус запуска (HTTP ' . $httpCode . ')';
                                WaveLogger::warning("⚠️ [ЭТАП 7] Неизвестный статус запуска", [
                                    'mb_uuid' => $mbUuid,
                                    'http_code' => $httpCode,
                                    'url' => $url,
                                    'response_preview' => substr($response ?? '', 0, 200)
                                ]);
                            }
                            
                            $result = [
                                'success' => $isSuccess,
                                'status' => $status,
                                'http_code' => $httpCode,
                                'migration' => $migration,
                                'url' => $url,
                                'message' => $message,
                                'data' => $resultData,
                                'brz_project_id' => $brzProjectId
                            ];
                            
                            // Всегда добавляем error, если есть ошибка или неуспешный статус
                            if ($error) {
                                $result['error'] = $error;
                            } elseif (!$isSuccess) {
                                // Если нет ошибки, но статус неуспешный, используем сообщение
                                $result['error'] = $message ?: 'Неизвестная ошибка запуска миграции';
                            }

                            $results[] = $result;
                            WaveLogger::info("📊 [ЭТАП 8] Результат обработан и добавлен", [
                                'mb_uuid' => $mbUuid,
                                'success' => $isSuccess,
                                'status' => $status,
                                'total_results' => count($results),
                                'brz_project_id' => $brzProjectId
                            ]);

                            // Удаляем handle из активных
                            curl_multi_remove_handle($multiHandle, $ch);
                            curl_close($ch);
                            
                            // Удаляем из массива активных handles
                            $keyToRemove = array_search($ch, $activeHandles);
                            if ($keyToRemove !== false) {
                                unset($activeHandles[$keyToRemove]);
                                $activeHandles = array_values($activeHandles); // Переиндексируем
                            }
                            // Удаляем из migrationMap по handleKey
                            if ($handleKey !== null) {
                                unset($migrationMap[$handleKey]);
                            }
                        }
                    }
                }

                // Небольшая задержка перед следующей итерацией
                if (!empty($activeHandles)) {
                    usleep(100000); // 0.1 секунды
                }
            }
        } finally {
            // Закрываем все оставшиеся handles
            foreach ($activeHandles as $ch) {
                curl_multi_remove_handle($multiHandle, $ch);
                curl_close($ch);
            }
            curl_multi_close($multiHandle);
        }

        $successCount = 0;
        $failedCount = 0;
        foreach ($results as $r) {
            if ($r['success'] ?? false) {
                $successCount++;
            } else {
                $failedCount++;
            }
        }
        
        WaveLogger::endOperation('MigrationExecutionService::executeBatch', [
            'total' => count($migrations),
            'processed' => count($results),
            'success_count' => $successCount,
            'failed_count' => $failedCount
        ]);

        return [
            'success' => true,
            'total' => count($migrations),
            'processed' => count($results),
            'results' => $results
        ];
    }

    /**
     * Получить статус миграции через проверку lock-файла
     * Использует существующую логику из MigrationService
     * 
     * @param string $mbUuid UUID проекта MB
     * @param int $brzId ID проекта Brizy
     * @return array Информация о статусе миграции
     */
    public function getMigrationStatus(string $mbUuid, int $brzId): array
    {
        WaveLogger::startOperation('MigrationExecutionService::getMigrationStatus', ['mb_uuid' => $mbUuid, 'brz_id' => $brzId]);
        try {
            $migrationService = new MigrationService();
            $result = $migrationService->getMigrationProcessInfo($mbUuid, $brzId);
            WaveLogger::endOperation('MigrationExecutionService::getMigrationStatus', ['success' => true, 'status' => $result['status'] ?? 'N/A']);
            return $result;
        } catch (Exception $e) {
            // КРИТИЧНО: Логируем все ошибки
            $logFile = dirname(__DIR__, 3) . '/var/log/wave_dashboard.log';
            $errorMsg = "[" . date('Y-m-d H:i:s') . "] [ERROR] ❌ ОШИБКА в getMigrationStatus: mb_uuid={$mbUuid}, brz_id={$brzId}, error=" . $e->getMessage() . "\n";
            @file_put_contents($logFile, $errorMsg, FILE_APPEND);
            
            WaveLogger::error("❌ ОШИБКА в getMigrationStatus", [
                'mb_uuid' => $mbUuid,
                'brz_id' => $brzId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            WaveLogger::endOperation('MigrationExecutionService::getMigrationStatus', ['success' => false, 'error' => $e->getMessage()]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
