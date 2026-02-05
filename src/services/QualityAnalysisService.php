<?php

namespace Dashboard\Services;

use MBMigration\Analysis\QualityReport;
use MBMigration\Core\Logger;
use Exception;
use Dashboard\Services\DatabaseService;
use Dashboard\Services\MigrationService;

/**
 * QualityAnalysisService
 * 
 * Сервис для работы с анализом качества миграций
 */
class QualityAnalysisService
{
    /**
     * @var QualityReport
     */
    private $qualityReport;

    public function __construct()
    {
        $this->qualityReport = new QualityReport();
    }

    /**
     * Получить список отчетов по миграции
     * 
     * @param int $migrationId ID миграции (brz_project_id)
     * @return array
     */
    public function getReportsByMigration(int $migrationId): array
    {
        $reports = $this->qualityReport->getReportsByMigration($migrationId);
        
        // Обогащаем отчеты данными из migration_pages
        try {
            $dbService = new \Dashboard\Services\DatabaseService();
            $db = $dbService->getWriteConnection();
            
            // Получаем страницы из migration_pages
            $migrationPages = $db->getAllRows(
                'SELECT slug, collection_items_id, brz_project_id 
                 FROM migration_pages 
                 WHERE brz_project_id = ?',
                [$migrationId]
            );
            
            // Создаем карту slug -> page data
            $pagesMap = [];
            foreach ($migrationPages as $page) {
                $pagesMap[$page['slug']] = [
                    'collection_items_id' => isset($page['collection_items_id']) ? (int)$page['collection_items_id'] : null,
                    'brz_project_id' => isset($page['brz_project_id']) ? (int)$page['brz_project_id'] : $migrationId,
                ];
            }
            
            // Обогащаем отчеты данными из migration_pages
            foreach ($reports as &$report) {
                $pageSlug = $report['page_slug'] ?? '';
                if (!empty($pageSlug) && isset($pagesMap[$pageSlug])) {
                    $report['collection_items_id'] = $pagesMap[$pageSlug]['collection_items_id'];
                    $report['brz_project_id'] = $pagesMap[$pageSlug]['brz_project_id'];
                } else {
                    // Если страница не найдена в migration_pages, используем migrationId как brz_project_id
                    $report['brz_project_id'] = $migrationId;
                }
            }
            unset($report); // Сбрасываем ссылку
        } catch (Exception $e) {
            // Логируем ошибку, но не прерываем выполнение
            error_log("Error enriching reports with migration_pages data: " . $e->getMessage());
        }
        
        return $reports;
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
        $report = $this->qualityReport->getReportBySlug($migrationId, $pageSlug, $includeArchived);
        
        if (!$report) {
            return null;
        }
        
        // Обогащаем отчет данными из migration_pages
        try {
            $dbService = new \Dashboard\Services\DatabaseService();
            $db = $dbService->getWriteConnection();
            
            // Получаем данные страницы из migration_pages
            $migrationPage = $db->find(
                'SELECT collection_items_id, brz_project_id 
                 FROM migration_pages 
                 WHERE brz_project_id = ? AND slug = ? 
                 LIMIT 1',
                [$migrationId, $pageSlug]
            );
            
            if ($migrationPage) {
                $report['collection_items_id'] = isset($migrationPage['collection_items_id']) ? (int)$migrationPage['collection_items_id'] : null;
                $report['brz_project_id'] = isset($migrationPage['brz_project_id']) ? (int)$migrationPage['brz_project_id'] : $migrationId;
            } else {
                // Если страница не найдена в migration_pages, используем migrationId как brz_project_id
                $report['brz_project_id'] = $migrationId;
            }
        } catch (Exception $e) {
            // Логируем ошибку, но не прерываем выполнение
            error_log("Error enriching report with migration_pages data: " . $e->getMessage());
            // Устанавливаем migrationId как brz_project_id по умолчанию
            $report['brz_project_id'] = $migrationId;
        }
        
        return $report;
    }

