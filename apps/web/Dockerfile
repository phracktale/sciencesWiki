# SciencesWiki — Front public (Symfony/Twig SSR) servi par FrankenPHP.
# Consomme l'API server-side via API_BASE_URL (jamais d'accès direct à la base).
# Cf. infra/README.md et docs/specifications.md §5.

FROM dunglas/frankenphp:1-php8.4 AS base

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
        file gettext git curl unzip \
    && rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    install-php-extensions \
        @composer \
        apcu \
        intl \
        opcache \
        zip

ENV APP_ENV=prod
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV SERVER_NAME=:80

COPY frankenphp/conf.d/app.prod.ini $PHP_INI_DIR/conf.d/app.ini
COPY frankenphp/Caddyfile /etc/caddy/Caddyfile
COPY --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

FROM base AS prod

COPY composer.json composer.lock symfony.lock ./
RUN set -eux; \
    composer install --no-cache --prefer-dist --no-dev --no-scripts --no-autoloader --no-progress

COPY . ./

# Visualiseur PDF.js auto-hébergé (souverain, pas de CDN) : prébuilt épinglé,
# extrait dans public/pdfjs → /pdfjs/web/viewer.html. Non-fatal si le réseau
# manque au build (la fiche article reste accessible, repli sur ouverture native).
ARG PDFJS_VERSION=4.7.76
RUN set -eux; \
    mkdir -p public/pdfjs; \
    if curl -fsSL -o /tmp/pdfjs.zip "https://github.com/mozilla/pdf.js/releases/download/v${PDFJS_VERSION}/pdfjs-${PDFJS_VERSION}-legacy-dist.zip"; then \
        unzip -q /tmp/pdfjs.zip -d public/pdfjs && rm -f /tmp/pdfjs.zip && echo "PDF.js ${PDFJS_VERSION} vendoré"; \
    else \
        echo "AVERTISSEMENT : téléchargement PDF.js échoué — repli visualiseur natif"; \
    fi

# Polices auto-hébergées (souveraines) : Roboto Mono (base/structure) + Monofur
# Nerd Font (contenu/secondaire) sont versionnées dans public/fonts/ (copiées via
# COPY . ./), donc servies directement par FrankenPHP. Aucune dépendance réseau.

RUN set -eux; \
    rm -rf frankenphp/ ; \
    mkdir -p var/cache var/log var/sessions public/uploads/domains; \
    composer dump-autoload --classmap-authoritative --no-dev; \
    composer dump-env prod; \
    composer run-script --no-dev post-install-cmd; \
    chmod +x bin/console; sync
