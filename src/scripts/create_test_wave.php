<?php
/**
 * Скрипт для создания тестовой волны с миграциями из migration_result_list
 * Использование: php dashboard/api/scripts/create_test_wave.php
 */

require_once dirname(__DIR__, 3) . '/vendor/autoload_runtime.php';

// Загрузка переменных окружения
if (file_exists(dirname(__DIR__, 3) . '/.env')) {
    $dotenv = \Dotenv\Dotenv::createMutable(dirname(__DIR__, 3));
    $dotenv->safeLoad();
}

$prodEnv = dirname(__DIR__, 3) . '/.env.prod.local';
if (file_exists($prodEnv)) {
    $dotenv = \Dotenv\Dotenv::createMutable(dirname(__DIR__, 2), ['.env.prod.local']);
    $dotenv->safeLoad();
}

use Dashboard\Services\DatabaseService;
use MBMigration\Layer\DataSource\driver\MySQL;

try {
    $dbService = new DatabaseService();
    $db = $dbService->getWriteConnection();
    
    // Получаем миграции из migration_result_list с migration_uuid = 1754581047
    $migrations = $db->getAllRows(
        "SELECT * FROM migration_result_list WHERE migration_uuid = ? ORDER BY created_at ASC",
        ['1754581047']
    );
    
    echo "=== Найдено миграций: " . count($migrations) . " ===\n\n";
    
    if (empty($migrations)) {
        echo "Миграции с migration_uuid = 1754581047 не найдены\n";
        exit(1);
    }
    
    // Показываем первые 5 миграций для примера
    echo "Примеры миграций:\n";
    foreach (array_slice($migrations, 0, 5) as $migration) {
        echo "- MB UUID: {$migration['mb_project_uuid']}, Brizy ID: {$migration['brz_project_id']}\n";
    }
    if (count($migrations) > 5) {
        echo "... и еще " . (count($migrations) - 5) . " миграций\n";
    }
    echo "\n";
    
    // Создаем тестовую волну
    $waveId = 'test_' . time() . '_' . random_int(1000, 9999);
    $waveName = 'Тестовая волна #' . date('Y-m-d H:i:s');
    $workspaceId = 22925473; // Можно изменить на реальный ID
    $workspaceName = $waveName;
    
    // Извлекаем список UUID проектов из миграций
    $projectUuids = array_map(function($migration) {
        return $migration['mb_project_uuid'];
    }, $migrations);
    
    echo "=== Создание волны ===\n";
    echo "Wave ID: {$waveId}\n";
    echo "Название: {$waveName}\n";
    echo "Workspace ID: {$workspaceId}\n";
    echo "Количество проектов: " . count($projectUuids) . "\n\n";
    
    // Создаем волну
    $waveDbId = $dbService->createWave(
        $waveId,
        $waveName,
        $projectUuids,
        $workspaceId,
        $workspaceName,
        3, // batch_size
        false // mgr_manual
    );
    
    echo "✅ Волна создана с ID в БД: {$waveDbId}\n\n";
    
    // Обновляем миграции в волне, связывая их с данными из migration_result_list
    $waveMigrations = [];
    $progress = [
        'total' => count($migrations),
        'completed' => 0,
        'failed' => 0,
    ];
    
    foreach ($migrations as $migration) {
        $resultJson = json_decode($migration['result_json'] ?? '{}', true);
        $resultValue = $resultJson['value'] ?? $resultJson ?? [];
        
        // Определяем статус миграции
        $status = 'completed';
        if (isset($resultValue['status']) && $resultValue['status'] === 'error') {
            $status = 'error';
            $progress['failed']++;
        } else {
            $progress['completed']++;
        }
        
        $waveMigrations[] = [
            'mb_project_uuid' => $migration['mb_project_uuid'],
            'brz_project_id' => $migration['brz_project_id'],
            'status' => $status,
            'brizy_project_domain' => $migration['brizy_project_domain'] ?? $resultValue['brizy_project_domain'] ?? null,
            'completed_at' => $migration['created_at'] ?? date('Y-m-d H:i:s'),
        ];
    }
    
    // Обновляем прогресс волны
    $dbService->updateWaveProgress(
        $waveId,
        $progress,
        $waveMigrations,
        'completed' // Статус волны
    );
    
    echo "✅ Прогресс обновлен:\n";
    echo "   Всего: {$progress['total']}\n";
    echo "   Завершено: {$progress['completed']}\n";
    echo "   Ошибок: {$progress['failed']}\n\n";
    
    // Проверяем, что волна создана
    $wave = $dbService->getWave($waveId);
    if ($wave) {
        echo "=== Проверка созданной волны ===\n";
        echo "ID: {$wave['id']}\n";
        echo "Название: {$wave['name']}\n";
        echo "Workspace ID: {$wave['workspace_id']}\n";
        echo "Статус: {$wave['status']}\n";
        echo "Прогресс: {$wave['progress']['completed']}/{$wave['progress']['total']}\n";
        echo "Миграций в волне: " . count($wave['migrations']) . "\n";
        echo "\n✅ Волна успешно создана и готова к использованию!\n";
    } else {
        echo "❌ Ошибка: волна не найдена после создания\n";
    }
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    echo "Файл: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Трассировка:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
