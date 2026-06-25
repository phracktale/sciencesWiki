#!/bin/sh
# Cron d'enrichissement « fulltext-drain » (Thor, */15 min).
# Enfile les publications dont le texte intégral doit être récupéré (GROBID) :
# les messages sont consommés par les conteneurs `fulltext-worker`. Charge GROBID
# + embeddings (chunks) sur Marvin. Pause : commenter la ligne dans `crontab -e`.
cd ~/scienceswiki/infra || exit 1
docker compose --env-file .env.prod exec -T api php bin/console app:fulltext:enqueue --limit=2000 --max-queue=5000 >> ~/scienceswiki/fulltext-cron.log 2>&1
