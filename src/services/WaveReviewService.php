<?php

namespace Dashboard\Services;

use Exception;

/**
 * WaveReviewService
 * 
 * Сервис для управления публичными токенами доступа к волнам для мануального ревью
 */
class WaveReviewService
{
    /** @var DatabaseService */
    private $dbService;

    /** Кэш validateToken: [ token => ['wave_id' => string, 'expires' => timestamp ] ] */
    private static $validateTokenCache = [];
    private const VALIDATE_TOKEN_CACHE_TTL = 10;

    public function __construct()
    {
        $this->dbService = new DatabaseService();
    }

    /**
     * Создать токен для публичного доступа к волне
     * 
     * @param string $waveId
     * @param int|null $expiresInDays Количество дней до истечения (null = без срока)
     * @param string|null $name Название токена
     * @param string|null $description Описание токена
     * @param int|null $createdBy ID пользователя, создавшего токен
     * @param array|null $settings Общие настройки доступа
     * @param array|null $projectSettings Настройки доступа для проектов [mb_uuid => [allowed_tabs => [...]]]
     * @return array Данные токена (id, token)
     * @throws Exception
     */
    public function createReviewToken(
        string $waveId, 
        ?int $expiresInDays = null,
        ?string $name = null,
        ?string $description = null,
        ?int $createdBy = null,
        ?array $settings = null,
        ?array $projectSettings = null
    ): array {
        // Проверяем, существует ли волна
        $waveService = new WaveService();
        $wave = $waveService->getWaveDetails($waveId);
        
        if (!$wave) {
            throw new Exception('Волна не найдена');
        }

        // Генерируем уникальный токен
        $token = bin2hex(random_bytes(32));
        
        $expiresAt = null;
        if ($expiresInDays !== null) {
            $expiresAt = date('Y-m-d H:i:s', time() + ($expiresInDays * 86400));
        }

        $db = $this->dbService->getWriteConnection();
        
        // Создаем новый токен
        $tokenId = $db->insert('wave_review_tokens', [
            'wave_id' => $waveId,
            'token' => $token,
            'name' => $name,
            'description' => $description,
            'created_by' => $createdBy,
            'settings' => $settings ? json_encode($settings) : null,
            'expires_at' => $expiresAt,
            'is_active' => 1
        ]);

        // Сохраняем настройки доступа для проектов
        if ($projectSettings && is_array($projectSettings)) {
            foreach ($projectSettings as $mbUuid => $config) {
                $allowedTabs = $config['allowed_tabs'] ?? [];
                $isActive = $config['is_active'] ?? true;
                
                $db->insert('wave_review_token_projects', [
                    'token_id' => $tokenId,
                    'mb_uuid' => $mbUuid,
                    'allowed_tabs' => json_encode($allowedTabs),
                    'is_active' => $isActive ? 1 : 0
                ]);
            }
        }

        return [
            'id' => $tokenId,
            'token' => $token
        ];
    }

    public function validateToken(string $token): ?string
    {
        if (empty($token)) {
            return null;
        }
        $cacheKey = $token;
        if (isset(self::$validateTokenCache[$cacheKey]) && self::$validateTokenCache[$cacheKey]['expires'] >= time()) {
            return self::$validateTokenCache[$cacheKey]['wave_id'];
        }

        $db = $this->dbService->getWriteConnection();

        $sql = "SELECT wave_id FROM wave_review_tokens
                WHERE token = ? AND is_active = 1
                AND (expires_at IS NULL OR expires_at > NOW())";

        $result = $db->find($sql, [$token]);

        if ($result) {
            self::$validateTokenCache[$cacheKey] = [
                'wave_id' => $result['wave_id'],
                'expires' => time() + self::VALIDATE_TOKEN_CACHE_TTL,
            ];
            return $result['wave_id'];
        }

        return null;
    }

