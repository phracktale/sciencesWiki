#!/bin/sh
set -e

# Crée les tables figtrk_* si absentes (isolées du cœur SW). Idempotent.
if [ "$RUN_INIT" = "1" ]; then
  echo "[figtrack] init base (tables figtrk_*)…"
  python -m app.init_db
fi

exec uvicorn app.main:app --host 0.0.0.0 --port 80 --workers "${FIGTRK_WORKERS:-2}"
