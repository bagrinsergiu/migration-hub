FROM composer:2.7.1 AS stage_composer
ARG COMPOSER_AUTH
ENV COMPOSER_AUTH ${COMPOSER_AUTH}

WORKDIR /vendor
COPY ./composer.json ./
COPY ./composer.lock* ./
# Нужны src/ и lib/ для генерации autoload (classmap и PSR-4)
COPY ./src ./src
COPY ./lib ./lib

RUN composer install --ignore-platform-reqs --prefer-dist --no-interaction --no-progress --optimize-autoloader --no-scripts --no-dev

FROM node:18-alpine AS stage_node
WORKDIR /build
COPY ./frontend/package.json ./frontend/package-lock.json* ./frontend/
WORKDIR /build/frontend
RUN npm ci

COPY ./frontend/ ./
RUN npm run build

FROM php:8.3-fpm AS production
WORKDIR /project
ARG UID=1000
ARG PHP_FPM_INI_DIR="/usr/local/etc/php"

# Копируем конфигурацию PHP
COPY .docker/conf.d/php.ini $PHP_FPM_INI_DIR/conf.d/php.ini

# Копируем конфигурацию PHP-FPM
COPY .docker/php-fpm/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

# Установка системных зависимостей и PHP расширений
RUN apt-get update && \
    apt-get install --no-install-recommends -y \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        libxml2-dev \
        libcurl4-openssl-dev \
        libicu-dev \
        nginx \
        rsyslog \
        zip \
        unzip \
        curl \
        vim \
        && \
    docker-php-ext-configure gd --with-jpeg --with-freetype && \
    docker-php-ext-install \
        gd \
        zip \
        xml \
        intl \
        mysqli \
        pdo \
        pdo_mysql \
        sockets \
        && \
    mkdir -p /project/var/log/nginx /project/var/log/php /project/var/log/syslog /project/var/cache /project/var/tmp /project/var/config /project/var/screenshots /var/run/php && \
    chown -R www-data:www-data /project/var/log /project/var/cache /project/var/tmp /project/var/config /project/var/screenshots /var/run/php && \
    chmod -R 755 /project/var/log /var/run/php && \
    rm -rf /var/lib/apt/lists/*

# Копируем vendor из stage_composer
COPY --from=stage_composer /vendor ./vendor

# Копируем собранный фронтенд из stage_node
COPY --from=stage_node /build/frontend/dist ./frontend/dist

# Копируем конфигурацию Nginx
COPY .docker/nginx/nginx.conf /etc/nginx/sites-enabled/default

# Копируем конфигурацию rsyslog
COPY .docker/rsyslog/rsyslog.conf /etc/rsyslog.conf

# Копируем entrypoint
COPY .docker/entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

# Копируем код проекта (--chown сразу, без медленного chown -R по всему дереву)
COPY --chown=www-data:www-data . .

# Только права на var (логи, кэш, скриншоты) — мало файлов, быстро
RUN chmod -R 755 /project/var

# Download tini для корректной обработки сигналов
ARG TINI_VERSION='v0.19.0'
ADD https://github.com/krallin/tini/releases/download/${TINI_VERSION}/tini /usr/local/bin/tini
RUN chmod +x /usr/local/bin/tini

ENTRYPOINT ["tini", "docker-entrypoint", "--"]
CMD []

FROM production AS development
COPY --from=stage_composer /usr/bin/composer /usr/bin/composer

ARG PHP_FPM_INI_DIR="/usr/local/etc/php"

# Xdebug через install-php-extensions (обычно быстрее pecl — пребилды при наличии)
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions xdebug

COPY .docker/conf.d/xdebug.ini "${PHP_FPM_INI_DIR}/conf.d/xdebug.ini"

# В dev при volume ./:/project права задаёт хост, chown в образе не нужен
