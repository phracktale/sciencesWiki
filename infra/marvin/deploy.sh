#!/usr/bin/env bash
# Déploie le service d'embeddings ml/ sur Marvin (nœud IA, 192.168.1.171).
#
# Prérequis : accès SSH à Marvin (`ssh marvin`) et Docker installé là-bas.
# Usage :    ./deploy.sh            (utilise l'hôte SSH « marvin »)
#            MARVIN_HOST=192.168.1.171 ./deploy.sh
set -euo pipefail

MARVIN_HOST="${MARVIN_HOST:-marvin}"
REMOTE_DIR="${REMOTE_DIR:-/opt/scienceswiki-ml}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

echo "==> Vérification de Marvin (Docker + Ollama)…"
ssh "$MARVIN_HOST" 'docker --version && (ollama --version || echo "ATTENTION: ollama introuvable")'

echo "==> Création du dossier distant $REMOTE_DIR…"
ssh "$MARVIN_HOST" "mkdir -p '$REMOTE_DIR/ml'"

echo "==> Synchronisation de ml/ et du compose…"
rsync -az --delete "$REPO_ROOT/ml/" "$MARVIN_HOST:$REMOTE_DIR/ml/"
rsync -az "$SCRIPT_DIR/docker-compose.yml" "$MARVIN_HOST:$REMOTE_DIR/docker-compose.yml"

echo "==> Build + démarrage du service d'embeddings…"
ssh "$MARVIN_HOST" "cd '$REMOTE_DIR' && docker compose up -d --build"

echo "==> Vérification de santé…"
ssh "$MARVIN_HOST" 'sleep 5 && curl -fsS http://127.0.0.1:8001/health && echo'

echo "==> Modèles Ollama disponibles (pour renseigner LLM_MODEL sur Thor) :"
ssh "$MARVIN_HOST" 'ollama list || true'

echo "OK. Embeddings: http://192.168.1.171:8001/embed | LLM Ollama: http://192.168.1.171:11434/v1"
