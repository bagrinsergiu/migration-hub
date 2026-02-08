<?php

namespace Dashboard\Controllers;

use Dashboard\Services\UserService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class UserController
{
    /** @var UserService */
    private $userService;

    public function __construct()
    {
        $this->userService = new UserService();
    }

    /**
     * GET /api/users
     * Получить список всех пользователей
     */
    public function list(Request $request): JsonResponse
    {
        try {
            $users = $this->userService->getAllUsers();

            return new JsonResponse([
                'success' => true,
                'data' => $users,
                'count' => count($users)
            ], 200);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/users/:id
     * Получить пользователя по ID
     */
    public function getDetails(Request $request, int $id): JsonResponse
    {
        try {
            $user = $this->userService->getUserById($id);

            if (!$user) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Пользователь не найден'
                ], 404);
            }

            return new JsonResponse([
                'success' => true,
                'data' => $user
            ], 200);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/users
     * Создать нового пользователя
     */
    public function create(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                $data = $request->request->all();
            }

            $user = $this->userService->createUser($data);

            return new JsonResponse([
                'success' => true,
                'data' => $user,
                'message' => 'Пользователь успешно создан'
            ], 201);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * PUT /api/users/:id
     * Обновить пользователя
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                $data = $request->request->all();
            }

            $user = $this->userService->updateUser($id, $data);

            return new JsonResponse([
                'success' => true,
                'data' => $user,
                'message' => 'Пользователь успешно обновлен'
            ], 200);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * DELETE /api/users/:id
     * Удалить пользователя
     */
    public function delete(Request $request, int $id): JsonResponse
    {
        try {
            $this->userService->deleteUser($id);

            return new JsonResponse([
                'success' => true,
                'message' => 'Пользователь успешно удален'
            ], 200);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * GET /api/users/roles
     * Получить список всех ролей
     */
    public function getRoles(Request $request): JsonResponse
    {
        try {
            $roles = $this->userService->getAllRoles();

            return new JsonResponse([
                'success' => true,
                'data' => $roles
            ], 200);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/users/permissions
     * Получить список всех разрешений
     */
    public function getPermissions(Request $request): JsonResponse
    {
        try {
            $permissions = $this->userService->getAllPermissions();

            return new JsonResponse([
                'success' => true,
                'data' => $permissions
            ], 200);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/users/:id/permissions
     * Получить разрешения пользователя
     */
    public function getUserPermissions(Request $request, int $id): JsonResponse
    {
        try {
            $permissions = $this->userService->getUserPermissions($id);

            return new JsonResponse([
                'success' => true,
                'data' => $permissions
            ], 200);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
