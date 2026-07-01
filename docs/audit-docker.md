# Audit de durcissement Docker & environnement conteneurisé — SciencesWiki

> **Périmètre.** Images et conteneurs de `apps/api` (Symfony/FrankenPHP),
> `apps/web` (Twig/FrankenPHP), la pile de production `infra/docker-compose.yml`
> (nœud « Thor ») et le nœud IA `infra/marvin/docker-compose.yml`. Complète l'audit
> applicatif : [`audit-securite.md`](audit-securite.md).
>
> **Date.** 1ᵉʳ juillet 2026 · **Barème.** 🟢 Faible · 🟡 Moyen · 🟠 Élevé · 🔴 Critique.
>
> **Contexte réel.** Homelab : TLS et exposition publique assurés par **nginx (Heimdall,
> `192.168.1.195`)** ; la pile applicative tourne sur **Thor (`192.168.1.36`)** ; l'IA et la
> **base PostgreSQL** sont sur **Marvin (`192.168.1.171`)**. La sécurité repose donc en partie
> sur la **segmentation réseau LAN / pare-feu**, ce qui est pris en compte dans les criticités.

---

## 0. Synthèse — points prioritaires

| # | Risque | Criticité | Où |
|---|--------|-----------|-----|
| D1 | Conteneurs applicatifs exécutés en **root** (pas de `USER`) | 🟠 Élevé | `apps/*/Dockerfile` |
| D2 | Aucun durcissement runtime (`no-new-privileges`, `cap_drop`, `read_only`, limits) | 🟠 Élevé | `infra/docker-compose.yml` |
| D3 | **Adminer** (`:8091`) et **Open WebUI** (`:8092`) publiés sur `0.0.0.0`, non proxifiés ; Open WebUI **contournable sans auth** en accès direct | 🔴 Critique | `docker-compose.yml:246-296` |
| D4 | `.env*` (dont `JWT_PASSPHRASE` dev en clair) **non exclus du contexte d'image** → embarqués | 🟠 Élevé | `apps/api/.dockerignore`, `.env:85` |
| D5 | **Tags d'images flottants** partout, dont `open-webui:main` | 🟡 Moyen | compose + Dockerfiles |
| D6 | `MERCURE_CORS_ORIGINS` défaut `*`, `MERCURE_JWT_SECRET`/`RAG_API_TOKEN` défaut vide | 🟡 Moyen | Caddyfile, compose, `.env` |
| D7 | `TRUSTED_PROXIES` par défaut très large (`10/8,172.16/12,192.168/16`) | 🟡 Moyen | `.env.prod.example:25` |
| D8 | **Migrations Doctrine auto au boot** (`RUN_INIT=1`) sans garde-fou | 🟡 Moyen | `docker-entrypoint.sh` |
| D9 | Jeton service RAG en clair dans le compose (`sk-scienceswiki-rag`) | 🟢 Faible | `docker-compose.yml:272` |
| D10 | Pas de scan d'image / SBOM / audit deps en CI | 🟡 Moyen | (CI absente) |
| D11 | `dompdf`/`tcpdf` (génération PDF) — surface de dépendance | 🟢 Faible | `apps/web/composer.lock` |

---

## 1. Conteneurs Docker (image de base, surface d'attaque)

- **Image de base :** `dunglas/frankenphp:1-php8.4` (Debian **bookworm**, non-slim/alpine) pour
  `api` et `web`. Officielle FrankenPHP, PHP 8.4. Base Debian = plus large qu'alpine mais bien
  maintenue.
- **Paquets système installés :**
  - api (`apps/api/Dockerfile:13-16`) : `acl file gettext git poppler-utils` + extensions PHP
    (`apcu intl opcache pdo_pgsql zip`).
  - web (`apps/web/Dockerfile:9-11`) : `file gettext git curl unzip` + extensions
    (`apcu intl opcache zip`).
- **Outils inutiles en prod runtime :** 🟡 `git`, `curl`, `unzip` restent dans l'image finale
  (utilisés au build : Composer, téléchargement PDF.js). Idem `poppler-utils` (`pdftotext`) —
  **celui-ci est légitimement requis au runtime** (ingestion fulltext). `git`/`curl`/`unzip`
  augmentent la surface post-exploitation.
