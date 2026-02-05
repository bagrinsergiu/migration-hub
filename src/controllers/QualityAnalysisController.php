<?php

namespace Dashboard\Controllers;

use Dashboard\Services\QualityAnalysisService;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class QualityAnalysisController
{
    /**
     * @var QualityAnalysisService
     */
    private $qualityService;

    public function __construct()
    {
        $this->qualityService = new QualityAnalysisService();
    }

    /**
     * GET /api/migrations/:id/quality-analysis
     * Получить список анализов качества для миграции
     */
    public function getAnalysisList(Request $request, int $migrationId): JsonResponse
    {
        try {
            $reports = $this->qualityService->getReportsByMigration($migrationId);
            
            return new JsonResponse([
                'success' => true,
                'data' => $reports,
                'count' => count($reports)
            ], 200);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/migrations/:id/quality-analysis/statistics
     * Получить статистику по анализу качества миграции
     */
    public function getStatistics(Request $request, int $migrationId): JsonResponse
    {
        try {
            $statistics = $this->qualityService->getMigrationStatistics($migrationId);
            
            // Всегда возвращаем успешный ответ, даже если статистика пустая
            // Это позволяет фронтенду отображать плитки с нулевыми значениями
            return new JsonResponse([
                'success' => true,
                'data' => $statistics
            ], 200);
        } catch (Exception $e) {
            error_log("Error getting quality statistics for migration $migrationId: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            
            // Возвращаем пустую статистику вместо ошибки, чтобы плитки все равно отображались
            return new JsonResponse([
                'success' => true,
                'data' => [
                    'total_pages' => 0,
                    'avg_quality_score' => null,
                    'by_severity' => [
                        'critical' => 0,
                        'high' => 0,
                        'medium' => 0,
                        'low' => 0,
                        'none' => 0
                    ],
                    'token_statistics' => [
                        'total_prompt_tokens' => 0,
                        'total_completion_tokens' => 0,
                        'total_tokens' => 0,
                        'avg_tokens_per_page' => 0,
                        'total_cost_usd' => 0,
                        'avg_cost_per_page_usd' => 0
                    ]
                ],
                'error' => $e->getMessage() // Сохраняем ошибку для отладки
            ], 200);
        }
    }

    /**
     * GET /api/migrations/:id/quality-analysis/:pageSlug
     * Получить детали анализа конкретной страницы
     */
    public function getPageAnalysis(Request $request, int $migrationId, string $pageSlug): JsonResponse
    {
        try {
            // Проверяем параметр include_archived из query string
            $includeArchived = $request->query->getBoolean('include_archived', false);
            $report = $this->qualityService->getReportBySlug($migrationId, $pageSlug, $includeArchived);
            
            if (!$report) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Анализ для страницы не найден'
                ], 404);
            }
            
            return new JsonResponse([
                'success' => true,
                'data' => $report
            ], 200);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/migrations/:id/quality-analysis/archived
     * Получить список архивных анализов качества для миграции
     */
    public function getArchivedAnalysisList(Request $request, int $migrationId): JsonResponse
    {
        try {
            $reports = $this->qualityService->getArchivedReportsByMigration($migrationId);
            
            return new JsonResponse([
                'success' => true,
                'data' => $reports,
                'count' => count($reports)
            ], 200);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/migrations/:id/quality-analysis/:pageSlug/screenshots/:type
     * Получить скриншот страницы
     * type: 'source' или 'migrated'
     */
    public function getScreenshot(Request $request, int $migrationId, string $pageSlug, string $type): JsonResponse
    {
        try {
            $report = $this->qualityService->getReportBySlug($migrationId, $pageSlug);
            
            if (!$report || empty($report['screenshots_path'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Скриншот не найден'
                ], 404);
            }
            
            $screenshots = $report['screenshots_path'];
            $screenshotPath = null;
            
            if ($type === 'source' && isset($screenshots['source'])) {
                $screenshotPath = $screenshots['source'];
            } elseif ($type === 'migrated' && isset($screenshots['migrated'])) {
                $screenshotPath = $screenshots['migrated'];
            } else {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Неверный тип скриншота. Используйте: source или migrated'
                ], 400);
            }
            
            if (!file_exists($screenshotPath)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Файл скриншота не найден'
                ], 404);
            }
            
            // Возвращаем путь к скриншоту (фронтенд будет загружать его напрямую)
            // Или можно вернуть base64, но это будет тяжело для больших файлов
            return new JsonResponse([
                'success' => true,
                'data' => [
                    'path' => $screenshotPath,
                    'url' => '/dashboard/api/screenshots/' . basename($screenshotPath),
                    'exists' => true,
                    'size' => filesize($screenshotPath)
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
     * POST /api/migrations/:id/quality-analysis/:pageSlug/reanalyze
     * Перезапустить анализ качества для конкретной страницы
     */
    public function reanalyzePage(Request $request, int $migrationId, string $pageSlug): JsonResponse
    {
        try {
            $result = $this->qualityService->reanalyzePage($migrationId, $pageSlug);
            
            return new JsonResponse([
                'success' => true,
                'data' => $result,
                'message' => 'Анализ страницы перезапущен'
            ], 200);
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();
            $errorTrace = $e->getTraceAsString();
            
            error_log("Error reanalyzing page: " . $errorMessage);
            error_log("File: " . $errorFile . " Line: " . $errorLine);
            error_log("Stack trace: " . $errorTrace);
            
            // Возвращаем более детальную информацию об ошибке
            return new JsonResponse([
                'success' => false,
                'error' => $errorMessage,
                'details' => [
                    'migration_id' => $migrationId,
                    'page_slug' => $pageSlug,
                    'file' => basename($errorFile),
                    'line' => $errorLine,
                    'type' => get_class($e)
                ]
            ], 500);
        } catch (Exception $e) {
            error_log("Error reanalyzing page (Exception): " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'details' => [
                    'migration_id' => $migrationId,
                    'page_slug' => $pageSlug,
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    /**
     * GET /api/migrations/:id/pages
     * Получить список всех страниц миграции
     */
    public function getPagesList(Request $request, int $migrationId): JsonResponse
    {
        try {
            $pages = $this->qualityService->getPagesList($migrationId);
            
            return new JsonResponse([
                'success' => true,
                'data' => $pages,
                'count' => count($pages)
            ], 200);
        } catch (Exception $e) {
            error_log("Error getting pages list for migration $migrationId: " . $e->getMessage());
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
