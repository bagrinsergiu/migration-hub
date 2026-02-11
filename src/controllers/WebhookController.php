<?php

namespace Dashboard\Controllers;

use Dashboard\Services\DatabaseService;
use Dashboard\Services\GoogleSheetsService;
use Dashboard\Services\MigrationService;
use Dashboard\Services\WaveService;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class WebhookController
{
    /**
     * POST /api/webhooks/migration-result
     * Принять результат миграции от сервера миграции через веб-хук
     */
    public function migrationResult(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                $data = $request->request->all();
            }
            
            // Валидация обязательных полей
            if (empty($data['mb_project_uuid']) || empty($data['brz_project_id'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Обязательные поля отсутствуют: mb_project_uuid, brz_project_id'
                ], 400);
            }
            
            $mbProjectUuid = $data['mb_project_uuid'];
            $brzProjectId = (int)$data['brz_project_id'];
            
            error_log("[MIG] WebhookController::migrationResult — получен веб-хук: mb_project_uuid={$mbProjectUuid}, brz_project_id={$brzProjectId}, status=" . ($data['status'] ?? 'n/a'));
            
            $dbService = new DatabaseService();
            $migrationService = new MigrationService();
            
            // Определяем статус миграции
            $status = 'completed';
            if (isset($data['status'])) {
                $status = $data['status'];
                // Нормализуем статус
                if ($status === 'success') {
                    $status = 'completed';
                } elseif ($status === 'failed' || $status === 'error') {
                    $status = 'error';
                }
            } elseif (isset($data['error'])) {
                $status = 'error';
            }
            
            // Формируем метаданные для обновления
            $metaData = [
                'status' => $status,
                'completed_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            
            // Добавляем данные из результата миграции
            if (isset($data['brizy_project_id'])) {
                $metaData['brizy_project_id'] = $data['brizy_project_id'];
            }
            if (isset($data['brizy_project_domain'])) {
                $metaData['brizy_project_domain'] = $data['brizy_project_domain'];
            }
            if (isset($data['migration_id'])) {
                $metaData['migration_id'] = $data['migration_id'];
            }
            if (isset($data['date'])) {
                $metaData['date'] = $data['date'];
            }
            if (isset($data['theme'])) {
                $metaData['theme'] = $data['theme'];
            }
            if (isset($data['mb_product_name'])) {
                $metaData['mb_product_name'] = $data['mb_product_name'];
            }
            if (isset($data['mb_site_id'])) {
                $metaData['mb_site_id'] = $data['mb_site_id'];
            }
            if (isset($data['mb_project_domain'])) {
                $metaData['mb_project_domain'] = $data['mb_project_domain'];
            }
            if (isset($data['progress'])) {
                $metaData['progress'] = is_array($data['progress']) ? json_encode($data['progress']) : $data['progress'];
            }
            if (isset($data['error'])) {
                $metaData['error'] = is_string($data['error']) ? $data['error'] : json_encode($data['error']);
            }
            
            // Обновляем статус в migrations_mapping
            $dbService->upsertMigrationMapping($brzProjectId, $mbProjectUuid, $metaData);
            
            // Сохраняем результат в migration_result_list и в migrations
            $migrationUuid = $data['migration_uuid'] ?? time() . random_int(100, 999);
            // Для миграций от волны сервер присылает migration_uuid = wave_id (формат "timestamp_random")
            $waveIdForSave = (isset($data['migration_uuid']) && preg_match('/^\d+_\d+$/', (string)$data['migration_uuid']))
                ? $data['migration_uuid']
                : null;
            $resultJson = $data;
            // Убираем служебные поля из result_json
            unset($resultJson['mb_project_uuid'], $resultJson['brz_project_id'], $resultJson['migration_uuid']);
            
            // Добавляем статус в result_json, чтобы saveMigration правильно его определил
            $resultJson['status'] = $status;
            
            $savePayload = [
                'migration_uuid' => $migrationUuid,
                'brz_project_id' => $brzProjectId,
                'brizy_project_domain' => $data['brizy_project_domain'] ?? '',
                'mb_project_uuid' => $mbProjectUuid,
                'result_json' => json_encode($resultJson),
                'status' => $status, // Передаем статус явно
            ];
            if ($waveIdForSave !== null) {
                $savePayload['wave_id'] = $waveIdForSave;
            }
            $dbService->saveMigrationResult($savePayload);
            
            error_log("[WebhookController::migrationResult] Статус миграции обновлен: status={$status}, mb_project_uuid={$mbProjectUuid}, brz_project_id={$brzProjectId}");
            
            // Пересчитываем прогресс волны, если это миграция из волны
            if ($waveIdForSave !== null) {
                try {
                    $waveService = new WaveService();
                    $waveService->recalculateWaveProgress($waveIdForSave);
                    error_log("[WebhookController::migrationResult] Прогресс волны пересчитан: wave_id={$waveIdForSave}");
                } catch (Exception $e) {
                    error_log("[WebhookController::migrationResult] Ошибка пересчета прогресса волны: " . $e->getMessage());
                }
            }
            
            if ($status === 'completed' && !empty($data['brizy_project_domain'])) {
                try {
                    $googleSheetsService = new GoogleSheetsService();
                    $googleSheetsService->updateWebsiteBrizyForMigration($mbProjectUuid, $data['brizy_project_domain']);
                } catch (Exception $e) {
                    error_log("[WebhookController::migrationResult] updateWebsiteBrizyForMigration: " . $e->getMessage());
                }
            }
            
            return new JsonResponse([
                'success' => true,
                'message' => 'Результат миграции успешно обработан',
                'data' => [
                    'mb_project_uuid' => $mbProjectUuid,
                    'brz_project_id' => $brzProjectId,
                    'status' => $status
                ]
            ], 200);
            
        } catch (Exception $e) {
            error_log("[WebhookController::migrationResult] Ошибка: " . $e->getMessage());
            error_log("[WebhookController::migrationResult] Stack trace: " . $e->getTraceAsString());
            
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/webhooks/test-connection
     * Тестовый эндпоинт: сервер миграции может вызвать GET, чтобы убедиться, что достучался до дашборда.
     * Ответ содержит идентификатор сервиса (migration-dashboard) и время.
     */
    public function testConnectionGet(Request $request): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'service' => 'migration-dashboard',
            'message' => 'Dashboard is reachable',
            'endpoint' => 'GET /api/webhooks/test-connection',
            'timestamp' => date('c'),
        ], 200);
    }

    /**
     * POST /api/webhooks/test-connection
     * Тестовый эндпоинт: сервер миграции отправляет POST с телом {"source": "migration_server"} (или любым JSON).
     * Дашборд отвечает, что это он, и возвращает полученные данные — обе стороны убеждаются, что связь есть.
     */
    public function testConnectionPost(Request $request): JsonResponse
    {
        $content = $request->getContent();
        $payload = $content ? json_decode($content, true) : [];
        if (!is_array($payload)) {
            $payload = [];
        }
        return new JsonResponse([
            'success' => true,
            'dashboard' => 'migration-dashboard',
            'message' => 'Dashboard received your request',
            'received_from' => $payload['source'] ?? 'unknown',
            'your_payload' => $payload,
            'endpoint' => 'POST /api/webhooks/test-connection',
            'timestamp' => date('c'),
        ], 200);
    }
}
