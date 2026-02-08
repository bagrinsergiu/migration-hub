# Статус сохранения Google OAuth токена

## Что было сделано

После успешной авторизации через Google OAuth токен автоматически сохраняется в базе данных в таблице `google_sheets_tokens`.

## Проверка сохранения токена

### 1. Через API endpoint

```bash
GET /api/google-sheets/oauth/status
```

**Ответ:**
```json
{
  "success": true,
  "data": {
    "authenticated": true,
    "token_info": {
      "has_token": true,
      "created_at": "2024-01-15 10:30:00",
      "expires_at": "2024-01-15 11:30:00",
      "expires_in": 3600,
      "is_expired": false,
      "has_refresh_token": true
    }
  }
}
```

### 2. Через логи

Проверьте логи PHP на наличие записей о сохранении токена:

```bash
tail -f var/log/php/php-errors.log | grep -i "GoogleSheets\|oauth\|token"
```

**Ожидаемые записи:**
```
[GoogleSheetsController::oauthCallback] Получен код авторизации: ...
[GoogleSheetsService::authenticate] Токен успешно получен и сохранен
[GoogleSheetsService::saveToken] Токен успешно сохранен в БД
[GoogleSheetsController::oauthCallback] Авторизация успешна
[GoogleSheetsController::oauthCallback] Токен сохранен: да
[GoogleSheetsController::oauthCallback] Подтверждение: токен найден в БД, создан: ...
```

### 3. Через базу данных

```sql
SELECT id, created_at, expires_in, 
       DATE_ADD(created_at, INTERVAL expires_in SECOND) as expires_at,
       LENGTH(access_token) as access_token_length,
       LENGTH(refresh_token) as refresh_token_length
FROM google_sheets_tokens 
ORDER BY created_at DESC 
LIMIT 1;
```

## Структура ответа OAuth callback

После успешной авторизации вы получите ответ:

```json
{
  "success": true,
  "data": {
    "success": true,
    "message": "Авторизация успешна",
    "token": {
      "access_token": "ya29.a0AUMWg_...",
      "expires_in": 3599,
      "refresh_token": "1//03qHvhc5QLoBTCgYIARAAGAMSNwF-...",
      "scope": "https://www.googleapis.com/auth/spreadsheets.readonly ...",
      "token_type": "Bearer",
      "refresh_token_expires_in": 604799,
      "created": 1770306447
    },
    "token_saved": true,
    "expires_at": "2024-01-15 11:30:00",
    "token_verified_in_db": true,
    "token_created_at": "2024-01-15 10:30:00"
  },
  "message": "Авторизация успешна. Токен сохранен в базе данных."
}
```

## Что происходит при сохранении

1. **Получение токена** - Google возвращает access_token и refresh_token
2. **Сохранение в БД** - Токен сохраняется в таблицу `google_sheets_tokens`
3. **Проверка сохранения** - Система проверяет, что токен действительно сохранен
4. **Логирование** - Все операции логируются в `var/log/php/php-errors.log`

## Автоматическое обновление токена

Система автоматически обновляет access_token при его истечении, используя refresh_token:

- Токен проверяется при каждом использовании
- Если токен истек, автоматически запрашивается новый через refresh_token
- Новый токен сохраняется в БД

## Проверка работоспособности

После сохранения токена вы можете:

1. **Проверить статус:**
   ```bash
   curl http://localhost:8088/api/google-sheets/oauth/status
   ```

2. **Использовать API Google Sheets:**
   - Подключить таблицу: `POST /api/google-sheets/connect`
   - Получить список листов: `GET /api/google-sheets/sheets/{spreadsheetId}`

## Устранение проблем

### Токен не сохраняется

1. Проверьте логи: `tail -f var/log/php/php-errors.log | grep -i token`
2. Проверьте, что таблица `google_sheets_tokens` существует
3. Проверьте права доступа к БД

### Токен истек

Система автоматически обновит токен при следующем использовании. Если обновление не удалось:

1. Проверьте наличие refresh_token в БД
2. Проверьте логи обновления токена
3. При необходимости выполните повторную авторизацию

## Логирование

Все операции с токенами логируются:

- Получение токена
- Сохранение токена
- Обновление токена
- Ошибки при работе с токенами

**Файл логов:** `var/log/php/php-errors.log`

**Фильтр для поиска:**
```bash
grep -i "GoogleSheets.*token\|oauth.*token" var/log/php/php-errors.log
```
