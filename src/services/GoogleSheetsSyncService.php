<?php

namespace Dashboard\Services;

use Exception;

/**
 * GoogleSheetsSyncService
 * 
 * Сервис для синхронизации данных между Google Sheets и базой данных
 */
class GoogleSheetsSyncService
{
    /** @var GoogleSheetsService */
    private $googleSheetsService;

    /** @var DatabaseService */
    private $dbService;

    /**
     * Конструктор
     */
    public function __construct()
    {
        $this->dbService = new DatabaseService();
        try {
            $this->googleSheetsService = new GoogleSheetsService();
        } catch (Exception $e) {
            // Сервис не инициализирован (нет credentials) - будет использоваться только для некоторых операций
            error_log("[GoogleSheetsSyncService] GoogleSheetsService не инициализирован: " . $e->getMessage());
            $this->googleSheetsService = null;
        }
    }

    /**
     * Проверить существование таблицы migration_reviewers
     * 
     * @return bool
     */
    private function checkMigrationReviewersTable(): bool
    {
        try {
            $db = $this->dbService->getWriteConnection();
            $result = $db->getAllRows("SHOW TABLES LIKE 'migration_reviewers'");
            return !empty($result);
        } catch (Exception $e) {
            error_log("[GoogleSheetsSyncService::checkMigrationReviewersTable] Ошибка проверки таблицы: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Синхронизировать лист Google таблицы
     * 
     * @param string $spreadsheetId ID Google таблицы
     * @param string $sheetName Название листа
     * @param string|null $waveId ID волны (опционально)
     * @return array Статистика синхронизации
     * @throws Exception
     */
    public function syncSheet(string $spreadsheetId, string $sheetName, ?string $waveId = null): array
    {
        error_log("═══════════════════════════════════════════════════════════════");
        error_log("[GoogleSheetsSyncService::syncSheet] 🚀 НАЧАЛО СИНХРОНИЗАЦИИ");
        error_log("  Spreadsheet ID: {$spreadsheetId}");
        error_log("  Sheet Name: {$sheetName}");
        error_log("  Wave ID: " . ($waveId ?? 'не указан'));
        error_log("═══════════════════════════════════════════════════════════════");

        if (!$this->googleSheetsService) {
            error_log("[GoogleSheetsSyncService::syncSheet] ❌ ОШИБКА: Google Sheets Service не настроен");
            throw new Exception('Google Sheets Service не настроен. Проверьте переменные окружения GOOGLE_CLIENT_ID и GOOGLE_CLIENT_SECRET');
        }
        error_log("[GoogleSheetsSyncService::syncSheet] ✓ Google Sheets Service инициализирован");

        // Проверяем существование таблицы migration_reviewers
        error_log("[GoogleSheetsSyncService::syncSheet] 📋 Шаг 1: Проверка таблицы migration_reviewers...");
        if (!$this->checkMigrationReviewersTable()) {
            error_log("[GoogleSheetsSyncService::syncSheet] ❌ ОШИБКА: Таблица migration_reviewers не существует");
            throw new Exception('Таблица migration_reviewers не существует. Выполните миграцию базы данных: php src/scripts/run_google_sheets_migration.php');
        }
        error_log("[GoogleSheetsSyncService::syncSheet] ✓ Таблица migration_reviewers существует");

        $stats = [
            'total_rows' => 0,
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'not_found' => 0,
            'errors' => 0,
            'errors_list' => []
        ];

        try {
            // 0. Отслеживаем изменения названия таблицы (если авторизованы)
            error_log("[GoogleSheetsSyncService::syncSheet] 📝 Шаг 2: Отслеживание изменений названия таблицы...");
            try {
                $this->googleSheetsService->trackSheetName($spreadsheetId);
                error_log("[GoogleSheetsSyncService::syncSheet] ✓ Название таблицы проверено");
            } catch (Exception $e) {
                // Игнорируем ошибки отслеживания - не блокируем синхронизацию
                error_log("[GoogleSheetsSyncService::syncSheet] ⚠ Предупреждение: Ошибка отслеживания названия: " . $e->getMessage());
            }

            // 1. Получаем данные листа через GoogleSheetsService
            error_log("[GoogleSheetsSyncService::syncSheet] 📥 Шаг 3: Получение данных листа '{$sheetName}' из Google Sheets...");
            $sheetData = $this->googleSheetsService->getSheetData($spreadsheetId, $sheetName);
            
            if (empty($sheetData)) {
                error_log("[GoogleSheetsSyncService::syncSheet] ❌ Лист '{$sheetName}' пуст или не найден");
                return $stats;
            }
            error_log("[GoogleSheetsSyncService::syncSheet] ✓ Получено строк из Google Sheets: " . count($sheetData));
            
            // Показываем первые строки для отладки
            if (count($sheetData) > 0) {
                error_log("[GoogleSheetsSyncService::syncSheet] 📄 Первая строка (заголовки): " . json_encode($sheetData[0], JSON_UNESCAPED_UNICODE));
                if (count($sheetData) > 1) {
                    error_log("[GoogleSheetsSyncService::syncSheet] 📄 Вторая строка (пример данных): " . json_encode($sheetData[1], JSON_UNESCAPED_UNICODE));
                }
            }

            // 2. Парсим данные (UUID и Person Brizy)
            error_log("[GoogleSheetsSyncService::syncSheet] 🔍 Шаг 4: Парсинг данных (поиск колонок UUID и Person Brizy)...");
            $parsedData = $this->googleSheetsService->parseSheetData($sheetData);
            $stats['total_rows'] = count($parsedData);
            
            error_log("[GoogleSheetsSyncService::syncSheet] ✓ После парсинга: " . count($parsedData) . " строк с данными");
            
            // Логируем первые несколько распарсенных строк для отладки
            if (!empty($parsedData)) {
                $sample = array_slice($parsedData, 0, min(5, count($parsedData)));
                error_log("[GoogleSheetsSyncService::syncSheet] 📊 Пример распарсенных данных (первые " . count($sample) . " строк):");
                foreach ($sample as $idx => $row) {
                    error_log("  " . ($idx + 1) . ". UUID: {$row['uuid']}, Person Brizy: " . ($row['person_brizy'] ?? 'null'));
                }
            }

            if (empty($parsedData)) {
                error_log("[GoogleSheetsSyncService::syncSheet] ❌ Нет данных для синхронизации после парсинга");
                error_log("[GoogleSheetsSyncService::syncSheet] Проверьте:");
                error_log("  - Есть ли колонка 'UUID' в первой строке");
                error_log("  - Есть ли колонка 'Person Brizy' в первой строке");
                error_log("  - Есть ли данные в строках (кроме заголовка)");
                return $stats;
            }

            // 3. Для каждой строки находим миграцию и создаем/обновляем запись
            $db = $this->dbService->getWriteConnection();
            
            // Пытаемся начать транзакцию (опционально, если поддерживается)
            $pdo = null;
            $transactionStarted = false;
            try {
                $reflection = new \ReflectionClass($db);
                $pdoProperty = $reflection->getProperty('pdo');
                $pdoProperty->setAccessible(true);
                $pdo = $pdoProperty->getValue($db);
                
                if ($pdo && method_exists($pdo, 'beginTransaction')) {
                    $pdo->beginTransaction();
                    $transactionStarted = true;
                }
            } catch (Exception $e) {
                // Если не удалось начать транзакцию, продолжаем без неё
                error_log("[GoogleSheetsSyncService::syncSheet] Не удалось начать транзакцию: " . $e->getMessage() . ". Продолжаем без транзакции.");
            }
            
            try {
                foreach ($parsedData as $row) {
                    $uuid = $row['uuid'];
                    $personBrizy = $row['person_brizy'];
                    
                    try {
                        // Находим миграцию по UUID
                        $migration = $this->findMigrationByUuid($uuid, $waveId);
                        
                        if (!$migration) {
                            $stats['not_found']++;
                            error_log("[GoogleSheetsSyncService::syncSheet] Миграция с UUID '{$uuid}' не найдена");
                            continue;
                        }
                        
                        $migrationId = (int)$migration['id'];
                        $stats['processed']++;
                        
                        // Создаем или обновляем запись в migration_reviewers
                        $result = $this->upsertMigrationReviewer($migrationId, $uuid, $personBrizy);
                        
                        if ($result['created']) {
                            $stats['created']++;
                        } else {
                            $stats['updated']++;
                        }
                        
                    } catch (Exception $e) {
                        $stats['errors']++;
                        $stats['errors_list'][] = [
                            'uuid' => $uuid,
                            'error' => $e->getMessage()
                        ];
                        error_log("[GoogleSheetsSyncService::syncSheet] Ошибка обработки UUID '{$uuid}': " . $e->getMessage());
                        error_log("[GoogleSheetsSyncService::syncSheet] Stack trace: " . $e->getTraceAsString());
                        // Продолжаем обработку следующих строк
                    }
                }
                
                // 4. Обновляем last_synced_at в таблице google_sheets
                $this->updateLastSyncedAt($spreadsheetId, $sheetName);
                
                // Коммитим транзакцию, если она была начата
                if ($transactionStarted && $pdo && method_exists($pdo, 'commit')) {
                    error_log("[GoogleSheetsSyncService::syncSheet] 💾 Коммит транзакции...");
                    try {
                        $pdo->commit();
                        error_log("[GoogleSheetsSyncService::syncSheet] ✓ Транзакция закоммичена успешно");
                    } catch (Exception $commitError) {
                        error_log("[GoogleSheetsSyncService::syncSheet] ❌ ОШИБКА при коммите транзакции: " . $commitError->getMessage());
                        throw $commitError;
                    }
                } else {
                    if (!$transactionStarted) {
                        error_log("[GoogleSheetsSyncService::syncSheet] ⚠ Транзакция не была начата, изменения применяются без транзакции");
                    }
                }
                
            } catch (Exception $e) {
                // Откатываем транзакцию при ошибке, если она была начата
                error_log("[GoogleSheetsSyncService::syncSheet] ❌ ОШИБКА: Откат транзакции...");
                if ($transactionStarted && $pdo && method_exists($pdo, 'rollBack')) {
                    try {
                        $pdo->rollBack();
                        error_log("[GoogleSheetsSyncService::syncSheet] ✓ Транзакция откачена");
                    } catch (Exception $rollbackError) {
                        error_log("[GoogleSheetsSyncService::syncSheet] ❌ Ошибка отката транзакции: " . $rollbackError->getMessage());
                    }
                }
                throw $e;
            }
            
            error_log("[GoogleSheetsSyncService::syncSheet] 📊 ИТОГОВАЯ СТАТИСТИКА:");
            error_log("  Всего строк: {$stats['total_rows']}");
            error_log("  Обработано: {$stats['processed']}");
            error_log("  Создано: {$stats['created']}");
            error_log("  Обновлено: {$stats['updated']}");
            error_log("  Не найдено миграций: {$stats['not_found']}");
            error_log("  Ошибок: {$stats['errors']}");
            error_log("═══════════════════════════════════════════════════════════════");
            error_log("[GoogleSheetsSyncService::syncSheet] ✅ СИНХРОНИЗАЦИЯ ЗАВЕРШЕНА УСПЕШНО");
            error_log("═══════════════════════════════════════════════════════════════");
            
            return $stats;
            
        } catch (Exception $e) {
            error_log("═══════════════════════════════════════════════════════════════");
            error_log("[GoogleSheetsSyncService::syncSheet] ❌ КРИТИЧЕСКАЯ ОШИБКА: " . $e->getMessage());
            error_log("[GoogleSheetsSyncService::syncSheet] Stack trace: " . $e->getTraceAsString());
            error_log("═══════════════════════════════════════════════════════════════");
            
            // Проверяем, является ли это ошибкой rate limit
            $message = $e->getMessage();
            if (strpos($message, '429') !== false || 
                strpos($message, 'rateLimitExceeded') !== false ||
                strpos($message, 'Quota exceeded') !== false ||
                strpos($message, 'Превышен лимит запросов') !== false) {
                throw new Exception(
                    'Превышен лимит запросов к Google Sheets API (60 запросов в минуту на пользователя). ' .
                    'Пожалуйста, подождите минуту и попробуйте снова. ' .
                    'Для увеличения лимита запросите повышение квоты в Google Cloud Console: ' .
                    'https://cloud.google.com/docs/quotas/help/request_increase'
                );
            }
            
            throw new Exception('Ошибка синхронизации листа: ' . $e->getMessage() . ' (проверьте логи для деталей)');
        }
    }

    /**
     * Синхронизировать лист по ID записи в таблице google_sheets
     * 
     * @param int $id ID записи в таблице google_sheets
     * @param string|null $sheetName Опциональное название листа (если не указано, берется из БД)
     * @return array Статистика синхронизации
     * @throws Exception
     */
    public function syncSheetById(int $id, ?string $sheetName = null): array
    {
        $db = $this->dbService->getWriteConnection();
        $sheet = $db->find(
            "SELECT spreadsheet_id, sheet_name, wave_id 
             FROM google_sheets 
             WHERE id = ?",
            [$id]
        );

        if (!$sheet) {
            throw new Exception("Таблица с ID {$id} не найдена");
        }

        // Используем переданное название листа или берем из БД
        $finalSheetName = $sheetName ?? $sheet['sheet_name'];
        
        if (empty($finalSheetName)) {
            throw new Exception("Название листа не указано и не найдено в базе данных для записи с ID {$id}");
        }

        return $this->syncSheet(
            $sheet['spreadsheet_id'],
            $finalSheetName,
            $sheet['wave_id'] ?? null
        );
    }

    /**
     * Синхронизировать все подключенные таблицы
     * 
     * @return array Общая статистика по всем таблицам
     * @throws Exception
     */
    public function syncAllSheets(): array
    {
        $db = $this->dbService->getWriteConnection();
        $sheets = $db->getAllRows(
            "SELECT id, spreadsheet_id, sheet_name, wave_id 
             FROM google_sheets 
             WHERE sheet_name IS NOT NULL 
             ORDER BY last_synced_at ASC, created_at ASC"
        );

        $totalStats = [
            'total_sheets' => count($sheets),
            'synced' => 0,
            'failed' => 0,
            'total_rows' => 0,
            'total_processed' => 0,
            'total_created' => 0,
            'total_updated' => 0,
            'total_not_found' => 0,
            'total_errors' => 0,
            'sheets' => []
        ];

        foreach ($sheets as $sheet) {
            try {
                $stats = $this->syncSheet(
                    $sheet['spreadsheet_id'],
                    $sheet['sheet_name'],
                    $sheet['wave_id'] ?? null
                );
                
                $stats['sheet_id'] = $sheet['id'];
                $stats['sheet_name'] = $sheet['sheet_name'];
                $totalStats['sheets'][] = $stats;
                
                $totalStats['synced']++;
                $totalStats['total_rows'] += $stats['total_rows'];
                $totalStats['total_processed'] += $stats['processed'];
                $totalStats['total_created'] += $stats['created'];
                $totalStats['total_updated'] += $stats['updated'];
                $totalStats['total_not_found'] += $stats['not_found'];
                $totalStats['total_errors'] += $stats['errors'];
                
            } catch (Exception $e) {
                $totalStats['failed']++;
                $totalStats['sheets'][] = [
                    'sheet_id' => $sheet['id'],
                    'sheet_name' => $sheet['sheet_name'],
                    'error' => $e->getMessage()
                ];
                error_log("[GoogleSheetsSyncService::syncAllSheets] Ошибка синхронизации листа ID={$sheet['id']}: " . $e->getMessage());
                // Продолжаем синхронизацию других таблиц
            }
        }

        return $totalStats;
    }

    /**
     * Найти миграцию в таблице migrations по UUID
     * 
     * @param string $uuid UUID проекта
     * @param string|null $waveId ID волны (опционально, для уточнения поиска)
     * @return array|null Данные миграции или null
     * @throws Exception
     */
    public function findMigrationByUuid(string $uuid, ?string $waveId = null): ?array
    {
        $db = $this->dbService->getWriteConnection();
        
        // Если указан wave_id, ищем миграцию в этой волне
        if ($waveId) {
            $migration = $db->find(
                "SELECT * FROM migrations 
                 WHERE mb_project_uuid = ? AND wave_id = ? 
                 ORDER BY created_at DESC LIMIT 1",
                [$uuid, $waveId]
            );
            
            if ($migration) {
                return $migration;
            }
        }
        
        // Ищем миграцию без привязки к волне
        $migration = $db->find(
            "SELECT * FROM migrations 
             WHERE mb_project_uuid = ? 
             ORDER BY created_at DESC LIMIT 1",
            [$uuid]
        );
        
        return $migration ?: null;
    }

    /**
     * Создать или обновить запись в migration_reviewers
     * 
     * @param int $migrationId ID миграции
     * @param string $uuid UUID проекта
     * @param string|null $personBrizy Имя ревьюера
     * @return array Результат операции ['created' => bool, 'id' => int]
     * @throws Exception
     */
    public function upsertMigrationReviewer(int $migrationId, string $uuid, ?string $personBrizy = null): array
    {
        error_log("[GoogleSheetsSyncService::upsertMigrationReviewer] 🔄 Начало upsert:");
        error_log("  Migration ID: {$migrationId}");
        error_log("  UUID: {$uuid}");
        error_log("  Person Brizy: " . ($personBrizy ?? 'null'));
        
        $db = $this->dbService->getWriteConnection();
        
        try {
            // Проверяем существующую запись
            error_log("[GoogleSheetsSyncService::upsertMigrationReviewer] 🔍 Поиск существующей записи...");
            $existing = $db->find(
                "SELECT id FROM migration_reviewers 
                 WHERE migration_id = ? AND uuid = ?",
                [$migrationId, $uuid]
            );
            
            if ($existing) {
                error_log("[GoogleSheetsSyncService::upsertMigrationReviewer] ✓ Найдена существующая запись: ID={$existing['id']}");
                error_log("[GoogleSheetsSyncService::upsertMigrationReviewer] 💾 Обновление записи...");
                
                // Обновляем существующую запись
                $reflection = new \ReflectionClass($db);
                $pdoProperty = $reflection->getProperty('pdo');
                $pdoProperty->setAccessible(true);
                $pdo = $pdoProperty->getValue($db);
                
                $stmt = $pdo->prepare(
                    "UPDATE migration_reviewers 
                     SET person_brizy = ?, updated_at = NOW() 
                     WHERE id = ?"
                );
                $stmt->execute([$personBrizy, $existing['id']]);
                
                $affectedRows = $stmt->rowCount();
                error_log("[GoogleSheetsSyncService::upsertMigrationReviewer] ✓ Обновлено строк: {$affectedRows}");
                
                return [
                    'created' => false,
                    'id' => (int)$existing['id']
                ];
            } else {
                error_log("[GoogleSheetsSyncService::upsertMigrationReviewer] 📝 Запись не найдена, создаем новую...");
                
                // Проверяем существование миграции перед вставкой
                error_log("[GoogleSheetsSyncService::upsertMigrationReviewer] 🔍 Проверка существования миграции ID={$migrationId}...");
                $migrationExists = $db->find(
                    "SELECT id FROM migrations WHERE id = ?",
                    [$migrationId]
                );
                
                if (!$migrationExists) {
                    error_log("[GoogleSheetsSyncService::upsertMigrationReviewer] ❌ ОШИБКА: Миграция с ID {$migrationId} не существует!");
                    throw new Exception("Миграция с ID {$migrationId} не существует в таблице migrations. Невозможно создать запись в migration_reviewers.");
                }
                
                error_log("[GoogleSheetsSyncService::upsertMigrationReviewer] ✓ Миграция существует, создаем запись...");
                
                // Создаем новую запись
                $insertData = [
                    'migration_id' => $migrationId,
                    'uuid' => $uuid,
                    'person_brizy' => $personBrizy
                ];
                
                error_log("[GoogleSheetsSyncService::upsertMigrationReviewer] 📊 Данные для вставки: " . json_encode($insertData, JSON_UNESCAPED_UNICODE));
                
                $id = $db->insert('migration_reviewers', $insertData);
                
                error_log("[GoogleSheetsSyncService::upsertMigrationReviewer] ✅ Запись создана успешно: ID={$id}");
                
                // Проверяем, что запись действительно создана
                $verify = $db->find(
                    "SELECT id FROM migration_reviewers WHERE id = ?",
                    [$id]
                );
                
                if ($verify) {
                    error_log("[GoogleSheetsSyncService::upsertMigrationReviewer] ✓ Запись подтверждена в БД");
                } else {
                    error_log("[GoogleSheetsSyncService::upsertMigrationReviewer] ⚠ ПРЕДУПРЕЖДЕНИЕ: Запись не найдена после создания!");
                }
                
                return [
                    'created' => true,
                    'id' => $id
                ];
            }
        } catch (Exception $e) {
            error_log("[GoogleSheetsSyncService::upsertMigrationReviewer] ❌ ОШИБКА: " . $e->getMessage());
            error_log("[GoogleSheetsSyncService::upsertMigrationReviewer] Stack trace: " . $e->getTraceAsString());
            
            // Проверяем, возможно таблица не существует
            if (strpos($e->getMessage(), "doesn't exist") !== false || 
                strpos($e->getMessage(), "Table") !== false ||
                strpos($e->getMessage(), "1146") !== false) {
                error_log("[GoogleSheetsSyncService::upsertMigrationReviewer] ❌ Таблица migration_reviewers не существует");
                throw new Exception("Таблица migration_reviewers не существует. Выполните миграцию базы данных.");
            }
            // Проверяем ошибки внешнего ключа
            if (strpos($e->getMessage(), "foreign key") !== false || 
                strpos($e->getMessage(), "1452") !== false ||
                strpos($e->getMessage(), "Cannot add or update") !== false) {
                error_log("[GoogleSheetsSyncService::upsertMigrationReviewer] ❌ Ошибка внешнего ключа: миграция не существует");
                throw new Exception("Ошибка внешнего ключа: миграция с ID {$migrationId} не существует в таблице migrations.");
            }
            
            // Логируем полную информацию об ошибке
            if ($e instanceof \PDOException) {
                error_log("[GoogleSheetsSyncService::upsertMigrationReviewer] PDO Error Code: " . $e->getCode());
                error_log("[GoogleSheetsSyncService::upsertMigrationReviewer] PDO Error Info: " . json_encode($e->errorInfo ?? []));
            }
            
            throw $e;
        }
    }

    /**
     * Обновить last_synced_at в таблице google_sheets
     * 
     * @param string $spreadsheetId ID Google таблицы
     * @param string $sheetName Название листа
     * @return void
     * @throws Exception
     */
    private function updateLastSyncedAt(string $spreadsheetId, string $sheetName): void
    {
        $db = $this->dbService->getWriteConnection();
        
        $reflection = new \ReflectionClass($db);
        $pdoProperty = $reflection->getProperty('pdo');
        $pdoProperty->setAccessible(true);
        $pdo = $pdoProperty->getValue($db);
        
        $stmt = $pdo->prepare(
            "UPDATE google_sheets 
             SET last_synced_at = NOW(), updated_at = NOW() 
             WHERE spreadsheet_id = ? AND sheet_name = ?"
        );
        $stmt->execute([$spreadsheetId, $sheetName]);
    }
}
