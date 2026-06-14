# SciencesWiki — API (Symfony 8)

Application API + back-end de la plateforme (cf. `../../docs/specifications.md`).

Ce dépôt contient, pour l'instant, le **Lot 1 de la Phase 1 : la moissonneuse**
(connecteur OpenAlex). Voir `../../docs/phase-1-moissonneuse.md`.

## Prérequis

- PHP 8.3+ (développé/testé sous PHP 8.4)
- Composer
- PostgreSQL 16+

## Installation

```bash
composer install
# Configurer la connexion et le contact dans .env.local :
#   DATABASE_URL="postgresql://app:app@127.0.0.1:5432/app?serverVersion=16&charset=utf8"
#   HARVESTER_CONTACT_EMAIL=contact@scienceswiki.org
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate -n
php bin/console harvester:seed-sources
```

## Moissonner (Lot 1 — OpenAlex)

```bash
# Moisson de découverte (traitement inline, compteurs exacts)
php bin/console harvester:discover openalex --max=50

# Moisson incrémentale (travaux mis à jour depuis une date)
php bin/console harvester:discover openalex --since=2026-01-01

# Reprise depuis le dernier curseur enregistré
php bin/console harvester:discover openalex --resume

# Mode asynchrone : publie un message par travail (cf. Phase 1 §4)
php bin/console harvester:discover openalex --max=50 --async
php bin/console messenger:consume harvester -vv
```

## Résoudre l'accès ouvert légal (Lot 2 — Unpaywall)

Unpaywall n'est pas une source de découverte mais un **résolveur** : pour chaque
DOI, il fournit la meilleure version *légalement* accessible et sa licence, ce
qui affine le portier de licence (full-text stockable ou non).

```bash
# Résout les publications ayant un DOI mais pas encore résolues OA
php bin/console harvester:resolve-oa --limit=500
```

## Moissonner arXiv (Lot 3 — OAI-PMH)

arXiv est moissonné via OAI-PMH (full-text STEM), avec moisson incrémentale
(`--since` → `from`), sous-ensembles OAI (`--set`, ex. `cs`, `math`) et un
rate-limit strict (≤ 1 requête / 3 s, respect du 503/Retry-After).

```bash
php bin/console harvester:discover arxiv --set=cs --since=2025-06-01 --max=500
```

## Enrichissement IA (Lot 4 — embeddings + placement)

Embeddings auto-hébergés (pgvector) et suggestion de placement dans l'arbre par
similarité (kNN cosinus, **non décisionnelle** — un humain valide).

```bash
# Amorce l'arbre des connaissances depuis la taxonomie OpenAlex (domaines/champs/sous-champs)
php bin/console harvester:seed-tree --max-level=2

# Calcule les embeddings des publications (titre + résumé)
php bin/console harvester:embed --limit=500

# Propose le placement dans l'arbre (k plus proches nœuds)
php bin/console harvester:suggest-placement -k 3
```

Le moteur d'embedding est choisi par `EMBEDDING_DRIVER` :

- `http` (défaut) : service `ml/` auto-hébergé (sentence-transformers) — cf. `../../ml/`.
- `hashing` : embedder déterministe local, sans modèle, pour le dev/les tests
  (signal lexical, même dimension 384 ; vérifie le pipeline pgvector/kNN).

Prérequis : extension PostgreSQL **pgvector** (créée par la migration).

## Ce que fait la moissonneuse (Lot 1)

