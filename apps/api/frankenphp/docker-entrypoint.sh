#!/bin/sh
# Entrypoint API SciencesWiki (FrankenPHP).
# - L'instance « api » (RUN_INIT=1) initialise : clés JWT, migrations, seed.
# - L'instance « worker » (RUN_INIT non défini) ne fait que consommer la file.
# La base est garantie disponible par `depends_on: condition: service_healthy`.
set -e

if [ "${RUN_INIT:-0}" = "1" ]; then
	echo "[entrypoint] Génération des clés JWT (si absentes)…"
	php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction

	echo "[entrypoint] Migrations de base de données…"
	php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

	echo "[entrypoint] Amorçage du registre de sources (idempotent)…"
	php bin/console harvester:seed-sources --no-interaction || true

	echo "[entrypoint] Initialisation terminée."
fi

exec docker-php-entrypoint "$@"
