# TASK-002: Создание миграций базы данных

## Статус: ⏳ В ожидании

**Дата создания:** 2025-01-30  
**Приоритет:** Критический  
**Зависимости:** Нет

---

## Описание

Создать миграции Phinx для двух новых таблиц:
1. `google_sheets` - для хранения информации о подключенных Google таблицах
2. `migration_reviewers` - для связи миграций с ревьюерами из Google Sheets

## Что нужно сделать

### 1. Создать файл миграции

**Файл:** `db/migrations/YYYYMMDDHHMMSS_create_google_sheets_tables.php`

Где `YYYYMMDDHHMMSS` - текущая дата и время в формате `20250130120000`

### 2. Создать таблицу `google_sheets`

Структура таблицы:
- `id` - integer, primary key, auto increment
- `spreadsheet_id` - string(255), NOT NULL, уникальный индекс
- `spreadsheet_name` - string(500), NULL (для отслеживания изменений)
- `sheet_id` - string(255), NULL (ID листа в Google Sheets)
- `sheet_name` - string(255), NULL (название листа)
- `wave_id` - string(100), NULL (связь с таблицей `waves`)
- `last_synced_at` - datetime, NULL
- `created_at` - datetime, default CURRENT_TIMESTAMP
- `updated_at` - datetime, default CURRENT_TIMESTAMP, on update CURRENT_TIMESTAMP

Индексы:
- Уникальный индекс на `spreadsheet_id`
- Индекс на `wave_id` для быстрого поиска по волне

### 3. Создать таблицу `migration_reviewers`

Структура таблицы:
- `id` - integer, primary key, auto increment
- `migration_id` - integer, NOT NULL (FK на `migrations.id`)
- `person_brizy` - string(255), NULL (имя человека из Google таблицы)
- `uuid` - string(255), NULL (UUID проекта из Google таблицы)
- `created_at` - datetime, default CURRENT_TIMESTAMP
- `updated_at` - datetime, default CURRENT_TIMESTAMP, on update CURRENT_TIMESTAMP

Индексы:
- Индекс на `migration_id` для быстрого поиска
- Индекс на `uuid` для поиска по UUID
- Уникальный составной индекс на `(migration_id, uuid)` для предотвращения дубликатов

### 4. Выполнить миграцию

```bash
vendor/bin/phinx migrate -e production
```

---

## Ожидаемые результаты

### Проверка структуры таблиц

- [ ] Таблица `google_sheets` создана в базе данных
- [ ] Все колонки созданы с правильными типами данных
- [ ] Индексы созданы корректно
- [ ] Таблица `migration_reviewers` создана в базе данных
- [ ] Все колонки созданы с правильными типами данных
- [ ] Индексы созданы корректно
- [ ] Foreign key на `migration_id` работает (если поддерживается)

### Проверка миграции

- [ ] Миграция успешно выполнена без ошибок
- [ ] Запись о миграции добавлена в таблицу `phinxlog`
- [ ] Можно выполнить `SELECT * FROM google_sheets` без ошибок
- [ ] Можно выполнить `SELECT * FROM migration_reviewers` без ошибок
- [ ] Можно выполнить `DESCRIBE google_sheets` и увидеть все колонки
- [ ] Можно выполнить `DESCRIBE migration_reviewers` и увидеть все колонки

### Проверка индексов

- [ ] Уникальный индекс на `google_sheets.spreadsheet_id` работает (попытка вставить дубликат должна вызвать ошибку)
- [ ] Индекс на `migration_reviewers.migration_id` существует
- [ ] Индекс на `migration_reviewers.uuid` существует
- [ ] Составной уникальный индекс на `(migration_id, uuid)` работает

---

## Связанные файлы

- `db/migrations/YYYYMMDDHHMMSS_create_google_sheets_tables.php` - файл миграции
- `phinx.php` - конфигурация Phinx

---

## Примечания

- Использовать формат миграций Phinx (AbstractMigration)
- Убедиться, что миграция идемпотентна (можно запускать несколько раз)
- Проверить совместимость с существующими таблицами
