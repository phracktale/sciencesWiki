#!/bin/sh
# Entrypoint front web SciencesWiki (FrankenPHP). Pas de base de données :
# le front est un pur client de l'API (API_BASE_URL).
set -e

exec docker-php-entrypoint "$@"