- **Cache apt nettoyé :** ✅ `rm -rf /var/lib/apt/lists/*` dans les deux Dockerfiles.
- **Multi-stage build :** ⚠️ **partiel** — il y a bien deux stages (`base` → `prod`), mais
  `prod` **hérite de `base`** (mêmes paquets) et **n'est pas un stage runtime minimal** : le
  code, Composer, git, etc. cohabitent dans l'image finale. Pas de séparation build/runtime au
  sens strict (pas de `COPY --from=build` vers une image nue).
- **Scan de vulnérabilités :** aucun dans le dépôt (pas de CI). Voir §Checklist / commandes.

**Correctifs :**
- Retirer `git`/`curl`/`unzip` de l'image finale via un vrai stage de build séparé :
  ```dockerfile
  FROM dunglas/frankenphp:1-php8.4 AS build
  RUN apt-get update && apt-get install -y --no-install-recommends git curl unzip \
      && rm -rf /var/lib/apt/lists/*
  # ... composer install, download PDF.js ...

  FROM dunglas/frankenphp:1-php8.4 AS prod   # image runtime : PAS de git/curl/unzip
  RUN apt-get update && apt-get install -y --no-install-recommends poppler-utils \
      && rm -rf /var/lib/apt/lists/*
  COPY --from=build --chown=www-data:www-data /app /app
  ```
- **Criticité :** 🟡 Moyen.

---

## 2. Dockerfile — durcissement

### 2.1 USER non-root — 🟠 Élevé (D1)
- **Constat :** **aucune directive `USER`** dans `apps/api/Dockerfile` ni
  `apps/web/Dockerfile`. `COMPOSER_ALLOW_SUPERUSER=1` (api:28, web:22) confirme l'exécution en
  **root**. PHP/FrankenPHP, les workers Messenger et l'entrypoint tournent tous en root.
- **Impact :** une RCE dans l'app (ou une lib PDF, cf. §11) donne **root dans le conteneur** —
  point de départ idéal pour une évasion (surtout combiné à l'absence de `no-new-privileges`,
  §3).
- **Correctif :** exécuter en utilisateur non privilégié (`www-data` est déjà présent dans
  l'image FrankenPHP) :
  ```dockerfile
  # à la fin du stage prod, après avoir fixé les droits sur var/ et les volumes
  RUN chown -R www-data:www-data var config/jwt config/secrets public/uploads
  USER www-data
  ```
  ⚠️ Vérifier que FrankenPHP peut se **binder sur `:80`** en non-root : soit exposer `:8080`
  (>1024) et adapter nginx/compose, soit conserver la capability `NET_BIND_SERVICE` (cf. §3).
- **Criticité :** 🟠 Élevé.

### 2.2 Permissions & `COPY --chown` — 🟡
- **Constat :** `COPY . ./` (api:50, web:38) copie sans `--chown` (root). L'entrypoint est
  `COPY --chmod=755`. Les répertoires inscriptibles (`var/`, `config/jwt`, `secrets`, uploads)
  sont créés (`mkdir`) mais restent root.
- **Correctif :** `COPY --chown=www-data:www-data . ./` et fixer les droits des volumes.

### 2.3 Secrets embarqués dans l'image — 🟠 Élevé (D4)
- **Constat :**
  - `apps/api/.dockerignore` exclut `.env.local*` mais **PAS `.env`, `.env.dev`, `.env.test`**
    → ces fichiers (dont **`JWT_PASSPHRASE=56cb2c33…` en clair**, `.env:85`) sont **copiés dans
    l'image**. `apps/web/.dockerignore` est encore plus laxiste (n'exclut pas `.env*` non-local).
  - `config/secrets/dev/` (vault chiffré **sans** clé privée) est aussi embarqué (chiffré →
    impact limité).
