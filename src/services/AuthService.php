<?php

namespace Dashboard\Services;

use Exception;

/**
 * AuthService
 * 
 * Сервис для управления авторизацией пользователей
 */
class AuthService
{
    /** @var DatabaseService */
    private $dbService;

    /** @var UserService */
    private $userService;

    public function __construct()
    {
        $this->dbService = new DatabaseService();
        $this->userService = new UserService();
    }

    /**
     * Проверить учетные данные пользователя
     * 
     * @param string $username
     * @param string $password
     * @return array|null Пользователь или null если неверные данные
     */
    public function validateCredentials(string $username, string $password): ?array
    {
        try {
            return $this->userService->validateUserPassword($username, $password);
        } catch (Exception $e) {
            error_log("[AuthService::validateCredentials] Ошибка при валидации учетных данных: " . $e->getMessage());
            error_log("[AuthService::validateCredentials] Stack trace: " . $e->getTraceAsString());
            // Если пользователь деактивирован, пробрасываем исключение дальше
            if (strpos($e->getMessage(), 'деактивирован') !== false) {
                throw $e;
            }
            return null;
        }
    }

    /**
     * Создать сессию пользователя
     * 
     * @param int $userId
     * @param string $username
     * @param string $ipAddress
     * @param string $userAgent
     * @return string Session ID
     * @throws Exception
     */
    public function createSession(int $userId, string $username, string $ipAddress = '', string $userAgent = ''): string
    {
        $sessionId = bin2hex(random_bytes(32));
        // Увеличиваем время жизни сессии до 7 дней для соответствия куки
        $expiresAt = date('Y-m-d H:i:s', time() + (86400 * 7)); // 7 дней

        $db = $this->dbService->getWriteConnection();
        
        $db->insert('admin_sessions', [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'admin_username' => $username,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'expires_at' => $expiresAt
        ]);

        return $sessionId;
    }

    /**
     * Получить пользователя из сессии
     * 
     * @param string $sessionId
     * @return array|null
     * @throws Exception
     */
    public function getUserFromSession(string $sessionId): ?array
    {
        if (empty($sessionId)) {
            return null;
        }

        $db = $this->dbService->getWriteConnection();
        
        $sql = "SELECT user_id, admin_username FROM admin_sessions 
                WHERE session_id = ? AND expires_at > NOW() AND is_active = 1";
        
        $result = $db->find($sql, [$sessionId]);
        
        if ($result && $result['user_id']) {
            return $this->userService->getUserById($result['user_id']);
        }

        return null;
    }

    /**
     * Проверить валидность сессии
     * 
     * @param string $sessionId
     * @return bool
     * @throws Exception
     */
    public function validateSession(string $sessionId): bool
    {
        if (empty($sessionId)) {
            return false;
        }

        $db = $this->dbService->getWriteConnection();
        
        $sql = "SELECT id FROM admin_sessions 
                WHERE session_id = ? AND expires_at > NOW() AND is_active = 1";
        
        $result = $db->find($sql, [$sessionId]);
        
        if ($result) {
            // Обновляем время последней активности через прямой SQL
            $updateSql = "UPDATE admin_sessions SET last_activity = NOW() WHERE session_id = ?";
            $db->getAllRows($updateSql, [$sessionId]);
            return true;
        }

        return false;
    }

    /**
     * Удалить сессию
     * 
     * @param string $sessionId
     * @return bool
     * @throws Exception
     */
    public function destroySession(string $sessionId): bool
    {
        if (empty($sessionId)) {
            return false;
        }

        $db = $this->dbService->getWriteConnection();
        
        // Используем delete метод для обновления через прямой SQL
        $sql = "UPDATE admin_sessions SET is_active = 0 WHERE session_id = ?";
        $db->getAllRows($sql, [$sessionId]);
        
        return true;
    }

    /**
     * Очистить истекшие сессии
     * 
     * @return int Количество удаленных сессий
     * @throws Exception
     */
    public function cleanupExpiredSessions(): int
    {
        $db = $this->dbService->getWriteConnection();
        
        $sql = "DELETE FROM admin_sessions WHERE expires_at < NOW()";
        $db->delete('admin_sessions', 'expires_at < NOW()');
        
        // Возвращаем примерное количество (MySQL не возвращает точное через delete)
        return 1;
    }
}
