# Полный процесс выполнения миграции из дашборда

Этот документ описывает все этапы выполнения миграции от запуска до получения результата, включая все операции опроса и логирования.

## Обзор процесса

```
[Фронтенд] → [API Дашборда] → [Сервер Миграции] → [Веб-хук] → [Обновление БД] → [Опрос статуса]
```

## Детальные этапы выполнения

### Этап 1: Запуск миграции из фронтенда

**Компонент:** `frontend/src/components/RunMigration.tsx` или `MigrationDetails.tsx`

**Действия:**
1. Пользователь заполняет форму запуска миграции
2. Нажимает кнопку "Запустить миграцию"
3. Фронтенд отправляет POST запрос на `/api/migrations/run`

**Логирование:**
- В консоли браузера: `[RunMigration] Starting migration...`
- Отправка данных: `POST /api/migrations/run`

**Параметры запроса:**
```json
{
  "mb_project_uuid": "uuid-проекта",
  "brz_project_id": 123,
  "mb_site_id": 1,
  "mb_secret": "секрет",
  "brz_workspaces_id": 456,
  "quality_analysis": true/false,
  "skip_media_upload": false,
  "skip_cache": false
}
```

---

### Этап 2: Обработка запроса в API (MigrationController)

**Файл:** `src/controllers/MigrationController.php::run()`

**Действия:**
1. Валидация обязательных полей (`mb_project_uuid`, `brz_project_id`)
2. Проверка наличия `mb_site_id` и `mb_secret` (из запроса или настроек)
3. Получение настроек из БД через `DatabaseService::getSettings()`
4. Вызов `MigrationService::runMigration()`

**Логирование:**
- В PHP логах: `[MigrationController::run] Запуск миграции для brz_project_id: {id}`
- Ошибки валидации возвращаются с HTTP 400

**Файлы логов:**
- `var/log/php/php-errors.log`
- `var/log/php/fpm-error.log`

---

### Этап 3: Обработка в MigrationService

**Файл:** `src/services/MigrationService.php::runMigration()`

**Действия:**
1. Получение настроек по умолчанию из БД
2. Заполнение недостающих параметров из настроек
3. Вызов `ApiProxyService::runMigration()`

**Логирование:**
- `[MigrationService::runMigration] Параметры миграции: mb_project_uuid={uuid}, brz_project_id={id}`

---

### Этап 4: Отправка запроса на сервер миграции (ApiProxyService)

**Файл:** `src/services/ApiProxyService.php::runMigration()`

**Действия:**

#### 4.1. Проверка health сервера миграции (опционально)
- Вызов `checkMigrationServerHealth()`
- Endpoint: `{MIGRATION_API_URL}/health`
- Таймаут: 5 секунд

**Логирование:**
```
[ApiProxyService::checkMigrationServerHealth] Проверка доступности сервера миграции: {url}
[ApiProxyService::checkMigrationServerHealth] Сервер миграции доступен (HTTP {code})
```

#### 4.2. Формирование параметров запроса
- Добавление всех параметров миграции в query string
- **Добавление параметров веб-хука:**
  - `webhook_url` - URL endpoint для обратного вызова
  - `webhook_mb_project_uuid` - UUID проекта для идентификации
  - `webhook_brz_project_id` - ID проекта Brizy для идентификации

**Логирование:**
```
[ApiProxyService::runMigration] Отправка HTTP запроса на сервер миграции: {url}
[ApiProxyService::runMigration] Параметры запроса: mb_project_uuid={uuid}, brz_project_id={id}, mb_site_id={site_id}
```

**Формируемый URL:**
```
{MIGRATION_API_URL}/?mb_project_uuid={uuid}&brz_project_id={id}&mb_site_id={site_id}&mb_secret={secret}&webhook_url={webhook_url}&webhook_mb_project_uuid={uuid}&webhook_brz_project_id={id}&...
```

#### 4.3. Отправка HTTP запроса
- Метод: GET
- Таймаут: 10 секунд
- Таймаут подключения: 5 секунд

**Логирование:**
```
[ApiProxyService::runMigration] HTTP код: {code}, ошибка: {error}
```