Pipeline (cf. Phase 1 §4) : **découverte** (OpenAlex, cursor paging, polite pool)
→ **mapping** → **portier de licence** (full-text stocké seulement si la licence
l'autorise) → **dédoublonnage** par DOI / identifiant externe → **persistance**
(publication, auteurs, provenance) → trace `IngestionJob`.

Propriétés : **idempotent** (rejouer ne crée pas de doublon), **incrémental**
(curseur de reprise), conforme (User-Agent + `mailto`).

## Comptes & gouvernance

Rôles (hiérarchie dans `security.yaml`) : `ROLE_ADMIN` > `ROLE_MODERATEUR` /
`ROLE_COMITE` > `ROLE_REDACTEUR` > `ROLE_USER`. Identité **vérifiée** requise pour
rédiger (nom réel ou pseudo, mais traçable — cf. spec §4/§8.6). Le **comité** est
rattaché à des nœuds (périmètre de validation, `DomainExpertise`).

```bash
# Créer/mettre à jour des comptes
bin/console app:user:create admin@scienceswiki.org --role=ROLE_ADMIN --verified
bin/console app:user:create dr.curie@labo.fr --role=ROLE_COMITE --type=scientifique \
  --real-name="Pr. Curie" --orcid=0000-0002-1234-5678 --verified

# Donner à un membre du comité la compétence de validation sur un domaine
bin/console app:user:grant-domain dr.curie@labo.fr physical-sciences
```

Règle d'autorisation (`AnswerVoter`) : valider une réponse exige `ROLE_COMITE`
**et** la compétence sur le domaine du nœud (ou `ROLE_ADMIN`) ; l'édition exige
`ROLE_REDACTEUR`.

### Authentification JWT

```bash
# Générer la paire de clés (une fois ; les .pem ne sont pas versionnés)
php bin/console lexik:jwt:generate-keypair

# Connexion -> jeton
curl -X POST /api/login_check -H 'Content-Type: application/json' \
     -d '{"email":"dr.curie@labo.fr","password":"secret"}'   # => { "token": "..." }

# Appel authentifié
curl /api/me -H "Authorization: Bearer <token>"
```

Lecture publique (`/api/tree_nodes`, `/api/publications`, `/api/answers`,
`/api/search`) sans authentification ; `/api/me` et les futures écritures/admin
exigent un rôle (cf. `security.yaml`).

## Rédaction RAG (brouillons de Q/R)

Génère un brouillon de réponse **vulgarisée et sourcée** pour une question
rattachée à un nœud : récupération pgvector → prompt sourcé → LLM → `Answer` +
révision IA + notes de bas de page (DOI). Le brouillon **n'est pas publié** : il
part en relecture comité (cf. spec §8.2).

```bash
bin/console wiki:draft-answer --node=computer-science \
  --question="Qu'est-ce que l'apprentissage automatique ?" -k 5
```

Le LLM est choisi par `LLM_DRIVER` (`openai`/Ollama sur la machine IA, ou `stub`
pour le dev). Le parseur tolère une sortie non structurée (fallback) ; avec le
vrai LLM, la sortie JSON sépare bloc académique / vulgarisation et cite les
sources sélectionnées.

## API de lecture (Phase 2/3 — API Platform)

API REST (JSON et JSON-LD/Hydra) exposant la connaissance moissonnée. Doc
interactive : `GET /api/docs`.

```
GET /api/tree_nodes?level=0&order[label]=asc   # arbre : domaines (filtres level, domain, label)
GET /api/tree_nodes/{slug}                      # nœud + enfants + parents (fil d'Ariane DAG)
GET /api/publications?order[publicationDate]=desc
GET /api/publications/{id}

# Recherche
GET /api/search?q=...&type=publications&mode=semantic   # kNN pgvector (embedding de la requête)
GET /api/search?q=...&type=publications&mode=text       # plein-texte (titre/résumé)
GET /api/search?q=...&type=nodes                         # nœuds les plus proches
```

> La recherche sémantique embede la requête via `EMBEDDING_DRIVER` (service `ml/`
> en prod, ou embedder local en dev). Pour une consommation cross-origin (apps
> Flutter), ajouter `nelmio/cors-bundle`.

## Tests

```bash
php bin/phpunit
```

Tests unitaires : normalisation DOI, portier de licence, dédoublonnage,
reconstruction du résumé OpenAlex, mapping OpenAlex.

## Architecture du code

```
src/
├── Entity/        Source, Publication, Author, Authorship, PublicationProvenance, IngestionJob
├── Enum/          OaStatus, ProcessingStatus, ApiType, IngestionStatus
├── Repository/    PublicationRepository (+ PublicationLookup), Source/Author/IngestionJob
└── Harvester/
    ├── Connector/ SourceConnector, ConnectorRegistry, OpenAlex\{Connector,Mapper,AbstractReconstructor}
    ├── Dto/       RawRef, RawPublication, RawAuthor, DiscoveryCursor
    ├── Pipeline/  Deduplicator, LicenseGate, PublicationImporter, PublicationLookup
    ├── Message/   ProcessWork                (+ MessageHandler/ProcessWorkHandler)
    ├── Support/   Doi
    ├── Command/   harvester:seed-sources, harvester:discover
    └── HarvestRunner.php
```

## Prochains lots (cf. Phase 1 §12)

- ~~**Lot 1** : socle + connecteur OpenAlex.~~ ✅
- ~~**Lot 2** : résolveur Unpaywall + résolution OA légale.~~ ✅
- ~~**Lot 3** : connecteur arXiv (OAI-PMH incrémental).~~ ✅
- ~~**Lot 4** : enrichissement IA (embeddings pgvector + suggestion de placement).~~ ✅

La Phase 1 (moissonneuse) est complète : ingestion légale 3 sources → résolution
OA → embeddings → placement assisté dans l'arbre.

## CORS (apps Flutter web / front cross-origin)

`nelmio/cors-bundle` ajoute les en-têtes CORS. En production, restreindre les
origines via `CORS_ALLOW_ORIGIN` (regex), ex. le domaine du front et de l'app web.
Les apps Flutter **mobiles** (iOS/Android natives) ne sont pas soumises au CORS.
