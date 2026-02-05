<?php

namespace Dashboard\Controllers;

use Dashboard\Services\TestMigrationService;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class TestMigrationController
{
    /**
     * @var TestMigrationService
     */
    private $testMigrationService;

    public function __construct()
    {
        $this->testMigrationService = new TestMigrationService();
    }

    /**
     * GET /api/test-migrations
     * Получить список тестовых миграций
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

            $migrations = $this->testMigrationService->getTestMigrationsList($filters);

            return new JsonResponse([
                'success' => true,
                'data' => $migrations,
                'count' => count($migrations)
            ], 200);
        } catch (\PDOException $e) {
            // Логируем полную ошибку для отладки
            error_log("TestMigrationController::list PDOException: " . $e->getMessage());
            error_log("PDO Error Code: " . $e->getCode());
            error_log("PDO Error Info: " . print_r($e->errorInfo ?? [], true));
            error_log("Stack trace: " . $e->getTraceAsString());
            
            $errorMessage = $e->getMessage();
            if (strpos($errorMessage, "doesn't exist") !== false || 
                strpos($errorMessage, "Unknown table") !== false ||
                $e->getCode() == '42S02') {
                $errorMessage = 'Таблица test_migrations не найдена. Необходимо выполнить миграцию базы данных: vendor/bin/phinx migrate';
            }
            
            return new JsonResponse([
                'success' => false,
                'error' => $errorMessage,
                'details' => [
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine(),
                    'code' => $e->getCode(),
                    'error_info' => $e->errorInfo ?? null
                ]
            ], 500);
        } catch (Exception $e) {
            // Логируем полную ошибку для отладки
            error_log("TestMigrationController::list Exception: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'details' => [
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    /**
     * GET /api/test-migrations/:id
     * Получить детали тестовой миграции
     */
    public function getDetails(Request $request, int $id): JsonResponse
    {
        try {
            $details = $this->testMigrationService->getTestMigrationDetails($id);

            if (!$details) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Тестовая миграция не найдена'
                ], 404);
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
     * POST /api/test-migrations
     * Создать новую тестовую миграцию
     */
    public function create(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                $data = $request->request->all();
            }

            // Валидация обязательных полей
            $required = ['mb_project_uuid', 'brz_project_id'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => "Обязательное поле отсутствует: {$field}"
                    ], 400);
                }
            }

            $result = $this->testMigrationService->createTestMigration($data);

            return new JsonResponse([
                'success' => true,
                'data' => $result
            ], 201);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * PUT /api/test-migrations/:id
     * Обновить тестовую миграцию
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                $data = $request->request->all();
            }

            $result = $this->testMigrationService->updateTestMigration($id, $data);

            return new JsonResponse([
                'success' => true,
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
     * DELETE /api/test-migrations/:id
     * Удалить тестовую миграцию
     */
    public function delete(Request $request, int $id): JsonResponse
    {
        try {
            $this->testMigrationService->deleteTestMigration($id);

            return new JsonResponse([
                'success' => true,
                'message' => 'Тестовая миграция удалена'
            ], 200);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/test-migrations/:id/run
     * Запустить тестовую миграцию
     */
    public function run(Request $request, int $id): JsonResponse
    {
        try {
            $result = $this->testMigrationService->runTestMigration($id);

            return new JsonResponse([
                'success' => $result['success'],
                'data' => $result['data'] ?? null,
                'http_code' => $result['http_code'] ?? 200
            ], $result['http_code'] ?? 200);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/test-migrations/:id/reset-status
     * Сбросить статус тестовой миграции на pending
     */
    public function resetStatus(Request $request, int $id): JsonResponse
    {
        try {
            $result = $this->testMigrationService->resetTestMigrationStatus($id);

            return new JsonResponse([
                'success' => $result['success'],
                'data' => $result,
                'message' => $result['message'] ?? 'Статус сброшен'
            ], 200);
        } catch (Exception $e) {
            error_log("TestMigrationController::resetStatus error: " . $e->getMessage());
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