#### 4.4. Обработка ответа
- **Успешный ответ (200-299):** Возврат данных миграции
- **Ошибка подключения:** Возврат успеха с предупреждением (миграция запускается в фоне)
- **Другие ошибки:** Выброс исключения

**Логирование при ошибке подключения:**
```
[ApiProxyService::runMigration] Ошибка подключения к серверу миграции: {error}
[ApiProxyService::runMigration] URL: {url}
[ApiProxyService::runMigration] Убедитесь, что сервер миграции запущен на указанном адресе
```

---

### Этап 5: Ответ фронтенду

**Действия:**
1. MigrationController возвращает JSON ответ
2. Фронтенд получает ответ и обновляет UI

**Формат ответа (успех):**
```json
{
  "success": true,
  "data": {
    "status": "in_progress",
    "message": "Миграция запущена",
    "mb_project_uuid": "uuid",
    "brz_project_id": 123
  }
}
```

**Логирование в браузере:**
- `[RunMigration] Migration started successfully`
- Перенаправление на страницу деталей миграции

---

### Этап 6: Опрос статуса миграции (периодический)

**Компонент:** `frontend/src/components/MigrationDetails.tsx`

**Действия:**

#### 6.1. Автоматическое обновление статуса
- **Интервал:** 3 секунды (для активных миграций)
- **Условие:** `status === 'in_progress'` или есть lock-файл

**Логирование в браузере:**
```javascript
console.log('[MigrationDetails] Auto-refreshing status...', status);
```

#### 6.2. Запросы к API
1. **GET `/api/migrations/{id}`** - Получение деталей миграции
   - Вызывается каждые 3 секунды
   - Обновляет статус, прогресс, данные миграции

2. **GET `/api/migrations/{id}/process`** - Информация о процессе
   - Вызывается каждые 3 секунды
   - Проверяет наличие lock-файла, статус процесса

3. **GET `/api/migrations/{id}/pages`** - Список страниц (если есть)
   - Вызывается каждые 3 секунды
   - Обновляет статусы миграции страниц

**Логирование:**
- В PHP: `[MigrationController::getDetails] Запрос деталей миграции: {id}`
- В PHP: `[MigrationService::getMigrationDetails] Получение деталей для brz_project_id: {id}`

#### 6.3. Опрос статуса с сервера миграции (опционально)
- **GET `/api/migrations/{id}/status-from-server`**
- Вызывается через `ApiProxyService::getMigrationStatusFromServer()`
- Endpoint на сервере миграции: `{MIGRATION_API_URL}/migration-status?mb_project_uuid={uuid}&brz_project_id={id}`

**Логирование:**
```
[ApiProxyService::getMigrationStatusFromServer] Запрос статуса миграции: {url}
```

---

### Этап 7: Выполнение миграции на сервере миграции

**Сервер миграции выполняет:**
1. Принимает запрос с параметрами веб-хука
2. Сохраняет параметры веб-хука для последующего вызова
3. Запускает миграцию в фоновом режиме
4. Обновляет статус и прогресс миграции
5. По завершении вызывает веб-хук

**Логирование на сервере миграции:**
- Все операции логируются в логах сервера миграции
- Статус доступен через endpoint `/migration-status`

---

### Этап 8: Вызов веб-хука при завершении миграции

**Сервер миграции отправляет:**
- **POST** запрос на `{webhook_url}` (обычно `http://localhost:8088/api/webhooks/migration-result`)

**Тело запроса:**
```json
{
  "mb_project_uuid": "uuid",
  "brz_project_id": 123,
  "status": "completed",
  "migration_uuid": "migration-uuid",
  "brizy_project_id": 123,
  "brizy_project_domain": "example.brizy.io",
  "migration_id": "mig-123",
  "date": "2024-01-15",
  "theme": "default",
  "mb_product_name": "Product Name",
  "mb_site_id": 1,
  "mb_project_domain": "example.com",
  "progress": {
    "total_pages": 10,
    "processed_pages": 10,
    "progress_percent": 100
  }
}
```

**Логирование:**
```
[WebhookController::migrationResult] Получен веб-хук для миграции: mb_project_uuid={uuid}, brz_project_id={id}
```

---

