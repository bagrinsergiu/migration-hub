<?php

namespace Dashboard\Controllers;

use Dashboard\Services\WaveService;
use Dashboard\Services\WaveLogger;
use Dashboard\Services\WaveReviewService;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class WaveController
{
    /** @var WaveService */
    private $waveService;

    public function __construct()
    {
        $this->waveService = new WaveService();
    }

    /**
     * POST /api/waves
     * Создать новую волну миграций
     */
    public function create(Request $request): JsonResponse
    {
        WaveLogger::startOperation('WaveController::create', [
            'method' => $request->getMethod(),
            'content_type' => $request->headers->get('Content-Type')
        ]);
        
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                $data = $request->request->all();
            }

            WaveLogger::debug("Получены данные запроса", [
                'has_name' => !empty($data['name']),
                'has_project_uuids' => !empty($data['project_uuids']),
                'project_uuids_count' => is_array($data['project_uuids'] ?? null) ? count($data['project_uuids']) : 0
            ]);

            // Валидация
            if (empty($data['name'])) {
                WaveLogger::error("Валидация: название волны пустое");
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Название волны обязательно'
                ], 400);
            }

            if (empty($data['project_uuids']) || !is_array($data['project_uuids'])) {
                WaveLogger::error("Валидация: список UUID проектов пустой или не массив");
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Список UUID проектов обязателен и должен быть массивом'
                ], 400);
            }

            // Очищаем UUID от пробелов и пустых значений
            $projectUuids = array_filter(
                array_map('trim', $data['project_uuids']),
                function($uuid) {
                    return !empty($uuid);
                }
            );

            if (empty($projectUuids)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Список UUID проектов не может быть пустым'
                ], 400);
            }

            $batchSize = isset($data['batch_size']) ? (int)$data['batch_size'] : 3;
            $mgrManual = isset($data['mgr_manual']) ? (bool)$data['mgr_manual'] : false;
            $enableCloning = isset($data['enable_cloning']) ? (bool)$data['enable_cloning'] : false;

            WaveLogger::info("Вызов WaveService::createWave", [
                'name' => $data['name'],
                'projects_count' => count($projectUuids),
                'batch_size' => $batchSize,
                'mgr_manual' => $mgrManual,
                'enable_cloning' => $enableCloning
            ]);

            $result = $this->waveService->createWave(
                $data['name'],
                array_values($projectUuids),
                $batchSize,
                $mgrManual,
                $enableCloning
            );

            WaveLogger::endOperation('WaveController::create', [
                'success' => true,
                'wave_id' => $result['wave_id'] ?? null
            ]);

            return new JsonResponse([
                'success' => true,
                'data' => $result
            ], 201);

        } catch (Exception $e) {
            WaveLogger::error("Ошибка в WaveController::create", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            WaveLogger::endOperation('WaveController::create', [
                'success' => false,
                'error' => $e->getMessage()
            ]);
            
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/waves
     * Получить список всех волн
     */
    public function list(Request $request): JsonResponse
    {
        // Убираем избыточное логирование для быстрого списка - логируем только ошибки
        try {
            $waves = $this->waveService->getWavesList();
            
            // Фильтрация по статусу (опционально)
            $statusFilter = $request->query->get('status');
            if ($statusFilter) {
                $waves = array_filter($waves, function($wave) use ($statusFilter) {
                    return $wave['status'] === $statusFilter;
                });
                $waves = array_values($waves); // Переиндексируем массив
            }

            return new JsonResponse([
                'success' => true,
                'data' => $waves,
                'count' => count($waves)
            ], 200);

        } catch (Exception $e) {
            WaveLogger::error("Ошибка в WaveController::list", ['error' => $e->getMessage()]);
            WaveLogger::endOperation('WaveController::list', ['success' => false, 'error' => $e->getMessage()]);
            
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/waves/:id
     * Получить детали волны
     */
    public function getDetails(Request $request, string $id): JsonResponse
    {
        // Убираем избыточное логирование для быстрого получения деталей - логируем только ошибки
        try {
            $details = $this->waveService->getWaveDetails($id);

            if (!$details) {
                $response = [
                    'success' => false,
                    'error' => 'Волна не найдена'
                ];
                if (($_ENV['APP_DEBUG'] ?? '') === 'true') {
                    $response['debug'] = [
                        'wave_id' => $id,
                        'hint' => 'Проверьте подключение к БД (MG_DB_HOST) и наличие волны в таблице waves',
                    ];
                }
                return new JsonResponse($response, 404);
            }

            return new JsonResponse([
                'success' => true,
                'data' => $details
            ], 200);

        } catch (Exception $e) {
            // Логируем только ошибки
            WaveLogger::error("Ошибка в WaveController::getDetails", [
                'wave_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/waves/:id/status
     * Получить статус волны (быстрый запрос)
     */
    public function getStatus(string $id): JsonResponse
    {
        // Убираем избыточное логирование для быстрого статуса - логируем только ошибки
        try {
            $details = $this->waveService->getWaveDetails($id);

            if (!$details) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Волна не найдена'
                ], 404);
            }

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'status' => $details['wave']['status'],
                    'progress' => $details['wave']['progress'],
                ]
            ], 200);

        } catch (Exception $e) {
            WaveLogger::error("Ошибка получения статуса волны", [
                'wave_id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/waves/:id/reset-status
     * Сбросить статус волны и всех миграций на pending (разблокирует перезапуск).
     */
    public function resetStatus(Request $request, string $id): JsonResponse
    {
        try {
            $result = $this->waveService->resetWaveStatus($id);
            return new JsonResponse([
                'success' => $result['success'],
                'message' => $result['message'],
                'data' => $result,
            ], 200);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/waves/:id/migrations/:mb_uuid/restart
     * Перезапустить миграцию в волне
     */
    public function restartMigration(Request $request, string $id, string $mbUuid): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                $data = $request->request->all();
            }

            $result = $this->waveService->restartMigrationInWave($id, $mbUuid, $data);

            return new JsonResponse([
                'success' => true,
                'data' => $result['data'],
                'message' => 'Миграция успешно перезапущена'
            ], 200);

        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/waves/:id/migrations/:mb_uuid/logs
     * Получить логи миграции
     */
    public function getMigrationLogs(Request $request, string $id, string $mbUuid): JsonResponse
    {
        try {
            $details = $this->waveService->getWaveDetails($id);

            if (!$details) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Волна не найдена'
                ], 404);
            }

            // Находим миграцию в волне
            $migration = null;
            foreach ($details['migrations'] as $m) {
                if ($m['mb_project_uuid'] === $mbUuid) {
                    $migration = $m;
                    break;
                }
            }

            if (!$migration) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Миграция не найдена в волне'
                ], 404);
            }

            $brzProjectId = $migration['brz_project_id'] ?? 0;
            
            // Если brz_project_id = 0, логи еще не созданы
            if ($brzProjectId == 0) {
                return new JsonResponse([
                    'success' => true,
                    'data' => [
                        'logs' => ['Проект еще не создан, логи недоступны'],
                        'log_files' => [],
                        'brz_project_id' => 0,
                        'mb_uuid' => $mbUuid
                    ]
                ], 200);
            }

            // Получаем логи проекта в волне через новый метод
            try {
                $logs = $this->waveService->getProjectLogsInWave($id, $brzProjectId);
                
                return new JsonResponse([
                    'success' => true,
                    'data' => $logs
                ], 200);
            } catch (Exception $e) {
                // Если новый метод не нашел логи, пробуем старый метод
                $logs = $this->waveService->getMigrationLogs($id, $mbUuid, $brzProjectId);
                
                return new JsonResponse([
                    'success' => true,
                    'data' => $logs,
                    'note' => 'Использован старый метод получения логов'
                ], 200);
            }

        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DELETE /api/waves/:id/migrations/:mb_uuid/lock
     * Удалить lock-файл миграции
     */
    public function removeMigrationLock(Request $request, string $id, string $mbUuid): JsonResponse
    {
        try {
            $details = $this->waveService->getWaveDetails($id);

            if (!$details) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Волна не найдена'
                ], 404);
            }

            // Находим миграцию в волне
            $migration = null;
            foreach ($details['migrations'] as $m) {
                if ($m['mb_project_uuid'] === $mbUuid) {
                    $migration = $m;
                    break;
                }
            }

            if (!$migration) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Миграция не найдена в волне'
                ], 404);
            }

            $brzProjectId = $migration['brz_project_id'] ?? 0;
            
            if ($brzProjectId == 0) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Проект еще не создан, lock-файл отсутствует'
                ], 400);
            }

            // Удаляем lock-файл
            $result = $this->waveService->removeMigrationLock($mbUuid, $brzProjectId);

            return new JsonResponse([
                'success' => true,
                'data' => $result,
                'message' => $result['message']
            ], 200);

        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/waves/:id/mapping
     * Получить маппинг проектов для волны
     */
    public function getMapping(string $id): JsonResponse
    {
        try {
            $mapping = $this->waveService->getWaveMapping($id);

            return new JsonResponse([
                'success' => true,
                'data' => $mapping,
                'count' => count($mapping)
            ], 200);

        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/waves/:id/logs
     * Получить логи волны
     */
    public function getWaveLogs(Request $request, string $id): JsonResponse
    {
        try {
            $logs = $this->waveService->getWaveLogs($id);
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
     * GET /api/waves/:id/projects/:brz_project_id/logs
     * Получить логи проекта в волне по brz_project_id
     */
    public function getProjectLogs(Request $request, string $id, int $brzProjectId): JsonResponse
    {
        try {
            $logs = $this->waveService->getProjectLogsInWave($id, $brzProjectId);
            return new JsonResponse([
                'success' => true,
                'data' => $logs
            ], 200);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/waves/:id/restart-all
     * Массовый перезапуск миграций в волне
     */
    public function restartAllMigrations(Request $request, string $id): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                $data = $request->request->all();
            }

            // Опционально: список UUID для перезапуска (если пустой - все миграции)
            $mbUuids = $data['mb_uuids'] ?? [];
            if (!empty($mbUuids) && !is_array($mbUuids)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'mb_uuids должен быть массивом'
                ], 400);
            }

            $params = [];
            if (isset($data['mb_site_id'])) {
                $params['mb_site_id'] = $data['mb_site_id'];
            }
            if (isset($data['mb_secret'])) {
                $params['mb_secret'] = $data['mb_secret'];
            }
            if (isset($data['quality_analysis'])) {
                $params['quality_analysis'] = (bool)$data['quality_analysis'];
            }

            $result = $this->waveService->restartAllMigrationsInWave($id, $mbUuids, $params);

            return new JsonResponse([
                'success' => $result['success'],
                'message' => $result['message'],
                'data' => $result['results']
            ], 200);

        } catch (Exception $e) {
            $msg = $e->getMessage();
            $code = 500;
            if (strpos($msg, 'mb_site_id и mb_secret') !== false || strpos($msg, 'Не найдено миграций') !== false) {
                $code = 400;
            } elseif (strpos($msg, 'Волна не найдена') !== false || strpos($msg, 'Workspace ID не найден') !== false) {
                $code = 404;
            }
            return new JsonResponse([
                'success' => false,
                'error' => $msg
            ], $code);
        }
    }

    /**
     * PUT /api/waves/:id/mapping/:brz_project_id/cloning
     * Переключить параметр cloning_enabled для проекта
     */
    public function toggleCloning(Request $request, string $id, int $brzProjectId): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                $data = $request->request->all();
            }

            $cloningEnabled = isset($data['cloning_enabled']) 
                ? (bool)$data['cloning_enabled'] 
                : true;

            $result = $this->waveService->updateCloningEnabled($brzProjectId, $cloningEnabled);

            return new JsonResponse([
                'success' => true,
                'data' => $result,
                'message' => 'Параметр клонирования успешно обновлен'
            ], 200);

        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/waves/:id/review-token
     * Создать токен для публичного доступа к ревью волны
     */
    public function createReviewToken(Request $request, string $id): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                $data = $request->request->all();
            }

            $expiresInDays = isset($data['expires_in_days']) ? (int)$data['expires_in_days'] : null;
            $name = $data['name'] ?? null;
            $description = $data['description'] ?? null;
            $createdBy = $request->attributes->get('user_id'); // Из AuthMiddleware
            $settings = $data['settings'] ?? null;
            $projectSettings = $data['project_settings'] ?? null;

            $reviewService = new WaveReviewService();
            $tokenData = $reviewService->createReviewToken(
                $id, 
                $expiresInDays,
                $name,
                $description,
                $createdBy,
                $settings,
                $projectSettings
            );

            // Формируем URL для ревью
            $baseUrl = $request->getSchemeAndHttpHost();
            $reviewUrl = $baseUrl . '/review/' . $tokenData['token'];

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'id' => $tokenData['id'],
                    'token' => $tokenData['token'],
                    'review_url' => $reviewUrl,
                    'expires_in_days' => $expiresInDays
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
     * GET /api/waves/:id/review-tokens
     * Получить список токенов для волны
     */
    public function getReviewTokens(Request $request, string $id): JsonResponse
    {
        try {
            $reviewService = new WaveReviewService();
            $tokens = $reviewService->getWaveTokens($id);

            // Формируем полные URL для каждого токена
            $baseUrl = $request->getSchemeAndHttpHost();
            foreach ($tokens as &$token) {
                $token['review_url'] = $baseUrl . '/review/' . $token['token'];
            }
            unset($token);

            return new JsonResponse([
                'success' => true,
                'data' => $tokens
            ], 200);

        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * PUT /api/waves/:id/review-tokens/:tokenId
     * Обновить токен
     */
    public function updateReviewToken(Request $request, string $id, int $tokenId): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            $reviewService = new WaveReviewService();
            $updated = $reviewService->updateToken($tokenId, $data);
            
            if ($updated) {
                return new JsonResponse([
                    'success' => true,
                    'message' => 'Токен обновлен'
                ], 200);
            }
            
            return new JsonResponse([
                'success' => false,
                'error' => 'Не удалось обновить токен'
            ], 500);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DELETE /api/waves/:id/review-tokens/:tokenId
     * Удалить токен
     */
    public function deleteReviewToken(Request $request, string $id, int $tokenId): JsonResponse
    {
        try {
            $reviewService = new WaveReviewService();
            $deleted = $reviewService->deleteToken($tokenId);
            
            if ($deleted) {
                return new JsonResponse([
                    'success' => true,
                    'message' => 'Токен удален'
                ], 200);
            }
            
            return new JsonResponse([
                'success' => false,
                'error' => 'Не удалось удалить токен'
            ], 500);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * PUT /api/waves/:id/review-tokens/:tokenId/projects/:mbUuid
     * Обновить настройки доступа для проекта
     */
    public function updateProjectAccess(Request $request, string $id, int $tokenId, string $mbUuid): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            $reviewService = new WaveReviewService();
            $updated = $reviewService->updateProjectAccess($tokenId, $mbUuid, $data);
            
            if ($updated) {
                return new JsonResponse([
                    'success' => true,
                    'message' => 'Настройки доступа обновлены'
                ], 200);
            }
            
            return new JsonResponse([
                'success' => false,
                'error' => 'Не удалось обновить настройки доступа'
            ], 500);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
