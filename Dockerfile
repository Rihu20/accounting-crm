# syntax=docker/dockerfile:1

# Render builds this image and runs it with $PORT set. FrankenPHP serves public/
# directly, so there is no nginx or php-fpm to keep in step.
FROM dunglas/frankenphp:1-php8.4 AS php

RUN install-php-extensions pdo_pgsql pdo_mysql bcmath gd intl zip opcache

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Invoice PDFs and Word uploads are well over the 2M default.
RUN printf '%s\n' \
    'memory_limit=512M' \
    'upload_max_filesize=24M' \
    'post_max_size=32M' \
    'max_execution_time=120' \
    'opcache.enable=1' \
    'opcache.memory_consumption=128' \
    'opcache.validate_timestamps=0' \
    > /usr/local/etc/php/conf.d/zz-crm.ini

FROM php AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install --ignore-scripts
COPY vite.config.js ./
COPY resources resources
# app.css pulls @source out of vendor and storage/framework/views.
COPY --from=vendor /app/vendor vendor
RUN mkdir -p storage/framework/views && npm run build

FROM php AS app

WORKDIR /app
COPY --from=vendor /app/vendor vendor
COPY . .
COPY --from=assets /app/public/build public/build

# The artisan calls below boot the framework, which needs a key of exactly 32
# bytes. This throwaway one never leaves the build; Render supplies the real
# APP_KEY at runtime.
# The writable directories come first: .dockerignore keeps them out of the build
# context, and both commands below boot the framework, which wants to log.
RUN set -eu; \
    export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"; \
    mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views \
             storage/logs storage/app/private storage/app/public bootstrap/cache; \
    composer dump-autoload --no-dev --optimize --classmap-authoritative; \
    php artisan filament:assets; \
    chmod -R 775 storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/crm-entrypoint
RUN chmod +x /usr/local/bin/crm-entrypoint

CMD ["crm-entrypoint"]
