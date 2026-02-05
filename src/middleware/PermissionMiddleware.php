<?php

namespace Dashboard\Middleware;

use Dashboard\Services\AuthService;
use Dashboard\Services\UserService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PermissionMiddleware
 * 
 * Middleware для проверки разрешений пользователя
 */
class PermissionMiddleware
{
    /** @var AuthService */
    private $authService;

    /** @var UserService */
    private $userService;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->userService = new UserService();
    }

    /**
     * Проверить разрешение пользователя
     * 
     * @param Request $request
     * @param string $resource Ресурс (migrations, waves, logs, settings, test, quality_analysis, users)
     * @param string $action Действие (view, create, edit, delete, manage)
     * @return Response|null Возвращает Response если нет доступа, null если есть доступ
     */
    public function checkPermission(Request $request, string $resource, string $action): ?Response
    {
        // Получаем session_id
        $sessionId = $request->cookies->get('dashboard_session') 
            ?? $request->headers->get('X-Dashboard-Session');

        if (empty($sessionId)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Не авторизован',
                'requires_auth' => true
            ], 401);
        }

        try {
            $user = $this->authService->getUserFromSession($sessionId);
            
            if (!$user) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Сессия истекла или недействительна',
                    'requires_auth' => true
                ], 401);
            }

            // Проверяем разрешение
            $hasPermission = $this->userService->hasPermission($user['id'], $resource, $action);
            
            if (!$hasPermission) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Недостаточно прав доступа',
                    'required_permission' => "$resource.$action"
                ], 403);
            }

            return null; // Доступ разрешен
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Ошибка проверки разрешений: ' . $e->getMessage()
            ], 500);
        }
    }
}