    /**
     * Получить настройки доступа для проекта по токену
     * 
     * @param string $token
     * @param string $mbUuid
     * @return array|null
     * @throws Exception
     */
    public function getProjectAccess(string $token, string $mbUuid): ?array
    {
        $db = $this->dbService->getWriteConnection();
        
        $sql = "SELECT tp.allowed_tabs, tp.is_active
                FROM wave_review_token_projects tp
                INNER JOIN wave_review_tokens t ON tp.token_id = t.id
                WHERE t.token = ? AND tp.mb_uuid = ? AND tp.is_active = 1";
        
        $result = $db->find($sql, [$token, $mbUuid]);
        
        if ($result) {
            return [
                'allowed_tabs' => $result['allowed_tabs'] ? json_decode($result['allowed_tabs'], true) : [],
                'is_active' => (bool)$result['is_active']
            ];
        }

        return null;
    }

    /**
     * Получить настройки доступа для нескольких проектов одним запросом (оптимизация для списка миграций).
     *
     * @param string $token
     * @param string[] $mbUuids
     * @return array [ mb_uuid => ['allowed_tabs' => array, 'is_active' => bool], ... ]
     */
    public function getProjectAccessBatch(string $token, array $mbUuids): array
    {
        if (empty($mbUuids)) {
            return [];
        }

        $db = $this->dbService->getWriteConnection();
        $placeholders = implode(',', array_fill(0, count($mbUuids), '?'));
        $params = array_merge([$token], $mbUuids);
        $sql = "SELECT tp.mb_uuid, tp.allowed_tabs, tp.is_active
                FROM wave_review_token_projects tp
                INNER JOIN wave_review_tokens t ON tp.token_id = t.id
                WHERE t.token = ? AND tp.mb_uuid IN ($placeholders) AND tp.is_active = 1";

        $rows = $db->getAllRows($sql, $params);
        $map = [];
        foreach ($rows as $row) {
            $map[$row['mb_uuid']] = [
                'allowed_tabs' => $row['allowed_tabs'] ? json_decode($row['allowed_tabs'], true) : [],
                'is_active' => (bool)$row['is_active'],
            ];
        }
        return $map;
    }

    /**
     * Деактивировать токен
     * 
     * @param string $token
     * @return bool
     * @throws Exception
     */
    public function deactivateToken(string $token): bool
    {
        if (empty($token)) {
            return false;
        }

        $db = $this->dbService->getWriteConnection();
        
        $sql = "UPDATE wave_review_tokens SET is_active = 0 WHERE token = ?";
        $db->getAllRows($sql, [$token]);
        
        return true;
    }

    /**
     * Получить список токенов для волны
     * 
     * @param string $waveId
     * @return array
     * @throws Exception
     */
    public function getWaveTokens(string $waveId): array
    {
        $db = $this->dbService->getWriteConnection();
        
        $sql = "SELECT 
                    t.id,
                    t.token,
                    t.name,
                    t.description,
                    t.created_at,
                    t.expires_at,
                    t.is_active,
                    t.settings,
                    u.username as created_by_username
                FROM wave_review_tokens t
                LEFT JOIN users u ON t.created_by = u.id
                WHERE t.wave_id = ? 
                ORDER BY t.created_at DESC";
        
        $tokens = $db->getAllRows($sql, [$waveId]);
        
        // Добавляем информацию о проектах для каждого токена
        foreach ($tokens as &$token) {
            $token['settings'] = $token['settings'] ? json_decode($token['settings'], true) : null;
            $token['projects'] = $this->getTokenProjects($token['id']);
        }
        unset($token);
        
        return $tokens;
    }

