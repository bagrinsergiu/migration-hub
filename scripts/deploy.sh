#!/bin/bash

# Скрипт для ручного деплоя на сервер
# Использование: ./scripts/deploy.sh [server_user@server_host]

set -e

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Параметры
SERVER="${1:-${DEPLOY_SERVER:-}}"
PROJECT_PATH="${DEPLOY_PROJECT_PATH:-/opt/mb-dashboard}"
DOCKER_IMAGE_NAME="mb-dashboard"
DOCKER_IMAGE_TAG="latest"
HTTP_PORT="${DEPLOY_PORT_HTTP:-8088}"

if [ -z "$SERVER" ]; then
    echo -e "${RED}Ошибка: Укажите сервер для деплоя${NC}"
    echo "Использование: $0 user@host"
    echo "Или установите переменную окружения DEPLOY_SERVER"
    exit 1
fi

echo -e "${GREEN}🚀 Начинаем деплой на $SERVER${NC}"

# Проверяем, что мы в корне проекта
if [ ! -f "Dockerfile" ]; then
    echo -e "${RED}Ошибка: Запустите скрипт из корня проекта${NC}"
    exit 1
fi

# Собираем Docker образ
echo -e "${YELLOW}📦 Собираем Docker образ...${NC}"
docker build --target production -t ${DOCKER_IMAGE_NAME}:${DOCKER_IMAGE_TAG} .

# Сохраняем образ в архив
echo -e "${YELLOW}💾 Сохраняем образ в архив...${NC}"
docker save ${DOCKER_IMAGE_NAME}:${DOCKER_IMAGE_TAG} | gzip > /tmp/mb-dashboard-image.tar.gz

# Копируем образ на сервер
echo -e "${YELLOW}📤 Копируем образ на сервер...${NC}"
scp /tmp/mb-dashboard-image.tar.gz ${SERVER}:/tmp/

# Деплоим на сервер
echo -e "${YELLOW}🔧 Деплоим на сервер...${NC}"
ssh ${SERVER} << EOF
set -e

# Загружаем образ
echo "Загружаем Docker образ..."
docker load < /tmp/mb-dashboard-image.tar.gz
rm /tmp/mb-dashboard-image.tar.gz

# Останавливаем старый контейнер
echo "Останавливаем старый контейнер..."
docker stop ${DOCKER_IMAGE_NAME} 2>/dev/null || true
docker rm ${DOCKER_IMAGE_NAME} 2>/dev/null || true

# Создаем директории если их нет
mkdir -p ${PROJECT_PATH}/var/log
mkdir -p ${PROJECT_PATH}/var/cache
mkdir -p ${PROJECT_PATH}/var/tmp

# Запускаем новый контейнер
echo "Запускаем новый контейнер..."
docker run -d \\
  --name ${DOCKER_IMAGE_NAME} \\
  --restart unless-stopped \\
  -p ${HTTP_PORT}:80 \\
  -v ${PROJECT_PATH}/.env:/project/.env:ro \\
  -v ${PROJECT_PATH}/var/log:/project/var/log \\
  -v ${PROJECT_PATH}/var/cache:/project/var/cache \\
  -v ${PROJECT_PATH}/var/tmp:/project/var/tmp \\
  ${DOCKER_IMAGE_NAME}:${DOCKER_IMAGE_TAG}

# Очищаем старые образы
echo "Очищаем старые образы..."
docker image prune -f

# Проверяем здоровье
echo "Проверяем здоровье приложения..."
sleep 5
if curl -f http://localhost:${HTTP_PORT}/api/health; then
    echo "✅ Приложение успешно запущено!"
else
    echo "❌ Ошибка: Приложение не отвечает"
    exit 1
fi
EOF

# Удаляем локальный архив
rm /tmp/mb-dashboard-image.tar.gz

echo -e "${GREEN}✅ Деплой завершен успешно!${NC}"
echo -e "${GREEN}🌐 Приложение доступно по адресу: http://${SERVER#*@}:${HTTP_PORT}${NC}"
