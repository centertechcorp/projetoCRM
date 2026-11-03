# syntax=docker/dockerfile:1
# Imagem de PRODUÇÃO do CRM (o docker-compose.yml da raiz, do Sail, continua sendo só de dev).
# Uma imagem só serve app (web), queue e scheduler — o que muda é o comando de cada um.
ARG PHP_VERSION=8.5

# 1) Front-end (Vite + Tailwind). public/build não vai pro Git, então é gerado aqui.
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# 2) Dependências PHP sem pacotes de dev.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# 3) Imagem final: FrankenPHP (servidor web + PHP + HTTPS automático via Caddy).
FROM dunglas/frankenphp:1-php${PHP_VERSION} AS app
RUN install-php-extensions pdo_pgsql pdo_mysql intl pcntl bcmath zip gd opcache

WORKDIR /app
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev --no-scripts \
    && php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

ENTRYPOINT ["app-entrypoint"]
CMD ["--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]
