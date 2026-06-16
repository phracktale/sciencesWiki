# SciencesWiki — API (Symfony 8 + API Platform) servie par FrankenPHP.
# Image de production pour le homelab (nœud applicatif « Thor »).
# Cf. infra/README.md et docs/specifications.md §5.1.

FROM dunglas/frankenphp:1-php8.4 AS base

WORKDIR /app

# Dépendances système + extensions PHP requises par l'API.
#  - pdo_pgsql : PostgreSQL
#  - intl, zip : Symfony / API Platform
#  - opcache, apcu : performance prod
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
# FrankenPHP écoute en HTTP clair : le TLS est terminé par nginx sur Heimdall.
ENV SERVER_NAME=:80

COPY frankenphp/conf.d/app.prod.ini $PHP_INI_DIR/conf.d/app.ini
COPY frankenphp/Caddyfile /etc/caddy/Caddyfile
COPY --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

# ---------------------------------------------------------------------------
# Étape build : dépendances Composer (sans dev) + autoload optimisé.
# ---------------------------------------------------------------------------
FROM base AS prod

# 1) Dépendances en cache (couche invalidée seulement si composer.* change).
COPY composer.json composer.lock symfony.lock ./
RUN set -eux; \
    composer install --no-cache --prefer-dist --no-dev --no-scripts --no-autoloader --no-progress

# 2) Code applicatif.
COPY . ./

# 3) Finalisation : autoload optimisé, env compilée, scripts Symfony.
RUN set -eux; \
    rm -rf frankenphp/ tests/ ; \
    mkdir -p var/cache var/log config/jwt; \
    composer dump-autoload --classmap-authoritative --no-dev; \
    composer dump-env prod; \
    composer run-script --no-dev post-install-cmd; \
    chmod +x bin/console; sync
