-- Скрипт для создания тестовой волны с миграциями из migration_result_list
-- Использование: выполните этот SQL в вашей базе данных
-- Миграции берутся из migration_result_list где migration_uuid = 1754581047

-- 1. Получаем список миграций с migration_uuid = 1754581047
-- 2. Создаем тестовую волну
-- 3. Связываем миграции с волной

SET @migration_uuid = '1754581047';
SET @wave_id = CONCAT('test_', UNIX_TIMESTAMP(), '_', FLOOR(RAND() * 9000 + 1000));
SET @wave_name = CONCAT('Тестовая волна #', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'));
SET @workspace_id = 22925473; -- Можно изменить на реальный ID
SET @workspace_name = @wave_name;
SET @batch_size = 3;
SET @mgr_manual = 0;

-- Подсчитываем миграции
SET @total_migrations = (
    SELECT COUNT(*) 
    FROM migration_result_list 
    WHERE migration_uuid = @migration_uuid
);

SET @completed_migrations = (
    SELECT COUNT(*) 
    FROM migration_result_list 
    WHERE migration_uuid = @migration_uuid
    AND result_json IS NOT NULL
    AND result_json != '{}'
    AND JSON_EXTRACT(result_json, '$.value.status') != 'error'
);

SET @failed_migrations = (
    SELECT COUNT(*) 
    FROM migration_result_list 
    WHERE migration_uuid = @migration_uuid
    AND (
        JSON_EXTRACT(result_json, '$.value.status') = 'error'
        OR JSON_EXTRACT(result_json, '$.error') IS NOT NULL
    )
);

-- Создаем волну
INSERT INTO waves (
    wave_id,
    name,
    workspace_id,
    workspace_name,
    status,
    progress_total,
    progress_completed,
    progress_failed,
    batch_size,
    mgr_manual,
    created_at,
    updated_at,
    completed_at
) VALUES (
    @wave_id,
    @wave_name,
    @workspace_id,
    @workspace_name,
    'completed',
    @total_migrations,
    @completed_migrations,
    @failed_migrations,
    @batch_size,
    @mgr_manual,
    NOW(),
    NOW(),
    NOW()
);

-- Сохраняем минимальную информацию в migrations_mapping для обратной совместимости
-- Не сохраняем все UUID, так как они уже есть в таблице waves и доступны через migration_result_list
INSERT INTO migrations_mapping (
    brz_project_id,
    mb_project_uuid,
    changes_json,
    created_at,
    updated_at
)
SELECT 
    0 as brz_project_id,
    CONCAT('wave_', @wave_id) as mb_project_uuid,
    JSON_OBJECT(
        'wave_id', @wave_id,
        'migration_uuid', @migration_uuid,
        'total_count', COUNT(*),
        'migrations', JSON_ARRAY()
    ) as changes_json,
    NOW() as created_at,
    NOW() as updated_at
FROM migration_result_list
WHERE migration_uuid = @migration_uuid
GROUP BY migration_uuid;

-- Выводим информацию о созданной волне
SELECT 
    @wave_id as wave_id,
    @wave_name as wave_name,
    @total_migrations as total_migrations,
    @completed_migrations as completed_migrations,
    @failed_migrations as failed_migrations,
    'Волна успешно создана!' as message;
