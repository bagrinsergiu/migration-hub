# Логирование синхронизации Google Sheets

## Где смотреть логи

Логи синхронизации выводятся через `error_log()` PHP, который обычно пишет в:

1. **Веб-сервер (Apache/Nginx)**: 
   - `/var/log/apache2/error.log` или `/var/log/nginx/error.log`
   - Или в конфигурации PHP: `php.ini` → `error_log`

2. **Docker контейнер**:
   ```bash
   docker-compose logs -f app
   ```

3. **PHP-FPM**:
   - `/var/log/php-fpm/error.log`

4. **Стандартный вывод** (если запущено через CLI):
   - Вывод в консоль

## Что логируется

### Этап 1: Инициализация
- ✅ Проверка Google Sheets Service
- ✅ Проверка таблицы migration_reviewers
- ✅ Параметры синхронизации (Spreadsheet ID, Sheet Name, Wave ID)

### Этап 2: Отслеживание названия таблицы
- ✅ Проверка изменений названия Google таблицы

### Этап 3: Получение данных
- ✅ Количество полученных строк из Google Sheets
- ✅ Первая строка (заголовки)
- ✅ Вторая строка (пример данных)

### Этап 4: Парсинг данных
- ✅ Поиск колонок UUID и Person Brizy
- ✅ Количество распарсенных строк
- ✅ Примеры распарсенных данных (первые 5 строк)

### Этап 5: Обработка данных
- ✅ Начало транзакции БД
- ✅ Для каждой строки:
  - UUID и Person Brizy
  - Поиск миграции
  - Результат поиска (найдена/не найдена)
  - Создание/обновление записи
  - Результат операции
- ✅ Коммит транзакции

### Этап 6: Обновление времени синхронизации
- ✅ Обновление last_synced_at

### Итоговая статистика
- ✅ Всего строк
- ✅ Обработано
- ✅ Создано
- ✅ Обновлено
- ✅ Не найдено миграций
- ✅ Ошибок

## Пример логов

