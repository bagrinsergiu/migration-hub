#!/bin/bash
# Полный цикл тестирования веб-хуков
# 
# Использование:
# ./src/scripts/test_webhook_full_cycle.sh

set -e

echo "=== Полный цикл тестирования веб-хуков ==="
echo ""

# Цвета для вывода
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Проверяем, что дашборд запущен
DASHBOARD_URL="${DASHBOARD_BASE_URL:-http://localhost:8088}"
echo "Проверка доступности дашборда на ${DASHBOARD_URL}..."
if curl -s -f "${DASHBOARD_URL}/api/health" > /dev/null 2>&1; then
    echo -e "${GREEN}✓ Дашборд доступен${NC}"
else
    echo -e "${RED}✗ Дашборд недоступен на ${DASHBOARD_URL}${NC}"
    echo "  Убедитесь, что дашборд запущен"
    exit 1
fi

# Проверяем, что mock-сервер миграции запущен
MIGRATION_SERVER_URL="${MIGRATION_API_URL:-http://localhost:8080}"
echo "Проверка доступности mock-сервера миграции на ${MIGRATION_SERVER_URL}..."
if curl -s -f "${MIGRATION_SERVER_URL}/health" > /dev/null 2>&1; then
    echo -e "${GREEN}✓ Mock-сервер миграции доступен${NC}"
else
    echo -e "${YELLOW}⚠ Mock-сервер миграции недоступен${NC}"
    echo "  Запустите mock-сервер:"
    echo "  php -S localhost:8080 src/scripts/test_mock_migration_server.php"
    echo ""
    read -p "Продолжить без mock-сервера? (y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

echo ""
echo "=== Тест 1: Запуск миграции с параметрами веб-хука ==="
echo ""

# Тестовые данные
MB_PROJECT_UUID="test-uuid-$(date +%s)"
BRZ_PROJECT_ID=$((RANDOM % 10000 + 1000))
WEBHOOK_URL="${DASHBOARD_URL}/api/webhooks/migration-result"

echo "Параметры тестовой миграции:"
echo "  mb_project_uuid: ${MB_PROJECT_UUID}"
echo "  brz_project_id: ${BRZ_PROJECT_ID}"
echo "  webhook_url: ${WEBHOOK_URL}"
echo ""

# Запускаем миграцию
MIGRATION_URL="${MIGRATION_SERVER_URL}/?mb_project_uuid=${MB_PROJECT_UUID}&brz_project_id=${BRZ_PROJECT_ID}&mb_site_id=1&mb_secret=test-secret&webhook_url=${WEBHOOK_URL}&webhook_mb_project_uuid=${MB_PROJECT_UUID}&webhook_brz_project_id=${BRZ_PROJECT_ID}"

echo "Отправка запроса на запуск миграции..."
RESPONSE=$(curl -s -w "\n%{http_code}" "${MIGRATION_URL}")
HTTP_CODE=$(echo "${RESPONSE}" | tail -n1)
BODY=$(echo "${RESPONSE}" | head -n-1)

if [ "${HTTP_CODE}" -ge 200 ] && [ "${HTTP_CODE}" -lt 300 ]; then
    echo -e "${GREEN}✓ Миграция запущена (HTTP ${HTTP_CODE})${NC}"
    echo "  Ответ: ${BODY}"
else
    echo -e "${RED}✗ Ошибка запуска миграции (HTTP ${HTTP_CODE})${NC}"
    echo "  Ответ: ${BODY}"
    exit 1
fi

echo ""
echo "=== Тест 2: Опрос статуса миграции ==="
echo ""

# Ждем немного
sleep 2

STATUS_URL="${MIGRATION_SERVER_URL}/migration-status?mb_project_uuid=${MB_PROJECT_UUID}&brz_project_id=${BRZ_PROJECT_ID}"
echo "Запрос статуса миграции..."
STATUS_RESPONSE=$(curl -s -w "\n%{http_code}" "${STATUS_URL}")
STATUS_HTTP_CODE=$(echo "${STATUS_RESPONSE}" | tail -n1)
STATUS_BODY=$(echo "${STATUS_RESPONSE}" | head -n-1)

if [ "${STATUS_HTTP_CODE}" -eq 200 ]; then
    echo -e "${GREEN}✓ Статус получен (HTTP ${STATUS_HTTP_CODE})${NC}"
    echo "  Ответ: ${STATUS_BODY}"
elif [ "${STATUS_HTTP_CODE}" -eq 404 ]; then
    echo -e "${YELLOW}⚠ Миграция не найдена (это нормально, если mock-сервер не запущен)${NC}"
else
    echo -e "${RED}✗ Ошибка получения статуса (HTTP ${STATUS_HTTP_CODE})${NC}"
    echo "  Ответ: ${STATUS_BODY}"
fi

echo ""
echo "=== Тест 3: Проверка веб-хука ==="
echo ""

# Ждем завершения миграции (mock-сервер завершает через 3 секунды)
echo "Ожидание завершения миграции (5 секунд)..."
sleep 5

# Проверяем, что веб-хук был вызван (проверяем БД через API)
echo "Проверка результата миграции в дашборде..."
DETAILS_URL="${DASHBOARD_URL}/api/migrations/${BRZ_PROJECT_ID}"
DETAILS_RESPONSE=$(curl -s -w "\n%{http_code}" "${DETAILS_URL}")
DETAILS_HTTP_CODE=$(echo "${DETAILS_RESPONSE}" | tail -n1)
DETAILS_BODY=$(echo "${DETAILS_RESPONSE}" | head -n-1)

if [ "${DETAILS_HTTP_CODE}" -eq 200 ]; then
    echo -e "${GREEN}✓ Данные миграции получены${NC}"
    # Проверяем статус в ответе
    STATUS=$(echo "${DETAILS_BODY}" | grep -o '"status":"[^"]*"' | head -1 | cut -d'"' -f4)
    if [ -n "${STATUS}" ]; then
        echo "  Статус миграции: ${STATUS}"
        if [ "${STATUS}" = "completed" ] || [ "${STATUS}" = "in_progress" ]; then
            echo -e "${GREEN}✓ Статус корректный${NC}"
        else
            echo -e "${YELLOW}⚠ Неожиданный статус: ${STATUS}${NC}"
        fi
    fi
else
    echo -e "${YELLOW}⚠ Не удалось получить данные миграции (HTTP ${DETAILS_HTTP_CODE})${NC}"
    echo "  Это нормально, если миграция еще не была обработана"
fi

echo ""
echo "=== Тестирование завершено ==="
echo ""
echo "Для просмотра деталей миграции откройте:"
echo "  ${DASHBOARD_URL}/migrations/${BRZ_PROJECT_ID}"
echo ""
