# Исправления и улучшения синхронизации Google Sheets

## Дата: 2025-02-05

## Выполненные исправления

### 1. Обработка ошибок Rate Limit (429)
**Файл:** `src/services/GoogleSheetsService.php`

- ✅ Добавлен метод `isRateLimitError()` для определения ошибок rate limit
- ✅ Обновлен `getSheetData()` с автоматическим retry (до 3 попыток)
- ✅ Обновлен `getSpreadsheet()` с автоматическим retry
- ✅ Экспоненциальная задержка: 60, 70, 80 секунд между попытками
- ✅ Понятные сообщения об ошибках для пользователя

### 2. Улучшение парсинга данных
**Файл:** `src/services/GoogleSheetsService.php`

- ✅ Расширен поиск колонки UUID (поддержка разных вариантов названия)
- ✅ Расширен поиск колонки "Person Brizy" (поддержка разных форматов)
- ✅ Добавлено логирование найденных колонок для отладки
- ✅ Улучшена обработка пустых строк и значений

**Поддерживаемые варианты названий:**
- UUID: `uuid`, `mb_uuid`, `mb project uuid`, `mb-project-uuid`, и любые содержащие "uuid"
- Person Brizy: `person brizy`, `personbrizy`, `person_brizy`, `person-brizy`, `reviewer`, `reviewer name`, `reviewer_name`

### 3. Улучшение контроллера синхронизации
**Файл:** `src/controllers/GoogleSheetsController.php`

- ✅ Добавлена поддержка опционального параметра `sheet_name` в теле запроса
- ✅ Улучшена обработка ошибок с детальной информацией
- ✅ Добавлено логирование для отладки

### 4. Улучшение сервиса синхронизации
**Файл:** `src/services/GoogleSheetsSyncService.php`

- ✅ Добавлен опциональный параметр `sheetName` в метод `syncSheetById()`
- ✅ Улучшено логирование на каждом этапе синхронизации
- ✅ Добавлены примеры распарсенных данных в логах
- ✅ Улучшена обработка ошибок с понятными сообщениями

### 5. Добавление класса в автозагрузку
**Файл:** `src/index.php`

- ✅ Добавлен `GoogleSheetsSyncService` в список предзагружаемых классов

### 6. Создание тестовых скриптов
**Файлы:** 
- `src/scripts/test_google_sheets_parsing.php` - тест парсинга
- `src/scripts/test_sync_debug.php` - полная проверка синхронизации

## Как использовать

### Синхронизация через API

```bash
POST /api/google-sheets/sync/<id>
Content-Type: application/json

{
  "sheet_name": "ZION"  // опционально, если не указано, берется из БД
}
```

### Тестирование парсинга

```bash
php src/scripts/test_google_sheets_parsing.php <id> ZION
```

### Полная проверка синхронизации

```bash
php src/scripts/test_sync_debug.php
```

## Проверка результатов

После синхронизации проверьте таблицу `migration_reviewers`:

```sql
SELECT 
    mr.id,
    mr.migration_id,
    mr.uuid,
    mr.person_brizy,
    m.mb_project_uuid,
    m.brz_project_id,
    mr.created_at
FROM migration_reviewers mr
INNER JOIN migrations m ON mr.migration_id = m.id
ORDER BY mr.created_at DESC;
```

## Известные ограничения

1. **Rate Limit Google Sheets API**: 60 запросов в минуту на пользователя
   - Система автоматически обрабатывает это с retry
   - При превышении лимита нужно подождать минуту или запросить увеличение квоты

2. **Требования к структуре листа**:
   - Первая строка должна содержать заголовки
   - Обязательная колонка: UUID (или варианты)
   - Опциональная колонка: Person Brizy (или варианты)

3. **Требования к данным**:
   - UUID должны соответствовать `mb_project_uuid` в таблице `migrations`
   - Пустые строки автоматически пропускаются

## Следующие шаги

1. Протестировать синхронизацию с реальными данными
2. Проверить логи на наличие ошибок
3. При необходимости запросить увеличение квоты в Google Cloud Console
