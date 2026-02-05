# MB Migration Dashboard - Standalone

Отдельный проект Dashboard для системы миграции MB, работающий на PHP 8.3.

## Требования

### Для Docker:
- Docker 20.10+
- Docker Compose 2.0+

### Для локальной установки:
- PHP 8.3 или выше
- Composer
- MySQL 5.7+ или 8.0+
- Node.js 18+ (для фронтенда)

## Установка

### С Docker (Рекомендуется)

1. Скопируйте проект:
```bash
cd dashboard-standalone
```

2. Настройте переменные окружения:
```bash
# Создайте .env файл с вашими настройками
# Минимально необходимые переменные:
# MG_DB_HOST, MG_DB_NAME, MG_DB_USER, MG_DB_PASS
# MIGRATION_API_URL (по умолчанию: http://localhost:8080)
```

Подробнее о переменных окружения см. [ENV_VARIABLES.md](doc/ENV_VARIABLES.md)

3. Запустите Docker:
```bash
docker-compose up -d
docker-compose run --rm composer install
```

4. Соберите фронтенд (локально):
```bash
cd frontend
npm install
npm run build
cd ..
```

### Без Docker

1. Скопируйте проект:
```bash
cd dashboard-standalone
```

2. Установите зависимости:
```bash
composer install
```

3. Настройте переменные окружения:
```bash
cp .env.example .env
# Отредактируйте .env файл с вашими настройками БД
```

4. Соберите фронтенд:
```bash
cd frontend
npm install
npm run build
cd ..
```

## Конфигурация

Создайте файл `.env` в корне проекта:

```env
# Database Configuration
MG_DB_HOST=your-db-host
MG_DB_NAME=your-db-name
MG_DB_USER=your-db-user
MG_DB_PASS=your-db-password
MG_DB_PORT=3306

# Migration Server URL (по умолчанию: http://localhost:8080)
MIGRATION_API_URL=http://localhost:8080

# Application
APP_ENV=production
APP_DEBUG=false
```

Подробнее о переменных окружения см. [ENV_VARIABLES.md](doc/ENV_VARIABLES.md)

## Структура проекта

```
dashboard-standalone/
├── src/                    # PHP исходный код
│   ├── Controllers/        # Контроллеры API
│   ├── Services/           # Бизнес-логика
│   └── Middleware/         # Middleware
├── lib/                    # Зависимости от MBMigration (адаптеры)
│   └── MBMigration/
├── frontend/               # React приложение
├── public/                 # Публичные файлы
├── var/                    # Временные файлы, логи, кэш
└── composer.json
```

## Запуск

### Docker (Рекомендуется)

```bash
# Сборка и запуск
docker-compose up -d

# Установка зависимостей
docker-compose run --rm composer install

# Сборка фронтенда (локально)
cd frontend && npm install && npm run build && cd ..

# Просмотр логов
docker-compose logs -f dashboard
```

Dashboard будет доступен по адресу: http://localhost:8088

Подробнее см. [DOCKER.md](DOCKER.md)

### Development (без Docker)

```bash
php -S localhost:8088 -t public
```

### Production (без Docker)

Настройте веб-сервер (Nginx/Apache) для работы с `public/index.php`

## API Endpoints

Базовый URL: `http://localhost:8088/api`

- `GET /health` - Проверка работоспособности
- `GET /migrations` - Список миграций
- `GET /migrations/:id` - Детали миграции
- `POST /migrations/run` - Запуск миграции
- И другие...

Подробнее см. [API.md](API.md)

## Миграция из основного проекта

Этот проект был выделен из основного проекта MB-migration для:
- Независимого развития
- Использования современного PHP 8.3
- Упрощения развертывания
- Изоляции зависимостей

## CI/CD и автоматический деплой

Проект настроен для автоматического деплоя при пуше в ветку `main` через GitHub Actions.

### Быстрая настройка

1. **Настройте GitHub Secrets:**
   - `DEPLOY_HOST` - IP или домен сервера
   - `DEPLOY_USER` - пользователь SSH
   - `DEPLOY_SSH_KEY` - приватный SSH ключ

2. **Подготовьте сервер:**
   - Установите Docker
   - Создайте директорию `/opt/mb-dashboard`
   - Создайте файл `.env` на сервере

3. **Готово!** При каждом пуше в `main` произойдет автоматический деплой.

Подробная инструкция: [CI_CD_SETUP.md](doc/CI_CD_SETUP.md)

### Ручной деплой

```bash
./scripts/deploy.sh user@server
```

## Лицензия

Proprietary
