# Module « analyses » (Symfony 8.1 + FrankenPHP) — standalone, base SW partagée.
# Aligné sur l'image du cœur (apps/api/Dockerfile).

FROM dunglas/frankenphp:1-php8.4 AS base

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
        acl file gettext git \
    && rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    install-php-extensions \
        @composer \
        apcu \
        intl \
        opcache \
        pdo_pgsql \
        zip

ENV APP_ENV=prod
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV SERVER_NAME=:80

COPY frankenphp/conf.d/app.prod.ini $PHP_INI_DIR/conf.d/app.ini
COPY frankenphp/Caddyfile /etc/caddy/Caddyfile
COPY --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

# ---------------------------------------------------------------------------
FROM base AS prod

# Dépendances (nécessite composer.lock — générer via `composer install` en local).
COPY composer.json composer.lock symfony.lock* ./
RUN set -eux; \
    composer install --no-cache --prefer-dist --no-dev --no-scripts --no-autoloader --no-progress

COPY . ./

RUN set -eux; \
    rm -rf frankenphp/ tests/ ; \
    mkdir -p var/cache var/log config/jwt; \
    composer dump-autoload --classmap-authoritative --no-dev; \
    composer dump-env prod; \
    chmod +x bin/console; sync
