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

### Дашборд (веб-хук и загрузка скриншотов)
- `DASHBOARD_BASE_URL` - Базовый URL дашборда (по умолчанию: `http://localhost:8088`)
  
  Используется для формирования URL, которые передаются серверу миграции при запуске миграций (в т.ч. волн):
  - **webhook результата миграции:** сервер по завершении отправляет результат на `{DASHBOARD_BASE_URL}/api/webhooks/migration-result`;
  - **загрузка скриншотов (Dashboard Screenshot Uploader):** сервер миграции отправляет скриншоты на `{DASHBOARD_BASE_URL}/api/webhooks/screenshots`.
  
  **Важно:** сервер миграции обращается к этому URL **со своей машины**. Если указан `http://localhost:8088`, то «localhost» для него — это сам сервер миграции, где порт 8088 не слушается, и вы получите ошибку вида `cURL error: Failed to connect to localhost port 8088: Connection refused`.
  
  При раздельном развёртывании дашборда и сервера миграции **обязательно** укажите URL, по которому **сервер миграции** может достучаться до дашборда (например, `http://dashboard-host:8088`, `https://dashboard.example.com` или из Docker: `http://host.docker.internal:8088`).

#### Как определить, какой IP/URL указать

Используется адрес **машины, где крутится дашборд**, такой, чтобы к нему мог достучаться **сервер миграции** (исходящие запросы идут с сервера миграции к дашборду).

| Где дашборд | Где сервер миграции | Что указать в `DASHBOARD_BASE_URL` |
|-------------|---------------------|------------------------------------|
| Та же машина (PHP или Docker с пробросом 8088) | Та же машина | `http://IP_ЭТОЙ_МАШИНЫ:8088` или `http://hostname:8088` |
| Docker на хосте (порт 8088) | На **хосте** (не в Docker) | `http://127.0.0.1:8088` или `http://IP_ХОСТА:8088` |
| Docker на хосте | В **другом** контейнере на том же хосте | См. раздел **«Оба в Docker»** ниже |
| Отдельный сервер (VPS/облако) | Другая машина | `http://IP_ИЛИ_ДОМЕН_ДАШБОРДА:8088` или `https://...` если есть прокси/SSL |

#### Оба в Docker (дашборд и сервер миграции)

Нужно задать URL, с которого **контейнер сервера миграции** достучится до дашборда.

**Вариант A: оба контейнера в одной Docker-сети** (один `docker-compose` или общая external-сеть)

- Внутри сети дашборд доступен по имени сервиса. Внутри контейнера дашборд слушает порт **80** (порт 8088 только на хосте).
- В `.env` дашборда (migration-hub) укажите:
  ```env
  DASHBOARD_BASE_URL=http://dashboard:80
  ```
  Если сервис дашборда в общем compose называется иначе (например `mb_dashboard`) — подставьте это имя: `http://имя_сервиса:80`.

**Вариант B: контейнеры в разных сетях, но на одном хосте**

- Контейнер сервера миграции должен обращаться к **хосту** на порт 8088 (проброс с дашборда).
- **Mac / Windows:** укажите в `.env` дашборда:
  ```env
  DASHBOARD_BASE_URL=http://host.docker.internal:8088
  ```
- **Linux:** в контейнере сервера миграции нет `host.docker.internal` по умолчанию. Либо:
  1. Узнайте IP хоста (на хосте: `hostname -I | awk '{print $1}'`) и укажите в `.env` дашборда:
     ```env
     DASHBOARD_BASE_URL=http://IP_ХОСТА:8088
     ```
  2. Либо в `docker-compose` **сервера миграции** добавьте контейнеру:
     ```yaml
     extra_hosts:
       - "host.docker.internal:host-gateway"
     ```
     тогда в `.env` дашборда можно указать:
     ```env
     DASHBOARD_BASE_URL=http://host.docker.internal:8088
     ```

**Узнать IP машины с дашбордом** (выполнить на этой машине):

```bash
# Linux: первый IPv4-адрес
hostname -I 2>/dev/null | awk '{print $1}'
# или
ip -4 route get 8.8.8.8 2>/dev/null | grep -oP 'src \K\S+'
```

**Проверка с сервера миграции:** с той машины, где крутится сервер миграции, выполните (подставьте свой IP/хост и порт):

```bash
curl -s -o /dev/null -w "%{http_code}" http://IP_ДАШБОРДА:8088/api/health
```

Ответ `200` значит, что дашборд достижим и в `.env` дашборда можно задать, например:

```env
DASHBOARD_BASE_URL=http://IP_ДАШБОРДА:8088
```

После изменения перезапустите контейнер/процесс дашборда.

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

# Дашборд (URL для веб-хука при раздельном развёртывании)
# DASHBOARD_BASE_URL=http://localhost:8088

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
