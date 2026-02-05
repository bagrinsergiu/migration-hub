<?php

namespace Dashboard\Services;

use Exception;

/**
 * UserService
 * 
 * Сервис для управления пользователями
 */
class UserService
{
    /** @var DatabaseService */
    private $dbService;

    public function __construct()
    {
        $this->dbService = new DatabaseService();
    }

    /**
     * Создать нового пользователя
     * 
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function createUser(array $data): array
    {
        // Валидация
        if (empty($data['username'])) {
            throw new Exception('Имя пользователя обязательно');
        }

        if (empty($data['password'])) {
            throw new Exception('Пароль обязателен');
        }

        if (strlen($data['password']) < 6) {
            throw new Exception('Пароль должен быть не менее 6 символов');
        }

        // Проверяем, существует ли пользователь
        $existing = $this->getUserByUsername($data['username']);
        if ($existing) {
            throw new Exception('Пользователь с таким именем уже существует');
        }

        // Хешируем пароль
        $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);

        $db = $this->dbService->getWriteConnection();

        // Создаем пользователя
        $userId = $db->insert('users', [
            'username' => $data['username'],
            'email' => $data['email'] ?? null,
            'password_hash' => $passwordHash,
            'full_name' => $data['full_name'] ?? null,
            'is_active' => isset($data['is_active']) ? (int)$data['is_active'] : 1
        ]);

        // Назначаем роли, если указаны
        if (!empty($data['role_ids']) && is_array($data['role_ids'])) {
            $this->assignRolesToUser($userId, $data['role_ids']);
        }

        return $this->getUserById($userId);
    }

    /**
     * Обновить пользователя
     * 
     * @param int $userId
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function updateUser(int $userId, array $data): array
    {
        $user = $this->getUserById($userId);
        if (!$user) {
            throw new Exception('Пользователь не найден');
        }

        $db = $this->dbService->getWriteConnection();
        $updateData = [];

        if (isset($data['email'])) {
            $updateData['email'] = $data['email'];
        }

        if (isset($data['full_name'])) {
            $updateData['full_name'] = $data['full_name'];
        }

        if (isset($data['is_active'])) {
            $updateData['is_active'] = (int)$data['is_active'];
        }

        if (!empty($data['password'])) {
            if (strlen($data['password']) < 6) {
                throw new Exception('Пароль должен быть не менее 6 символов');
            }
            $updateData['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        if (!empty($updateData)) {
            // Используем прямой SQL для UPDATE
            $setParts = [];
            $params = [];
            foreach ($updateData as $key => $value) {
                $setParts[] = "`$key` = ?";
                $params[] = $value;
            }
            $params[] = $userId;
            
            $sql = "UPDATE users SET " . implode(', ', $setParts) . " WHERE id = ?";
            $db->getAllRows($sql, $params);
        }

        // Обновляем роли, если указаны
        if (isset($data['role_ids']) && is_array($data['role_ids'])) {
            $this->assignRolesToUser($userId, $data['role_ids']);
        }

        return $this->getUserById($userId);
    }

    /**
     * Удалить пользователя
     * 
     * @param int $userId
     * @return bool
     * @throws Exception
     */
    public function deleteUser(int $userId): bool
    {
        $user = $this->getUserById($userId);
        if (!$user) {
            throw new Exception('Пользователь не найден');
        }

        $db = $this->dbService->getWriteConnection();
        $db->delete('users', 'id = ?', [$userId]);

        return true;
    }

    /**
     * Получить пользователя по ID
     * 
     * @param int $userId
     * @return array|null
     * @throws Exception
     */
    public function getUserById(int $userId): ?array
    {
        $db = $this->dbService->getWriteConnection();
        $user = $db->find('SELECT * FROM users WHERE id = ?', [$userId]);

        if ($user) {
            unset($user['password_hash']); // Не возвращаем хеш пароля
            $user['roles'] = $this->getUserRoles($userId);
            $user['permissions'] = $this->getUserPermissions($userId);
        }

        return $user ?: null;
    }

    /**
     * Получить пользователя по имени
     * 
     * @param string $username
     * @return array|null
     * @throws Exception
     */
    public function getUserByUsername(string $username): ?array
    {
        $db = $this->dbService->getWriteConnection();
        $user = $db->find('SELECT * FROM users WHERE username = ?', [$username]);
        return $user ?: null;
    }

    /**
     * Получить список всех пользователей
     * 
     * @return array
     * @throws Exception
     */
    public function getAllUsers(): array
    {
        $db = $this->dbService->getWriteConnection();
        $users = $db->getAllRows('SELECT id, username, email, full_name, is_active, created_at, last_login FROM users ORDER BY created_at DESC');

        foreach ($users as &$user) {
            $user['roles'] = $this->getUserRoles($user['id']);
        }

        return $users;
    }

