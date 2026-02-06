#!/bin/sh

set -e

echo "Starting MB Migration Dashboard..."

# Проверяем наличие .env файла
if [ ! -f .env ]; then
    echo "Warning: .env file not found. Creating from .env.example..."
    if [ -f .env.example ]; then
        cp .env.example .env
    fi
fi

# Создаем директории для логов если их нет
mkdir -p /project/var/log/nginx /project/var/log/php /project/var/log/syslog /project/var/cache /project/var/tmp /project/var/config /var/run/php

# Устанавливаем права на директории
chown -R www-data:www-data /project/var/log /project/var/cache /project/var/tmp /project/var/config || true
chmod -R 755 /project/var/log || true
# Устанавливаем права на файлы логов (на случай если они уже созданы)
chown -R www-data:www-data /project/var/log/* || true
chmod -R 644 /project/var/log/**/*.log 2>/dev/null || true

# Копируем конфигурацию rsyslog
if [ -f /project/.docker/rsyslog/rsyslog.conf ]; then
    cp /project/.docker/rsyslog/rsyslog.conf /etc/rsyslog.conf
fi

# Запускаем rsyslog в фоне
echo "Starting rsyslog..."
rsyslogd || true

# Запускаем Nginx в фоне
echo "Starting Nginx..."
nginx

# Запускаем PHP-FPM в foreground (это будет основной процесс)
echo "Starting PHP-FPM..."
exec php-fpm -F