- **Correctifs :**
  ```
  # apps/api/.dockerignore ET apps/web/.dockerignore — ajouter :
  /.env
  /.env.dev
  /.env.test
  /config/secrets/dev/
  /tests/
  /docs/
  ```
  **+** retirer la valeur `JWT_PASSPHRASE` de `apps/api/.env` (la laisser vide, comme
  `APP_SECRET`) et **rotationner** la passphrase + le keypair JWT en prod (cf.
  `audit-securite.md` A4). Vérifier avec `docker history` / `docker inspect` qu'aucun secret ne
  subsiste dans une couche.
- **Criticité :** 🟠 Élevé.

### 2.4 Composer prod / autoloader — 🟢 sain
- ✅ `composer install --no-dev --no-scripts --no-autoloader` puis
  `dump-autoload --classmap-authoritative --no-dev` + `dump-env prod` (api:46-58, web:35-61).
  Pas de dev-dependencies, autoloader optimisé, `tests/` supprimé au build (api:54, web:57).

### 2.5 Healthcheck, entrypoint, signaux, arrêt graceful — 🟡
- **Healthcheck :** aucun `HEALTHCHECK` explicite dans les Dockerfiles (un HTTP hérité de
  l'image FrankenPHP est désactivé pour les workers, `docker-compose.yml:129-131`). 🟡 Ajouter
  un healthcheck applicatif réel (ex. `/api` ou une route `/health`).
- **Entrypoint (`docker-entrypoint.sh`) :** `set -e`. Sur l'instance `api` (`RUN_INIT=1`) :
  génère les clés JWT, **exécute les migrations Doctrine** puis seed. Voir §8 (D8).
- **Arrêt graceful des workers Messenger :** les workers utilisent
  `messenger:consume … --time-limit=3600 --memory-limit=1500M` — bon pour le recyclage. ⚠️
  Vérifier la **propagation de SIGTERM** (PID 1) : FrankenPHP/`docker-php-entrypoint` doit
  transmettre le signal pour un arrêt propre entre deux messages (sinon `stop_grace_period` +
  perte de messages en cours). Recommander `stop_grace_period: 30s` sur les services worker et
  s'assurer que Messenger reçoit SIGTERM.

---

## 3. Sécurité runtime (docker-compose) — 🟠 Élevé (D2)

- **Constat :** `infra/docker-compose.yml` **n'a aucun** `read_only`, `tmpfs`, `cap_drop`,
  `security_opt` (`no-new-privileges`, seccomp, AppArmor), `pids_limit`, ni
  `deploy.resources.limits`. Seul `restart: unless-stopped` est présent. Combiné au root (D1),
  c'est la principale faiblesse de durcissement.
- **Correctif (gabarit à appliquer à `api`, `web`, workers) :**
  ```yaml
  services:
    api:
      # ... build, environment ...
      user: "www-data"                 # cf. §2.1 (ou définir USER dans le Dockerfile)
      read_only: true                  # FS racine en lecture seule
      tmpfs:
        - /tmp
      security_opt:
        - no-new-privileges:true
      cap_drop:
        - ALL
      cap_add:
        - NET_BIND_SERVICE             # UNIQUEMENT si FrankenPHP bind :80 en non-root
      pids_limit: 512
      deploy:
        resources:
          limits:
            cpus: "2.0"
            memory: 1536M              # aligné sur --memory-limit Messenger
      # volumes inscriptibles montés explicitement en rw (var/, uploads, jwt, secrets)
  ```
  Notes : avec `read_only: true`, monter en `rw` **uniquement** les chemins réellement
  inscriptibles (`var/cache`, `var/log`, `var/sessions`, `public/uploads`, `config/jwt`,
  `config/secrets`) et le reste via `tmpfs`. Tester chaque service (FrankenPHP écrit dans
  `var/`).
- **Privileged / socket Docker :** ✅ aucun service `privileged: true`, **aucun montage de
  `/var/run/docker.sock`**. Bon point.
- **User namespace remapping / rootless :** non configuré (niveau démon Docker de l'hôte). 🟡
  Recommandé pour le homelab (`userns-remap` dans `/etc/docker/daemon.json`, ou Docker rootless).
- **Criticité :** 🟠 Élevé.

---

## 4. Secrets

- **Bon :** `infra/.gitignore` exclut `.env.prod`, `.env.*.local`, `marvin/.env`. Aucun vault
  **prod** ni clé privée JWT committés (vérifié via `git ls-files`). Le vault **dev** est
  chiffré **sans** sa clé privée (non exploitable). `.env.prod.example` ne contient que des
  placeholders `__CHANGER…__`. En prod, secrets via `--env-file .env.prod` + volume
  `secrets_vault` + `SYMFONY_DECRYPTION_SECRET`.
- **À corriger :**
  - **D4** `JWT_PASSPHRASE` dev en clair dans `apps/api/.env` (versionné + embarqué) — cf. §2.3.
  - **D9** 🟢 `OPENAI_API_KEY: "sk-scienceswiki-rag"` en dur dans `docker-compose.yml:272` :
    jeton interne du RAG, statique. À déplacer en variable d'env `.env.prod` et à faire
    correspondre au `RAG_API_TOKEN` **obligatoire** (cf. `audit-securite.md` A3).
  - **Docker secrets :** non utilisés — les secrets passent par variables d'env (visibles dans
    `docker inspect` / `/proc/1/environ`). 🟡 Pour un durcissement supplémentaire, envisager
    `docker secret` (Swarm) ou des fichiers montés `*_FILE` plutôt que des env vars pour les
    valeurs les plus sensibles (MDP DB, passphrase JWT).
  - **Rotation & séparation dev/staging/prod :** documenter la rotation (APP_SECRET, keypair
    JWT, MDP DB, tokens) et **utiliser des secrets distincts par environnement** (le partage du
    keypair JWT dev/prod serait critique).
- **Criticité :** 🟠 (D4) / 🟢 (D9).

---

## 5. Réseau — 🔴 Critique (D3)

- **Constat — bindings sur `0.0.0.0` :** tous les ports de `infra/docker-compose.yml` sont
  publiés **sans IP d'interface** (donc toutes les interfaces de Thor) :
  - `api` `:8000`, `web` `:8090` → cibles légitimes du proxy Heimdall.
  - **`adminer` `:8091`** → **accès total à la base**, **non proxifié**, protégé **uniquement
    par le pare-feu LAN**. Le commentaire admet « jamais public » mais rien dans le compose ne
    le garantit.
  - **`openwebui` `:8092`** → le commentaire (`:283-285`) reconnaît que **l'accès direct au
    port LAN contourne l'authentification SSO** (les en-têtes `X-SW-Auth-*` de confiance ne
    sont posés QUE par Heimdall). Quiconque atteint `Thor:8092` **utilise l'assistant sans
    identité**.
  - Contraste : Marvin **lie explicitement l'IP LAN** (`192.168.1.171:5432`, `:8070`) — bonne
    pratique à répliquer sur Thor.
