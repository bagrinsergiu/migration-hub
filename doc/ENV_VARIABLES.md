# Переменные окружения

## Обязательные переменные

### База данных
- `MG_DB_HOST` - Хост базы данных (по умолчанию: `localhost`)
- `MG_DB_NAME` - Имя базы данных
- `MG_DB_USER` - Пользователь базы данных
- `MG_DB_PASS` - Пароль базы данных
- `MG_DB_PORT` - Порт базы данных (по умолчанию: `3306`)

## Опциональные переменные

### Сервер миграции
- `MIGRATION_API_URL` - URL сервера миграции (по умолчанию: `http://localhost:8080`)
  
  Используется для запуска и перезапуска миграций через HTTP запросы.
  
  Примеры:
  - Локально: `http://localhost:8080`
  - В Docker: `http://127.0.0.1:80` (если миграция запущена в том же Docker network)
  - Удаленный сервер: `http://migration-server.example.com:8080`

### Google Sheets API (опционально)
- `GOOGLE_CLIENT_ID` - OAuth 2.0 Client ID для Google Sheets API
- `GOOGLE_CLIENT_SECRET` - OAuth 2.0 Client Secret для Google Sheets API
- `GOOGLE_REDIRECT_URI` - Redirect URI для OAuth callback (по умолчанию: `http://localhost:8088/api/google-sheets/oauth/callback`)
- `GOOGLE_SYNC_INTERVAL` - Интервал синхронизации в секундах (по умолчанию: `300` = 5 минут)

  Подробная инструкция по настройке: см. [GOOGLE_SHEETS_SETUP.md](./GOOGLE_SHEETS_SETUP.md)

### Другие переменные
- `BASE_URL` - Базовый URL (используется как fallback для `MIGRATION_API_URL`, устаревшее)
- `CACHE_PATH` - Путь к директории кэша (по умолчанию: `var/cache`)

## Пример .env файла

```env
# База данных
MG_DB_HOST=localhost
MG_DB_NAME=migration_db
MG_DB_USER=root
MG_DB_PASS=password
MG_DB_PORT=3306

# Сервер миграции
MIGRATION_API_URL=http://localhost:8080

# Кэш
CACHE_PATH=var/cache

# Google Sheets API (опционально)
GOOGLE_CLIENT_ID=your_client_id_here.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your_client_secret_here
GOOGLE_REDIRECT_URI=http://localhost:8088/api/google-sheets/oauth/callback
GOOGLE_SYNC_INTERVAL=300
```

## Примечания

- Переменные окружения загружаются из файла `.env` в корне проекта
- Если переменная `MIGRATION_API_URL` не указана, используется значение по умолчанию `http://localhost:8080`
- Внутри Docker контейнера автоматически определяется использование порта 80, если не указано иное
