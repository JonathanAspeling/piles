# syntax=docker/dockerfile:1.7

# ---------- Stage 1: PHP vendor ----------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --no-interaction \
    --no-progress

# ---------- Stage 2: Frontend assets ----------
FROM node:22-alpine AS assets

WORKDIR /app

ARG VITE_APP_NAME="Piles"
ARG VITE_APP_URL
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT=443
ARG VITE_REVERB_SCHEME=https

ENV VITE_APP_NAME=${VITE_APP_NAME} \
    VITE_APP_URL=${VITE_APP_URL} \
    VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY} \
    VITE_REVERB_HOST=${VITE_REVERB_HOST} \
    VITE_REVERB_PORT=${VITE_REVERB_PORT} \
    VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME}

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY --from=vendor /app/vendor ./vendor
COPY . .

RUN npm run build

# ---------- Stage 3: Runtime ----------
FROM php:8.4-fpm-alpine AS runtime

# icu-libs, libzip and sqlite-libs are installed as first-class packages, not
# left to arrive as dependencies of the -dev packages: apk garbage-collects
# orphaned dependencies on `apk del`, which strips the shared libraries out from
# under intl.so/zip.so and leaves PHP warning at every startup.
RUN apk add --no-cache \
        nginx \
        supervisor \
        sqlite \
        sqlite-libs \
        bash \
        nano \
        tini \
        icu-libs \
        libzip \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        oniguruma-dev \
        libzip-dev \
        sqlite-dev \
        linux-headers \
    && docker-php-ext-install \
        pdo_sqlite \
        bcmath \
        pcntl \
        opcache \
        sockets \
        intl \
        zip \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build
COPY . .

RUN composer dump-autoload --optimize --no-dev --no-scripts \
    && mkdir -p /data /app/storage/framework/{cache,sessions,views,testing} /app/storage/logs /app/bootstrap/cache \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache /data \
    && chmod -R 775 /app/storage /app/bootstrap/cache /data

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Late on purpose: a version bump should not invalidate the composer, npm or
# extension-build layers above it.
ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION}

EXPOSE 80 8080

ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