```
═══════════════════════════════════════════════════════════════
[GoogleSheetsController::sync] 📥 ПОЛУЧЕН ЗАПРОС НА СИНХРОНИЗАЦИЮ
  ID таблицы: 1
  URL: http://localhost:3000/api/google-sheets/sync/1
  Method: POST
═══════════════════════════════════════════════════════════════
[GoogleSheetsController::sync] 📋 Шаг 1: Получение информации о таблице из БД...
[GoogleSheetsController::sync] ✓ Таблица найдена:
  Spreadsheet ID: 1abc...
  Sheet Name: ZION
  Wave ID: не указан
[GoogleSheetsController::sync] ✓ Используется лист: ZION
[GoogleSheetsController::sync] 🚀 Шаг 2: Запуск синхронизации...
═══════════════════════════════════════════════════════════════
[GoogleSheetsSyncService::syncSheet] 🚀 НАЧАЛО СИНХРОНИЗАЦИИ
  Spreadsheet ID: 1abc...
  Sheet Name: ZION
  Wave ID: не указан
═══════════════════════════════════════════════════════════════
[GoogleSheetsSyncService::syncSheet] ✓ Google Sheets Service инициализирован
[GoogleSheetsSyncService::syncSheet] 📋 Шаг 1: Проверка таблицы migration_reviewers...
[GoogleSheetsSyncService::syncSheet] ✓ Таблица migration_reviewers существует
[GoogleSheetsSyncService::syncSheet] 📝 Шаг 2: Отслеживание изменений названия таблицы...
[GoogleSheetsSyncService::syncSheet] ✓ Название таблицы проверено
[GoogleSheetsSyncService::syncSheet] 📥 Шаг 3: Получение данных листа 'ZION' из Google Sheets...
[GoogleSheetsSyncService::syncSheet] ✓ Получено строк из Google Sheets: 4
[GoogleSheetsSyncService::syncSheet] 📄 Первая строка (заголовки): ["UUID","Person Brizy"]
[GoogleSheetsSyncService::syncSheet] 📄 Вторая строка (пример данных): ["123e4567-e89b-12d3-a456-426614174000","Иван Иванов"]
[GoogleSheetsSyncService::syncSheet] 🔍 Шаг 4: Парсинг данных (поиск колонок UUID и Person Brizy)...
[GoogleSheetsService::parseSheetData] Найдена колонка UUID на индексе 0: 'UUID'
[GoogleSheetsService::parseSheetData] Найдена колонка Person Brizy на индексе 1: 'Person Brizy'
[GoogleSheetsSyncService::syncSheet] ✓ После парсинга: 3 строк с данными
[GoogleSheetsSyncService::syncSheet] 📊 Пример распарсенных данных (первые 3 строк):
  1. UUID: 123e4567-e89b-12d3-a456-426614174000, Person Brizy: Иван Иванов
  2. UUID: 223e4567-e89b-12d3-a456-426614174001, Person Brizy: Петр Петров
  3. UUID: 323e4567-e89b-12d3-a456-426614174002, Person Brizy: Сидор Сидоров
[GoogleSheetsSyncService::syncSheet] 🔄 Шаг 5: Обработка 3 строк данных...
[GoogleSheetsSyncService::syncSheet] 💾 Начало транзакции базы данных...
[GoogleSheetsSyncService::syncSheet] ✓ Транзакция начата
[GoogleSheetsSyncService::syncSheet]   📌 Строка 1/3: UUID=123e4567-e89b-12d3-a456-426614174000, Person Brizy=Иван Иванов
[GoogleSheetsSyncService::syncSheet]     🔍 Поиск миграции по UUID '123e4567-e89b-12d3-a456-426614174000'...
[GoogleSheetsSyncService::syncSheet]     ✓ Найдена миграция: ID=123
[GoogleSheetsSyncService::syncSheet]     💾 Создание/обновление записи в migration_reviewers...
[GoogleSheetsSyncService::syncSheet]     ✅ Создана новая запись: ID=1
...
[GoogleSheetsSyncService::syncSheet] ✓ Обработано строк: 3/3
[GoogleSheetsSyncService::syncSheet] 📝 Шаг 6: Обновление last_synced_at в таблице google_sheets...
[GoogleSheetsSyncService::syncSheet] ✓ Время синхронизации обновлено
[GoogleSheetsSyncService::syncSheet] 💾 Коммит транзакции...
[GoogleSheetsSyncService::syncSheet] ✓ Транзакция закоммичена
[GoogleSheetsSyncService::syncSheet] 📊 ИТОГОВАЯ СТАТИСТИКА:
  Всего строк: 3
  Обработано: 3
  Создано: 3
  Обновлено: 0
  Не найдено миграций: 0
  Ошибок: 0
═══════════════════════════════════════════════════════════════
[GoogleSheetsSyncService::syncSheet] ✅ СИНХРОНИЗАЦИЯ ЗАВЕРШЕНА УСПЕШНО
═══════════════════════════════════════════════════════════════
[GoogleSheetsController::sync] ✅ Синхронизация завершена успешно
[GoogleSheetsController::sync] 📊 Статистика:
  Всего строк: 3
  Обработано: 3
  Создано: 3
  Обновлено: 0
  Не найдено: 0
  Ошибок: 0
```

## Просмотр логов в реальном времени

### Linux/Mac
```bash
tail -f /var/log/apache2/error.log | grep "GoogleSheets"
# или
tail -f /var/log/nginx/error.log | grep "GoogleSheets"
```

### Docker
```bash
docker-compose logs -f app | grep "GoogleSheets"
```

### Все логи синхронизации
```bash
tail -f /var/log/apache2/error.log | grep -E "(GoogleSheets|syncSheet|СИНХРОНИЗАЦИЯ)"
```

## Уровни логирования

- 🚀 **НАЧАЛО** - начало операции
- ✅ **УСПЕШНО** - успешное выполнение
- ❌ **ОШИБКА** - ошибка выполнения
- ⚠ **ПРЕДУПРЕЖДЕНИЕ** - предупреждение (не критично)
- 📋 **ШАГ** - начало этапа
- 📥 **ВХОД** - получение данных
- 📤 **ВЫХОД** - отправка данных
- 🔍 **ПОИСК** - поиск данных
- 💾 **БД** - операция с базой данных
- 📊 **СТАТИСТИКА** - статистика
- 📄 **ДАННЫЕ** - пример данных
- 📌 **СТРОКА** - обработка строки
