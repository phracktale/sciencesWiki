# Documentation Développeur — SciencesWiki

> Portail technique du projet. Ces documents décrivent **comment SciencesWiki est
> construit**, **pourquoi** nous avons fait ces choix, et **comment y contribuer**.
> Ils s'adressent aux développeurs qui rejoignent le projet (désormais open source).

SciencesWiki est une **encyclopédie scientifique sourcée**. Elle moissonne les
métadonnées de la littérature scientifique (via [OpenAlex](https://openalex.org)),
les vectorise dans une base **pgvector**, et s'appuie sur un pipeline **RAG**
(Retrieval-Augmented Generation) **sourcé par construction** pour produire des
réponses et des articles de vulgarisation où **chaque affirmation porte ses sources
(DOI)**. Un vérificateur anti-hallucination marque les affirmations non étayées d'un
`[réf. nécessaire]`.

## Sommaire

1. **[Architecture](01-architecture.md)** — vue d'ensemble du monorepo, schéma de
   déploiement générique, composants, modèle de données, flux principaux (moisson,
   RAG, analyse, texte intégral), asynchrone, temps réel, sécurité.
2. **[Choix techniques](02-choix-techniques.md)** — pourquoi ces décisions :
   Symfony 8 / API Platform, FrankenPHP, PostgreSQL+pgvector (vs base vectorielle
   dédiée), BFF Symfony/Twig (vs SPA), IA auto-hébergée (vs API payantes), RAG
   sourcé, « jeter le PDF / garder l'URL », JWT + session, etc.
3. **[Démarrage & environnement de dev](03-demarrage.md)** — prérequis, lancer la
   pile en local, commandes console, tests, workflow de développement.
4. **[Conventions de code](04-conventions-de-code.md)** — style, structure, règles
   de nommage, patterns (Message/Handler, Mapper, Factory…), Doctrine/migrations,
   Twig, Dart, tests.
5. **[Contribuer & conventions de PR](05-contribution-et-pr.md)** — workflow Git,
   format des commits, gabarit de Pull Request, critères de revue, licence.

## Le dépôt en un coup d'œil

```
sciencesWiki/
├── apps/
│   ├── api/      → Cœur métier : Symfony 8 + API Platform (FrankenPHP).
│   │              Moisson OpenAlex, RAG pgvector, analyse, sécurité JWT.
│   ├── web/      → Front public Symfony/Twig — BFF (Backend-For-Frontend),
│   │              client server-side de l'API. Thème « CRT » rétro.
│   └── mobile/   → Application Flutter (consultation publique).
├── ml/           → Micro-service d'embeddings (FastAPI + sentence-transformers).
├── infra/        → Orchestration Docker Compose + reverse proxy + services IA.
├── docs/         → Spécifications et cette documentation développeur.
└── corpus/       → Échantillons de données (hors dépôt en production).
```

## Pile technique en bref

| Couche | Technologie |
|---|---|
| API | PHP 8.4, Symfony 8.1, API Platform 4.3, FrankenPHP |
| Persistance | PostgreSQL 16 + **pgvector** (recherche sémantique), Doctrine ORM 3.6 |
| Asynchrone | Symfony Messenger (transport Doctrine), pools de workers dédiés |
| Temps réel | Mercure |
| Auth | JWT (LexikJWTAuthenticationBundle), session côté BFF |
| Front web | Symfony 8.1, Twig, Hotwire Turbo, CommonMark |
| Mobile | Flutter / Dart |
| IA (auto-hébergée) | Embeddings (sentence-transformers), LLM (Ollama / compatible OpenAI), GROBID |
| Source de données | OpenAlex (métadonnées + résumés), Unpaywall (OA) |

> **Note sur l'hébergement** : la production tourne sur un *homelab*, mais ces
> documents décrivent une **topologie générique** (reverse proxy + nœuds
> applicatifs + nœud données/IA) reproductible sur n'importe quel hébergeur. Les
> spécificités matérielles ne sont pas requises pour développer ou déployer.
