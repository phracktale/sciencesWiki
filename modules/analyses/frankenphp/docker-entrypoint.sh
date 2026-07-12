#!/bin/sh
# Entrypoint du module « analyses » (FrankenPHP).
# RUN_INIT=1 : applique UNIQUEMENT les migrations analys_* sur la base SW partagée.
# Le module NE génère PAS de clés JWT : il valide avec la clé publique de SW (montée RO).
set -e

if [ "${RUN_INIT:-0}" = "1" ]; then
	echo "[analyses] Migrations (tables analys_*)…"
	php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
	echo "[analyses] Initialisation terminée."
fi

# Mode worker : consomme la file d'analyses (traitement LLM asynchrone).
if [ "${RUN_WORKER:-0}" = "1" ]; then
	echo "[analyses] Worker : consommation de la file analys_analysis…"
	exec php bin/console messenger:consume analys_analysis --time-limit=3600 --memory-limit=256M -v
fi

exec docker-php-entrypoint "$@"
