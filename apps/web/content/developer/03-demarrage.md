# Démarrage & environnement de développement

> Comment installer la pile et développer en local. Voir
> **[Architecture](01-architecture.md)** pour le contexte des composants.

## 1. Prérequis

| Outil | Version | Pour |
|---|---|---|
| **Docker** + Compose | récent | lancer la pile (voie recommandée) |
| **PHP** | 8.4 (8.3+ accepté) | dev `api`/`web` hors conteneur |
| **Composer** | 2.x | dépendances PHP |
| **PostgreSQL** | 16+ avec **pgvector** | base (fournie par Compose) |
| **Flutter / Dart** | 3.4+ | uniquement pour `apps/mobile` |

Pour développer **sans GPU ni service IA**, on utilise les drivers déterministes
(`EMBEDDING_DRIVER=hashing`, `LLM_DRIVER=stub`) — voir §5.

## 2. Cloner et se repérer

```bash
git clone <url-du-depot> sciencesWiki
cd sciencesWiki
```

Voir l'arborescence dans le **[portail](README.md)**. Les deux apps PHP (`apps/api`,
`apps/web`) sont des projets **Symfony Flex** autonomes (chacune son `composer.json`,
ses migrations, son `.env`).

## 3. Lancer en local

### Voie A — tout en Docker (recommandée)
Le `docker-compose.yml` d'`infra/` orchestre la pile complète (api, web, workers,
base, services IA). Copier l'exemple d'environnement puis lancer :

```bash
cd infra
cp .env.prod.example .env.prod      # ajuster les valeurs (secrets, URL IA…)
docker compose --env-file .env.prod up -d --build
```

L'instance `api` exécute l'**initialisation** au démarrage quand `RUN_INIT=1` :
génération des clés JWT, **migrations** Doctrine, *seed* des sources. Les workers
n'ont **pas** `RUN_INIT` (ils ne migrent pas, ils consomment seulement).

### Voie B — app par app (itération rapide sur le PHP)
Pour développer l'`api` seule, lancer juste la base puis l'app en local :

```bash
cd apps/api
docker compose up -d database          # Postgres + pgvector (compose.yaml local)
composer install
# .env.local :
#   DATABASE_URL="postgresql://app:app@127.0.0.1:5432/app?serverVersion=16&charset=utf8"
#   HARVESTER_CONTACT_EMAIL=contact@scienceswiki.org
#   EMBEDDING_DRIVER=hashing
#   LLM_DRIVER=stub
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate -n
php bin/console harvester:seed-sources
php bin/console lexik:jwt:generate-keypair   # clés JWT (non versionnées)
symfony serve            # ou: frankenphp / php -S, selon ton setup
```

Le `web` se lance pareillement (`composer install` + `symfony serve`), avec
`API_BASE_URL` pointant vers l'`api` locale.

## 4. Créer un compte et se connecter

```bash
# Compte admin
bin/console app:user:create admin@scienceswiki.org --role=ROLE_ADMIN --verified

# Membre du comité avec compétence sur un domaine
bin/console app:user:create dr.curie@labo.fr --role=ROLE_COMITE --real-name="Pr. Curie" --verified
bin/console app:user:grant-domain dr.curie@labo.fr physical-sciences

# Obtenir un jeton
curl -X POST localhost/api/login_check -H 'Content-Type: application/json' \
     -d '{"email":"admin@scienceswiki.org","password":"..."}'   # => { "token": "..." }
curl localhost/api/me -H "Authorization: Bearer <token>"
```

## 5. Pipeline de données en local (sans IA réelle)

Avec `EMBEDDING_DRIVER=hashing` et `LLM_DRIVER=stub`, on déroule toute la chaîne
sans GPU (signal lexical déterministe, dimension 384 — suffisant pour vérifier le
pipeline pgvector/kNN) :

```bash
# 1. Amorcer l'arbre des connaissances (taxonomie OpenAlex)
php bin/console harvester:seed-tree --max-level=2

# 2. Moissonner quelques publications (OpenAlex, gratuit)
php bin/console harvester:discover openalex --max=50

# 3. Embeddings (titre + résumé)
php bin/console harvester:embed --limit=500

# 4. Suggestion de placement dans l'arbre (kNN, non décisionnel)
php bin/console harvester:suggest-placement -k 3

# 5. Brouillon de réponse RAG sourcée pour une question
php bin/console wiki:draft-answer --node=computer-science \
  --question="Qu'est-ce que l'apprentissage automatique ?" -k 5
```

> La **liste de référence** des commandes est `php bin/console list` (préfixes
> `harvester:*`, `app:*`, `analysis:*`, `wiki:*`). Ce guide n'en montre qu'un
> extrait — ne pas le considérer comme exhaustif.

## 6. Asynchrone (workers)

Pour traiter les messages au lieu du mode *inline*, lancer un consommateur par file :

```bash
php bin/console messenger:consume harvester -vv     # moisson
php bin/console messenger:consume fulltext -vv      # texte intégral (GROBID)
php bin/console messenger:consume analysis -vv      # controverses & lacunes
```

En production, ces consommateurs tournent en conteneurs dédiés avec `--time-limit`
et `--memory-limit` (cf. `infra/docker-compose.yml`).

## 7. Tests

```bash
cd apps/api
php bin/phpunit
```

PHPUnit est configuré en mode **strict** : il **échoue sur toute *deprecation*,
*notice* ou *warning*** (`phpunit.dist.xml`). Les tests utilisent les drivers
`stub`/`hashing` et un `MockHttpClient` — **aucun service externe requis**. Le `web`
n'a pas (encore) de suite de tests.

## 8. Points d'accès utiles

| URL | Quoi |
|---|---|
| `/api/docs` | Doc OpenAPI interactive (API Platform) |
| `/api/tree_nodes`, `/api/publications`, `/api/answers`, `/api/search` | Lecture publique |
| `web` (`/fr/...`) | Site public (BFF) |
| Adminer (port LAN) | Inspection directe de la base (jamais public) |

## 9. Variables d'environnement clés

| Variable | Rôle |
|---|---|
| `DATABASE_URL` / `DB_HOST` | Connexion PostgreSQL (host configurable selon le nœud) |
| `EMBEDDING_DRIVER` | `http` (service `ml/`) ou `hashing` (dev) |
| `LLM_DRIVER` | `openai`/Ollama ou `stub` (dev) |
| `ML_EMBED_URL`, `LLM_BASE_URL`, `LLM_MODEL`, `GROBID_URL` | Endpoints des services IA |
| `HARVESTER_CONTACT_EMAIL` | *Polite pool* OpenAlex (obligatoire) |
| `JWT_*` | Clés/passphrase JWT (clés générées au 1er démarrage) |
| `RUN_INIT` | `1` sur l'instance qui migre/seed ; absent sur les workers |
| `CORS_ALLOW_ORIGIN` | Origines autorisées (regex) pour le front/mobile web |
| `RAG_API_TOKEN` | Jeton optionnel de l'endpoint `/api/rag` |

Secrets sensibles (clé API OpenAlex, Brevo…) : **coffre Symfony chiffré**, jamais en
clair dans `.env`, jamais commité.
