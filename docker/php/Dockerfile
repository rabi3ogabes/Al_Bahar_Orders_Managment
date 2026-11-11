# syntax=docker/dockerfile:1.7
# ── Al Bahar Home & Living — Laravel application image (API + admin + workers + Reverb) ──
FROM node:22-alpine AS assets
WORKDIR /app
COPY backend/package.json backend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY backend/ ./
RUN npm run build

# Customer storefront (React + TypeScript) → /store
FROM node:22-alpine AS storefront
WORKDIR /app
COPY storefront/package.json storefront/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY storefront/ ./
RUN npm run build

# Customer app for the web (Flutter) → /mobile-web. Same-origin API, no CDN, no push.
# Flutter is pinned to the version the app is developed and tested with (Dart 3.13.x).
FROM debian:bookworm-slim AS mobileweb
ARG FLUTTER_VERSION=3.47.9
RUN apt-get update && apt-get install -y --no-install-recommends git curl unzip xz-utils ca-certificates && rm -rf /var/lib/apt/lists/*
RUN git clone --depth 1 --branch ${FLUTTER_VERSION} https://github.com/flutter/flutter.git /opt/flutter
ENV PATH=/opt/flutter/bin:$PATH FLUTTER_SUPPRESS_ANALYTICS=true CI=true
RUN git config --global --add safe.directory /opt/flutter && flutter config --no-analytics --enable-web && flutter precache --web
WORKDIR /app
COPY mobile/pubspec.yaml mobile/pubspec.lock ./
RUN flutter pub get
COPY mobile/ ./
RUN flutter build web --release --no-web-resources-cdn --base-href /mobile-web/ \
    --dart-define=FLAVOR=prod --dart-define=API_BASE_URL=/api/v1 --dart-define=PUSH_ENABLED=false

FROM php:8.3-fpm-alpine AS base
RUN apk add --no-cache icu-libs libpq libzip libpng libjpeg-turbo libwebp freetype fcgi \
 && apk add --no-cache --virtual .build $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev linux-headers \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip gd pcntl opcache bcmath sockets \
 && pecl install redis && docker-php-ext-enable redis \
 && apk del .build
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

FROM base AS production
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
# .dockerignore excludes tests, tools, node_modules, .env and local storage from the build context.
COPY backend/ ./
COPY --from=assets /app/public/build ./public/build
COPY --from=storefront /app/dist ./public/store
COPY --from=mobileweb /app/build/web ./public/mobile-web
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
 && chown -R www-data:www-data storage bootstrap/cache
USER www-data
HEALTHCHECK --interval=30s --timeout=5s CMD php -r "exit(@fsockopen('127.0.0.1', 9000) ? 0 : 1);"
CMD ["php-fpm"]
