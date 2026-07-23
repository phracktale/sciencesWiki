#!/bin/sh
# Entrypoint du module « analyses » (FrankenPHP).
# RUN_INIT=1 : applique UNIQUEMENT les migrations analys_* sur la base SW partagée.
# Le module NE génère PAS de clés JWT : il valide avec la clé publique de SW (montée RO).
set -e

# Attente de la base partagée (sur Marvin, externe à ce compose) avant migrations/worker :
# évite l'échec au boot si Marvin redémarre (cf. entrypoint de l'API).
echo "[analyses] Attente de la base de données…"
db_tries=0
until php bin/console dbal:run-sql "SELECT 1" >/dev/null 2>&1; do
	db_tries=$((db_tries + 1))
	if [ "$db_tries" -ge 60 ]; then
		echo "[analyses] Base toujours injoignable après 60 tentatives (~3 min) — abandon."
		exit 1
	fi
	echo "[analyses] Base injoignable, nouvel essai dans 3 s… ($db_tries/60)"
	sleep 3
done
echo "[analyses] Base disponible."

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