- **Correctifs :**
  ```yaml
  # Lier les services internes à l'IP LAN (ou 127.0.0.1) — jamais 0.0.0.0
  adminer:
    ports:
      - "192.168.1.36:8091:8080"     # + règle pare-feu : autoriser seulement l'IP admin
  openwebui:
    ports:
      - "192.168.1.36:8092:8080"     # + n'autoriser que Heimdall (192.168.1.195) au pare-feu
  api:
    ports:
      - "192.168.1.36:8000:80"       # seul Heimdall doit joindre :8000/:8090
  web:
    ports:
      - "192.168.1.36:8090:80"
  ```
  - **Mieux :** créer des **réseaux Docker séparés** (frontend / backend) et ne publier sur
    l'hôte **que** `web` et `api` ; joindre Adminer/Open WebUI via le proxy avec auth, ou les
    laisser **non publiés** (accès via `docker exec` / tunnel SSH ponctuel).
  - **En production réelle : ne pas déployer Adminer du tout** (outil de debug). Idem
    PhpMyAdmin/Mailhog.
  - **Base de données :** ✅ déjà non exposée sur Internet (Marvin, IP LAN). Vérifier le
    pare-feu Marvin (seul Thor doit joindre `:5432`).
- **Trusted proxies (D7) :** `TRUSTED_PROXIES` par défaut couvre tout le RFC1918
  (`.env.prod.example:25`). 🟡 **Restreindre à l'IP de Heimdall** (`192.168.1.195/32`) + le
  sous-réseau Docker interne. Un `trusted_proxies` trop large permet à tout hôte LAN de forger
  `X-Forwarded-For`/`X-Voter-Ip` (cf. `audit-securite.md` A13).
