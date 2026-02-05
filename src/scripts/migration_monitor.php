#!/usr/bin/env php
<?php
/**
 * Migration Process Monitor Worker
 * 
 * Этот скрипт должен запускаться как демон и мониторить процессы миграции.
 * Запуск: php dashboard/api/scripts/migration_monitor.php
 * Или через systemd/supervisor для постоянной работы
 */

require_once dirname(__DIR__, 3) . '/vendor/autoload_runtime.php';

// Загрузка переменных окружения
if (file_exists(dirname(__DIR__, 3) . '/.env')) {
    $dotenv = \Dotenv\Dotenv::createMutable(dirname(__DIR__, 3));
    $dotenv->safeLoad();
}

use Dashboard\Services\DatabaseService;
use Dashboard\Services\MigrationService;

class MigrationMonitor
{
    private $cachePath;
    private $dbService;
    private $migrationService;
    private $checkInterval; // Интервал проверки в секундах
    private $running = true;

    public function __construct()
    {
        $projectRoot = dirname(__DIR__, 3);
        $this->cachePath = $_ENV['CACHE_PATH'] ?? getenv('CACHE_PATH') ?: $projectRoot . '/var/cache';
        $this->checkInterval = (int)($_ENV['MIGRATION_MONITOR_INTERVAL'] ?? getenv('MIGRATION_MONITOR_INTERVAL') ?: 10); // По умолчанию 10 секунд
        
        $this->dbService = new DatabaseService();
        $this->migrationService = new MigrationService();
        
        // Обработка сигналов для корректного завершения
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
        }
    }

    public function handleSignal($signo)
    {
        echo "Получен сигнал $signo, завершаем работу...\n";
        $this->running = false;
    }

    /**
     * Получить все lock-файлы миграций
     */
    private function getLockFiles(): array
    {
        $lockFiles = [];
        
        if (!is_dir($this->cachePath)) {
            return $lockFiles;
        }
        
        $files = glob($this->cachePath . '/*.lock');
        
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content) {
                $data = json_decode($content, true);
                if ($data && isset($data['mb_project_uuid']) && isset($data['brz_project_id'])) {
                    $lockFiles[] = [
                        'file' => $file,
                        'data' => $data
                    ];
                }
            }
        }
        
        return $lockFiles;
    }

    /**
     * Проверить, запущен ли процесс по PID
     */
    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        
        // Используем posix_kill с сигналом 0 для проверки существования процесса
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        
        // Альтернативный способ через ps
        $command = sprintf('ps -p %d > /dev/null 2>&1', $pid);
        exec($command, $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Обновить статус миграции на error, если процесс не найден
     */
    private function updateStaleMigration(string $mbUuid, int $brzProjectId, array $lockData): void
    {
        try {
            $mapping = $this->dbService->getMigrationById($brzProjectId);
            if (!$mapping) {
                return;
            }

            $changesJson = [];
            if (!empty($mapping['changes_json'])) {
                $changesJson = is_string($mapping['changes_json']) 
                    ? json_decode($mapping['changes_json'], true) 
                    : $mapping['changes_json'];
            }

            if (isset($changesJson['status']) && $changesJson['status'] === 'in_progress') {
                $lockFileAge = isset($lockData['started_timestamp']) 
                    ? (time() - $lockData['started_timestamp']) 
                    : 999999;

                // Обновляем статус, если процесс не найден и lock-файл старый (более 10 минут)
                if ($lockFileAge > 600) {
                    $changesJson['status'] = 'error';
                    $changesJson['error'] = 'Процесс миграции был прерван или завершился некорректно. Статус обновлен монитором.';
                    $changesJson['status_updated_at'] = date('Y-m-d H:i:s');
                    $changesJson['monitor_updated'] = true;

                    $this->dbService->upsertMigrationMapping(
                        $brzProjectId,
                        $mbUuid,
                        $changesJson
                    );

                    echo sprintf(
                        "[%s] Обновлен статус миграции %s (PID: %d) - процесс не найден, lock-файл старше %d минут\n",
                        date('Y-m-d H:i:s'),
                        $brzProjectId,
                        $lockData['pid'] ?? 0,
                        round($lockFileAge / 60)
                    );
                }
            }
        } catch (Exception $e) {
            error_log("Ошибка обновления статуса миграции в мониторе: " . $e->getMessage());
        }
    }

    /**
     * Удалить lock-файл, если процесс завершен
     */
    private function cleanupLockFile(string $lockFile, array $lockData): void
    {
        try {
            if (@unlink($lockFile)) {
                echo sprintf(
                    "[%s] Удален lock-файл для миграции %s (PID: %d был завершен)\n",
                    date('Y-m-d H:i:s'),
                    $lockData['brz_project_id'] ?? 'unknown',
                    $lockData['pid'] ?? 0
                );
            }
        } catch (Exception $e) {
            error_log("Ошибка удаления lock-файла: " . $e->getMessage());
        }
    }

    /**
     * Основной цикл мониторинга
     */
    public function run(): void
    {
        echo sprintf("[%s] Запуск монитора миграций (интервал проверки: %d сек)\n", date('Y-m-d H:i:s'), $this->checkInterval);
        
        while ($this->running) {
            try {
                $lockFiles = $this->getLockFiles();
                
                if (empty($lockFiles)) {
                    // Нет активных миграций, ждем следующей проверки
                    sleep($this->checkInterval);
                    continue;
                }

                echo sprintf("[%s] Проверка %d активных миграций...\n", date('Y-m-d H:i:s'), count($lockFiles));

                foreach ($lockFiles as $lockInfo) {
                    $lockFile = $lockInfo['file'];
                    $lockData = $lockInfo['data'];
                    
                    $pid = $lockData['pid'] ?? null;
                    $mbUuid = $lockData['mb_project_uuid'] ?? '';
                    $brzProjectId = $lockData['brz_project_id'] ?? 0;

                    if (!$pid || $pid <= 0) {
                        continue;
                    }

                    // Проверяем, запущен ли процесс
                    $isRunning = $this->isProcessRunning($pid);

                    if (!$isRunning) {
                        // Процесс не найден - проверяем статус и обновляем при необходимости
                        $this->updateStaleMigration($mbUuid, $brzProjectId, $lockData);
                        
                        // Удаляем lock-файл, если процесс завершен
                        $lockFileAge = isset($lockData['started_timestamp']) 
                            ? (time() - $lockData['started_timestamp']) 
                            : 999999;
                        
                        if ($lockFileAge > 600) { // Более 10 минут
                            $this->cleanupLockFile($lockFile, $lockData);
                        }
                    } else {
                        // Процесс работает - обновляем время последней проверки в lock-файле
                        $lockData['last_check'] = date('Y-m-d H:i:s');
                        $lockData['last_check_timestamp'] = time();
                        @file_put_contents($lockFile, json_encode($lockData, JSON_PRETTY_PRINT));
                    }
                }

            } catch (Exception $e) {
                error_log("Ошибка в мониторе миграций: " . $e->getMessage());
                echo sprintf("[%s] Ошибка: %s\n", date('Y-m-d H:i:s'), $e->getMessage());
            }

            // Обработка сигналов (для корректного завершения)
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // Ждем перед следующей проверкой
            sleep($this->checkInterval);
        }

        echo sprintf("[%s] Монитор миграций остановлен\n", date('Y-m-d H:i:s'));
    }
}

// Запуск монитора
if (php_sapi_name() === 'cli') {
    $monitor = new MigrationMonitor();
    $monitor->run();
} else {
    die("Этот скрипт должен запускаться только из командной строки\n");
}
