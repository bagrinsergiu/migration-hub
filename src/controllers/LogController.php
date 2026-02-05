<?php

namespace Dashboard\Controllers;

use Dashboard\Services\ApiProxyService;
use Dashboard\Services\DatabaseService;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class LogController
{
    /** @var ApiProxyService */
    private $apiProxy;
    /** @var DatabaseService */
    private $dbService;

    public function __construct()
    {
        $this->apiProxy = new ApiProxyService();
        $this->dbService = new DatabaseService();
    }

    /**
     * GET /api/logs/:brz_project_id
     * Получить логи миграции
     */
    public function getLogs(Request $request, int $brzProjectId): JsonResponse
    {
        try {
            $result = $this->apiProxy->getMigrationLogs($brzProjectId);

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
     * GET /api/logs/recent
     * Получить последние логи
     */
    public function getRecent(Request $request): JsonResponse
    {
        try {
            $limit = (int)($request->query->get('limit') ?? 10);
            $results = $this->dbService->getMigrationResults($limit);

            $logs = [];
            foreach ($results as $result) {
                $resultData = json_decode($result['result_json'] ?? '{}', true);
                $logs[] = [
                    'mb_project_uuid' => $result['mb_project_uuid'],
                    'brz_project_id' => $result['brz_project_id'],
                    'migration_uuid' => $result['migration_uuid'],
                    'status' => $resultData['status'] ?? 'unknown',
                    'created_at' => $result['migration_uuid'] ?? null, // Используем migration_uuid как timestamp
                ];
            }

            return new JsonResponse([
                'success' => true,
                'data' => $logs,
                'count' => count($logs)
            ], 200);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