- **Criticité :** 🔴 Critique (Open WebUI sans auth + Adminer exposés si le pare-feu LAN faiblit).

---

## 6. Base de données conteneurisée

- **Constat :** `pgvector/pgvector:pg16`. Sur Marvin : bind `192.168.1.171:5432` (LAN only),
  bind-mount `/data/scienceswiki-pgdata`, `--data-checksums`, healthcheck `pg_isready`. En
  prod, la base locale de Thor est en profil `rollback` (non démarrée).
- **À durcir :**
  - 🟡 **Utilisateur SQL = propriétaire de la base** (`POSTGRES_USER`). Créer un **rôle
    applicatif à privilèges minimaux** (DML seulement) distinct du rôle de migration (cf.
    `audit-securite.md` §14).
  - **Mot de passe fort :** `.env.prod.example` impose `__CHANGER_mot_de_passe_fort__` — ok si
    respecté.
  - **Sauvegardes :** non décrites dans le compose. **Mettre en place** `pg_dump` planifié
    (cron hôte) + test de restauration + rétention. Chiffrer les dumps s'ils quittent le LAN.
  - **Chiffrement au repos :** volume non chiffré. Selon sensibilité (données personnelles),
    chiffrer le disque `/data` (LUKS).
- **Criticité :** 🟡 Moyen.

---

## 7. Logs & fichiers

- **Constat :** logs applicatifs → **stderr** (Caddyfile `log { output stderr }`) → capturés
  par Docker. ✅ Bonne pratique 12-factor.
- **À faire :**
  - 🟡 **Rotation des logs Docker** côté hôte (`/etc/docker/daemon.json`) :
    ```json
    { "log-driver": "json-file", "log-opts": { "max-size": "10m", "max-file": "5" } }
    ```
    (ou driver `local`). Sinon les logs grossissent sans limite.
  - **Volumes séparés :** ✅ `web_sessions`, `web_uploads`, `jwt_keys`, `secrets_vault`
    distincts. Bon cloisonnement.
  - **Permissions `var/cache`, `var/log`, uploads :** à fixer en non-root (cf. §2.1) surtout
    avec `read_only: true`.
  - **Pas de secret dans les logs :** vérifier qu'aucun `MAILER_DSN`/token n'est loggé (les DSN
    Brevo contiennent des identifiants). Attention au niveau `-v`/`-vv` des workers.
  - **Sauvegarde des uploads :** `web_uploads` (covers de domaines) — inclure dans la stratégie
    de backup ; antivirus (ClamAV) recommandé sur les PDF entrants (cf. `audit-securite.md` §8).
- **Criticité :** 🟢–🟡.

---

## 8. Migrations auto au boot (D8) — 🟡

- **Constat :** `apps/api/frankenphp/docker-entrypoint.sh` exécute
  `doctrine:migrations:migrate --no-interaction` au démarrage quand `RUN_INIT=1` (instance
  `api`). Pratique en homelab, mais **risqué** : une migration lourde/échouée au boot peut
  **bloquer le démarrage** ou **verrouiller la base** ; en cas de rollback applicatif, la base
  a déjà migré.
- **Correctifs :**
  - Découpler les migrations du boot : job de déploiement dédié (`deploy.sh`) exécutant les
    migrations **avant** de basculer le trafic, avec sauvegarde préalable.
  - À défaut, ajouter un verrou et journaliser explicitement le résultat ; ne pas relancer le
    seed en boucle (déjà `|| true`).
- **Criticité :** 🟡 Moyen.

---

