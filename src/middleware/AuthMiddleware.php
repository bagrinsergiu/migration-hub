<?php

namespace Dashboard\Middleware;

use Dashboard\Services\AuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AuthMiddleware
 * 
 * Middleware для проверки авторизации пользователя
 */
class AuthMiddleware
{
    /** @var AuthService */
    private $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    /**
     * Проверить авторизацию
     * 
     * @param Request $request
     * @return Response|null Возвращает Response если не авторизован, null если авторизован
     */
    public function checkAuth(Request $request): ?Response
    {
        // Получаем session_id из cookie или заголовка
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
            $isValid = $this->authService->validateSession($sessionId);
            
            if (!$isValid) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Сессия истекла или недействительна',
                    'requires_auth' => true
                ], 401);
            }

            return null; // Авторизован
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Ошибка проверки авторизации: ' . $e->getMessage()
            ], 500);
        }
    }
}
