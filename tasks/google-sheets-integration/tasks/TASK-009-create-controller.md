# TASK-009: Создание GoogleSheetsController

## Статус: ⏳ В ожидании

**Дата создания:** 2025-01-30  
**Приоритет:** Высокий  
**Зависимости:** TASK-004, TASK-006, TASK-007

---

## Описание

Создать контроллер для API endpoints работы с Google Sheets. Контроллер должен предоставлять REST API для подключения таблиц, синхронизации данных и управления связями с волнами.

## Что нужно сделать

### 1. Создать файл контроллера

**Файл:** `dashboard/api/controllers/GoogleSheetsController.php`

### 2. Реализовать класс GoogleSheetsController

**Namespace:** `Dashboard\Controllers`

**Зависимости:**
- `GoogleSheetsService`
- `GoogleSheetsSyncService`
- `DatabaseService`

**API Endpoints:**

#### `POST /api/google-sheets/connect`
Подключить Google таблицу:
- Принимает: `spreadsheet_id`, `spreadsheet_name` (опционально)
- Сохраняет информацию о таблице в БД
- Возвращает: информация о подключенной таблице

#### `GET /api/google-sheets/list`
Список всех подключенных таблиц:
- Возвращает: массив таблиц с информацией (id, название, последняя синхронизация, привязанные волны)

#### `GET /api/google-sheets/:id`
Получить информацию о конкретной таблице:
- Возвращает: детальная информация о таблице, список листов

#### `POST /api/google-sheets/sync/:id`
Синхронизировать таблицу:
- Параметры: `sheet_name` (опционально, если не указан - синхронизировать все листы)
- Вызывает `GoogleSheetsSyncService::syncSheet()`
- Возвращает: статистику синхронизации

#### `POST /api/google-sheets/link-wave`
Привязать лист к волне:
- Принимает: `spreadsheet_id`, `sheet_name`, `wave_id`
- Вызывает `GoogleSheetsService::linkSheetToWave()`
- Возвращает: информация о привязке

#### `GET /api/google-sheets/sheets/:spreadsheetId`
Получить список листов таблицы:
- Возвращает: массив листов с их названиями и ID

#### `GET /api/google-sheets/oauth/authorize`
Получить URL для OAuth авторизации:
- Возвращает: URL для редиректа пользователя

#### `GET /api/google-sheets/oauth/callback`
Callback для OAuth:
- Принимает: `code` из Google
- Обменивает код на токен
- Сохраняет токен
- Редиректит на страницу успеха

---

## Ожидаемые результаты

### Проверка создания контроллера

- [ ] Файл `GoogleSheetsController.php` создан
- [ ] Класс находится в namespace `Dashboard\Controllers`
- [ ] Класс можно импортировать без ошибок
- [ ] Конструктор инициализирует зависимости

### Проверка API endpoints

- [ ] `POST /api/google-sheets/connect` создает запись в БД
- [ ] `GET /api/google-sheets/list` возвращает список таблиц
- [ ] `GET /api/google-sheets/:id` возвращает информацию о таблице
- [ ] `POST /api/google-sheets/sync/:id` запускает синхронизацию
- [ ] `POST /api/google-sheets/link-wave` привязывает лист к волне
- [ ] `GET /api/google-sheets/sheets/:spreadsheetId` возвращает список листов
- [ ] `GET /api/google-sheets/oauth/authorize` возвращает URL авторизации
- [ ] `GET /api/google-sheets/oauth/callback` обрабатывает OAuth callback

### Проверка валидации

- [ ] Все обязательные параметры валидируются
- [ ] При отсутствии параметров возвращается ошибка 400
- [ ] При неверных параметрах возвращается понятное сообщение об ошибке

### Проверка обработки ошибок

- [ ] Ошибки API обрабатываются и возвращаются в формате JSON
- [ ] HTTP статус коды корректны (200, 400, 401, 404, 500)
- [ ] Ошибки логируются

### Проверка безопасности

- [ ] Endpoints защищены аутентификацией (если требуется)
- [ ] Входные данные санитизируются
- [ ] SQL injection защита (использование prepared statements)

---

## Связанные файлы

- `dashboard/api/controllers/GoogleSheetsController.php` - основной файл контроллера
- `dashboard/api/index.php` - регистрация routes (если нужно)

---

## Примечания

- Использовать существующий формат ответов API (согласовать с другими контроллерами)
- Добавить CORS заголовки если нужно
- Учесть rate limiting для API запросов