## 9. Mercure (temps réel) — 🟡 (D6)

- **Constat (`apps/api/frankenphp/Caddyfile:21-26`) :** hub Mercure avec **`anonymous`**
  (abonnements anonymes autorisés — voulu, topics publics) et
  **`cors_origins "{$MERCURE_CORS_ORIGINS:*}"`** → **CORS `*` par défaut** si la variable n'est
  pas fournie. Publication protégée par `publisher_jwt`. Côté compose, `MERCURE_JWT_SECRET` a un
  **défaut vide** (`${MERCURE_JWT_SECRET:-}`).
- **Correctifs :**
  - Définir `MERCURE_CORS_ORIGINS=https://scienceswiki.eu` (pas `*`) dans `.env.prod`.
  - **Rendre `MERCURE_JWT_SECRET` obligatoire** (non vide) : un secret vide ⇒ JWT Mercure
    trivialement forgeable ⇒ publication de messages arbitraires.
  - Vérifier que seuls des **topics publics non sensibles** transitent par les abonnements
    anonymes.
- **Criticité :** 🟡 Moyen.

---

## 10. Supply chain & dépendances

- **Versions réelles (composer.lock) :** Symfony **8.1.0**, API Platform **4.3.13**, Lexik JWT
  **3.2.0**, Doctrine ORM 3.6.7, nelmio/cors 2.6.1. Front : **dompdf 3.1.5**, league/commonmark
  2.8.2, tcpdf 6.11.3, fpdi 2.6.8. PHP `>=8.4`.
- **Pas de `package.json`** (aucun build npm) — JS vendored (Turbo, 3d-force-graph, PDF.js
  `4.7.76`).