    /**
     * Получить настройки доступа для проектов токена
     * 
     * @param int $tokenId
     * @return array
     * @throws Exception
     */
    public function getTokenProjects(int $tokenId): array
    {
        $db = $this->dbService->getWriteConnection();
        
        $sql = "SELECT mb_uuid, allowed_tabs, is_active 
                FROM wave_review_token_projects 
                WHERE token_id = ?";
        
        $projects = $db->getAllRows($sql, [$tokenId]);
        
        foreach ($projects as &$project) {
            $project['allowed_tabs'] = $project['allowed_tabs'] ? json_decode($project['allowed_tabs'], true) : [];
        }
        unset($project);
        
        return $projects;
    }

    /**
     * Получить полную информацию о токене с настройками
     * 
     * @param string $token
     * @return array|null
     * @throws Exception
     */
    public function getTokenInfo(string $token): ?array
    {
        if (empty($token)) {
            return null;
        }

        $db = $this->dbService->getWriteConnection();
        
        $sql = "SELECT 
                    t.*,
                    u.username as created_by_username
                FROM wave_review_tokens t
                LEFT JOIN users u ON t.created_by = u.id
                WHERE t.token = ?";
        
        $tokenData = $db->find($sql, [$token]);
        
        if (!$tokenData) {
            return null;
        }

        $tokenData['settings'] = $tokenData['settings'] ? json_decode($tokenData['settings'], true) : null;
        $tokenData['projects'] = $this->getTokenProjects($tokenData['id']);
        
        return $tokenData;
    }

    /**
     * Обновить токен
     * 
     * @param int $tokenId
     * @param array $data
     * @return bool
     * @throws Exception
     */
    public function updateToken(int $tokenId, array $data): bool
    {
        $db = $this->dbService->getWriteConnection();
        
        $updateData = [];
        
        if (isset($data['name'])) {
            $updateData['name'] = $data['name'];
        }
        if (isset($data['description'])) {
            $updateData['description'] = $data['description'];
        }
        if (isset($data['expires_at'])) {
            $updateData['expires_at'] = $data['expires_at'];
        }
        if (isset($data['is_active'])) {
            $updateData['is_active'] = $data['is_active'] ? 1 : 0;
        }
        if (isset($data['settings'])) {
            $updateData['settings'] = json_encode($data['settings']);
        }

        if (empty($updateData)) {
            return false;
        }

        $result = $db->update('wave_review_tokens', $updateData, ['id' => $tokenId]);
        return $result !== false;
    }

    /**
     * Удалить токен
     * 
     * @param int $tokenId
     * @return bool
     * @throws Exception
     */
    public function deleteToken(int $tokenId): bool
    {
        $db = $this->dbService->getWriteConnection();
        return $db->delete('wave_review_tokens', 'id = ?', [$tokenId]);
    }

    /**
     * Обновить настройки доступа для проекта
     * 
     * @param int $tokenId
     * @param string $mbUuid
     * @param array $config
     * @return bool
     * @throws Exception
     */
    public function updateProjectAccess(int $tokenId, string $mbUuid, array $config): bool
    {
        $db = $this->dbService->getWriteConnection();
        
        $allowedTabs = $config['allowed_tabs'] ?? [];
        $isActive = $config['is_active'] ?? true;
        
        // Проверяем, существует ли запись
        $existing = $db->find(
            'SELECT id FROM wave_review_token_projects WHERE token_id = ? AND mb_uuid = ?',
            [$tokenId, $mbUuid]
        );
        
        if ($existing) {
            $result = $db->update('wave_review_token_projects', [
                'allowed_tabs' => json_encode($allowedTabs),
                'is_active' => $isActive ? 1 : 0
            ], ['id' => $existing['id']]);
            return $result !== false;
        } else {
            return (bool)$db->insert('wave_review_token_projects', [
                'token_id' => $tokenId,
                'mb_uuid' => $mbUuid,
                'allowed_tabs' => json_encode($allowedTabs),
                'is_active' => $isActive ? 1 : 0
            ]);
        }
    }

