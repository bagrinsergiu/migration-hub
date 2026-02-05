-- Упрощенный скрипт для создания тестовой волны
-- Использование: выполните этот SQL в вашей базе данных

-- Создаем тестовую волну с данными из migration_result_list где migration_uuid = 1754581047

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
)
SELECT 
    CONCAT('test_', UNIX_TIMESTAMP(), '_', FLOOR(RAND() * 9000 + 1000)) as wave_id,
    CONCAT('Тестовая волна #', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')) as name,
    22925473 as workspace_id,
    CONCAT('Тестовая волна #', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')) as workspace_name,
    'completed' as status,
    COUNT(*) as progress_total,
    SUM(CASE 
        WHEN result_json IS NOT NULL 
        AND result_json != '{}' 
        AND (JSON_EXTRACT(result_json, '$.value.status') IS NULL OR JSON_EXTRACT(result_json, '$.value.status') != 'error')
        AND JSON_EXTRACT(result_json, '$.error') IS NULL
        THEN 1 ELSE 0 
    END) as progress_completed,
    SUM(CASE 
        WHEN JSON_EXTRACT(result_json, '$.value.status') = 'error' 
        OR JSON_EXTRACT(result_json, '$.error') IS NOT NULL
        THEN 1 ELSE 0 
    END) as progress_failed,
    3 as batch_size,
    0 as mgr_manual,
    NOW() as created_at,
    NOW() as updated_at,
    NOW() as completed_at
FROM migration_result_list
WHERE migration_uuid = '1754581047'
GROUP BY migration_uuid;

-- Получаем ID созданной волны
SET @wave_id = (SELECT wave_id FROM waves ORDER BY id DESC LIMIT 1);

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
    0,
    CONCAT('wave_', @wave_id),
    JSON_OBJECT(
        'wave_id', @wave_id,
        'migration_uuid', '1754581047',
        'total_count', (
            SELECT COUNT(*)
            FROM migration_result_list
            WHERE migration_uuid = '1754581047'
        ),
        'migrations', JSON_ARRAY()
    ),
    NOW(),
    NOW()
LIMIT 1;

-- Выводим информацию о созданной волне
SELECT 
    wave_id,
    name,
    workspace_id,
    workspace_name,
    status,
    progress_total,
    progress_completed,
    progress_failed,
    created_at
FROM waves
WHERE wave_id = @wave_id;
