#!/usr/bin/env bash
# Déploie le service d'embeddings ml/ sur Marvin (nœud IA, 192.168.1.171).
#
# Déploiement par git (pas de rsync) : Marvin clone/maj le dépôt puis build.
# Prérequis : `ssh marvin` fonctionnel, Docker + git installés sur Marvin.
# Usage :    ./deploy.sh
#            MARVIN_HOST=192.168.1.171 BRANCH=main ./deploy.sh
set -euo pipefail

MARVIN_HOST="${MARVIN_HOST:-marvin}"
BRANCH="${BRANCH:-feat/infra-homelab-deploy}"
REPO_URL="${REPO_URL:-https://github.com/phracktale/sciencesWiki.git}"
REMOTE_DIR="${REMOTE_DIR:-\$HOME/scienceswiki}"

ssh "$MARVIN_HOST" "bash -s" <<REMOTE
set -euo pipefail
if [ -d "$REMOTE_DIR/.git" ]; then
  cd "$REMOTE_DIR" && git fetch origin --quiet && git checkout "$BRANCH" --quiet && git pull --ff-only --quiet
else
  git clone --quiet -b "$BRANCH" "$REPO_URL" "$REMOTE_DIR" && cd "$REMOTE_DIR"
fi
cd infra/marvin
docker compose up -d --build
sleep 5
curl -fsS http://127.0.0.1:8001/health && echo
echo "--- modèles Ollama (pour LLM_MODEL côté Thor) ---"
ollama list || true
REMOTE

echo "OK. Embeddings: http://192.168.1.171:8001/embed | LLM Ollama: http://192.168.1.171:11434/v1"
