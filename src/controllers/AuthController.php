<?php

namespace Dashboard\Controllers;

use Dashboard\Services\AuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthController
{
    /** @var AuthService */
    private $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    /**
     * POST /api/auth/login
     * Авторизация администратора
     */
    public function login(Request $request): JsonResponse
    {
        // ВРЕМЕННЫЙ ТЕСТ: возвращаем простой ответ для проверки
        // TODO: Удалить после отладки
        $testMode = false; // Установите в true для теста
        
        if ($testMode) {
            return new JsonResponse([
                'success' => true,
                'data' => [
                    'session_id' => 'test_session_123',
                    'user' => ['id' => 1, 'username' => 'admin']
                ],
                'test' => true
            ], 200);
        }
        
        // Логируем в stderr для гарантированного вывода
        file_put_contents('php://stderr', "[AuthController::login] Метод вызван\n");
        file_put_contents('php://stderr', "[AuthController::login] Request method: " . $request->getMethod() . "\n");
        file_put_contents('php://stderr', "[AuthController::login] Request content: " . substr($request->getContent(), 0, 100) . "\n");
        
        error_log("[AuthController::login] Метод вызван");
        error_log("[AuthController::login] Request method: " . $request->getMethod());
        error_log("[AuthController::login] Request content: " . substr($request->getContent(), 0, 100));
        
        try {
            $data = json_decode($request->getContent(), true);
            error_log("[AuthController::login] JSON decoded: " . json_encode($data));
            
            if (!$data) {
                $data = $request->request->all();
                error_log("[AuthController::login] Using request->request->all(): " . json_encode($data));
            }

            $username = $data['username'] ?? '';
            $password = $data['password'] ?? '';

            error_log("[AuthController::login] Username: {$username}, Password length: " . strlen($password));

            if (empty($username) || empty($password)) {
                error_log("[AuthController::login] Пустой username или password");
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Логин и пароль обязательны'
                ], 400);
            }

            error_log("[AuthController::login] Попытка авторизации для пользователя: {$username}");
            
            try {
                $user = $this->authService->validateCredentials($username, $password);
            } catch (\Exception $e) {
                error_log("[AuthController::login] Исключение при валидации: " . $e->getMessage());
                return new JsonResponse([
                    'success' => false,
                    'error' => $e->getMessage()
                ], 401);
            }
            
            if (!$user) {
                error_log("[AuthController::login] Пользователь не найден или неверный пароль: {$username}");
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Неверный логин или пароль'
                ], 401);
            }
            
            error_log("[AuthController::login] Пользователь найден, создаем сессию: {$username}");

            // Загружаем полную информацию о пользователе с разрешениями
            // Временно используем только базового пользователя, чтобы избежать проблем с сериализацией
            $fullUser = null;
            try {
                $userService = new \Dashboard\Services\UserService();
                // Получаем только базовую информацию, без permissions (может вызывать проблемы)
                $user['roles'] = $userService->getUserRoles($user['id']);
                // Не загружаем permissions, чтобы избежать проблем с сериализацией
                // $user['permissions'] = $userService->getUserPermissions($user['id']);
                $fullUser = $user;
                error_log("[AuthController::login] User data prepared, roles count: " . count($user['roles']));
            } catch (\Exception $e) {
                error_log("[AuthController::login] Error getting user data: " . $e->getMessage());
                error_log("[AuthController::login] Stack trace: " . $e->getTraceAsString());
                $fullUser = $user; // Используем базового пользователя
            }

            // Создаем сессию
            $ipAddress = $request->getClientIp() ?? '';
            $userAgent = $request->headers->get('User-Agent') ?? '';
            
            try {
                $sessionId = $this->authService->createSession($user['id'], $username, $ipAddress, $userAgent);
                error_log("[AuthController::login] Session created: " . substr($sessionId, 0, 10) . "...");
            } catch (\Exception $e) {
                error_log("[AuthController::login] ERROR creating session: " . $e->getMessage());
                throw $e;
            }

            // Упрощаем ответ, чтобы избежать проблем с сериализацией
            $responseData = [
                'success' => true,
                'data' => [
                    'session_id' => $sessionId,
                    'user' => [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'email' => $user['email'] ?? null,
                        'full_name' => $user['full_name'] ?? null,
                        'roles' => $fullUser['roles'] ?? []
                    ]
                ]
            ];
            
            error_log("[AuthController::login] Создаем ответ");
            
            // Проверяем, что данные можно сериализовать
            $testJson = json_encode($responseData, JSON_UNESCAPED_UNICODE);
            if ($testJson === false) {
                error_log("[AuthController::login] ERROR: Cannot serialize responseData: " . json_last_error_msg());
                // Минимальный ответ
                $responseData = [
                    'success' => true,
                    'data' => [
                        'session_id' => $sessionId,
                        'user' => [
                            'id' => $user['id'],
                            'username' => $user['username']
                        ]
                    ]
                ];
            } else {
                error_log("[AuthController::login] JSON serialization OK, length: " . strlen($testJson));
            }
            
            $response = new JsonResponse($responseData, 200);

            // Устанавливаем cookie
            $response->headers->setCookie(
                new \Symfony\Component\HttpFoundation\Cookie(
                    'dashboard_session',
                    $sessionId,
                    time() + 86400, // 24 часа
                    '/',
                    null,
                    false, // httpOnly будет установлен в index.php
                    true, // secure в production
                    false,
                    'Lax'
                )
            );

            // Убеждаемся, что Content-Type установлен ПЕРЕД prepare
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
            
            // Подготавливаем ответ перед получением контента
            $response->prepare($request);
            $responseContent = $response->getContent();
            $contentLength = strlen($responseContent);
            
            error_log("[AuthController::login] Ответ создан, Content-Type: " . $response->headers->get('Content-Type'));
            error_log("[AuthController::login] Ответ content length: " . $contentLength);
            
            if ($contentLength === 0) {
                error_log("[AuthController::login] ERROR: Response content is empty after prepare!");
                error_log("[AuthController::login] Response data: " . json_encode($responseData, JSON_UNESCAPED_UNICODE));
                
                // Создаем минимальный ответ вручную
                $response = new JsonResponse([
                    'success' => true,
                    'data' => [
                        'session_id' => $sessionId,
                        'user' => [
                            'id' => (int)$user['id'],
                            'username' => (string)$user['username']
                        ]
                    ]
                ], 200);
                $response->headers->set('Content-Type', 'application/json; charset=utf-8');
                $response->prepare($request);
                $newContent = $response->getContent();
                error_log("[AuthController::login] Создан новый ответ, length: " . strlen($newContent));
                
                if (empty($newContent)) {
                    error_log("[AuthController::login] CRITICAL: Even new response is empty!");
                }
            } else {
                error_log("[AuthController::login] Ответ content preview: " . substr($responseContent, 0, 200));
            }
            
            // Финальная проверка перед возвратом - ответ не должен быть пустым
            $finalContent = $response->getContent();
            if (empty($finalContent)) {
                error_log("[AuthController::login] CRITICAL: Response is empty before return!");
                $response = new JsonResponse([
                    'success' => false,
                    'error' => 'Critical: Empty response generated',
                    'session_id' => $sessionId ?? null
                ], 500);
                $response->headers->set('Content-Type', 'application/json; charset=utf-8');
                $response->prepare($request);
            }
            
            return $response;
        } catch (\Exception $e) {
            $errorMsg = "AuthController::login error: " . $e->getMessage();
            $trace = $e->getTraceAsString();
            
            error_log($errorMsg);
            error_log("Stack trace: " . $trace);
            
            // Создаем ответ с ошибкой
            $errorResponse = new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
                'type' => get_class($e)
            ], 500);
            
            // Проверяем, что ответ не пустой
            $errorResponse->prepare($request);
            $content = $errorResponse->getContent();
            if (empty($content)) {
                error_log("[AuthController::login] CRITICAL: Error response is also empty!");
                $errorResponse = new JsonResponse([
                    'success' => false,
                    'error' => 'Critical: Failed to generate error response',
                    'original_error' => $e->getMessage()
                ], 500);
            }
            
            return $errorResponse;
        }
    }

    /**
     * POST /api/auth/logout
     * Выход из системы
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $sessionId = $request->cookies->get('dashboard_session') 
                ?? $request->headers->get('X-Dashboard-Session');

            if ($sessionId) {
                $this->authService->destroySession($sessionId);
            }

            $response = new JsonResponse([
                'success' => true,
                'message' => 'Выход выполнен успешно'
            ], 200);

            // Удаляем cookie
            $response->headers->clearCookie('dashboard_session', '/');

            return $response;
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/auth/check
     * Проверить статус авторизации
     */
    public function check(Request $request): JsonResponse
    {
        try {
            $sessionId = $request->cookies->get('dashboard_session') 
                ?? $request->headers->get('X-Dashboard-Session');

            if (empty($sessionId)) {
                return new JsonResponse([
                    'success' => false,
                    'authenticated' => false
                ], 401);
            }

            $isValid = $this->authService->validateSession($sessionId);
            $user = null;
            
            if ($isValid) {
                $user = $this->authService->getUserFromSession($sessionId);
            }

            return new JsonResponse([
                'success' => $isValid,
                'authenticated' => $isValid,
                'user' => $user
            ], $isValid ? 200 : 401);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
