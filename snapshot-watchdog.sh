#!/bin/sh
# Watchdog de l'ingestion du snapshot OpenAlex (Thor, cron */10).
# L'ingestion tourne en process DÉTACHÉ dans le conteneur api : elle meurt à
# chaque recréation/restart du conteneur (déploiement, crash, OOM). Ce watchdog
# la relance automatiquement depuis le bon point (done_files-1, lu dans la clé
# setting qui survit à tout), tant qu'elle n'est pas terminée.
# Idempotent : ne relance PAS si un process d'ingestion tourne déjà (check /proc).
set -e
cd ~/scienceswiki/infra || exit 1
DC="docker compose --env-file .env.prod"
PROG="openalex.snapshot_progress"

# 1) Déjà en cours ? (process ingest-snapshot vivant dans le conteneur api)
RUNNING=$($DC exec -T api sh -c 'for p in /proc/[0-9]*; do tr "\0" " " < "$p/cmdline" 2>/dev/null | grep -q "ingest-snapshot" && { echo yes; break; }; done' 2>/dev/null || true)
[ "$RUNNING" = "yes" ] && exit 0

# 2) Lire la progression (JSON) depuis la base.
VAL=$($DC exec -T api php bin/console dbal:run-sql "SELECT value FROM setting WHERE name='$PROG'" 2>/dev/null || true)

# Terminé → ne rien faire.
echo "$VAL" | grep -q '"finished":true' && exit 0

# 3) Calculer le point de reprise (done_files-1 ; 0 si inconnu).
D=$(echo "$VAL" | grep -oE 'done_files":[0-9]+' | grep -oE '[0-9]+' | head -1)
[ -z "$D" ] && D=1
SKIP=$((D - 1)); [ "$SKIP" -lt 0 ] && SKIP=0

# 4) Relancer, détaché.
$DC exec -d api sh -c "php bin/console app:openalex:ingest-snapshot --dir=/openalexSnapshot --since=2015 --min-citations=5 --langs=en,fr --skip-files=$SKIP > /tmp/ingest-snapshot.log 2>&1"
echo "$(date '+%Y-%m-%d %H:%M:%S') watchdog: ingestion relancée (skip=$SKIP)" >> ~/scienceswiki/snapshot-watchdog.log
