<?php

namespace Dashboard\Services;

use Exception;
use Dashboard\Services\DatabaseService;

/**
 * ScreenshotService
 * 
 * Сервис для управления скриншотами в дашборде
 */
class ScreenshotService
{
    private $projectRoot;
    private $screenshotsDir;
    private $dbService;

    public function __construct()
    {
        $this->dbService = new DatabaseService();
        
        // Определяем корневую директорию проекта
        $currentFile = __FILE__;
        $this->projectRoot = dirname(dirname(dirname($currentFile)));
        $this->screenshotsDir = $this->projectRoot . '/var/screenshots/';
        
        // Создаем директорию для скриншотов, если её нет
        if (!is_dir($this->screenshotsDir)) {
            mkdir($this->screenshotsDir, 0755, true);
        }
    }

    /**
     * Сохранить скриншот из миграции
     * 
     * @param string $mbUuid MB UUID проекта
     * @param string $pageSlug Slug страницы
     * @param string $type Тип скриншота: 'source' или 'migrated'
     * @param string $fileContent Содержимое файла (base64 или бинарные данные)
     * @param string $filename Имя файла (опционально)
     * @return array Информация о сохраненном скриншоте
     * @throws Exception
     */
    public function saveScreenshot(string $mbUuid, string $pageSlug, string $type, string $fileContent, ?string $filename = null): array
    {
        if (!in_array($type, ['source', 'migrated'])) {
            throw new Exception("Неверный тип скриншота. Используйте: 'source' или 'migrated'");
        }

        // Создаем директорию для проекта, если её нет
        $projectDir = $this->screenshotsDir . $mbUuid . '/';
        if (!is_dir($projectDir)) {
            mkdir($projectDir, 0755, true);
        }

        // Генерируем имя файла, если не указано
        if (!$filename) {
            $extension = 'png'; // По умолчанию PNG
            $filename = $pageSlug . '_' . $type . '_' . time() . '.' . $extension;
        }

        // Декодируем base64, если это base64
        $binaryContent = $fileContent;
        if (preg_match('/^data:image\/(\w+);base64,/', $fileContent, $matches)) {
            $binaryContent = base64_decode(substr($fileContent, strpos($fileContent, ',') + 1));
        } elseif (base64_encode(base64_decode($fileContent, true)) === $fileContent) {
            // Проверяем, является ли строка валидным base64
            $binaryContent = base64_decode($fileContent);
        }

        $filePath = $projectDir . $filename;
        
        // Сохраняем файл
        if (file_put_contents($filePath, $binaryContent) === false) {
            throw new Exception("Не удалось сохранить скриншот: $filePath");
        }

        // Сохраняем информацию о скриншоте в БД
        $this->saveScreenshotMetadata($mbUuid, $pageSlug, $type, $filename, $filePath);

        return [
            'filename' => $filename,
            'path' => $filePath,
            'url' => '/api/screenshots/' . $mbUuid . '/' . $filename,
            'size' => filesize($filePath),
            'type' => $type
        ];
    }

    /**
     * Получить скриншот
     * 
     * @param string $mbUuid MB UUID проекта
     * @param string $pageSlug Slug страницы
     * @param string $type Тип скриншота: 'source' или 'migrated'
     * @return array|null Информация о скриншоте или null, если не найден
     */
    public function getScreenshot(string $mbUuid, string $pageSlug, string $type): ?array
    {
        if (!in_array($type, ['source', 'migrated'])) {
            return null;
        }

        // Ищем в БД
        $db = $this->dbService->getWriteConnection();
        $screenshot = $db->getRow(
            'SELECT filename, file_path, created_at 
             FROM dashboard_screenshots 
             WHERE mb_uuid = ? AND page_slug = ? AND type = ? 
             ORDER BY created_at DESC 
             LIMIT 1',
            [$mbUuid, $pageSlug, $type]
        );

        if (!$screenshot) {
            return null;
        }

        $filePath = $screenshot['file_path'];
        
        // Проверяем существование файла
        if (!file_exists($filePath)) {
            return null;
        }

        return [
            'filename' => $screenshot['filename'],
            'path' => $filePath,
            'url' => '/api/screenshots/' . $mbUuid . '/' . $screenshot['filename'],
            'size' => filesize($filePath),
            'type' => $type,
            'created_at' => $screenshot['created_at']
        ];
    }

    /**
     * Получить файл скриншота по пути
     * 
     * @param string $mbUuid MB UUID проекта
     * @param string $filename Имя файла
     * @return string|null Путь к файлу или null, если не найден
     */
    public function getScreenshotFile(string $mbUuid, string $filename): ?string
    {
        $filePath = $this->screenshotsDir . $mbUuid . '/' . basename($filename);
        
        if (file_exists($filePath) && is_file($filePath)) {
            return $filePath;
        }

        return null;
    }

    /**
     * Сохранить метаданные скриншота в БД
     * 
     * @param string $mbUuid MB UUID проекта
     * @param string $pageSlug Slug страницы
     * @param string $type Тип скриншота
     * @param string $filename Имя файла
     * @param string $filePath Полный путь к файлу
     */
    private function saveScreenshotMetadata(string $mbUuid, string $pageSlug, string $type, string $filename, string $filePath): void
    {
        $db = $this->dbService->getWriteConnection();
        
        // Проверяем существование таблицы
        $this->ensureScreenshotsTable();
        
        // Удаляем старые скриншоты того же типа для этой страницы
        $db->execute(
            'DELETE FROM dashboard_screenshots 
             WHERE mb_uuid = ? AND page_slug = ? AND type = ?',
            [$mbUuid, $pageSlug, $type]
        );
        
        // Сохраняем новый скриншот
        $db->execute(
            'INSERT INTO dashboard_screenshots (mb_uuid, page_slug, type, filename, file_path, created_at) 
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$mbUuid, $pageSlug, $type, $filename, $filePath]
        );
    }

    /**
     * Создать таблицу для хранения метаданных скриншотов, если её нет
     */
    private function ensureScreenshotsTable(): void
    {
        $db = $this->dbService->getWriteConnection();
        
        $db->execute("
            CREATE TABLE IF NOT EXISTS dashboard_screenshots (
                id INT AUTO_INCREMENT PRIMARY KEY,
                mb_uuid VARCHAR(255) NOT NULL,
                page_slug VARCHAR(500) NOT NULL,
                type ENUM('source', 'migrated') NOT NULL,
                filename VARCHAR(500) NOT NULL,
                file_path VARCHAR(1000) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_mb_uuid (mb_uuid),
                INDEX idx_page_slug (page_slug),
                INDEX idx_type (type),
                INDEX idx_mb_page_type (mb_uuid, page_slug, type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