    /**
     * Проверить пароль пользователя
     * 
     * @param string $username
     * @param string $password
     * @return array|null Пользователь или null если неверный пароль
     * @throws Exception
     */
    public function validateUserPassword(string $username, string $password): ?array
    {
        try {
            $user = $this->getUserByUsername($username);
            
            if (!$user) {
                error_log("[UserService::validateUserPassword] Пользователь не найден: {$username}");
                return null;
            }

            if (!$user['is_active']) {
                error_log("[UserService::validateUserPassword] Пользователь деактивирован: {$username}");
                throw new Exception('Пользователь деактивирован');
            }

            if (empty($user['password_hash'])) {
                error_log("[UserService::validateUserPassword] У пользователя нет пароля: {$username}");
                return null;
            }

            if (!password_verify($password, $user['password_hash'])) {
                error_log("[UserService::validateUserPassword] Неверный пароль для пользователя: {$username}");
                return null;
            }

            // Обновляем время последнего входа
            $db = $this->dbService->getWriteConnection();
            $db->getAllRows('UPDATE users SET last_login = NOW() WHERE id = ?', [$user['id']]);

            unset($user['password_hash']);
            error_log("[UserService::validateUserPassword] Успешная авторизация: {$username}");
            return $user;
        } catch (Exception $e) {
            error_log("[UserService::validateUserPassword] Ошибка при валидации пароля: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Получить роли пользователя
     * 
     * @param int $userId
     * @return array
     * @throws Exception
     */
    public function getUserRoles(int $userId): array
    {
        $db = $this->dbService->getWriteConnection();
        $sql = "SELECT r.* FROM roles r
                INNER JOIN user_roles ur ON r.id = ur.role_id
                WHERE ur.user_id = ? AND r.is_active = 1";
        return $db->getAllRows($sql, [$userId]);
    }

    /**
     * Назначить роли пользователю
     * 
     * @param int $userId
     * @param array $roleIds
     * @return bool
     * @throws Exception
     */
    public function assignRolesToUser(int $userId, array $roleIds): bool
    {
        $db = $this->dbService->getWriteConnection();

        // Удаляем старые роли
        $db->delete('user_roles', 'user_id = ?', [$userId]);

        // Добавляем новые роли
        foreach ($roleIds as $roleId) {
            // Проверяем, не существует ли уже такая связь
            $existing = $db->find(
                'SELECT id FROM user_roles WHERE user_id = ? AND role_id = ?',
                [$userId, (int)$roleId]
            );
            
            if (!$existing) {
                $db->insert('user_roles', [
                    'user_id' => $userId,
                    'role_id' => (int)$roleId
                ]);
            }
        }

        return true;
    }

    /**
     * Получить разрешения пользователя
     * 
     * @param int $userId
     * @return array
     * @throws Exception
     */
    public function getUserPermissions(int $userId): array
    {
        $db = $this->dbService->getWriteConnection();
        $sql = "SELECT DISTINCT p.* FROM permissions p
                INNER JOIN role_permissions rp ON p.id = rp.permission_id
                INNER JOIN user_roles ur ON rp.role_id = ur.role_id
                WHERE ur.user_id = ? AND p.is_active = 1
                ORDER BY p.resource, p.action";
        return $db->getAllRows($sql, [$userId]);
    }

    /**
     * Проверить, имеет ли пользователь разрешение
     * 
     * @param int $userId
     * @param string $resource
     * @param string $action
     * @return bool
     * @throws Exception
     */
    public function hasPermission(int $userId, string $resource, string $action): bool
    {
        $permissions = $this->getUserPermissions($userId);
        
        foreach ($permissions as $permission) {
            // Проверяем точное совпадение или manage для ресурса
            if (($permission['resource'] === $resource && $permission['action'] === $action) ||
                ($permission['resource'] === $resource && $permission['action'] === 'manage')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Получить все роли
     * 
     * @return array
     * @throws Exception
     */
    public function getAllRoles(): array
    {
        $db = $this->dbService->getWriteConnection();
        return $db->getAllRows('SELECT * FROM roles WHERE is_active = 1 ORDER BY name');
    }

    /**
     * Получить все разрешения
     * 
     * @return array
     * @throws Exception
     */
    public function getAllPermissions(): array
    {
        $db = $this->dbService->getWriteConnection();
        return $db->getAllRows('SELECT * FROM permissions WHERE is_active = 1 ORDER BY resource, action');
    }

    /**
     * Получить разрешения роли
     * 
     * @param int $roleId
     * @return array
     * @throws Exception
     */
    public function getRolePermissions(int $roleId): array
    {
        $db = $this->dbService->getWriteConnection();
        $sql = "SELECT p.* FROM permissions p
                INNER JOIN role_permissions rp ON p.id = rp.permission_id
                WHERE rp.role_id = ? AND p.is_active = 1
                ORDER BY p.resource, p.action";
        return $db->getAllRows($sql, [$roleId]);
    }
}
