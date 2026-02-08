# Документация API для сервера миграции: Веб-хуки и опрос статуса

Эта документация описывает интеграцию между дашбордом миграций и сервером миграции через веб-хуки и API для опроса статуса.

## Обзор

Дашборд миграций передает параметры веб-хука при запуске/перезапуске миграции. Сервер миграции должен:

1. Принять параметры веб-хука при запуске миграции
2. Вызвать веб-хук по завершению миграции (успешной или с ошибкой)
3. Предоставить endpoint для опроса статуса миграции

## 0. Взаимное рукопожатие и тест связи

### Взаимное рукопожатие (рекомендуемый способ проверки)

Дашборд дергает сервер миграции, получает его идентичность (сервис, server_id, IP), а сервер миграции в ответ на тот же запрос сам дергает дашборд. Если оба шага успешны — связь работает в обе стороны.

- **Дашборд:** `GET /api/migration-server/handshake` (требует авторизации). Ответ содержит `migration_server` (service, server_id, client_ip), `handshake_with_dashboard: "ok"` или `"fail"`. При `ok` — рукопожатие прошло.
- **Сервер миграции:** `GET /dashboard-handshake?dashboard_callback_url={url}` — возвращает идентичность и при переданном `dashboard_callback_url` вызывает дашборд (`POST {url}` с `{"source": "migration_server", "handshake": true}`), в ответ добавляет `handshake_with_dashboard: "ok"` или `"fail"`.

Ожидаемое тело ответа сервера миграции на `GET /dashboard-handshake` (без параметра): `service: "migration-server"`, `server_id`, `timestamp`, `client_ip`. С параметром `dashboard_callback_url` — дополнительно `handshake_with_dashboard`, при ошибке — `handshake_error`.

### Тест связи только к дашборду

Чтобы убедиться, что сервер миграции достучится до дашборда (тот же хост, что в `webhook_url`), можно вызвать тестовый эндпоинт **без авторизации**.

**URL:** `{DASHBOARD_BASE_URL}/api/webhooks/test-connection`

- **GET** — проверка доступности дашборда. Ответ: `{"success": true, "service": "migration-dashboard", "message": "Dashboard is reachable", "timestamp": "..."}`.
- **POST** — проверка с идентификацией. Тело, например: `{"source": "migration_server", "handshake": true}`. Ответ содержит `dashboard: "migration-dashboard"` и поле `your_payload` — обе стороны убеждаются, что связь с нужным сервисом есть.

Пример с сервера миграции:

```bash
curl -s "http://dashboard-host:8088/api/webhooks/test-connection"
curl -s -X POST "http://dashboard-host:8088/api/webhooks/test-connection" \
  -H "Content-Type: application/json" \
  -d '{"source": "migration_server", "handshake": true}'
```

Локальный тест из репозитория дашборда: `php src/scripts/test_webhook_connection.php [BASE_URL]`.

## 1. Параметры веб-хука при запуске миграции

### Запрос на запуск миграции

Отправка всех параметров webhook является обязательными, только если они отправляются из дашборда.
Когда дашборд запускает миграцию, он отправляет GET запрос на сервер миграции со следующими параметрами:

**URL:** `{MIGRATION_SERVER_URL}/?{query_params}`

**Метод:** `GET`

**Query параметры:**

| Параметр | Тип | Обязательный | Описание |
|----------|-----|--------------|----------|
| `mb_project_uuid` | string | Да | UUID проекта в системе MB |
| `brz_project_id` | integer | Да | ID проекта в системе Brizy |
| `mb_site_id` | integer | Да | ID сайта в системе MB |
| `mb_secret` | string | Да | Секретный ключ для доступа к API MB |
| `brz_workspaces_id` | integer | Нет | ID рабочего пространства Brizy |
| `mb_page_slug` | string | Нет | Slug страницы для миграции конкретной страницы |
| `mb_element_name` | string | Нет | Имя элемента для тестовой миграции |
| `skip_media_upload` | boolean | Нет | Пропустить загрузку медиа (true/false) |
| `skip_cache` | boolean | Нет | Пропустить кэш (true/false) |
| `mgr_manual` | integer | Нет | Режим ручной миграции (0/1) |
| `quality_analysis` | boolean | Нет | Включить анализ качества (true/false) |
| **`webhook_url`** | string | **Да** | URL endpoint для отправки результата миграции |
| **`webhook_mb_project_uuid`** | string | **Да** | UUID проекта для идентификации в веб-хуке |
| **`webhook_brz_project_id`** | integer | **Да** | ID проекта Brizy для идентификации в веб-хуке |

