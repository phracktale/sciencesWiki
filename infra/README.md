# SciencesWiki — Déploiement homelab (`infra/`)

Topologie (décidée) :

```
                 Internet / LAN
                       │  HTTPS
              ┌────────▼─────────┐
              │   Heimdall        │  nginx — termine le TLS
              │   (reverse proxy) │  /  → web   |  /api → api
              └────────┬─────────┘
                       │  HTTP (réseau privé)
              ┌────────▼───────────────────────────┐
              │   Thor  (docker compose)            │
              │   web (FrankenPHP)                  │
              │   api (FrankenPHP) ── worker (Messenger)
              │   database (PostgreSQL + pgvector)  │
              └────────┬───────────────────────────┘
                       │  HTTP (réseau privé)
              ┌────────▼─────────┐
              │   Marvin (GPU)    │  embeddings ml/ (:8001)
              │   192.168.1.171   │  + Ollama natif (:11434)
              └──────────────────┘
```

- **TLS** terminé sur Heimdall ; FrankenPHP écoute en HTTP clair derrière.
- **Un seul domaine** : front à la racine, API sous `/api`.
- **IA sur Marvin** : embeddings (conteneur) + LLM (Ollama natif).

---

## 1. Marvin (nœud IA) — embeddings

> Ollama est déjà installé sur Marvin. On y ajoute le service d'embeddings `ml/`.
> ⚠️ Le port SSH 22 de Marvin était injoignable lors de la préparation : vérifier
> que `ssh marvin` fonctionne (sshd actif, pare-feu) avant de lancer le script.

```bash
cd infra/marvin
./deploy.sh                      # rsync ml/ vers Marvin + docker compose up -d --build
# Le script affiche `ollama list` : noter le tag exact du modèle de rédaction.
```

Vérification : `curl http://192.168.1.171:8001/health` → `{"status":"ok",...}`.

## 2. Thor (nœud applicatif) — pile principale

```bash
cd infra
cp .env.prod.example .env.prod
# Renseigner les secrets (openssl rand -hex 32) et, surtout :
#   - POSTGRES_PASSWORD
#   - APP_SECRET / WEB_APP_SECRET / JWT_PASSPHRASE
#   - PUBLIC_URL (domaine réel)
#   - LLM_MODEL (tag exact relevé via `ollama list` sur Marvin)
nano .env.prod

docker compose --env-file .env.prod up -d --build
```

Au premier démarrage, le conteneur `api` (RUN_INIT=1) :
1. génère la paire de clés JWT (volume `jwt_keys`),
2. exécute les migrations Doctrine (dont `CREATE EXTENSION vector`),
3. amorce le registre de sources (`harvester:seed-sources`).

### Créer un administrateur + amorcer l'arbre

```bash
# Compte admin (email = argument requis ; mot de passe généré si absent)
docker compose --env-file .env.prod exec api \
  php bin/console app:user:create admin@scienceswiki.org --role=ROLE_ADMIN --verified

# Taxonomie de départ (concepts OpenAlex) — cf. spec §7
docker compose --env-file .env.prod exec api \
  php bin/console harvester:seed-tree
```

> Les noms exacts des commandes sont visibles via
> `docker compose --env-file .env.prod exec api php bin/console list`.

## 3. Heimdall (reverse proxy)

```bash
# Sur Heimdall (convention homelab : vhosts dans sites-enabled/, certs Let's Encrypt).
# 1) Obtenir le certificat (DNS scienceswiki.phracktale.com -> Heimdall requis) :
sudo certbot certonly --webroot -w /var/www/letsencrypt -d scienceswiki.phracktale.com
# 2) Déposer le vhost (déjà rempli avec l'IP de Thor 192.168.1.36) :
sudo cp scienceswiki.phracktale.com.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

---

## 4. Premier remplissage (moisson)

```bash
# Découverte OpenAlex (asynchrone → traitée par le worker)
docker compose --env-file .env.prod exec api \
  php bin/console harvester:discover openalex --max=200 --async

# Résolution OA (Unpaywall) puis embeddings + placement kNN
docker compose --env-file .env.prod exec api php bin/console harvester:resolve-oa --limit=500
docker compose --env-file .env.prod exec api php bin/console harvester:embed
docker compose --env-file .env.prod exec api php bin/console harvester:suggest-placement
```

Le `worker` consomme la file en continu (`messenger:consume harvester`).

---

## Exploitation

```bash
docker compose --env-file .env.prod ps
docker compose --env-file .env.prod logs -f api worker
docker compose --env-file .env.prod down            # arrêt (données conservées)
docker compose --env-file .env.prod up -d --build    # mise à jour après git pull
```

### Sauvegarde de la base

```bash
docker compose --env-file .env.prod exec database \
  pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB" | gzip > scienceswiki-$(date +%F).sql.gz
```

---

## Notes & points de vigilance

- **pgvector** : l'image `pgvector/pgvector:pg16` est obligatoire (l'extension
  `vector` est créée par migration). Une image `postgres` standard échouerait.
- **Secrets** : `.env.prod` n'est jamais commité (voir `.gitignore`). Pour un
  durcissement supérieur, basculer vers *Symfony Secrets* (vault chiffré).
- **LLM_MODEL** : la valeur de dev (`qwen3.6:27b`) est probablement erronée ;
  utiliser le tag réel listé par `ollama list` sur Marvin.
- **Réseau** : ni la base, ni le service d'embeddings (8001), ni Ollama (11434)
  ne doivent être exposés publiquement — réseau privé homelab uniquement.
- **TRUSTED_PROXIES** : restreindre à l'IP de Heimdall dès qu'elle est fixe.
- **CI/CD** : non couvert ici (déploiement manuel `git pull` + `up -d --build`).
