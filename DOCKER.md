# Docker Setup для Dashboard

## Быстрый старт

### 1. Сборка и запуск

```bash
# Сборка образа
docker-compose build

# Запуск контейнера
docker-compose up -d

# Просмотр логов
docker-compose logs -f dashboard
```

### 2. Установка зависимостей

```bash
# Установка PHP зависимостей через Composer
docker-compose run --rm composer install

# Установка Node.js зависимостей (локально)
cd frontend
npm install
npm run build
cd ..
```

### 3. Настройка окружения

Создайте файл `.env` в корне проекта:

```env
APP_ENV=production
APP_DEBUG=0

# Database
MG_DB_HOST=your-db-host
MG_DB_NAME=migration_db
MG_DB_USER=root
MG_DB_PASS=your-password
MG_DB_PORT=3306

# Brizy API
BRIZY_TOKEN=your-token
BRIZY_CLOUD_HOST=https://cloud.brizy.io
```

### 4. Доступ к приложению

- Dashboard: http://localhost:8088
- API: http://localhost:8088/api/health

## Структура Docker

### Образы

- **production**: Production образ с PHP 8.3-FPM и Nginx
- **development**: Development образ с Xdebug

### Сервисы

- **dashboard**: Основной контейнер с приложением
- **composer**: Контейнер для установки PHP зависимостей
- **mysql**: Опциональный MySQL контейнер (закомментирован)

## Команды

### Разработка

```bash
# Запуск в режиме разработки
docker-compose up -d

# Пересборка после изменений
docker-compose build --no-cache dashboard
docker-compose up -d

# Просмотр логов
docker-compose logs -f dashboard

# Выполнение команд внутри контейнера
docker-compose exec dashboard bash

# Остановка
docker-compose down
```

### Production

```bash
# Сборка production образа
docker build --target production -t mb-dashboard:latest .

# Запуск production контейнера
docker run -d \
  --name mb-dashboard \
  -p 8088:80 \
  -v $(pwd)/.env:/project/.env:ro \
  -v $(pwd)/var/log:/project/var/log \
  -v $(pwd)/var/cache:/project/var/cache \
  mb-dashboard:latest
```

## Настройка Nginx

Конфигурация Nginx находится в `.docker/nginx/nginx.conf`.

Основные маршруты:
- `/api/*` → PHP-FPM (src/index.php)
- `/assets/*` → Статические файлы фронтенда
- `/review/*` → Публичный доступ к ревью
- `/` → Главная страница (index.php)

## Настройка PHP

Конфигурация PHP находится в `.docker/conf.d/php.ini`.

Основные параметры:
- `memory_limit = 512M`
- `max_execution_time = 300`
- `upload_max_filesize = 100M`

## Отладка

### Xdebug (только в development)

1. Убедитесь, что используете `target: development` в docker-compose.yaml
2. Настройте IDE для подключения к порту 9003
3. Xdebug автоматически подключится к `host.docker.internal:9003`

### Логи

```bash
# Логи приложения
docker-compose logs -f dashboard

# Логи PHP
docker-compose exec dashboard tail -f /var/log/php_errors.log

# Логи Nginx
docker-compose exec dashboard tail -f /var/log/nginx/error.log
```

## Troubleshooting

### Проблема: Контейнер не запускается

```bash
# Проверьте логи
docker-compose logs dashboard

# Проверьте конфигурацию
docker-compose config
```

### Проблема: 502 Bad Gateway

```bash
# Проверьте, запущен ли PHP-FPM
docker-compose exec dashboard ps aux | grep php-fpm

# Перезапустите контейнер
docker-compose restart dashboard
```

### Проблема: Фронтенд не загружается

```bash
# Убедитесь, что фронтенд собран
ls -la frontend/dist/

# Если нет, соберите локально
cd frontend && npm run build
```

### Проблема: Нет доступа к БД

```bash
# Проверьте настройки в .env
docker-compose exec dashboard cat .env | grep MG_DB

# Проверьте подключение из контейнера
docker-compose exec dashboard php -r "new PDO('mysql:host=your-host;dbname=your-db', 'user', 'pass');"
```

## Volumes

По умолчанию монтируются:
- `./:/project` - весь код проекта
- `./var/log:/project/var/log` - логи
- `./var/cache:/project/var/cache` - кэш
- `./var/tmp:/project/var/tmp` - временные файлы

## Порты

- `8088:80` - HTTP порт для доступа к dashboard

## Health Check

Контейнер проверяет здоровье через:
```bash
curl http://localhost/api/health
```

Интервал проверки: 30 секунд
Таймаут: 10 секунд
Попыток: 3
