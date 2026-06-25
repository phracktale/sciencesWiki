#!/bin/sh
# Cron d'enrichissement « embed-drain » (Thor, */10 min).
# Dépile les publications à enrichir : embeddings (par lots /embed-batch),
# placement dans l'arbre (kNN), nettoyage des moissons bloquées, backfill journaux.
# Charge les services de Marvin (embeddings + base). Pour mettre en pause :
# commenter la ligne dans `crontab -e` (cf. docs/infra-marvin.md).
cd ~/scienceswiki/infra || exit 1
docker compose --env-file .env.prod exec -T api php -d memory_limit=1024M bin/console harvester:embed --limit=1000 >> ~/scienceswiki/embed-cron.log 2>&1
docker compose --env-file .env.prod exec -T api php -d memory_limit=1024M bin/console harvester:suggest-placement --limit=1000 >> ~/scienceswiki/embed-cron.log 2>&1
docker compose --env-file .env.prod exec -T api php bin/console app:harvest:reap-stale >> ~/scienceswiki/embed-cron.log 2>&1
docker compose --env-file .env.prod exec -T api php bin/console app:journals:backfill --limit=200 >> ~/scienceswiki/embed-cron.log 2>&1
