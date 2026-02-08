# Настройка CI/CD для автоматического деплоя

Этот документ описывает настройку автоматического деплоя при пуше в ветку `main`.

## Обзор

Система CI/CD использует GitHub Actions для автоматической сборки Docker образа и деплоя на сервер при каждом пуше в ветку `main`.

## Требования

1. **GitHub репозиторий** с настроенными секретами
2. **Сервер для деплоя** с установленным Docker
3. **SSH доступ** к серверу для деплоя

## Настройка GitHub Secrets

Перейдите в настройки репозитория GitHub: `Settings` → `Secrets and variables` → `Actions`

Добавьте следующие секреты:

### Обязательные секреты:

- `DEPLOY_HOST` - IP адрес или доменное имя сервера (например: `192.168.1.100` или `deploy.example.com`)
- `DEPLOY_USER` - Имя пользователя для SSH подключения (например: `deploy` или `root`)
- `DEPLOY_SSH_KEY` - Приватный SSH ключ для подключения к серверу

### Опциональные секреты:

- `DEPLOY_PORT` - SSH порт (по умолчанию: `22`)
- `DEPLOY_PORT_HTTP` - HTTP порт для приложения (по умолчанию: `8088`)
- `DEPLOY_PROJECT_PATH` - Путь к проекту на сервере (по умолчанию: `/opt/mb-dashboard`)
- `DOCKER_HUB_USERNAME` - Имя пользователя Docker Hub (если нужно пушить образы)
- `DOCKER_HUB_PASSWORD` - Пароль Docker Hub (если нужно пушить образы)

## Генерация SSH ключа

Если у вас еще нет SSH ключа для деплоя:

```bash
# Генерируем новый SSH ключ
ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/.ssh/github_deploy

# Копируем публичный ключ на сервер
ssh-copy-id -i ~/.ssh/github_deploy.pub user@your-server

# Показываем приватный ключ для добавления в GitHub Secrets
cat ~/.ssh/github_deploy
```

**Важно:** Никогда не коммитьте приватный SSH ключ в репозиторий!

## Подготовка сервера

### 1. Установка Docker

```bash
# Обновляем систему
sudo apt update && sudo apt upgrade -y

# Устанавливаем Docker
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh

# Добавляем пользователя в группу docker
sudo usermod -aG docker $USER

# Проверяем установку
docker --version
```

### 2. Создание директорий на сервере

```bash
# Создаем директорию проекта
sudo mkdir -p /opt/mb-dashboard
sudo chown $USER:$USER /opt/mb-dashboard

# Создаем директории для логов и кэша
mkdir -p /opt/mb-dashboard/var/{log,cache,tmp}
```

### 3. Настройка .env файла

Создайте файл `.env` на сервере:

```bash
# На сервере
cd /opt/mb-dashboard
nano .env
```

Пример содержимого `.env`:

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

**Важно:** Файл `.env` должен быть создан на сервере до первого деплоя!

## Как это работает

1. **Push в main** → GitHub Actions автоматически запускает workflow
2. **Сборка образа** → Собирается Docker образ с production target
3. **Сохранение образа** → Образ сохраняется в архив
4. **Копирование на сервер** → Образ копируется на сервер через SCP
5. **Деплой** → На сервере:
   - Загружается новый образ
   - Останавливается старый контейнер
   - Запускается новый контейнер
   - Проверяется здоровье приложения

## Ручной запуск деплоя

Вы можете запустить деплой вручную через GitHub Actions:

1. Перейдите в `Actions` → `Deploy to Production`
2. Нажмите `Run workflow`
3. Выберите ветку и нажмите `Run workflow`

## Ручной деплой через скрипт

Также можно использовать скрипт для ручного деплоя:

```bash
# Установите права на выполнение
chmod +x scripts/deploy.sh

# Запустите деплой
./scripts/deploy.sh user@server

# Или через переменную окружения
export DEPLOY_SERVER="user@server"
./scripts/deploy.sh
```

## Мониторинг деплоя

### Просмотр логов GitHub Actions

1. Перейдите в `Actions` в вашем репозитории
2. Выберите последний запуск workflow
3. Просмотрите логи каждого шага

### Просмотр логов на сервере

```bash
# Логи контейнера
docker logs -f mb-dashboard

# Логи приложения
tail -f /opt/mb-dashboard/var/log/app.log

# Логи Nginx
docker exec mb-dashboard tail -f /var/log/nginx/error.log
```

## Откат к предыдущей версии

Если что-то пошло не так, можно откатиться:

```bash
# На сервере
ssh user@server

# Останавливаем текущий контейнер
docker stop mb-dashboard
docker rm mb-dashboard

# Запускаем предыдущий образ (если он еще есть)
docker images | grep mb-dashboard
docker run -d \
  --name mb-dashboard \
  --restart unless-stopped \
  -p 8088:80 \
  -v /opt/mb-dashboard/.env:/project/.env:ro \
  -v /opt/mb-dashboard/var/log:/project/var/log \
  -v /opt/mb-dashboard/var/cache:/project/var/cache \
  -v /opt/mb-dashboard/var/tmp:/project/var/tmp \
  mb-dashboard:PREVIOUS_TAG
```

## Troubleshooting

### Проблема: GitHub Actions не может подключиться к серверу

**Решение:**
- Проверьте, что SSH ключ правильно добавлен в секреты
- Убедитесь, что сервер доступен из интернета
- Проверьте firewall на сервере

### Проблема: Деплой завершается с ошибкой

**Решение:**
- Проверьте логи GitHub Actions
- Убедитесь, что на сервере достаточно места
- Проверьте, что Docker запущен на сервере
- Убедитесь, что файл `.env` существует на сервере

### Проблема: Приложение не запускается после деплоя

**Решение:**
```bash
# Проверьте логи контейнера
docker logs mb-dashboard

# Проверьте, что контейнер запущен
docker ps | grep mb-dashboard

# Проверьте здоровье приложения
curl http://localhost:8088/api/health
```

### Проблема: Старые образы занимают много места

**Решение:**
```bash
# На сервере
docker system prune -a --volumes
```

## Безопасность

1. **SSH ключи:** Используйте отдельный SSH ключ только для CI/CD
2. **Секреты:** Никогда не коммитьте секреты в репозиторий
3. **Firewall:** Ограничьте доступ к серверу только с необходимых IP
4. **Обновления:** Регулярно обновляйте Docker и систему на сервере

## Дополнительные настройки

### Использование Docker Registry

Если вы хотите использовать Docker Registry вместо прямого копирования образа:

1. Раскомментируйте шаг "Log in to Docker Hub"
2. Добавьте `push: true` в шаг "Build Docker image"
3. Измените шаг деплоя для использования `docker pull` вместо `docker load`

### Использование Docker Compose на сервере

Можно использовать Docker Compose для более сложных конфигураций:

```yaml
# docker-compose.production.yml на сервере
version: '3.8'
services:
  dashboard:
    image: mb-dashboard:latest
    restart: unless-stopped
    ports:
      - "8088:80"
    volumes:
      - ./.env:/project/.env:ro
      - ./var/log:/project/var/log
      - ./var/cache:/project/var/cache
      - ./var/tmp:/project/var/tmp
```

## Поддержка

При возникновении проблем:
1. Проверьте логи GitHub Actions
2. Проверьте логи на сервере
3. Убедитесь, что все секреты настроены правильно
