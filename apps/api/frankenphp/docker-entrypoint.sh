#!/bin/sh
# Entrypoint API SciencesWiki (FrankenPHP).
# - L'instance « api » (RUN_INIT=1) initialise : clés JWT, migrations, seed.
# - L'instance « worker » (RUN_INIT non défini) ne fait que consommer la file.
set -e

# Attente de la base de données avant tout (init OU worker). La base vit désormais sur
# Marvin (externe à ce compose), donc l'ancien `depends_on: service_healthy` ne s'applique
# plus : sans cette attente, un simple redémarrage de Marvin ferait échouer les migrations
# (set -e) → conteneur en boucle de crash. On patiente jusqu'à ~3 min que la DB réponde.
echo "[entrypoint] Attente de la base de données…"
db_tries=0
until php bin/console dbal:run-sql "SELECT 1" >/dev/null 2>&1; do
	db_tries=$((db_tries + 1))
	if [ "$db_tries" -ge 60 ]; then
		echo "[entrypoint] Base toujours injoignable après 60 tentatives (~3 min) — abandon."
		exit 1
	fi
	echo "[entrypoint] Base injoignable, nouvel essai dans 3 s… ($db_tries/60)"
	sleep 3
done
echo "[entrypoint] Base disponible."

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