### Этап 9: Обработка веб-хука (WebhookController)

**Файл:** `src/controllers/WebhookController.php::migrationResult()`

**Действия:**
1. Валидация обязательных полей (`mb_project_uuid`, `brz_project_id`)
2. Нормализация статуса (`success` → `completed`, `failed` → `error`)
3. Обновление записи в `migrations_mapping` через `DatabaseService::upsertMigrationMapping()`
4. Сохранение результата в `migration_result_list` через `DatabaseService::saveMigrationResult()`

**Логирование:**
```
[WebhookController::migrationResult] Получен веб-хук для миграции: mb_project_uuid={uuid}, brz_project_id={id}
[WebhookController::migrationResult] Статус миграции обновлен: status={status}, mb_project_uuid={uuid}, brz_project_id={id}
```

**Ошибки:**
- HTTP 400: Отсутствуют обязательные поля
- HTTP 500: Ошибка при сохранении в БД

**Ответ:**
```json
{
  "success": true,
  "message": "Результат миграции успешно обработан",
  "data": {
    "mb_project_uuid": "uuid",
    "brz_project_id": 123,
    "status": "completed"
  }
}
```

---

### Этап 10: Обновление UI после завершения

**Компонент:** `frontend/src/components/MigrationDetails.tsx`

**Действия:**
1. Периодический опрос обнаруживает изменение статуса на `completed` или `error`
2. Выполняется финальное обновление данных
3. Останавливается автоматическое обновление (интервал очищается)

**Логирование в браузере:**
```javascript
console.log('[MigrationDetails] Status changed to:', status);
console.log('[MigrationDetails] Stopping auto-refresh');
```

**Условие остановки опроса:**
```javascript
const hasActiveMigration = details?.status === 'in_progress' || 
                          (processInfo?.lock_file_exists && processInfo?.process?.running) ||
                          Object.values(pageMigrationStatus).some(status => status === 'in_progress');
```

---

## Схема временной последовательности

```
T+0s:   [Фронтенд] → POST /api/migrations/run
T+0.1s: [API] → Валидация параметров
T+0.2s: [API] → Health check сервера миграции (опционально)
T+0.5s: [API] → GET {MIGRATION_API_URL}/?{params}&webhook_url=...
T+1s:   [Сервер миграции] → Запуск миграции в фоне
T+1s:   [API] → Ответ фронтенду: {status: "in_progress"}
T+1s:   [Фронтенд] → Перенаправление на страницу деталей
T+4s:   [Фронтенд] → Первый опрос статуса (GET /api/migrations/{id})
T+7s:   [Фронтенд] → Второй опрос статуса
T+10s:  [Фронтенд] → Третий опрос статуса
...     [Фронтенд] → Опрос каждые 3 секунды
T+300s: [Сервер миграции] → Завершение миграции
T+300s: [Сервер миграции] → POST {webhook_url} (веб-хук)
T+300.1s: [API] → Обработка веб-хука, обновление БД
T+303s: [Фронтенд] → Опрос обнаруживает статус "completed"
T+303s: [Фронтенд] → Остановка автоматического обновления
```

---

## Логирование на всех этапах

### PHP логи (серверная часть)

**Файлы:**
- `var/log/php/php-errors.log` - Все ошибки и предупреждения
- `var/log/php/fpm-error.log` - Ошибки PHP-FPM
- `var/log/php/fpm-access.log` - Доступ к PHP-FPM

**Ключевые сообщения:**
```
[ApiProxyService] Инициализация с baseUrl: {url}
[ApiProxyService::checkMigrationServerHealth] Проверка доступности сервера миграции
[ApiProxyService::runMigration] Отправка HTTP запроса на сервер миграции
[ApiProxyService::runMigration] HTTP код: {code}
[MigrationController::run] Запуск миграции для brz_project_id: {id}
[WebhookController::migrationResult] Получен веб-хук для миграции
[WebhookController::migrationResult] Статус миграции обновлен
```

### Логи браузера (клиентская часть)

**Консоль браузера (F12):**
```javascript
[RunMigration] Starting migration...
[RunMigration] Migration started successfully
[MigrationDetails] Loading migration details...
[MigrationDetails] Auto-refreshing status...
[MigrationDetails] Status changed to: completed
[MigrationDetails] Stopping auto-refresh
```