- **À faire :**
  - 🟡 **`composer audit`** en CI (api + web) — aucune vérif automatisée aujourd'hui.
  - **D11** 🟢 `dompdf`/`tcpdf` génèrent du PDF depuis du contenu : historiquement sujets à
    SSRF/RCE. Vérifier `dompdf` en mode `isRemoteEnabled = false` (pas de fetch d'URL distante),
    et tenir ces libs à jour.
  - **Épingler les libs JS vendored** (consigner version + SHA, surveiller les CVE PDF.js).
  - **SBOM** (CycloneDX / `syft`) si contexte réglementaire.
- **Criticité :** 🟡 Moyen.

---

## 11. Pinning d'images (D5) — 🟡

- **Constat :** tags **flottants**, **aucun digest `@sha256`** :
  `dunglas/frankenphp:1-php8.4`, `pgvector/pgvector:pg16`, `adminer:5`,
  **`ghcr.io/open-webui/open-webui:main`** (TODO explicite d'épinglage,
  `docker-compose.yml:261`), `grobid/grobid:0.8.1`.
- **Impact :** builds non reproductibles ; `:main` peut introduire une version malveillante ou
  cassée à tout rebuild.
- **Correctif :** épingler par **digest** pour les composants sensibles :
  ```yaml
  image: ghcr.io/open-webui/open-webui:v0.5.20@sha256:<digest>
  image: pgvector/pgvector:pg16@sha256:<digest>
  ```
  et rebuild régulier contrôlé (Renovate/Dependabot) plutôt que `:main` flottant.
- **Criticité :** 🟡 Moyen (🟠 pour `:main` d'Open WebUI, qui traite l'auth).

---

## 12. CI/CD & registre

- **Constat :** aucune pipeline CI/CD dans le dépôt ; déploiement par
  `docker compose --env-file .env.prod up -d --build` (+ `infra/marvin/deploy.sh` = git clone +
  build sur Marvin). Pas de registre privé, pas de scan avant déploiement, pas de promotion
  dev→staging→prod, pas de rollback formalisé (hormis le profil `rollback` de la base).
- **Recommandations (long terme) :**
  - Pipeline (GitHub Actions déjà accessible) : `composer audit`, PHPStan/Psalm, `lint:*`,
    **hadolint** (Dockerfiles), **Trivy/Grype** (image), **gitleaks** (secrets) — **bloquants**.
  - Build → push vers **registre privé** avec tags immuables (SHA de commit), **jamais
    `latest`** en prod ; promotion contrôlée entre environnements ; scan avant déploiement ;
    déploiement reproductible (digests).
- **Criticité :** 🟡 Moyen.

---

## 13. Orchestration

**Non applicable** — pas de Kubernetes/Swarm (Docker Compose simple sur homelab). Si une
migration K8s survenait, appliquer : `securityContext` (`runAsNonRoot: true`,
`readOnlyRootFilesystem: true`, `allowPrivilegeEscalation: false`, `capabilities.drop: [ALL]`),
`resources.limits`, `NetworkPolicy` (isoler la base), `Secrets` K8s (montés en fichiers),
probes liveness/readiness, RBAC minimal + ServiceAccount dédié, Pod Security Standards
`restricted`.

---

## 14. Checklist « Durcissement Docker pour Symfony en production »

> Distinguer clairement **dev** / **préprod (staging)** / **prod**.

```
IMAGE (build)
[prod]     Multi-stage réel : stage build (git/curl/composer) SÉPARÉ du stage runtime
[prod]     Runtime = base minimale + poppler-utils seulement (retirer git/curl/unzip)
[all]      rm -rf /var/lib/apt/lists/*  (fait)
[prod]     .dockerignore exclut .env, .env.dev, .env.test, config/secrets/dev, tests, docs
[prod]     Aucun secret dans une couche : vérifier `docker history --no-trunc` / `docker inspect`
[prod]     Images épinglées par digest @sha256 (surtout open-webui, pas de :main)
[prod]     composer install --no-dev --classmap-authoritative (fait)

DOCKERFILE (durcissement)
[prod]     USER www-data (non-root) pour php-fpm/frankenphp/workers
[prod]     COPY --chown=www-data:www-data ; droits var/, jwt, secrets, uploads fixés
[prod]     HEALTHCHECK applicatif réel
[prod]     SIGTERM propagé aux workers Messenger (arrêt graceful) + stop_grace_period

RUNTIME (compose)
[prod]     security_opt: [no-new-privileges:true]
[prod]     cap_drop: [ALL] (+ cap_add NET_BIND_SERVICE si bind :80 non-root)
[prod]     read_only: true + tmpfs /tmp + volumes rw explicites (var, uploads, jwt, secrets)
[prod]     deploy.resources.limits (cpus, memory) ; pids_limit
[all]      privileged:false (ok) ; PAS de /var/run/docker.sock (ok)
[prod]     userns-remap ou Docker rootless au niveau démon

RÉSEAU
[prod]     Publier UNIQUEMENT web + api, liés à l'IP LAN (192.168.1.36), pas 0.0.0.0
[prod]     Adminer : NE PAS déployer en prod (outil debug)
[prod]     Open WebUI : non publié en direct, joignable seulement via Heimdall (auth)
[prod]     Réseaux Docker séparés frontend/backend ; base non exposée (ok, Marvin LAN)
[prod]     TRUSTED_PROXIES = IP Heimdall (pas tout le RFC1918)
[prod]     MERCURE_CORS_ORIGINS = domaine réel (pas *) ; MERCURE_JWT_SECRET non vide
[prod]     RAG_API_TOKEN obligatoire (endpoint /api/rag)

SECRETS
[all]      .env.prod jamais commité (ok) ; JWT_PASSPHRASE retiré de .env versionné
[prod]     Secrets par env distincts ; rotation documentée (APP_SECRET, JWT, MDP DB, tokens)
[prod]     Envisager docker secrets / *_FILE plutôt qu'env pour MDP DB & passphrase

DONNÉES & LOGS
[prod]     Rôle SQL applicatif à privilèges minimaux (DML) ≠ rôle migration
[prod]     Sauvegardes pg_dump planifiées + test de restauration + rétention (chiffré hors LAN)
[prod]     Rotation des logs Docker (max-size/max-file) ; aucun secret loggé
[prod]     Migrations exécutées par un job de déploiement (pas au boot du conteneur)

SUPPLY CHAIN / CI
[all]      composer audit (api + web) en CI, bloquant
[prod]     hadolint (Dockerfiles), Trivy/Grype (image), gitleaks (secrets) en CI
[prod]     SBOM (syft) si réglementaire ; rebuild régulier contrôlé des images de base
```

## 15. Commandes utiles (Docker)

```bash
# --- Scan de vulnérabilités d'image ---
docker scout cves scienceswiki-api:latest         # Docker Scout
trivy image --severity HIGH,CRITICAL scienceswiki-api:latest
grype scienceswiki-api:latest

# --- SBOM ---
syft scienceswiki-api:latest -o cyclonedx-json > sbom-api.json

# --- Inspecter couches & secrets embarqués ---
docker history --no-trunc scienceswiki-api:latest      # repérer un secret dans une couche
docker inspect scienceswiki-api:latest | jq '.[0].Config.Env'   # env non secrète ?
docker run --rm scienceswiki-api:latest sh -c 'ls -la .env* config/secrets 2>/dev/null'

# --- Lint Dockerfile ---
hadolint apps/api/Dockerfile
hadolint apps/web/Dockerfile

# --- Vérifier la config compose résolue (ports, env, secrets exposés) ---
docker compose --env-file infra/.env.prod -f infra/docker-compose.yml config
docker compose -f infra/docker-compose.yml config | grep -E '0\.0\.0\.0|ports:' -A2

# --- Vérifier l'utilisateur d'exécution (doit être non-root) ---
docker compose exec api id            # attendu: uid=www-data, PAS uid=0(root)
docker compose exec api cat /proc/1/status | grep -i cap   # capabilities du PID 1

# --- Dépendances applicatives ---
composer audit --working-dir=apps/api
composer audit --working-dir=apps/web

# --- Secrets dans l'historique git ---
gitleaks detect --source . --redact
```

## 16. Plan d'action (3 niveaux)

### 16.1 Immédiat (prod) — bloquant
1. **Réseau (D3)** : lier Adminer/Open WebUI/api/web à l'IP LAN (pas `0.0.0.0`), n'autoriser
   Open WebUI/Adminer qu'au pare-feu ; **retirer Adminer** de la prod. *(le plus urgent)*
2. **Secrets (D4/D9)** : exclure `.env*` du contexte d'image, retirer/rotationner
   `JWT_PASSPHRASE`, sortir `sk-scienceswiki-rag` du compose + `RAG_API_TOKEN` obligatoire.
3. **Mercure (D6)** : `MERCURE_JWT_SECRET` non vide, `MERCURE_CORS_ORIGINS` = domaine réel.
4. **TRUSTED_PROXIES (D7)** = IP Heimdall.

### 16.2 Durcissement moyen terme
5. **USER non-root (D1)** dans les Dockerfiles + droits des volumes.
6. **Runtime hardening (D2)** : `no-new-privileges`, `cap_drop: ALL`, `read_only` + tmpfs,
   resource limits, `pids_limit`.
7. **Pinning par digest (D5)** ; épingler Open WebUI (retirer `:main`).
8. **Multi-stage runtime minimal** (retirer git/curl/unzip de l'image finale).
9. **Migrations hors boot (D8)** ; rôle SQL applicatif à privilèges minimaux.
10. **Sauvegardes PostgreSQL** planifiées + test de restauration ; rotation des logs Docker.

### 16.3 DevSecOps long terme
11. **CI sécurité** : `composer audit`, hadolint, Trivy/Grype, gitleaks, PHPStan/Psalm —
    bloquants en pré-merge.
12. **Registre privé** + tags immuables (SHA), promotion dev→staging→prod, scan avant déploiement.
13. **SBOM** (syft/CycloneDX) + rebuild régulier contrôlé des images de base.
14. **Docker rootless / userns-remap** au niveau démon ; **Docker secrets** pour les valeurs les
    plus sensibles.
15. **Antivirus (ClamAV)** sur les PDF entrants ; chiffrement au repos du volume base si requis.

---

*Voir aussi : [`audit-securite.md`](audit-securite.md) — audit applicatif Symfony.*