    /**
     * Получить статистику по миграции
     * 
     * @param int $migrationId ID миграции
     * @return array
     */
    public function getMigrationStatistics(int $migrationId): array
    {
        return $this->qualityReport->getMigrationStatistics($migrationId);
    }

    /**
     * Получить архивные отчеты по миграции
     * 
     * @param int $migrationId ID миграции
     * @return array
     */
    public function getArchivedReportsByMigration(int $migrationId): array
    {
        return $this->qualityReport->getArchivedReportsByMigration($migrationId);
    }

    /**
     * Получить список всех страниц миграции
     * 
     * @param int $migrationId ID миграции (brz_project_id)
     * @return array Массив страниц с основной информацией
     */
    public function getPagesList(int $migrationId): array
    {
        try {
            error_log("[QualityAnalysisService] getPagesList called for migrationId: {$migrationId}");
            
            $dbService = new \Dashboard\Services\DatabaseService();
            $db = $dbService->getWriteConnection();
            
            // Получаем страницы из migration_pages
            $migrationPages = $db->getAllRows(
                'SELECT slug, collection_items_id, title, is_homepage, is_protected, created_at, updated_at 
                 FROM migration_pages 
                 WHERE brz_project_id = ? 
                 ORDER BY created_at ASC',
                [$migrationId]
            );
            
            error_log("[QualityAnalysisService] Found " . count($migrationPages) . " pages in migration_pages for brz_project_id: {$migrationId}");
            
            // Получаем все отчеты для миграции
            $reports = $this->qualityReport->getReportsByMigration($migrationId);
            
            error_log("[QualityAnalysisService] Found " . count($reports) . " reports for migrationId: {$migrationId}");
            
            // Создаем массив уникальных страниц с основной информацией
            $pagesMap = [];
            
            // Сначала добавляем страницы из migration_pages
            foreach ($migrationPages as $migrationPage) {
                $pageSlug = $migrationPage['slug'] ?? '';
                if (empty($pageSlug)) {
                    continue;
                }
                
                $pagesMap[$pageSlug] = [
                    'page_slug' => $pageSlug,
                    'collection_items_id' => isset($migrationPage['collection_items_id']) ? (int)$migrationPage['collection_items_id'] : null,
                    'brz_project_id' => $migrationId,
                    'title' => $migrationPage['title'] ?? null,
                    'is_homepage' => isset($migrationPage['is_homepage']) ? (bool)$migrationPage['is_homepage'] : false,
                    'is_protected' => isset($migrationPage['is_protected']) ? (bool)$migrationPage['is_protected'] : false,
                    'quality_score' => null,
                    'severity_level' => 'none',
                    'analysis_status' => 'pending',
                    'created_at' => $migrationPage['created_at'] ?? null,
                    'updated_at' => $migrationPage['updated_at'] ?? null,
                    'has_analysis' => false
                ];
            }
            
            // Обновляем информацию из отчетов анализа качества
            foreach ($reports as $report) {
                $pageSlug = $report['page_slug'] ?? '';
                if (empty($pageSlug)) {
                    continue;
                }
                
                // Если страница уже есть в карте, обновляем информацию более свежим отчетом
                if (!isset($pagesMap[$pageSlug])) {
                    $pagesMap[$pageSlug] = [
                        'page_slug' => $pageSlug,
                        'collection_items_id' => null,
                        'brz_project_id' => $migrationId,
                        'source_url' => $report['source_url'] ?? null,
                        'migrated_url' => $report['migrated_url'] ?? null,
                        'quality_score' => $report['quality_score'] ?? null,
                        'severity_level' => $report['severity_level'] ?? 'none',
                        'analysis_status' => $report['analysis_status'] ?? 'pending',
                        'created_at' => $report['created_at'] ?? null,
                        'updated_at' => $report['updated_at'] ?? null,
                        'has_analysis' => true
                    ];
                } else {
                    // Обновляем информацию из отчета, если он новее
                    if (isset($report['created_at']) && isset($pagesMap[$pageSlug]['created_at']) &&
                        strtotime($report['created_at']) > strtotime($pagesMap[$pageSlug]['created_at'])) {
                        $pagesMap[$pageSlug]['quality_score'] = $report['quality_score'] ?? $pagesMap[$pageSlug]['quality_score'];
                        $pagesMap[$pageSlug]['severity_level'] = $report['severity_level'] ?? $pagesMap[$pageSlug]['severity_level'];
                        $pagesMap[$pageSlug]['analysis_status'] = $report['analysis_status'] ?? $pagesMap[$pageSlug]['analysis_status'];
                        $pagesMap[$pageSlug]['source_url'] = $report['source_url'] ?? $pagesMap[$pageSlug]['source_url'] ?? null;
                        $pagesMap[$pageSlug]['migrated_url'] = $report['migrated_url'] ?? $pagesMap[$pageSlug]['migrated_url'] ?? null;
                    }
                    $pagesMap[$pageSlug]['has_analysis'] = true;
                }
            }
            
            // Преобразуем карту в массив и сортируем по дате создания (новые первыми)
            $pages = array_values($pagesMap);
            usort($pages, function($a, $b) {
                $dateA = $a['created_at'] ? strtotime($a['created_at']) : 0;
                $dateB = $b['created_at'] ? strtotime($b['created_at']) : 0;
                return $dateB - $dateA;
            });
            
            error_log("[QualityAnalysisService] getPagesList returning " . count($pages) . " pages for migrationId: {$migrationId}");
            return $pages;
        } catch (Exception $e) {
            error_log("Error getting pages list for migration $migrationId: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return [];
        }
    }

    /**
     * Перезапустить анализ качества для конкретной страницы
     * 
     * @param int $migrationId ID миграции (brz_project_id)
     * @param string $pageSlug Slug страницы
     * @return array
     * @throws Exception
     */
    public function reanalyzePage(int $migrationId, string $pageSlug): array
    {
        try {
            // Получаем информацию о миграции для получения необходимых данных
            $dbService = new DatabaseService();
            $migrations = $dbService->getMigrationsList();
            
            // Ищем миграцию по brz_project_id
            $migration = null;
            foreach ($migrations as $mig) {
                if ($mig['brz_project_id'] == $migrationId) {
                    $migration = $mig;
                    break;
                }
            }
            
            if (!$migration) {
                throw new Exception("Миграция с ID {$migrationId} не найдена");
            }

            $mbProjectUuid = $migration['mb_project_uuid'];
            error_log("[Reanalyze] Found migration: mb_uuid={$mbProjectUuid}, brz_id={$migrationId}");
            
            // Парсим changes_json из mapping (как в MigrationService)
            $changesJson = [];
            if (!empty($migration['changes_json'])) {
                $changesJson = is_string($migration['changes_json']) 
                    ? json_decode($migration['changes_json'], true) 
                    : $migration['changes_json'];
                if (!is_array($changesJson)) {
                    $changesJson = [];
                }
            }
            
            $result = $dbService->getMigrationResultByUuid($mbProjectUuid);
            
            if (!$result) {
                throw new Exception("Результат миграции не найден для UUID: {$mbProjectUuid}. Убедитесь, что миграция завершена успешно.");
            }

            $resultData = json_decode($result['result_json'] ?? '{}', true);
            
            if (!$resultData) {
                $jsonError = json_last_error_msg();
                throw new Exception("Не удалось декодировать result_json для миграции. JSON error: {$jsonError}");
            }

            // Извлекаем данные из value, если они там находятся
            $migrationValue = $resultData['value'] ?? $resultData;
            
            // Проверяем все возможные места, где могут быть домены (как в MigrationService)
            $mbProjectDomain = $migrationValue['mb_project_domain'] 
                ?? $resultData['mb_project_domain'] 
                ?? $changesJson['mb_project_domain'] 
                ?? null;
                
            $brizyProjectDomain = $migrationValue['brizy_project_domain'] 
                ?? $resultData['brizy_project_domain'] 
                ?? $changesJson['brizy_project_domain'] 
                ?? null;
                
            $themeName = $migrationValue['theme'] ?? $resultData['theme'] ?? 'default';

            // Проверяем, что значения не пустые строки
            if ($mbProjectDomain === '' || $mbProjectDomain === null) {
                $mbProjectDomain = null;
            }
            if ($brizyProjectDomain === '' || $brizyProjectDomain === null) {
                $brizyProjectDomain = null;
            }

            error_log("[Reanalyze] Extracted domains: mb={$mbProjectDomain}, brizy={$brizyProjectDomain}, theme={$themeName}");
            error_log("[Reanalyze] Sources checked: migrationValue=" . (isset($migrationValue['mb_project_domain']) ? var_export($migrationValue['mb_project_domain'], true) : 'NOT SET') . 
                      ", resultData=" . (isset($resultData['mb_project_domain']) ? var_export($resultData['mb_project_domain'], true) : 'NOT SET') . 
                      ", changesJson=" . (isset($changesJson['mb_project_domain']) ? var_export($changesJson['mb_project_domain'], true) : 'NOT SET'));

            // Сначала пробуем получить домены из существующего отчета анализа (самый надежный способ)
            if (!$mbProjectDomain || !$brizyProjectDomain) {
                error_log("[Reanalyze] Domains not found in result, trying to get from existing report...");
                try {
                    $existingReport = $this->qualityReport->getReportBySlug($migrationId, $pageSlug);
                    if ($existingReport) {
                        // Извлекаем домены из source_url и migrated_url
                        if (!empty($existingReport['source_url'])) {
                            $parsedSourceUrl = parse_url($existingReport['source_url']);
                            if (!empty($parsedSourceUrl['scheme']) && !empty($parsedSourceUrl['host'])) {
                                $mbProjectDomain = $mbProjectDomain ?: ($parsedSourceUrl['scheme'] . '://' . $parsedSourceUrl['host']);
                                error_log("[Reanalyze] Extracted mb domain from source_url: {$mbProjectDomain}");
                            }
                        }
                        
                        if (!empty($existingReport['migrated_url'])) {
                            $parsedMigratedUrl = parse_url($existingReport['migrated_url']);
                            if (!empty($parsedMigratedUrl['scheme']) && !empty($parsedMigratedUrl['host'])) {
                                $brizyProjectDomain = $brizyProjectDomain ?: ($parsedMigratedUrl['scheme'] . '://' . $parsedMigratedUrl['host']);
                                error_log("[Reanalyze] Extracted brizy domain from migrated_url: {$brizyProjectDomain}");
                            }
                        }
                    }
                } catch (\Exception $e) {
                    error_log("[Reanalyze] Failed to get domains from existing report: " . $e->getMessage());
                }
            }

            // Если домены всё ещё не найдены, пробуем получить их из деталей миграции через MigrationService
            if (!$mbProjectDomain || !$brizyProjectDomain) {
                error_log("[Reanalyze] Domains still not found, trying MigrationService...");
                try {
                    $migrationService = new MigrationService();
                    $migrationDetails = $migrationService->getMigrationDetails($migrationId);
                    
                    if ($migrationDetails) {
                        $mbProjectDomain = $mbProjectDomain ?: ($migrationDetails['mb_project_domain'] ?? null);
                        $brizyProjectDomain = $brizyProjectDomain ?: ($migrationDetails['brizy_project_domain'] ?? null);
                        error_log("[Reanalyze] Domains from MigrationService: mb={$mbProjectDomain}, brizy={$brizyProjectDomain}");
                    }
                } catch (\Exception $e) {
                    error_log("[Reanalyze] Failed to get domains from MigrationService: " . $e->getMessage());
                }
            }

            // Если MB домен всё ещё не найден, пробуем получить его из базы данных MB напрямую
            if (!$mbProjectDomain && !empty($migrationValue['mb_site_id'])) {
                error_log("[Reanalyze] MB domain still not found, trying to get from MB database...");
                try {
                    $mbSiteId = $migrationValue['mb_site_id'];
                    // Используем тот же подход, что и в MBProjectDataCollector
                    if (class_exists('\MBMigration\Layer\MB\MBProjectDataCollector')) {
                        $domains = \MBMigration\Layer\MB\MBProjectDataCollector::getAllDomainsBySiteId((int)$mbSiteId);
                        if (!empty($domains)) {
                            $firstDomain = $domains[0];
                            // Нормализуем домен
                            $normalizedDomain = \MBMigration\Layer\MB\MBProjectDataCollector::normalizeDomain($firstDomain);
                            if ($normalizedDomain) {
                                // Добавляем протокол если его нет
                                if (!preg_match('/^https?:\/\//', $normalizedDomain)) {
                                    $mbProjectDomain = 'http://' . $normalizedDomain;
                                } else {
                                    $mbProjectDomain = $normalizedDomain;
                                }
                                error_log("[Reanalyze] Extracted mb domain from MB database: {$mbProjectDomain}");
                            }
                        }
                    }
                } catch (\Exception $e) {
                    error_log("[Reanalyze] Failed to get domain from MB database: " . $e->getMessage());
                }
            }

            // Формируем URLs для анализа
            // Сначала пробуем использовать существующие URL из отчета (самый точный способ)
            // Это гарантирует, что мы используем правильный полный путь к странице, включая вложенность
            $sourceUrl = null;
            $migratedUrl = null;
            
            error_log("[Reanalyze] Step: Getting URLs from existing report...");
            try {
                $existingReport = $this->qualityReport->getReportBySlug($migrationId, $pageSlug);
                if ($existingReport) {
                    error_log("[Reanalyze] Existing report found, checking URLs...");
                    error_log("[Reanalyze] Report source_url: " . ($existingReport['source_url'] ?? 'NULL'));
                    error_log("[Reanalyze] Report migrated_url: " . ($existingReport['migrated_url'] ?? 'NULL'));
                    
                    // Используем существующие URL если они есть и не пустые
                    if (!empty($existingReport['source_url']) && trim($existingReport['source_url']) !== '') {
                        $sourceUrl = trim($existingReport['source_url']);
                        error_log("[Reanalyze] ✓ Using source_url from existing report: {$sourceUrl}");
                    } else {
                        error_log("[Reanalyze] ✗ source_url in report is empty or null");
                    }
                    
                    if (!empty($existingReport['migrated_url']) && trim($existingReport['migrated_url']) !== '') {
                        $migratedUrl = trim($existingReport['migrated_url']);
                        error_log("[Reanalyze] ✓ Using migrated_url from existing report: {$migratedUrl}");
                    } else {
                        error_log("[Reanalyze] ✗ migrated_url in report is empty or null");
                    }
                } else {
                    error_log("[Reanalyze] No existing report found for migration_id={$migrationId}, page_slug={$pageSlug}");
                }
            } catch (\Exception $e) {
                error_log("[Reanalyze] Failed to get URLs from existing report: " . $e->getMessage());
                error_log("[Reanalyze] Stack trace: " . $e->getTraceAsString());
            }
            
            // Если URL не найдены в отчете, формируем их из доменов
            // Это fallback на случай, если отчет еще не создан или URL не были сохранены
            if (!$sourceUrl && $mbProjectDomain) {
                $sourceUrl = rtrim($mbProjectDomain, '/') . '/' . ltrim($pageSlug, '/');
                error_log("[Reanalyze] ⚠ Formed source_url from domain (fallback): {$sourceUrl}");
                error_log("[Reanalyze] ⚠ WARNING: Using fallback URL. Full path may be incorrect for nested pages.");
            }
            
            if (!$migratedUrl && $brizyProjectDomain) {
                $migratedUrl = rtrim($brizyProjectDomain, '/') . '/' . ltrim($pageSlug, '/');
                error_log("[Reanalyze] ⚠ Formed migrated_url from domain (fallback): {$migratedUrl}");
            }

            // Проверяем, что у нас есть оба URL
            if (!$sourceUrl) {
                $mbDomainValue = isset($migrationValue['mb_project_domain']) ? var_export($migrationValue['mb_project_domain'], true) : 'NOT SET';
                $availableKeys = array_keys($migrationValue);
                throw new Exception("Не удалось получить source URL для анализа. MB Project Domain: {$mbDomainValue}. Проверьте, что миграция завершена успешно и домены были сохранены, или что существует отчет анализа с source_url. Доступные ключи: " . json_encode($availableKeys));
            }
            
            if (!$migratedUrl) {
                $brizyDomainValue = isset($migrationValue['brizy_project_domain']) ? var_export($migrationValue['brizy_project_domain'], true) : 'NOT SET';
                $availableKeys = array_keys($migrationValue);
                throw new Exception("Не удалось получить migrated URL для анализа. Brizy Project Domain: {$brizyDomainValue}. Проверьте, что миграция завершена успешно и домены были сохранены, или что существует отчет анализа с migrated_url. Доступные ключи: " . json_encode($availableKeys));
            }

            error_log("[Reanalyze] ✓ Final URLs determined: source={$sourceUrl}, migrated={$migratedUrl}");

            // Инициализируем Logger перед вызовом PageQualityAnalyzer
            // Logger необходим для работы PageQualityAnalyzer и его зависимостей
            try {
                error_log("[Reanalyze] Step 0: Initializing Logger...");
                
                // Проверяем, инициализирован ли Logger
                $loggerInitialized = false;
                if (class_exists('\MBMigration\Core\Logger')) {
                    if (method_exists('\MBMigration\Core\Logger', 'isInitialized')) {
                        $loggerInitialized = \MBMigration\Core\Logger::isInitialized();
                    } else {
                        // Если метод isInitialized не существует, пробуем вызвать instance()
                        try {
                            \MBMigration\Core\Logger::instance();
                            $loggerInitialized = true;
                        } catch (\Exception $e) {
                            $loggerInitialized = false;
                        }
                    }
                }
                
                if (!$loggerInitialized) {
                    error_log("[Reanalyze] Logger not initialized, initializing now...");
                    
                    // Получаем параметры для инициализации Logger из окружения
                    $projectRoot = dirname(__DIR__, 2);
                    $logPath = $_ENV['LOG_FILE_PATH'] ?? getenv('LOG_FILE_PATH') ?: $projectRoot . '/var/log';
                    $logLevel = $_ENV['LOG_LEVEL'] ?? getenv('LOG_LEVEL') ?: \Monolog\Logger::DEBUG;
                    
                    // Создаем директорию для логов если её нет
                    if (!is_dir($logPath)) {
                        @mkdir($logPath, 0755, true);
                    }
                    
                    // Формируем путь к файлу лога для этой миграции
                    $logFileName = 'reanalyze_' . $migrationId . '_' . time() . '.log';
                    $logFilePath = rtrim($logPath, '/') . '/' . $logFileName;
                    
                    error_log("[Reanalyze] Initializing Logger with path: {$logFilePath}, level: {$logLevel}");
                    
                    \MBMigration\Core\Logger::initialize(
                        "reanalyze-{$migrationId}",
                        $logLevel,
                        $logFilePath
                    );
                    
                    error_log("[Reanalyze] ✓ Logger initialized successfully");
                } else {
                    error_log("[Reanalyze] ✓ Logger already initialized");
                }
            } catch (\Exception $loggerException) {
                error_log("[Reanalyze] ERROR - Failed to initialize Logger: " . $loggerException->getMessage());
                error_log("[Reanalyze] ERROR - Stack trace: " . $loggerException->getTraceAsString());
                throw new Exception("Не удалось инициализировать Logger: " . $loggerException->getMessage(), 0, $loggerException);
            }

            // Запускаем анализ через PageQualityAnalyzer
            try {
                error_log("[Reanalyze] Step 1: Checking if PageQualityAnalyzer class exists...");
                
                // Проверяем, что класс доступен
                $analyzerClassName = '\MBMigration\Analysis\PageQualityAnalyzer';
                $classExists = class_exists($analyzerClassName);
                error_log("[Reanalyze] Class exists check: " . ($classExists ? 'YES' : 'NO'));
                
                if (!$classExists) {
                    // Пробуем загрузить через autoload
                    $autoloadFile = dirname(__DIR__, 2) . '/vendor/autoload_runtime.php';
                    error_log("[Reanalyze] Autoload file path: {$autoloadFile}");
                    error_log("[Reanalyze] Autoload file exists: " . (file_exists($autoloadFile) ? 'YES' : 'NO'));
                    
                    if (file_exists($autoloadFile)) {
                        error_log("[Reanalyze] Loading autoload file...");
                        require_once $autoloadFile;
                        $classExists = class_exists($analyzerClassName);
                        error_log("[Reanalyze] Class exists after autoload: " . ($classExists ? 'YES' : 'NO'));
                    }
                    
                    if (!$classExists) {
                        throw new Exception("Класс PageQualityAnalyzer не найден. Проверьте автозагрузку классов. Класс: {$analyzerClassName}");
                    }
                }
                
                error_log("[Reanalyze] Step 2: Creating PageQualityAnalyzer instance...");
                $analyzer = new $analyzerClassName(true);
                
                if (!$analyzer) {
                    throw new Exception("Не удалось создать экземпляр PageQualityAnalyzer");
                }
                
                error_log("[Reanalyze] Step 3: Instance created successfully");
                error_log("[Reanalyze] Step 4: Calling analyzePage with params:");
                error_log("[Reanalyze]   - sourceUrl: {$sourceUrl}");
                error_log("[Reanalyze]   - migratedUrl: {$migratedUrl}");
                error_log("[Reanalyze]   - pageSlug: {$pageSlug}");
                error_log("[Reanalyze]   - mbUuid: {$mbProjectUuid}");
                error_log("[Reanalyze]   - brizyId: {$migrationId}");
                error_log("[Reanalyze]   - theme: {$themeName}");
                
                $reportId = $analyzer->analyzePage(
                    $sourceUrl,
                    $migratedUrl,
                    $pageSlug,
                    $mbProjectUuid,
                    $migrationId,
                    $themeName
                );

                error_log("[Reanalyze] Step 5: analyzePage returned: " . ($reportId ? "report_id={$reportId}" : "NULL"));

                if (!$reportId) {
                    throw new Exception("Не удалось создать отчет анализа. Анализ вернул null (возможно, анализ отключен или произошла ошибка при сохранении)");
                }
                
                error_log("[Reanalyze] Analysis completed successfully, report_id={$reportId}");
            } catch (\Throwable $analyzerException) {
                $errorMsg = $analyzerException->getMessage();
                $errorFile = $analyzerException->getFile();
                $errorLine = $analyzerException->getLine();
                $errorTrace = $analyzerException->getTraceAsString();
                
                error_log("[Reanalyze] ERROR - Analyzer exception: {$errorMsg}");
                error_log("[Reanalyze] ERROR - File: {$errorFile}, Line: {$errorLine}");
                error_log("[Reanalyze] ERROR - Stack trace: {$errorTrace}");
                
                throw new Exception("Ошибка при выполнении анализа: {$errorMsg} (File: " . basename($errorFile) . ", Line: {$errorLine})", 0, $analyzerException);
            }

            // Получаем обновленный отчет
            $report = $this->qualityReport->getReportBySlug($migrationId, $pageSlug);

            return [
                'report_id' => $reportId,
                'page_slug' => $pageSlug,
                'migration_id' => $migrationId,
                'report' => $report
            ];
        } catch (Exception $e) {
            error_log("[Reanalyze] Error: " . $e->getMessage());
            error_log("[Reanalyze] Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }
}
