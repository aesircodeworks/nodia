# syntax=docker/dockerfile:1
#
# FrankenPHP/Octane image for the API service (docs/decisions/009-octane-on-frankenphp.md).
# Built for local orchestration via infra/compose/docker-compose.yml (research.md R4);
# the same artifact is the eventual deployment image (docs/decisions/017-containers-first-defer-orchestrator.md).

FROM dunglas/frankenphp:1-php8.5 AS base

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

RUN install-php-extensions \
    bcmath \
    intl \
    pcntl \
    pdo_pgsql \
    redis \
    zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# ---------------------------------------------------------------------------
FROM base AS vendor

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-autoloader

# ---------------------------------------------------------------------------
FROM base AS runtime

ENV OCTANE_SERVER=frankenphp

COPY --from=vendor /app/vendor ./vendor
COPY . .

RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && cp vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php public/frankenphp-worker.php

COPY Caddyfile /etc/frankenphp/Caddyfile

EXPOSE 8000

HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=5 \
    CMD curl -f http://127.0.0.1:8000/v1/health || exit 1

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
