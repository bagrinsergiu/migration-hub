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

        // Получаем mbSiteId для сохранения файла в правильную директорию
        $mbSiteId = $this->getMbSiteIdByUuid($mbUuid);
        $dirId = $mbSiteId ? (string)$mbSiteId : $mbUuid; // Используем mbSiteId, если доступен, иначе mbUuid

        // Создаем директорию для проекта, если её нет
        $projectDir = $this->screenshotsDir . $dirId . '/';
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

        // Получаем mbSiteId для генерации URL (используем тот же dirId, что и для сохранения)
        $urlSiteId = $mbSiteId ? (string)$mbSiteId : $mbUuid; // Fallback на mbUuid, если mbSiteId не найден

        return [
            'filename' => $filename,
            'path' => $filePath,
            'url' => '/api/screenshots/' . $urlSiteId . '/' . $filename,
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
        $screenshot = $db->find(
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

        // Получаем mbSiteId для генерации URL
        $mbSiteId = $this->getMbSiteIdByUuid($mbUuid);
        $urlSiteId = $mbSiteId ? $mbSiteId : $mbUuid; // Fallback на mbUuid, если mbSiteId не найден

        return [
            'filename' => $screenshot['filename'],
            'path' => $filePath,
            'url' => '/api/screenshots/' . $urlSiteId . '/' . $screenshot['filename'],
            'size' => filesize($filePath),
            'type' => $type,
            'created_at' => $screenshot['created_at']
        ];
    }

    /**
     * Получить файл скриншота по mbSiteId
     * 
     * @param string|int $mbSiteId MB Site ID проекта
     * @param string $filename Имя файла
     * @return string|null Путь к файлу или null, если не найден
     */
    public function getScreenshotFileBySiteId($mbSiteId, string $filename): ?string
    {
        $filename = basename($filename);
        $mbSiteId = (string)$mbSiteId;
        
        // Пробуем найти файл в директории mb_site_id
        $filePath = $this->screenshotsDir . $mbSiteId . '/' . $filename;
        
        if (file_exists($filePath) && is_file($filePath)) {
            return $filePath;
        }
        
        // Пробуем то же имя с другим расширением (например .png вместо .jpg)
        $baseWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
        $siteDir = $this->screenshotsDir . $mbSiteId . '/';
        if (is_dir($siteDir)) {
            $altMatches = glob($siteDir . $baseWithoutExt . '.*');
            if (!empty($altMatches) && file_exists($altMatches[0]) && is_file($altMatches[0])) {
                return $altMatches[0];
            }
        }

        return null;
    }

    /**
     * Получить файл скриншота по пути (старый метод для обратной совместимости)
     * 
     * @param string $mbUuid MB UUID проекта
     * @param string $filename Имя файла
     * @return string|null Путь к файлу или null, если не найден
     */
    public function getScreenshotFile(string $mbUuid, string $filename): ?string
    {
        $filename = basename($filename);
        
        // Сначала ищем в БД по имени файла и mbUuid (точное совпадение)
        $db = $this->dbService->getWriteConnection();
        $screenshot = $db->find(
            'SELECT file_path 
             FROM dashboard_screenshots 
             WHERE mb_uuid = ? AND filename = ? 
             ORDER BY created_at DESC 
             LIMIT 1',
            [$mbUuid, $filename]
        );
        
        if ($screenshot && isset($screenshot['file_path'])) {
            $filePath = $screenshot['file_path'];
            if (file_exists($filePath) && is_file($filePath)) {
                return $filePath;
            }
        }
        
        // Если не нашли точное совпадение, пробуем найти по части имени файла
        // (на случай, если имя файла в URL отличается от имени в БД)
        $screenshot = $db->find(
            'SELECT file_path 
             FROM dashboard_screenshots 
             WHERE mb_uuid = ? AND (filename = ? OR filename LIKE ? OR file_path LIKE ?)
             ORDER BY created_at DESC 
             LIMIT 1',
            [$mbUuid, $filename, '%' . $filename, '%' . $filename]
        );
        
        if ($screenshot && isset($screenshot['file_path'])) {
            $filePath = $screenshot['file_path'];
            if (file_exists($filePath) && is_file($filePath)) {
                return $filePath;
            }
        }
        
        // Если не нашли в БД, пробуем стандартный путь по mbUuid
        $filePath = $this->screenshotsDir . $mbUuid . '/' . $filename;
        
        if (file_exists($filePath) && is_file($filePath)) {
            return $filePath;
        }
        
        // Пробуем то же имя с другим расширением (например .png вместо .jpg) в папке mbUuid
        $baseWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
        $uuidDir = $this->screenshotsDir . $mbUuid . '/';
        if (is_dir($uuidDir)) {
            $altMatches = glob($uuidDir . $baseWithoutExt . '.*');
            if (!empty($altMatches) && file_exists($altMatches[0]) && is_file($altMatches[0])) {
                return $altMatches[0];
            }
        }
        
        // Fallback: пробуем найти по mb_site_id (для обратной совместимости со старыми файлами)
        // Получаем mb_site_id из миграции по mbUuid
        try {
            $migration = $db->find(
                'SELECT mb_site_id FROM migrations WHERE mb_project_uuid = ? LIMIT 1',
                [$mbUuid]
            );
            
            if ($migration && isset($migration['mb_site_id']) && $migration['mb_site_id']) {
                $mbSiteId = $migration['mb_site_id'];
                $siteDir = $this->screenshotsDir . $mbSiteId . '/';
                
                // Пробуем найти файл в директории mb_site_id
                $siteFilePath = $siteDir . $filename;
                if (file_exists($siteFilePath) && is_file($siteFilePath)) {
                    return $siteFilePath;
                }
                
                // Пробуем с альтернативным расширением
                if (is_dir($siteDir)) {
                    $altMatches = glob($siteDir . $baseWithoutExt . '.*');
                    if (!empty($altMatches) && file_exists($altMatches[0]) && is_file($altMatches[0])) {
                        return $altMatches[0];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Игнорируем ошибки при поиске по mb_site_id
            error_log("Error searching screenshot by mb_site_id: " . $e->getMessage());
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
     * Получить mb_site_id по mbUuid из таблицы migrations
     * 
     * @param string $mbUuid MB UUID проекта
     * @return int|null mb_site_id или null, если не найден
     */
    private function getMbSiteIdByUuid(string $mbUuid): ?int
    {
        try {
            $db = $this->dbService->getWriteConnection();
            $migration = $db->find(
                'SELECT mb_site_id FROM migrations WHERE mb_project_uuid = ? LIMIT 1',
                [$mbUuid]
            );
            
            if ($migration && isset($migration['mb_site_id']) && $migration['mb_site_id']) {
                return (int)$migration['mb_site_id'];
            }
        } catch (\Throwable $e) {
            error_log("Error getting mb_site_id by mbUuid: " . $e->getMessage());
        }
        
        return null;
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
