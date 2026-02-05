<?php

namespace MBMigration\Analysis;

use Exception;
use MBMigration\Layer\DataSource\driver\MySQL;
use MBMigration\Core\Logger;

/**
 * QualityReport
 * 
 * Класс для сохранения и получения отчетов о качестве миграции
 */
class QualityReport
{
    /**
     * @var MySQL
     */
    private $db;

    public function __construct()
    {
        // Используем существующее подключение к БД миграций
        // ВАЖНО: Записываем только в базу mb-migration.cupzc9ey0cip.us-east-1.rds.amazonaws.com
        $this->db = new MySQL(
            $_ENV['MG_DB_USER'] ?? 'admin',
            $_ENV['MG_DB_PASS'] ?? '',
            $_ENV['MG_DB_NAME'] ?? 'MG_prepare_mapping',
            $_ENV['MG_DB_HOST'] ?? 'mb-migration.cupzc9ey0cip.us-east-1.rds.amazonaws.com'
        );
        $this->db->doConnect();
        
        // Проверяем и создаем таблицу если её нет
        $this->ensureTableExists();
    }

    /**
     * Проверить существование таблицы и создать если её нет
     */
    private function ensureTableExists(): void
    {
        try {
            // Проверяем существует ли таблица
            $checkTable = $this->db->getAllRows(
                "SELECT COUNT(*) as cnt FROM information_schema.tables 
                 WHERE table_schema = DATABASE() 
                 AND table_name = 'page_quality_analysis'"
            );
            
            if (empty($checkTable) || (isset($checkTable[0]['cnt']) && $checkTable[0]['cnt'] == 0)) {
                Logger::instance()->info("[Quality Analysis] Table page_quality_analysis does not exist, creating it...");
                $this->createTable();
                Logger::instance()->info("[Quality Analysis] Table page_quality_analysis created successfully");
            } else {
                // Проверяем и обновляем ENUM для analysis_status если нужно
                $this->updateAnalysisStatusEnum();
            }
        } catch (Exception $e) {
            Logger::instance()->warning("[Quality Analysis] Could not check/create table, will try to create on first insert", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Обновить ENUM для analysis_status чтобы добавить 'archived'
     */
    private function updateAnalysisStatusEnum(): void
    {
        try {
            // Проверяем текущий ENUM
            $enumCheck = $this->db->getAllRows(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS 
                 WHERE TABLE_SCHEMA = DATABASE() 
                 AND TABLE_NAME = 'page_quality_analysis' 
                 AND COLUMN_NAME = 'analysis_status'"
            );
            
            if (!empty($enumCheck) && isset($enumCheck[0]['COLUMN_TYPE'])) {
                $currentEnum = $enumCheck[0]['COLUMN_TYPE'];
                // Проверяем есть ли 'archived' в ENUM
                if (strpos($currentEnum, 'archived') === false) {
                    Logger::instance()->info("[Quality Analysis] Updating analysis_status ENUM to include 'archived'");
                    $this->db->getAllRows(
                        "ALTER TABLE page_quality_analysis 
                         MODIFY COLUMN analysis_status ENUM('pending', 'analyzing', 'completed', 'error', 'archived') DEFAULT 'pending'"
                    );
                    Logger::instance()->info("[Quality Analysis] analysis_status ENUM updated successfully");
                }
            }
        } catch (Exception $e) {
            Logger::instance()->warning("[Quality Analysis] Could not update analysis_status ENUM", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Создать таблицу page_quality_analysis
     */
    private function createTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `page_quality_analysis` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `migration_id` INT NULL COMMENT 'ID миграции (brz_project_id)',
            `mb_project_uuid` VARCHAR(255) NULL COMMENT 'UUID проекта MB',
            `page_slug` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Slug страницы',
            `source_url` TEXT NULL COMMENT 'URL исходной страницы (MB)',
            `migrated_url` TEXT NULL COMMENT 'URL мигрированной страницы (Brizy)',
            `analysis_status` ENUM('pending', 'analyzing', 'completed', 'error', 'archived') DEFAULT 'pending' COMMENT 'Статус анализа',
            `quality_score` INT(3) NULL COMMENT 'Оценка качества (0-100)',
            `severity_level` ENUM('critical', 'high', 'medium', 'low', 'none') DEFAULT 'none' COMMENT 'Уровень критичности проблем',
            `issues_summary` TEXT NULL COMMENT 'Краткое описание проблем (JSON)',
            `detailed_report` TEXT NULL COMMENT 'Детальный отчет от AI (JSON)',
            `screenshots_path` TEXT NULL COMMENT 'Путь к скриншотам (JSON)',
            `html_diff_path` TEXT NULL COMMENT 'Путь к HTML diff файлу',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_migration_id` (`migration_id`),
            INDEX `idx_mb_project_uuid` (`mb_project_uuid`),
            INDEX `idx_page_slug` (`page_slug`),
            INDEX `idx_severity_level` (`severity_level`),
            INDEX `idx_quality_score` (`quality_score`),
            INDEX `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        // Используем getAllRows для выполнения CREATE TABLE (DDL запрос)
        // Для DDL запросов это вернет пустой массив, но выполнит запрос
        try {
            $this->db->getAllRows($sql);
            Logger::instance()->info("[Quality Analysis] CREATE TABLE query executed successfully");
        } catch (Exception $e) {
            Logger::instance()->error("[Quality Analysis] Error executing CREATE TABLE", [
                'error' => $e->getMessage(),
                'sql' => substr($sql, 0, 200) . '...'
            ]);
            throw $e;
        }
    }

    /**
     * Сохранить отчет о качестве миграции
     * 
     * @param array $reportData Данные отчета
     * @return int ID сохраненной записи
     * @throws Exception
     */
    public function saveReport(array $reportData): int
    {
        try {
            // Проверяем и создаем таблицу если её нет (на случай если не создалась в конструкторе)
            $this->ensureTableExists();
            
            $data = [
                'migration_id' => $reportData['migration_id'] ?? null,
                'mb_project_uuid' => $reportData['mb_project_uuid'] ?? null,
                'page_slug' => $reportData['page_slug'] ?? '',
                'source_url' => $reportData['source_url'] ?? '',
                'migrated_url' => $reportData['migrated_url'] ?? '',
                'analysis_status' => $reportData['analysis_status'] ?? 'completed',
                'quality_score' => $reportData['quality_score'] ?? null,
                'severity_level' => $reportData['severity_level'] ?? 'none',
                'issues_summary' => json_encode($reportData['issues_summary'] ?? []),
                'detailed_report' => json_encode($reportData['detailed_report'] ?? []),
                'screenshots_path' => $reportData['screenshots_path'] ?? null,
                'html_diff_path' => $reportData['html_diff_path'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            Logger::instance()->debug("[Quality Analysis] Inserting report into database", [
                'db_host' => $_ENV['MG_DB_HOST'] ?? 'mb-migration.cupzc9ey0cip.us-east-1.rds.amazonaws.com',
                'db_name' => $_ENV['MG_DB_NAME'] ?? 'MG_prepare_mapping',
                'migration_id' => $reportData['migration_id'] ?? null,
                'page_slug' => $reportData['page_slug'] ?? '',
                'quality_score' => $reportData['quality_score'] ?? null,
                'severity_level' => $reportData['severity_level'] ?? null
            ]);
            
            $reportId = $this->db->insert('page_quality_analysis', $data);

            // Декодируем screenshots_path для логирования
            $screenshotsInfo = [];
            if (!empty($reportData['screenshots_path'])) {
                if (is_string($reportData['screenshots_path'])) {
                    $screenshotsInfo = json_decode($reportData['screenshots_path'], true) ?? [];
                } else {
                    $screenshotsInfo = $reportData['screenshots_path'];
                }
            }
            
            Logger::instance()->info("[Quality Analysis] Quality report saved to database", [
                'report_id' => $reportId,
                'page_slug' => $reportData['page_slug'] ?? '',
                'migration_id' => $reportData['migration_id'] ?? null,
                'quality_score' => $reportData['quality_score'] ?? null,
                'severity_level' => $reportData['severity_level'] ?? null,
                'analysis_status' => $reportData['analysis_status'] ?? 'unknown',
                'screenshots' => [
                    'source_path' => $screenshotsInfo['source'] ?? null,
                    'source_exists' => isset($screenshotsInfo['source']) && file_exists($screenshotsInfo['source']),
                    'migrated_path' => $screenshotsInfo['migrated'] ?? null,
                    'migrated_exists' => isset($screenshotsInfo['migrated']) && file_exists($screenshotsInfo['migrated'])
                ]
            ]);

            return (int)$reportId;

        } catch (Exception $e) {
            Logger::instance()->error("Error saving quality report", [
                'error' => $e->getMessage(),
                'report_data' => $reportData
            ]);
            throw $e;
        }
    }

    /**
     * Обновить статус анализа
     */
    public function updateStatus(int $reportId, string $status): void
    {
        try {
            $sql = "UPDATE page_quality_analysis 
                    SET analysis_status = :status, updated_at = NOW() 
                    WHERE id = :id";
            
            // Используем прямой SQL запрос через getAllRows (он выполняет UPDATE тоже)
            $this->db->getAllRows($sql, [
                ':status' => $status,
                ':id' => $reportId
            ]);
        } catch (Exception $e) {
            Logger::instance()->error("Error updating report status", [
                'report_id' => $reportId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Получить отчеты по миграции
     * 
     * @param int $migrationId ID миграции
     * @return array Массив отчетов
     */
    public function getReportsByMigration(int $migrationId): array
    {
        try {
            // Получаем только последние записи для каждой страницы (исключая архивные)
            // Используем подзапрос для получения максимального id для каждой страницы
            // Это обеспечивает согласованность с getMigrationStatistics
            $sql = "SELECT pqa.* FROM page_quality_analysis pqa
                    INNER JOIN (
                        SELECT page_slug, MAX(id) as max_id
                        FROM page_quality_analysis
                        WHERE migration_id = :migration_id
                        AND (analysis_status IS NULL OR analysis_status != 'archived')
                        GROUP BY page_slug
                    ) latest ON pqa.page_slug = latest.page_slug AND pqa.id = latest.max_id
                    WHERE pqa.migration_id = :migration_id
                    AND (pqa.analysis_status IS NULL OR pqa.analysis_status != 'archived')
                    ORDER BY pqa.created_at DESC";
            
            $reports = $this->db->getAllRows($sql, [':migration_id' => $migrationId]);
            
            // Декодируем JSON поля
            foreach ($reports as &$report) {
                try {
                    $report['issues_summary'] = json_decode($report['issues_summary'] ?? '[]', true) ?? [];
                } catch (Exception $e) {
                    $report['issues_summary'] = [];
                }
                
                try {
                    $report['detailed_report'] = json_decode($report['detailed_report'] ?? '{}', true) ?? [];
                    // Извлекаем token_usage из detailed_report для удобства доступа
                    if (isset($report['detailed_report']['token_usage'])) {
                        $report['token_usage'] = $report['detailed_report']['token_usage'];
                    }
                } catch (Exception $e) {
                    $report['detailed_report'] = [];
                }
                
                // Декодируем screenshots_path
                if (!empty($report['screenshots_path'])) {
                    try {
                        $decoded = json_decode($report['screenshots_path'], true);
                        $report['screenshots_path'] = is_array($decoded) ? $decoded : [];
                    } catch (Exception $e) {
                        $report['screenshots_path'] = [];
                    }
                } else {
                    $report['screenshots_path'] = [];
                }
            }
            
            return $reports;
        } catch (Exception $e) {
            try {
                Logger::instance()->error("Error getting reports", [
                    'migration_id' => $migrationId,
                    'error' => $e->getMessage()
                ]);
            } catch (Exception $logError) {
                // Logger не инициализирован, используем error_log
                error_log("Quality Analysis Error getting reports for migration $migrationId: " . $e->getMessage());
            }
            return [];
        }
    }

    /**
     * Получить отчет по slug страницы
     * 
     * @param int $migrationId ID миграции
     * @param string $pageSlug Slug страницы
     * @param bool $includeArchived Включить архивные отчеты
     * @return array|null
     */
    public function getReportBySlug(int $migrationId, string $pageSlug, bool $includeArchived = false): ?array
    {
        try {
            $statusFilter = $includeArchived ? '' : "AND analysis_status != 'archived'";
            $sql = "SELECT * FROM page_quality_analysis 
                    WHERE migration_id = :migration_id AND page_slug = :page_slug 
                    {$statusFilter}
                    ORDER BY created_at DESC LIMIT 1";
            
            $report = $this->db->find($sql, [
                ':migration_id' => $migrationId,
                ':page_slug' => $pageSlug
            ]);
            
            if ($report) {
                $report['issues_summary'] = json_decode($report['issues_summary'] ?? '[]', true);
                try {
                    $report['detailed_report'] = json_decode($report['detailed_report'] ?? '{}', true) ?? [];
                    // Извлекаем token_usage из detailed_report для удобства доступа
                    if (isset($report['detailed_report']['token_usage'])) {
                        $report['token_usage'] = $report['detailed_report']['token_usage'];
                    }
                } catch (Exception $e) {
                    $report['detailed_report'] = [];
                }
                // Декодируем screenshots_path
                if (!empty($report['screenshots_path'])) {
                    $report['screenshots_path'] = json_decode($report['screenshots_path'], true) ?? [];
                } else {
                    $report['screenshots_path'] = [];
                }
            }
            
            return $report ?: null;
        } catch (Exception $e) {
            Logger::instance()->error("Error getting report by slug", [
                'migration_id' => $migrationId,
                'page_slug' => $pageSlug,
                'include_archived' => $includeArchived,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Получить статистику по миграции
     */
    public function getMigrationStatistics(int $migrationId): array
    {
        try {
            // Сначала проверяем, есть ли вообще записи для этой миграции
            // Проверяем все записи, включая архивные, чтобы понять, есть ли данные вообще
            $checkSqlAll = "SELECT COUNT(*) as cnt FROM page_quality_analysis WHERE migration_id = :migration_id";
            $checkResultAll = $this->db->find($checkSqlAll, [':migration_id' => $migrationId]);
            $totalRecords = $checkResultAll !== false && isset($checkResultAll['cnt']) ? (int)$checkResultAll['cnt'] : 0;
            
            // Теперь проверяем неархивные записи
            $checkSql = "SELECT COUNT(*) as cnt FROM page_quality_analysis 
                        WHERE migration_id = :migration_id 
                        AND (analysis_status IS NULL OR analysis_status != 'archived')";
            $checkResult = $this->db->find($checkSql, [':migration_id' => $migrationId]);
            $hasRecords = $checkResult !== false && isset($checkResult['cnt']) && (int)$checkResult['cnt'] > 0;
            
            // Если есть записи, но все заархивированы, все равно возвращаем пустую статистику
            if ($totalRecords > 0 && !$hasRecords) {
                try {
                    Logger::instance()->info("[Quality Analysis] All records are archived for migration", [
                        'migration_id' => $migrationId,
                        'total_records' => $totalRecords
                    ]);
                } catch (Exception $logError) {
                    error_log("Quality Analysis: All records are archived for migration $migrationId (total: $totalRecords)");
                }
            }
            
            if (!$hasRecords) {
                try {
                    Logger::instance()->info("[Quality Analysis] No records found for migration", [
                        'migration_id' => $migrationId
                    ]);
                } catch (Exception $logError) {
                    // Logger не инициализирован, игнорируем
                }
                return [
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
                ];
            }
            
            // Получаем только последние записи для каждой страницы (исключая архивные)
            // Используем подзапрос для получения максимального id для каждой страницы
            $sql = "SELECT 
                    COUNT(DISTINCT pqa.page_slug) as total_pages,
                    AVG(pqa.quality_score) as avg_quality_score,
                    SUM(CASE WHEN pqa.severity_level = 'critical' THEN 1 ELSE 0 END) as critical_count,
                    SUM(CASE WHEN pqa.severity_level = 'high' THEN 1 ELSE 0 END) as high_count,
                    SUM(CASE WHEN pqa.severity_level = 'medium' THEN 1 ELSE 0 END) as medium_count,
                    SUM(CASE WHEN pqa.severity_level = 'low' THEN 1 ELSE 0 END) as low_count,
                    SUM(CASE WHEN pqa.severity_level = 'none' THEN 1 ELSE 0 END) as none_count
                    FROM page_quality_analysis pqa
                    INNER JOIN (
                        SELECT page_slug, MAX(id) as max_id
                        FROM page_quality_analysis
                        WHERE migration_id = :migration_id
                        AND (analysis_status IS NULL OR analysis_status != 'archived')
                        GROUP BY page_slug
                    ) latest ON pqa.page_slug = latest.page_slug AND pqa.id = latest.max_id
                    WHERE pqa.migration_id = :migration_id
                    AND (pqa.analysis_status IS NULL OR pqa.analysis_status != 'archived')";
            
            try {
                Logger::instance()->info("[Quality Analysis] Executing statistics query", [
                    'migration_id' => $migrationId,
                    'sql' => $sql
                ]);
            } catch (Exception $logError) {
                // Logger не инициализирован, игнорируем
            }
            
            $statsResult = $this->db->find($sql, [':migration_id' => $migrationId]);
            
            try {
                Logger::instance()->info("[Quality Analysis] Statistics query result", [
                    'migration_id' => $migrationId,
                    'result' => $statsResult,
                    'result_type' => gettype($statsResult),
                    'is_array' => is_array($statsResult)
                ]);
            } catch (Exception $logError) {
                // Logger не инициализирован, игнорируем
            }
            
            // Метод find может вернуть false если нет результатов
            if ($statsResult === false || !is_array($statsResult)) {
                try {
                    Logger::instance()->warning("[Quality Analysis] No statistics found for migration (query returned false or not array)", [
                        'migration_id' => $migrationId,
                        'result_type' => gettype($statsResult),
                        'result' => $statsResult
                    ]);
                } catch (Exception $logError) {
                    // Logger не инициализирован, игнорируем
                }
                return [
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
                ];
            }
            
            // Получаем общее количество страниц из SQL запроса
            $totalPages = (int)($statsResult['total_pages'] ?? 0);
            
            // Получаем средний рейтинг из SQL запроса
            $avgQualityScore = null;
            if (isset($statsResult['avg_quality_score']) && $statsResult['avg_quality_score'] !== null) {
                $avgQualityScore = round((float)$statsResult['avg_quality_score'], 2);
            }
            
            try {
                Logger::instance()->info("[Quality Analysis] Statistics retrieved", [
                    'migration_id' => $migrationId,
                    'total_pages' => $totalPages,
                    'avg_quality_score' => $avgQualityScore
                ]);
            } catch (Exception $logError) {
                // Logger не инициализирован, игнорируем
            }
            
            // Если нет страниц, возвращаем пустую статистику
            if ($totalPages === 0) {
                return [
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
                ];
            }
            
            // Получаем только последние отчеты для каждой страницы для подсчета токенов (исключая архивные)
            $reportsSql = "SELECT pqa.detailed_report 
                          FROM page_quality_analysis pqa
                          INNER JOIN (
                              SELECT page_slug, MAX(id) as max_id
                              FROM page_quality_analysis
                              WHERE migration_id = :migration_id
                              AND (analysis_status IS NULL OR analysis_status != 'archived')
                              GROUP BY page_slug
                          ) latest ON pqa.page_slug = latest.page_slug AND pqa.id = latest.max_id
                          WHERE pqa.migration_id = :migration_id
                          AND (pqa.analysis_status IS NULL OR pqa.analysis_status != 'archived')";
            $reports = $this->db->getAllRows($reportsSql, [':migration_id' => $migrationId]);
            
            $totalPromptTokens = 0;
            $totalCompletionTokens = 0;
            $totalCost = 0.0;
            $pagesWithTokens = 0;
            
            // Подсчитываем статистику по токенам из detailed_report
            foreach ($reports as $report) {
                try {
                    $detailedReport = json_decode($report['detailed_report'] ?? '{}', true);
                    if (isset($detailedReport['token_usage'])) {
                        $tokenUsage = $detailedReport['token_usage'];
                        if (isset($tokenUsage['prompt_tokens'])) {
                            $totalPromptTokens += (int)$tokenUsage['prompt_tokens'];
                        }
                        if (isset($tokenUsage['completion_tokens'])) {
                            $totalCompletionTokens += (int)$tokenUsage['completion_tokens'];
                        }
                        if (isset($tokenUsage['cost_estimate_usd'])) {
                            $totalCost += (float)$tokenUsage['cost_estimate_usd'];
                            $pagesWithTokens++;
                        }
                    }
                } catch (Exception $e) {
                    // Игнорируем ошибки парсинга JSON
                }
            }
            
            $totalTokens = $totalPromptTokens + $totalCompletionTokens;
            $avgTokensPerPage = $totalPages > 0 ? round($totalTokens / $totalPages, 0) : 0;
            $avgCostPerPage = $pagesWithTokens > 0 ? round($totalCost / $pagesWithTokens, 6) : 0;
            
            return [
                'total_pages' => $totalPages,
                'avg_quality_score' => $avgQualityScore > 0 ? $avgQualityScore : null,
                'by_severity' => [
                    'critical' => (int)($statsResult['critical_count'] ?? 0),
                    'high' => (int)($statsResult['high_count'] ?? 0),
                    'medium' => (int)($statsResult['medium_count'] ?? 0),
                    'low' => (int)($statsResult['low_count'] ?? 0),
                    'none' => (int)($statsResult['none_count'] ?? 0)
                ],
                'token_statistics' => [
                    'total_prompt_tokens' => $totalPromptTokens,
                    'total_completion_tokens' => $totalCompletionTokens,
                    'total_tokens' => $totalTokens,
                    'avg_tokens_per_page' => $avgTokensPerPage,
                    'total_cost_usd' => round($totalCost, 6),
                    'avg_cost_per_page_usd' => $avgCostPerPage
                ]
            ];
        } catch (Exception $e) {
            try {
                Logger::instance()->error("Error getting migration statistics", [
                    'migration_id' => $migrationId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            } catch (Exception $logError) {
                // Logger не инициализирован, используем error_log
                error_log("Quality Analysis Error: " . $e->getMessage());
            }
            // Возвращаем пустую статистику с правильной структурой вместо пустого массива
            return [
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
            ];
        }
    }

    /**
     * Архивировать старые отчеты для миграции
     * Помечает все существующие отчеты для данной миграции как 'archived'
     * 
     * @param int $migrationId ID миграции
     * @return int Количество заархивированных отчетов
     * @throws Exception
     */
    public function archiveOldReports(int $migrationId): int
    {
        try {
            // Обновляем ENUM если нужно
            $this->updateAnalysisStatusEnum();
            
            // Сначала получаем количество отчетов которые будут заархивированы
            $countSql = "SELECT COUNT(*) as cnt FROM page_quality_analysis 
                        WHERE migration_id = :migration_id 
                        AND analysis_status != 'archived'";
            
            $countResult = $this->db->find($countSql, [':migration_id' => $migrationId]);
            $toArchiveCount = (int)($countResult['cnt'] ?? 0);
            
            if ($toArchiveCount === 0) {
                Logger::instance()->info("[Quality Analysis] No reports to archive for migration", [
                    'migration_id' => $migrationId
                ]);
                return 0;
            }
            
            // Обновляем статус на 'archived'
            $sql = "UPDATE page_quality_analysis 
                    SET analysis_status = 'archived', updated_at = NOW() 
                    WHERE migration_id = :migration_id 
                    AND analysis_status != 'archived'";
            
            // Используем getAllRows для выполнения UPDATE (он выполняет UPDATE тоже)
            $this->db->getAllRows($sql, [':migration_id' => $migrationId]);
            
            Logger::instance()->info("[Quality Analysis] Archived old reports for migration", [
                'migration_id' => $migrationId,
                'archived_count' => $toArchiveCount
            ]);
            
            return $toArchiveCount;
        } catch (Exception $e) {
            Logger::instance()->error("Error archiving old reports", [
                'migration_id' => $migrationId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Получить архивные отчеты по миграции
     * 
     * @param int $migrationId ID миграции
     * @return array Массив архивных отчетов
     */
    public function getArchivedReportsByMigration(int $migrationId): array
    {
        try {
            $sql = "SELECT * FROM page_quality_analysis 
                    WHERE migration_id = :migration_id 
                    AND analysis_status = 'archived'
                    ORDER BY created_at DESC";
            
            $reports = $this->db->getAllRows($sql, [':migration_id' => $migrationId]);
            
            // Декодируем JSON поля
            foreach ($reports as &$report) {
                try {
                    $report['issues_summary'] = json_decode($report['issues_summary'] ?? '[]', true) ?? [];
                } catch (Exception $e) {
                    $report['issues_summary'] = [];
                }
                
                try {
                    $report['detailed_report'] = json_decode($report['detailed_report'] ?? '{}', true) ?? [];
                    // Извлекаем token_usage из detailed_report для удобства доступа
                    if (isset($report['detailed_report']['token_usage'])) {
                        $report['token_usage'] = $report['detailed_report']['token_usage'];
                    }
                } catch (Exception $e) {
                    $report['detailed_report'] = [];
                }
                
                // Декодируем screenshots_path
                if (!empty($report['screenshots_path'])) {
                    try {
                        $decoded = json_decode($report['screenshots_path'], true);
                        $report['screenshots_path'] = is_array($decoded) ? $decoded : [];
                    } catch (Exception $e) {
                        $report['screenshots_path'] = [];
                    }
                } else {
                    $report['screenshots_path'] = [];
                }
            }
            
            return $reports;
        } catch (Exception $e) {
            Logger::instance()->error("Error getting archived reports", [
                'migration_id' => $migrationId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}