    /**
     * Сохранить ревью проекта
     * 
     * @param string $token Токен ревью
     * @param int $brzProjectId Brizy Project ID
     * @param string $reviewStatus Статус ревью (approved, rejected, needs_changes, pending)
     * @param string|null $comment Комментарий ревью
     * @return bool
     * @throws Exception
     */
    public function saveProjectReview(string $token, int $brzProjectId, string $reviewStatus, ?string $comment = null): bool
    {
        // Валидируем токен и получаем token_id
        $tokenInfo = $this->getTokenInfo($token);
        if (!$tokenInfo) {
            throw new Exception('Недействительный токен');
        }
        
        $tokenId = $tokenInfo['id'];
        
        // Получаем mb_project_uuid по brz_project_id
        $migrationService = new MigrationService();
        $migrationDetails = $migrationService->getMigrationDetails($brzProjectId);
        
        if (!$migrationDetails || !isset($migrationDetails['mapping']['mb_project_uuid'])) {
            throw new Exception('Проект не найден');
        }
        
        $mbProjectUuid = $migrationDetails['mapping']['mb_project_uuid'];
        
        // Валидируем статус
        $allowedStatuses = ['approved', 'rejected', 'needs_changes', 'pending'];
        if (!in_array($reviewStatus, $allowedStatuses)) {
            throw new Exception('Неверный статус ревью');
        }
        
        $db = $this->dbService->getWriteConnection();
        
        // Проверяем, существует ли запись
        $existing = $db->find(
            'SELECT id FROM project_reviews WHERE token_id = ? AND brz_project_id = ?',
            [$tokenId, $brzProjectId]
        );
        
        $data = [
            'token_id' => $tokenId,
            'brz_project_id' => $brzProjectId,
            'mb_project_uuid' => $mbProjectUuid,
            'review_status' => $reviewStatus,
            'comment' => $comment,
            'reviewed_at' => date('Y-m-d H:i:s')
        ];
        
        if ($existing) {
            // Обновляем существующую запись
            unset($data['token_id']); // Не обновляем token_id
            unset($data['brz_project_id']); // Не обновляем brz_project_id
            $result = $db->update('project_reviews', $data, ['id' => $existing['id']]);
            return $result !== false;
        } else {
            // Создаем новую запись
            return (bool)$db->insert('project_reviews', $data);
        }
    }

    /**
     * Получить ревью проекта
     * 
     * @param string $token Токен ревью
     * @param int $brzProjectId Brizy Project ID
     * @return array|null
     * @throws Exception
     */
    public function getProjectReview(string $token, int $brzProjectId): ?array
    {
        $tokenInfo = $this->getTokenInfo($token);
        if (!$tokenInfo) {
            return null;
        }
        
        $tokenId = $tokenInfo['id'];
        
        $db = $this->dbService->getWriteConnection();
        $review = $db->find(
            'SELECT * FROM project_reviews WHERE token_id = ? AND brz_project_id = ?',
            [$tokenId, $brzProjectId]
        );
        
        return $review ?: null;
    }

    public function getProjectReviewsByToken(string $token): array
    {
        $tokenInfo = $this->getTokenInfo($token);
        if (!$tokenInfo) {
            return [];
        }
        return $this->getProjectReviewsByTokenId((int)$tokenInfo['id']);
    }

    /**
     * Получить все ревью проектов по token_id (без повторного запроса getTokenInfo).
     *
     * @param int $tokenId
     * @return array [brz_project_id => [review_status, reviewed_at, ...], ...]
     */
    public function getProjectReviewsByTokenId(int $tokenId): array
    {
        $db = $this->dbService->getWriteConnection();
        $rows = $db->getAllRows(
            'SELECT brz_project_id, review_status, reviewed_at, comment FROM project_reviews WHERE token_id = ?',
            [$tokenId]
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['brz_project_id']] = [
                'review_status' => $row['review_status'],
                'reviewed_at' => $row['reviewed_at'],
                'comment' => $row['comment'] ?? null,
            ];
        }
        return $map;
    }
}
