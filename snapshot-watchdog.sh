#!/bin/sh
# Watchdog de l'ingestion du snapshot OpenAlex (Thor, cron */10).
# L'ingestion tourne en process DÉTACHÉ dans le conteneur api : elle meurt à
# chaque recréation/restart du conteneur (déploiement, crash, OOM). Ce watchdog
# la relance automatiquement depuis le bon point, tant qu'elle n'est pas terminée.
#
# LIVENESS = FRAÎCHEUR DU SETTING (vérité terrain), PAS /proc : l'ingestion écrit
# un battement de cœur (updated_at) toutes les ~30 s. Si updated_at < 6 min → vivante.
# (Le check /proc était piégé : son propre grep "ingest-snapshot" se détectait
# lui-même → le watchdog croyait toujours que ça tournait.)
cd ~/scienceswiki/infra || exit 1
DC="docker compose --env-file .env.prod"
PROG="openalex.snapshot_progress"
LOG=~/scienceswiki/snapshot-watchdog.log

VAL=$($DC exec -T api php bin/console dbal:run-sql "SELECT value FROM setting WHERE name='$PROG'" 2>/dev/null || true)
[ -z "$VAL" ] && exit 0                                  # jamais lancée
echo "$VAL" | grep -q '"finished":true' && exit 0        # terminée

# Âge de la dernière mise à jour.
UPD=$(echo "$VAL" | grep -oE '"updated_at":"[^"]+"' | sed -E 's/.*:"([^"]+)"/\1/')
TS=$(date -u -d "$UPD" +%s 2>/dev/null || echo 0)
AGE=$(( $(date -u +%s) - TS ))
[ "$TS" -gt 0 ] && [ "$AGE" -lt 360 ] && exit 0           # < 6 min sans MAJ = vivante (heartbeat 30 s)

# STALE → tuer un éventuel process php d'ingestion résiduel (comm=php pour ne PAS
# matcher le grep du watchdog lui-même), puis relancer depuis done_files-1.
$DC exec -T api sh -c 'for p in /proc/[0-9]*; do [ "$(cat "$p/comm" 2>/dev/null)" = php ] || continue; tr "\0" " " < "$p/cmdline" 2>/dev/null | grep -q ingest-snapshot && kill -9 "$(basename "$p")" 2>/dev/null; done' 2>/dev/null || true

D=$(echo "$VAL" | grep -oE '"done_files":[0-9]+' | grep -oE '[0-9]+' | head -1)
case "$D" in ''|*[!0-9]*) D=1 ;; esac                     # valide: entier
SKIP=$((D - 1)); [ "$SKIP" -lt 0 ] && SKIP=0

# memory_limit=1024M : les partitions récentes denses (~16k retenues/fichier) faisaient
# exploser la limite PHP par défaut (128M) au flush Doctrine (OOM). --flush=250 = lots plus petits.
$DC exec -d api sh -c "php -d memory_limit=1024M bin/console app:openalex:ingest-snapshot --dir=/openalexSnapshot --since=2015 --min-citations=5 --langs=en,fr --flush=250 --skip-files=$SKIP >> /tmp/ingest-snapshot.log 2>&1"
echo "$(date '+%Y-%m-%d %H:%M:%S') watchdog: stale (${AGE}s) → relance skip=$SKIP" >> "$LOG"
