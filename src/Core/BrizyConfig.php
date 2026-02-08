<?php

namespace Dashboard\Core;

/**
 * BrizyConfig
 * 
 * Конфигурация для работы с Brizy API
 * Заменяет статический MBMigration\Core\Config
 */
class BrizyConfig
{
    private string $apiToken;
    private string $baseUrl;
    private string $projectRoot;
    private string $logPath;
    private string $cachePath;
    private string $tmpPath;
    private bool $debugMode;

    public function __construct()
    {
        $this->loadFromEnv();
    }

    /**
     * Загрузить настройки из переменных окружения
     */
    private function loadFromEnv(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        
        // Загружаем .env файлы
        if (file_exists($projectRoot . '/.env')) {
            $dotenv = \Dotenv\Dotenv::createMutable($projectRoot);
            $dotenv->safeLoad();
        }

        $this->apiToken = $_ENV['BRIZY_TOKEN'] ?? getenv('BRIZY_TOKEN') ?? '';
        // Поддерживаем оба варианта переменных окружения для обратной совместимости
        $this->baseUrl = $_ENV['BRIZY_HOST'] ?? getenv('BRIZY_HOST') 
            ?? $_ENV['BRIZY_CLOUD_HOST'] ?? getenv('BRIZY_CLOUD_HOST') 
            ?? 'https://admin.brizy.io';
        $this->projectRoot = $projectRoot;
        $this->logPath = $_ENV['LOG_PATH'] ?? getenv('LOG_PATH') ?: $projectRoot . '/var/log';
        $this->cachePath = $_ENV['CACHE_PATH'] ?? getenv('CACHE_PATH') ?: $projectRoot . '/var/cache';
        $this->tmpPath = $_ENV['TMP_PATH'] ?? getenv('TMP_PATH') ?: $projectRoot . '/var/tmp';
        
        $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'production';
        $this->debugMode = ($appEnv === 'development' || ($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG')) === 'true');
    }

    public function getApiToken(): string
    {
        return $this->apiToken;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getProjectRoot(): string
    {
        return $this->projectRoot;
    }

    public function getLogPath(): string
    {
        return $this->logPath;
    }

    public function getCachePath(): string
    {
        return $this->cachePath;
    }

    public function getTmpPath(): string
    {
        return $this->tmpPath;
    }

    public function isDebugMode(): bool
    {
        return $this->debugMode;
    }

    /**
     * Проверить, что конфигурация валидна
     * 
     * @throws \Exception
     */
    public function validate(): void
    {
        if (empty($this->apiToken)) {
            throw new \Exception('BRIZY_TOKEN не установлен в переменных окружения');
        }
    }
}
