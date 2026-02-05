# Тестирование интеграции веб-хуков

Этот документ описывает, как тестировать интеграцию веб-хуков между дашбордом и сервером миграции.

## Быстрый старт

### 1. Запуск mock-сервера миграции

Mock-сервер имитирует сервер миграции для тестирования:

```bash
# Запуск на порту 8080
php -S localhost:8080 src/scripts/test_mock_migration_server.php
```

Mock-сервер предоставляет следующие endpoints:
- `GET /health` - Проверка работоспособности
- `GET /?{params}` - Запуск миграции (с параметрами веб-хука)
- `GET /migration-status?mb_project_uuid={uuid}&brz_project_id={id}` - Опрос статуса

### 2. Запуск тестового скрипта

```bash
# Базовый тест интеграции
php src/scripts/test_webhook_integration.php
```

Этот скрипт проверяет:
- Передачу параметров веб-хука при запуске миграции
- Доступность endpoint для приема веб-хука
- Endpoint для опроса статуса миграции
- Сохранение результатов в БД

### 3. Полный цикл тестирования

```bash
# Убедитесь, что дашборд запущен на http://localhost:8088
# Убедитесь, что mock-сервер запущен на http://localhost:8080

./src/scripts/test_webhook_full_cycle.sh
```

Этот скрипт выполняет полный цикл:
1. Запуск миграции с параметрами веб-хука
2. Опрос статуса миграции
3. Проверка обработки веб-хука
4. Проверка сохранения результатов в БД

## Детальное тестирование

### Тест 1: Передача параметров веб-хука

Проверяет, что при запуске миграции передаются параметры веб-хука:

```bash
php src/scripts/test_webhook_integration.php
```

Ожидаемый результат:
- ✓ Параметры веб-хука должны быть добавлены в запрос
- ✓ webhook_url должен быть корректным
- ✓ webhook_mb_project_uuid и webhook_brz_project_id должны совпадать с параметрами миграции

### Тест 2: Прием веб-хука

Проверяет, что endpoint для приема веб-хука работает корректно:

```bash
# Вручную отправить веб-хук
curl -X POST http://localhost:8088/api/webhooks/migration-result \
  -H "Content-Type: application/json" \
  -d '{
    "mb_project_uuid": "test-uuid-123",
    "brz_project_id": 999,
    "status": "completed",
    "brizy_project_domain": "test.brizy.io"
  }'
```

Ожидаемый результат:
```json
{
  "success": true,
  "message": "Результат миграции успешно обработан",
  "data": {
    "mb_project_uuid": "test-uuid-123",
    "brz_project_id": 999,
    "status": "completed"
  }
}
```

### Тест 3: Опрос статуса миграции

Проверяет endpoint для опроса статуса:

```bash
# Через API дашборда
curl http://localhost:8088/api/migrations/999/status-from-server

# Напрямую к серверу миграции
curl "http://localhost:8080/migration-status?mb_project_uuid=test-uuid-123&brz_project_id=999"
```

Ожидаемый результат:
```json
{
  "status": "in_progress",
  "mb_project_uuid": "test-uuid-123",
  "brz_project_id": 999,
  "progress": {
    "total_pages": 10,
    "processed_pages": 5,
    "progress_percent": 50
  }
}
```

## Тестирование с реальным сервером миграции

Если у вас есть реальный сервер миграции, настройте его согласно документации в `MIGRATION_SERVER_WEBHOOK_API.md` и выполните тесты:

```bash
# Установите URL реального сервера миграции
export MIGRATION_API_URL=http://your-migration-server:8080

# Запустите тесты
php src/scripts/test_webhook_integration.php
```

## Проверка логов

Все операции логируются. Проверьте логи:

```bash
# Логи PHP
tail -f var/log/php/php-errors.log

# Логи mock-сервера (если запущен через встроенный сервер PHP)
# Логи выводятся в stderr
```

## Устранение неполадок

### Проблема: Веб-хук не вызывается

**Причины:**
1. Mock-сервер не запущен
2. Неправильный URL веб-хука
3. Ошибка при вызове веб-хука

**Решение:**
1. Проверьте, что mock-сервер запущен: `curl http://localhost:8080/health`
2. Проверьте логи mock-сервера
3. Проверьте, что дашборд доступен: `curl http://localhost:8088/api/health`

### Проблема: Endpoint опроса статуса возвращает 404

**Причины:**
1. Endpoint не реализован на сервере миграции
2. Неправильные параметры запроса

**Решение:**
1. Убедитесь, что сервер миграции реализует endpoint `/migration-status`
2. Проверьте параметры запроса (mb_project_uuid, brz_project_id)

### Проблема: Результаты не сохраняются в БД

**Причины:**
1. Ошибка в WebhookController
2. Проблемы с подключением к БД

**Решение:**
1. Проверьте логи PHP: `tail -f var/log/php/php-errors.log`
2. Проверьте, что БД доступна и таблицы созданы
3. Проверьте права доступа к БД

## Автоматизация тестирования

Для автоматизации тестирования можно использовать CI/CD:

```yaml
# Пример для GitHub Actions
- name: Test webhook integration
  run: |
    # Запуск mock-сервера в фоне
    php -S localhost:8080 src/scripts/test_mock_migration_server.php &
    
    # Запуск дашборда в фоне
    php -S localhost:8088 -t public &
    
    # Ожидание запуска сервисов
    sleep 5
    
    # Запуск тестов
    php src/scripts/test_webhook_integration.php
```

## Дополнительные тесты

### Тест производительности

Проверка, что система может обрабатывать множественные веб-хуки:

```bash
# Отправка 10 веб-хуков одновременно
for i in {1..10}; do
  curl -X POST http://localhost:8088/api/webhooks/migration-result \
    -H "Content-Type: application/json" \
    -d "{\"mb_project_uuid\": \"test-uuid-$i\", \"brz_project_id\": $i, \"status\": \"completed\"}" &
done
wait
```

### Тест обработки ошибок

Проверка обработки некорректных данных:

```bash
# Отправка веб-хука без обязательных полей
curl -X POST http://localhost:8088/api/webhooks/migration-result \
  -H "Content-Type: application/json" \
  -d '{"status": "completed"}'
```

Ожидаемый результат: HTTP 400 с описанием ошибки

## Заключение

Используйте эти тесты для проверки корректности интеграции веб-хуков перед развертыванием в production. При возникновении проблем проверьте логи и убедитесь, что все сервисы запущены и доступны.