**Пример запроса:**
```
GET http://localhost:8080/?mb_project_uuid=abc-123&brz_project_id=456&mb_site_id=1&mb_secret=secret123&webhook_url=http://localhost:8088/api/webhooks/migration-result&webhook_mb_project_uuid=abc-123&webhook_brz_project_id=456
```

**Ожидаемый ответ:**

Сервер миграции должен вернуть HTTP 200 или 202 (Accepted) с JSON ответом:

```json
{
  "status": "in_progress",
  "message": "Миграция запущена",
  "mb_project_uuid": "abc-123",
  "brz_project_id": 456
}
```

**Важно:** Сервер миграции должен сохранить параметры `webhook_url`, `webhook_mb_project_uuid` и `webhook_brz_project_id` для последующего вызова веб-хука по завершению миграции.

## 2. Вызов веб-хука по завершению миграции

Когда миграция завершается (успешно или с ошибкой), сервер миграции должен отправить POST запрос на URL, указанный в параметре `webhook_url`.

### Запрос веб-хука

**URL:** `{webhook_url}` (переданный в параметре `webhook_url` при запуске)

**Метод:** `POST`

**Content-Type:** `application/json`

**Тело запроса (JSON):**

```json
{
  "mb_project_uuid": "abc-123",
  "brz_project_id": 456,
  "status": "completed",
  "migration_uuid": "migration-12345",
  "brizy_project_id": 456,
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

**Поля запроса:**

| Поле | Тип | Обязательный | Описание |
|------|-----|--------------|----------|
| `mb_project_uuid` | string | Да | UUID проекта в системе MB (должен совпадать с `webhook_mb_project_uuid`) |
| `brz_project_id` | integer | Да | ID проекта в системе Brizy (должен совпадать с `webhook_brz_project_id`) |
| `status` | string | Да | Статус миграции: `"completed"`, `"error"`, `"in_progress"` |
| `migration_uuid` | string | Нет | Уникальный идентификатор миграции |
| `brizy_project_id` | integer | Нет | ID проекта в Brizy |
| `brizy_project_domain` | string | Нет | Домен проекта в Brizy |
| `migration_id` | string | Нет | ID миграции |
| `date` | string | Нет | Дата миграции (формат: YYYY-MM-DD) |
| `theme` | string | Нет | Тема проекта |
| `mb_product_name` | string | Нет | Название продукта в MB |
| `mb_site_id` | integer | Нет | ID сайта в MB |
| `mb_project_domain` | string | Нет | Домен проекта в MB |
| `progress` | object | Нет | Объект с информацией о прогрессе миграции |
| `error` | string | Нет | Сообщение об ошибке (если статус = "error") |

**Пример успешного ответа от дашборда:**

```json
{
  "success": true,
  "message": "Результат миграции успешно обработан",
  "data": {
    "mb_project_uuid": "abc-123",
    "brz_project_id": 456,
    "status": "completed"
  }
}
```

**Пример ответа с ошибкой от дашборда:**

```json
{
  "success": false,
  "error": "Обязательные поля отсутствуют: mb_project_uuid, brz_project_id"
}
```

**HTTP коды ответа:**

- `200` - Результат успешно обработан
- `400` - Ошибка валидации (недостаточно обязательных полей)
- `500` - Внутренняя ошибка сервера

**Важно:** 
- Сервер миграции должен повторять попытку отправки веб-хука в случае ошибки (например, при таймауте или недоступности дашборда)
- Рекомендуется реализовать механизм повторных попыток (retry) с экспоненциальной задержкой
- Максимальное количество попыток: 3-5 раз
- Интервал между попытками: 5 секунд, 15 секунд, 30 секунд

## 3. Endpoint для опроса статуса миграции

Дашборд периодически опрашивает сервер миграции для получения актуального статуса миграции. Это необходимо на случай, если веб-хук не был доставлен или миграция еще выполняется.

### Запрос статуса миграции

**URL:** `{MIGRATION_SERVER_URL}/migration-status?mb_project_uuid={uuid}&brz_project_id={id}`

**Метод:** `GET`

**Query параметры:**

| Параметр | Тип | Обязательный | Описание |
|----------|-----|--------------|----------|
| `mb_project_uuid` | string | Да | UUID проекта в системе MB |
| `brz_project_id` | integer | Да | ID проекта в системе Brizy |

**Пример запроса:**
```
GET http://localhost:8080/migration-status?mb_project_uuid=abc-123&brz_project_id=456
```

**Ожидаемый ответ (успешный):**

```json
{
  "status": "in_progress",
  "mb_project_uuid": "abc-123",
  "brz_project_id": 456,
  "progress": {
    "total_pages": 10,
    "processed_pages": 5,
    "progress_percent": 50,
    "current_stage": "migrating_pages"
  },
  "started_at": "2024-01-15T10:00:00Z",
  "updated_at": "2024-01-15T10:05:00Z"
}
```

**Ожидаемый ответ (завершенная миграция):**

```json
{
  "status": "completed",
  "mb_project_uuid": "abc-123",
  "brz_project_id": 456,
  "brizy_project_id": 456,
  "brizy_project_domain": "example.brizy.io",
  "migration_id": "mig-123",
  "progress": {
    "total_pages": 10,
    "processed_pages": 10,
    "progress_percent": 100
  },
  "started_at": "2024-01-15T10:00:00Z",
  "completed_at": "2024-01-15T10:30:00Z"
}
```

**Ожидаемый ответ (ошибка):**

```json
{
  "status": "error",
  "mb_project_uuid": "abc-123",
  "brz_project_id": 456,
  "error": "Ошибка при миграции страницы: Connection timeout",
  "started_at": "2024-01-15T10:00:00Z",
  "failed_at": "2024-01-15T10:15:00Z"
}
```

**Ожидаемый ответ (миграция не найдена):**

```json
{
  "error": "Миграция не найдена",
  "mb_project_uuid": "abc-123",
  "brz_project_id": 456
}
```

**HTTP коды ответа:**

- `200` - Статус успешно получен
- `404` - Миграция не найдена
- `500` - Внутренняя ошибка сервера

**Поля ответа:**

| Поле | Тип | Описание |
|------|-----|----------|
| `status` | string | Статус миграции: `"pending"`, `"in_progress"`, `"completed"`, `"error"` |
| `mb_project_uuid` | string | UUID проекта в системе MB |
| `brz_project_id` | integer | ID проекта в системе Brizy |
| `progress` | object | Объект с информацией о прогрессе (опционально) |
| `progress.total_pages` | integer | Общее количество страниц |
| `progress.processed_pages` | integer | Количество обработанных страниц |
| `progress.progress_percent` | integer | Процент выполнения (0-100) |
| `progress.current_stage` | string | Текущий этап миграции (например, "migrating_pages", "uploading_media") |
| `started_at` | string | Время начала миграции (ISO 8601) |
| `updated_at` | string | Время последнего обновления статуса (ISO 8601) |
| `completed_at` | string | Время завершения миграции (ISO 8601, только для завершенных) |
| `failed_at` | string | Время ошибки (ISO 8601, только для ошибок) |
| `error` | string | Сообщение об ошибке (только для статуса "error") |
| `brizy_project_id` | integer | ID проекта в Brizy (только для завершенных) |
| `brizy_project_domain` | string | Домен проекта в Brizy (только для завершенных) |
| `migration_id` | string | ID миграции (только для завершенных) |

## 4. Рекомендации по реализации

### Хранение параметров веб-хука

Сервер миграции должен сохранять параметры веб-хука при запуске миграции. Рекомендуется хранить их в:

- Базе данных (таблица миграций)
- Файловой системе (lock-файл или конфигурационный файл)
- Памяти (для краткосрочных миграций)

**Пример структуры данных для хранения:**

```json
{
  "mb_project_uuid": "abc-123",
  "brz_project_id": 456,
  "webhook_url": "http://localhost:8088/api/webhooks/migration-result",
  "webhook_mb_project_uuid": "abc-123",
  "webhook_brz_project_id": 456,
  "started_at": "2024-01-15T10:00:00Z",
  "status": "in_progress"
}
```

### Вызов веб-хука

Рекомендуется вызывать веб-хук в следующих случаях:

1. **При успешном завершении миграции** - статус `"completed"`
2. **При ошибке миграции** - статус `"error"` с описанием ошибки
3. **При критических ошибках** - статус `"error"` с детальной информацией

**Пример кода для вызова веб-хука (псевдокод):**

```php
function callWebhook($webhookUrl, $migrationData) {
    $maxRetries = 3;
    $retryDelay = 5; // секунд
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($migrationData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            // Успешно отправлено
            return true;
        }
        
        // Если не последняя попытка, ждем перед повтором
        if ($attempt < $maxRetries) {
            sleep($retryDelay * $attempt); // Экспоненциальная задержка
        }
    }
    
    // Все попытки неудачны - логируем ошибку
    error_log("Failed to call webhook after {$maxRetries} attempts: {$error}");
    return false;
}
```

### Endpoint для опроса статуса

Endpoint `/migration-status` должен:

1. Принимать `mb_project_uuid` и `brz_project_id` как query параметры
2. Искать миграцию в хранилище (БД, файлы, память)
3. Возвращать актуальный статус и прогресс миграции
4. Возвращать 404, если миграция не найдена

**Пример реализации (псевдокод):**

```php
function getMigrationStatus($mbProjectUuid, $brzProjectId) {
    // Ищем миграцию в хранилище
    $migration = findMigration($mbProjectUuid, $brzProjectId);
    
    if (!$migration) {
        return [
            'error' => 'Миграция не найдена',
            'mb_project_uuid' => $mbProjectUuid,
            'brz_project_id' => $brzProjectId
        ];
    }
    
    // Формируем ответ
    $response = [
        'status' => $migration['status'],
        'mb_project_uuid' => $mbProjectUuid,
        'brz_project_id' => $brzProjectId,
        'started_at' => $migration['started_at'],
        'updated_at' => $migration['updated_at']
    ];
    
    // Добавляем прогресс, если миграция в процессе
    if ($migration['status'] === 'in_progress') {
        $response['progress'] = [
            'total_pages' => $migration['total_pages'],
            'processed_pages' => $migration['processed_pages'],
            'progress_percent' => calculateProgress($migration),
            'current_stage' => $migration['current_stage']
        ];
    }
    
    // Добавляем данные результата, если миграция завершена
    if ($migration['status'] === 'completed') {
        $response['brizy_project_id'] = $migration['brizy_project_id'];
        $response['brizy_project_domain'] = $migration['brizy_project_domain'];
        $response['migration_id'] = $migration['migration_id'];
        $response['completed_at'] = $migration['completed_at'];
    }
    
    // Добавляем ошибку, если миграция завершилась с ошибкой
    if ($migration['status'] === 'error') {
        $response['error'] = $migration['error'];
        $response['failed_at'] = $migration['failed_at'];
    }
    
    return $response;
}
```

## 5. Частота опроса

Дашборд опрашивает статус миграции с следующей частотой:

- **Активные миграции (in_progress):** каждые 3-5 секунд
- **Завершенные миграции:** не опрашиваются (используется веб-хук)

Сервер миграции должен быть готов обрабатывать частые запросы на опрос статуса.

## 6. Безопасность

### Рекомендации по безопасности:

1. **Валидация данных:** Проверяйте все входящие данные в веб-хуке и endpoint опроса статуса
2. **Rate limiting:** Ограничьте частоту запросов к endpoint опроса статуса (например, 10 запросов в секунду с одного IP)
3. **HTTPS:** Используйте HTTPS для передачи данных веб-хука в production
4. **Токен авторизации (опционально):** Можно добавить проверку токена в веб-хуке для дополнительной безопасности

**Пример добавления токена авторизации:**

При запуске миграции можно передавать дополнительный параметр `webhook_token`:

```
webhook_token=secret-token-123
```

При вызове веб-хука сервер миграции должен включить этот токен в заголовок:

```
Authorization: Bearer secret-token-123
```

Или в тело запроса:

```json
{
  "mb_project_uuid": "abc-123",
  "brz_project_id": 456,
  "webhook_token": "secret-token-123",
  ...
}
```

## 7. Примеры полного цикла

### Пример 1: Успешная миграция

1. **Запуск миграции:**
   ```
   GET http://localhost:8080/?mb_project_uuid=abc-123&brz_project_id=456&mb_site_id=1&mb_secret=secret&webhook_url=http://localhost:8088/api/webhooks/migration-result&webhook_mb_project_uuid=abc-123&webhook_brz_project_id=456
   ```
   
   Ответ: `200 OK` с `{"status": "in_progress"}`

2. **Опрос статуса (через 5 секунд):**
   ```
   GET http://localhost:8080/migration-status?mb_project_uuid=abc-123&brz_project_id=456
   ```
   
   Ответ: `200 OK` с `{"status": "in_progress", "progress": {...}}`

3. **Завершение миграции - вызов веб-хука:**
   ```
   POST http://localhost:8088/api/webhooks/migration-result
   Content-Type: application/json
   
   {
     "mb_project_uuid": "abc-123",
     "brz_project_id": 456,
     "status": "completed",
     "brizy_project_domain": "example.brizy.io",
     ...
   }
   ```
   
   Ответ: `200 OK` с `{"success": true}`

### Пример 2: Миграция с ошибкой

1. **Запуск миграции:** (аналогично примеру 1)

2. **Ошибка миграции - вызов веб-хука:**
   ```
   POST http://localhost:8088/api/webhooks/migration-result
   Content-Type: application/json
   
   {
     "mb_project_uuid": "abc-123",
     "brz_project_id": 456,
     "status": "error",
     "error": "Ошибка при миграции страницы: Connection timeout"
   }
   ```
   
   Ответ: `200 OK` с `{"success": true}`

3. **Опрос статуса (для проверки):**
   ```
   GET http://localhost:8080/migration-status?mb_project_uuid=abc-123&brz_project_id=456
   ```
   
   Ответ: `200 OK` с `{"status": "error", "error": "..."}`

## 8. Чек-лист для реализации на сервере миграции

- [ ] Сохранение параметров веб-хука при запуске миграции
- [ ] Реализация вызова веб-хука при завершении миграции (успешной или с ошибкой)
- [ ] Реализация механизма повторных попыток (retry) для веб-хука
- [ ] Реализация endpoint `/migration-status` для опроса статуса
- [ ] Обработка всех возможных статусов миграции
- [ ] Возврат корректных HTTP кодов ответа
- [ ] Логирование всех операций с веб-хуками
- [ ] Обработка ошибок и таймаутов
- [ ] Валидация входящих данных
- [ ] (Опционально) Реализация rate limiting для endpoint опроса статуса
- [ ] (Опционально) Реализация токена авторизации для веб-хука

## 9. Контакты и поддержка

При возникновении вопросов или проблем с интеграцией обращайтесь к команде разработки дашборда миграций.
