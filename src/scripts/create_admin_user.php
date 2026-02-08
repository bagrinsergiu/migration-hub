<?php
/**
 * Скрипт для создания первого администратора
 * 
 * Использование:
 * php src/scripts/create_admin_user.php [username] [password] [email]
 * 
 * Если параметры не указаны, будут использованы значения по умолчанию:
 * username: admin
 * password: admin123
 * email: admin@example.com
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// Загрузка переменных окружения
if (file_exists(dirname(__DIR__, 2) . '/.env')) {
    $dotenv = \Dotenv\Dotenv::createMutable(dirname(__DIR__, 2));
    $dotenv->safeLoad();
}

// Загрузка .env.prod.local если существует
$prodEnv = dirname(__DIR__, 2) . '/.env.prod.local';
if (file_exists($prodEnv)) {
    $dotenv = \Dotenv\Dotenv::createMutable(dirname(__DIR__, 2), ['.env.prod.local']);
    $dotenv->safeLoad();
}

require_once __DIR__ . '/../services/DatabaseService.php';
require_once __DIR__ . '/../services/UserService.php';

use Dashboard\Services\DatabaseService;
use Dashboard\Services\UserService;

try {
    $username = $argv[1] ?? 'admin';
    $password = $argv[2] ?? 'admin123';
    $email = $argv[3] ?? 'admin@example.com';

    echo "Создание администратора...\n";
    echo "Username: $username\n";
    echo "Email: $email\n";
    echo "Password: " . str_repeat('*', strlen($password)) . "\n\n";

    $userService = new UserService();

    // Проверяем, существует ли уже пользователь
    $existing = $userService->getUserByUsername($username);
    if ($existing) {
        echo "❌ Пользователь с именем '$username' уже существует!\n";
        exit(1);
    }

    // Создаем пользователя
    $user = $userService->createUser([
        'username' => $username,
        'email' => $email,
        'password' => $password,
        'full_name' => 'Администратор',
        'is_active' => true,
        'role_ids' => [1] // Роль admin (id=1)
    ]);

    echo "✅ Пользователь успешно создан!\n";
    echo "ID: {$user['id']}\n";
    echo "Username: {$user['username']}\n";
    echo "Email: {$user['email']}\n";
    echo "\n";
    echo "Теперь вы можете войти в систему используя эти учетные данные.\n";

} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
