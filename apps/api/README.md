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

## Ce que fait la moissonneuse (Lot 1)

Pipeline (cf. Phase 1 §4) : **découverte** (OpenAlex, cursor paging, polite pool)
→ **mapping** → **portier de licence** (full-text stocké seulement si la licence
l'autorise) → **dédoublonnage** par DOI / identifiant externe → **persistance**
(publication, auteurs, provenance) → trace `IngestionJob`.

Propriétés : **idempotent** (rejouer ne crée pas de doublon), **incrémental**
(curseur de reprise), conforme (User-Agent + `mailto`).

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

- **Lot 2** : connecteur Unpaywall + résolution OA légale.
- **Lot 3** : connecteur arXiv (OAI-PMH incrémental).
- **Lot 4** : enrichissement IA (embeddings + suggestion de placement).