### Логи сервера миграции

**Логируются на стороне сервера миграции:**
- Прием запроса на запуск миграции
- Выполнение миграции
- Вызов веб-хука
- Ответы на запросы статуса

---

## Мониторинг процесса миграции

### В дашборде

1. **Страница деталей миграции** (`/migrations/{id}`)
   - Статус миграции
   - Прогресс выполнения
   - Информация о процессе (lock-файл, PID)
   - Логи миграции

2. **Автоматическое обновление**
   - Каждые 3 секунды для активных миграций
   - Останавливается при завершении

3. **Индикаторы статуса**
   - `pending` - Ожидает запуска
   - `in_progress` - Выполняется
   - `completed` - Завершена успешно
   - `error` - Завершена с ошибкой

### Через API

1. **GET `/api/migrations/{id}`** - Детали миграции
2. **GET `/api/migrations/{id}/status`** - Только статус
3. **GET `/api/migrations/{id}/process`** - Информация о процессе
4. **GET `/api/migrations/{id}/status-from-server`** - Статус с сервера миграции
5. **GET `/api/migrations/{id}/logs`** - Логи миграции

---

## Обработка ошибок

### Ошибки на этапе запуска

1. **Валидация параметров (HTTP 400)**
   - Отсутствуют обязательные поля
   - Логирование: `[MigrationController::run] Ошибка валидации: {field}`

2. **Сервер миграции недоступен**
   - Health check возвращает ошибку
   - Миграция все равно запускается (может быть в фоне)
   - Логирование: `[ApiProxyService::runMigration] Сервер миграции недоступен`

3. **Ошибка подключения**
   - Таймаут или отказ в подключении
   - Возвращается успех с предупреждением
   - Логирование: `[ApiProxyService::runMigration] Ошибка подключения: {error}`

### Ошибки на этапе выполнения

1. **Веб-хук не доставлен**
   - Фронтенд продолжает опрашивать статус
   - Статус обновляется через опрос `/migration-status`

2. **Ошибка обработки веб-хука**
   - Логирование: `[WebhookController::migrationResult] Ошибка: {error}`
   - Возвращается HTTP 500
   - Сервер миграции может повторить попытку

### Ошибки на этапе опроса

1. **Миграция не найдена (HTTP 404)**
   - Нормально для новых миграций
   - Фронтенд продолжает опрос

2. **Ошибка получения статуса**
   - Логирование: `[ApiProxyService::getMigrationStatusFromServer] Ошибка: {error}`
   - Фронтенд продолжает опрос

---

## Рекомендации по мониторингу

1. **Мониторинг логов PHP:**
   ```bash
   tail -f var/log/php/php-errors.log | grep -i migration
   ```

2. **Мониторинг веб-хуков:**
   ```bash
   tail -f var/log/php/php-errors.log | grep -i webhook
   ```

3. **Мониторинг опросов:**
   ```bash
   tail -f var/log/php/fpm-access.log | grep "GET /api/migrations"
   ```

4. **Проверка статуса миграций:**
   - Через UI: Страница деталей миграции
   - Через API: `GET /api/migrations/{id}`
   - Через БД: Таблица `migrations_mapping` и `migration_result_list`

---

## Чек-лист для отладки

- [ ] Проверить логи PHP: `var/log/php/php-errors.log`
- [ ] Проверить доступность сервера миграции: `GET {MIGRATION_API_URL}/health`
- [ ] Проверить параметры веб-хука в запросе запуска
- [ ] Проверить получение веб-хука: `POST /api/webhooks/migration-result`
- [ ] Проверить обновление статуса в БД
- [ ] Проверить опрос статуса в браузере (консоль F12)
- [ ] Проверить endpoint опроса статуса: `GET /api/migrations/{id}/status-from-server`

---

## Заключение

Весь процесс миграции полностью логируется на всех этапах:
- Запуск миграции
- Опрос статуса
- Обработка веб-хука
- Обновление БД
- Обновление UI

Все логи доступны в файлах `var/log/php/*.log` и в консоли браузера.
