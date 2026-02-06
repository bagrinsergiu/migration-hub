# Поэтапная проверка синхронизации Google Sheets

## Шаг 1: Проверка подключения таблицы

1. Убедитесь, что таблица подключена:
   ```bash
   GET /api/google-sheets/list
   ```

2. Найдите ID записи вашей таблицы в ответе

## Шаг 2: Проверка парсинга данных (тестовый скрипт)

Используйте тестовый скрипт для проверки парсинга:

```bash
php src/scripts/test_google_sheets_parsing.php <id> ZION
```

Где `<id>` - это ID записи из таблицы `google_sheets`

Скрипт покажет:
- Первые 5 строк данных из листа
- Распарсенные данные (UUID и Person Brizy)
- Какие миграции найдены по UUID
- Что будет добавлено в migration_reviewers

## Шаг 3: Проверка структуры листа

Убедитесь, что в листе "ZION":
- Первая строка содержит заголовки
- Есть колонка "UUID" (или "uuid", "MB UUID", "mb_uuid")
- Есть колонка "Person Brizy" (или "PersonBrizy", "person_brizy")

## Шаг 4: Выполнение синхронизации

После проверки парсинга выполните синхронизацию:

```bash
POST /api/google-sheets/sync/<id>
```

Где `<id>` - это ID записи из таблицы `google_sheets`

## Шаг 5: Проверка результатов

Проверьте таблицу `migration_reviewers`:

```sql
SELECT 
    mr.*,
    m.mb_project_uuid,
    m.brz_project_id
FROM migration_reviewers mr
INNER JOIN migrations m ON mr.migration_id = m.id
ORDER BY mr.created_at DESC
LIMIT 10;
```

## Возможные проблемы

### Колонка "Person Brizy" не найдена
- Проверьте точное название колонки в первой строке
- Убедитесь, что нет лишних пробелов
- Попробуйте переименовать колонку в "Person Brizy" (точно так)

### UUID не найдены
- Проверьте, что в колонке UUID есть данные
- Убедитесь, что UUID соответствуют миграциям в таблице `migrations`

### Миграции не найдены
- Проверьте, что UUID из Google Sheets совпадают с `mb_project_uuid` в таблице `migrations`
- Убедитесь, что миграции существуют в базе данных
