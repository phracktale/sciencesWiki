# Spécifications — Plateforme « SciencesWiki »

> **Statut :** brouillon v0.1 — document vivant, soumis à vos réponses.
> **Date :** 2026-06-14
> **Devise du projet :** *éducation populaire, savoir libre, sources ouvertes.*

---

## 1. Vision

Construire une plateforme **libre et open source** qui :

1. **Répertorie** l'ensemble des notions scientifiques sous forme d'un **arbre des
   connaissances** navigable.
2. **Vulgarise** ces savoirs dans des articles wiki, **adossés à des publications
   de recherche** (sources primaires), avec un **bloc clairement identifié**
   séparant le travail académique sourcé du travail de vulgarisation non
   académique.
3. **S'appuie sur un comité scientifique** par domaine de compétence pour la
   relecture, et sur une **modération communautaire de type Wikipedia** pour
   l'édition.
4. **Incite les chercheurs** à déposer librement leurs travaux comme sources, au
   nom d'une démarche d'éducation populaire, libre et ouverte.

Le projet se compose de :

- une **application web** (Symfony 8, API + back-office + front public) ;
- des **applications mobiles** iOS & Android (Flutter) ;
- une **moissonneuse** (harvester) qui alimente la base à partir de sources
  d'articles scientifiques en libre accès, assistée par IA pour le tri et le
  classement dans l'arbre.

---

## 2. Principes directeurs

| Principe | Implication concrète |
|---|---|
| **Légalité stricte des sources** | On ne moissonne **que** des contenus légalement diffusés en open access (déposés par les auteurs/éditeurs). Aucune source piratée. |
| **Transparence des savoirs** | Toute affirmation vulgarisée renvoie à au moins une source primaire identifiable (DOI). |
| **Séparation académique / vulgarisation** | Deux blocs visuellement distincts : *Sources & faits établis* vs *Vulgarisation (travail non académique)*. |
| **Gouvernance ouverte** | Édition communautaire + validation par comité scientifique du domaine. |
| **Libre & ouvert** | Code sous licence libre ; contenu sous **CC BY-SA 4.0**. |
| **Francophone d'abord** | UI et contenus en français au lancement ; architecture i18n prête pour l'anglais ensuite. |
| **Souveraineté technique** | Tri IA réalisé via **modèles open source auto-hébergés** (pas de dépendance à une API propriétaire). |

---

## 3. Cadre légal & éthique des sources

### 3.1 Sources retenues (libre accès légal)

| Source | Type | Accès | Rôle dans le pipeline |
|---|---|---|---|
| **OpenAlex** | Index méta (250M+ travaux) | API gratuite | Socle de découverte, métadonnées, graphe de citations, lien OA |
| **Unpaywall** | Résolveur OA légal | API gratuite | Trouve la version **légalement** déposée d'un DOI |
| **arXiv** | Préprints STEM | Full-text libre, API | Moisson full-text (physique, maths, info, bio…) |
| **Europe PMC / PMC** | Biomédical | Full-text OA, API | Moisson full-text biomédical |
| **HAL** | Archive ouverte FR | Full-text OA, API | Forte couverture francophone |
| **DOAJ** | Annuaire de revues OA | API | Filtrage revues 100 % OA |
| **CORE** | Agrégateur OA mondial | API | Complément de couverture |
| **OpenAIRE** (EU Open Research) | Agrégateur européen | API | Projets/financements européens |
| **Persée** | SHS francophone | OA | Sciences humaines et sociales |
| **Diamond OA** (revues sans frais) | Revues | OA | Cible prioritaire (ni paywall, ni APC) |

> *Nature, Google Scholar et autres : voir §3.3.*

### 3.2 Principe « Diamond / Green / Gold OA »

La moissonneuse privilégie, dans l'ordre :
1. **Diamond OA** (libre pour le lecteur ET l'auteur),
2. **Gold OA** (libre pour le lecteur, licence CC explicite),
3. **Green OA** (version auteur légalement auto-archivée, via Unpaywall).

Chaque article moissonné porte sa **licence d'origine** et son **statut OA** ;
on ne réutilise le *full-text* que si la licence l'autorise, sinon on ne stocke
que **métadonnées + lien + citation**.

### 3.3 Sources écartées (et pourquoi) — ⚠️ important

| Source | Décision | Motif |
|---|---|---|
| **Sci-Hub** | **Exclue** | Redistribution d'articles **en violation du droit d'auteur**. L'intégrer organiserait une infraction de masse, ruinerait la crédibilité auprès du comité scientifique et des chercheurs, et exposerait juridiquement le projet. Contredit frontalement l'argument « démarche libre et légale ». |
| **Google Scholar** | **Exclue comme moissonneuse** | N'est pas une source OA mais un index ; son scraping est **interdit par ses CGU** et bloqué techniquement. Le même besoin de découverte est couvert **légalement** par OpenAlex + Unpaywall. |
| **Nature** (contenu paywall) | **Métadonnées seulement** | Seuls les articles Nature explicitement OA seront full-text ; le reste = métadonnées + lien éditeur. |

> Cette frontière n'est pas un détail technique : c'est **l'argument commercial
> et éthique** auprès des chercheurs. « Nous ne diffusons que ce qui est
> légalement libre » est précisément ce qui les rassure pour déposer.

---

## 4. Acteurs & rôles

| Rôle | Droits | Notes |
|---|---|---|
| **Visiteur** | Lecture, recherche, navigation dans l'arbre | Anonyme |
| **Contributeur** (compte) | Proposer/éditer des articles, suggérer sources, signaler | Modèle wiki |
| **Relecteur expert** | Valider les blocs « académiques » de son domaine | Rattaché à un/des nœuds de l'arbre |
| **Comité scientifique (domaine)** | Adouber un article comme « validé scientifiquement », trancher les litiges | Élargi par domaine de compétence |
| **Modérateur** | Gérer signalements, conflits d'édition, vandalisme | Type Wikipedia |
| **Administrateur** | Gestion plateforme, rôles, taxonomie de haut niveau | — |
| **Moissonneuse (système)** | Ingestion automatique, propositions de placement | Agent non humain |

> **Système de réputation** (à confirmer §13) : gains de droits par contributions
> validées, à la manière de Wikipedia / StackExchange.

---

## 5. Architecture générale

Monorepo proposé :

```
sciencesWiki/
├── apps/
│   ├── api/            # Symfony 8 — API REST/JSON + GraphQL optionnel
│   ├── web/            # Front public (Symfony + Twig/Stimulus, ou SPA)
│   └── mobile/         # Flutter (iOS + Android, code partagé)
├── services/
│   └── harvester/      # Moissonneuse (worker autonome)
├── ml/                 # Modèles IA auto-hébergés (embeddings, classif.)
├── infra/              # Docker, IaC, CI/CD
└── docs/               # Spécifications, ADR, gouvernance
```

**Flux de données :**

```
Sources OA ──▶ Moissonneuse ──▶ File d'ingestion ──▶ Normalisation
   (API)                                              │
                                                      ▼
                              IA auto-hébergée (embeddings + classif.)
                                                      │
                          Suggestion de placement dans l'arbre
                                                      │
                                                      ▼
                        Base (PostgreSQL) ◀──▶ API Symfony 8
                                                      │
                        ┌─────────────────────────────┼─────────────┐
                        ▼                             ▼             ▼
                    Web public                   App Flutter   Back-office
                                                              (révision/modération)
```

---

## 6. La moissonneuse (priorité n°1)

### 6.1 Responsabilités

1. Interroger périodiquement les **API des sources retenues** (§3.1).
2. Récupérer **métadonnées** (titre, auteurs, DOI, date, revue, licence, statut
   OA, résumé) et, **si la licence le permet**, le **full-text** (HTML/PDF/XML
   JATS).
3. **Dédoublonner** par DOI / identifiants (un même papier peut apparaître dans
   plusieurs sources).
4. **Normaliser** vers un schéma interne commun.
5. Faire appel à la couche **IA auto-hébergée** pour :
   - générer un **embedding** du contenu,
   - **proposer un (des) emplacement(s)** dans l'arbre des connaissances,
   - extraire **mots-clés / concepts** et **niveau de difficulté**.
6. Déposer le résultat en **file de validation** (rien n'est publié sans contrôle).

### 6.2 Pipeline détaillé

```
[1] Découverte   → liste de DOI/identifiants à traiter (par source, par requête)
[2] Récupération → métadonnées + (full-text si licence OK)
[3] Filtrage OA  → Unpaywall/licence : full-text autorisé ? sinon méta seules
[4] Dédoublon    → clé DOI ; fusion des provenances
[5] Normalisation→ schéma interne (voir §9 modèle de données)
[6] Enrichisst IA→ embeddings + classification arbre + concepts
[7] Mise en file → statut "à valider" / suggestions de placement
```

Architecture worker : **messages/queue** (Symfony Messenger + transport, ou
worker dédié), traitement **idempotent**, **rate-limiting** respectueux des API
sources, **journalisation** complète de la provenance (audit/transparence).

### 6.3 Couche IA (auto-hébergée)

- **Embeddings** : modèle open source (ex. famille *sentence-transformers* /
  multilingue) servi en local.
- **Classification** : k-NN sur embeddings vers les nœuds de l'arbre + (option)
  petit LLM open source local pour justifier/affiner le placement.
- **Pas de dépendance à une API propriétaire** ; abstraction permettant de
  changer de modèle.
- Toute suggestion IA est **non décisionnelle** : un humain valide le placement.

### 6.4 Conformité & politesse

- Respect du `robots.txt`, des quotas et CGU de chaque API.
- En-tête `User-Agent` identifiant le projet + contact.
- Conservation de la **licence et de l'attribution** de chaque source.

---

## 7. L'arbre des connaissances

- Structure **hiérarchique** (taxonomie) des domaines → sous-domaines → notions.
- Un nœud = une **notion scientifique** ; peut porter un ou plusieurs **articles**.
- Besoin probable d'un **graphe** plutôt qu'un arbre strict (une notion peut
  relever de plusieurs parents : ex. « théorie de l'information » ↔ maths/info/
  physique). → modèle **DAG** (arbre + liens transverses) à valider §13.
- Base taxonomique de départ : à choisir (ex. classifications existantes type
  OpenAlex *concepts*, UNESCO, Dewey scientifique…) **à confirmer §13**.

---

## 8. Le wiki

### 8.1 Anatomie d'un article

```
┌────────────────────────────────────────────┐
│  Titre de la notion + position dans l'arbre │
├────────────────────────────────────────────┤
│  🔵 BLOC ACADÉMIQUE (sourcé)                 │
│     Faits établis, chacun lié à un DOI       │
│     Statut : validé par comité du domaine    │
├────────────────────────────────────────────┤
│  🟡 BLOC VULGARISATION (non académique)      │
│     Travail pédagogique communautaire        │
│     Mention explicite « non académique »     │
├────────────────────────────────────────────┤
│  📚 Sources primaires (publications OA)      │
│  🔗 Ressources de vulgarisation « sûres »    │
│      (vidéos, articles), bloc identifié      │
└────────────────────────────────────────────┘
```

### 8.2 Cycle de vie éditorial (type Wikipedia)

```
Brouillon → Proposition → Révision communautaire → Relecture experte
        → Validation comité (bloc académique) → Publié
        ↺ (historique des versions, diff, restauration, page de discussion)
```

- **Versioning** complet (chaque édition = révision, diff, auteur, date).
- **Page de discussion** par article.
- **Signalement / modération** (vandalisme, source douteuse, hors-sujet).
- **Validation séparée** : le bloc *académique* exige l'aval du comité du
  domaine ; le bloc *vulgarisation* suit la modération communautaire.

---

## 9. Modèle de données (entités principales — esquisse)

- **Source** : provenance OA (nom, type d'API, licence par défaut).
- **Publication** : DOI, titre, auteurs, date, revue, résumé, licence, statut OA,
  full-text (si autorisé), embedding, provenances\[].
- **TreeNode** : nœud de l'arbre (label, parent(s), description, domaine).
- **Article** : rattaché à un TreeNode ; blocs académique/vulgarisation ; statut.
- **Revision** : version d'un article (contenu, auteur, date, diff).
- **Citation** : lien Article(affirmation) → Publication(DOI).
- **ExternalResource** : ressource de vulgarisation « sûre » (URL, type, fiabilité).
- **User / Role** : comptes, rôles, domaines de compétence, réputation.
- **Review** : relecture experte / validation comité (statut, commentaire).
- **Report** : signalement de modération.
- **IngestionJob** : trace de moisson (source, requête, résultat, horodatage).

---

## 10. Stack technique (proposition)

| Brique | Choix proposé | À confirmer |
|---|---|---|
| API & back | **Symfony 8** (PHP 8.3+), API Platform, Messenger | ✓ imposé |
| Base de données | **PostgreSQL** (+ `pgvector` pour embeddings) | §13 |
| Recherche | OpenSearch/Elasticsearch ou pg full-text | §13 |
| File / workers | Symfony Messenger (+ RabbitMQ/Redis) | §13 |
| Front web | Twig + Stimulus/Turbo, ou SPA (à décider) | §13 |
| Mobile | **Flutter** (iOS + Android) | ✓ imposé |
| IA | Modèles open source auto-hébergés (embeddings + LLM léger) | ✓ choisi |
| Conteneurisation | Docker / Docker Compose, CI/CD | §13 |
| Auth | JWT/OAuth2 pour API ; comptes wiki | §13 |

---

## 11. Inciter les chercheurs (volet « éducation populaire »)

- **Page « Déposer ma recherche »** expliquant la démarche libre/open source.
- **Attribution forte** : le chercheur reste cité comme source primaire de chaque
  affirmation vulgarisée (visibilité, ORCID).
- **Statut de contributeur-chercheur** et rattachement au comité de son domaine.
- **Garantie légale** : « nous ne diffusons que des versions légalement OA » —
  argument de confiance central (cf. §3.3).
- **Métriques d'impact** : nombre d'articles de vulgarisation s'appuyant sur leurs
  travaux, vues, portée pédagogique.

---

## 12. Feuille de route (phasage proposé)

| Phase | Objectif | Livrables |
|---|---|---|
| **0. Cadrage** | Valider la spec | Ce document finalisé + ADR |
| **1. Moissonneuse (MVP)** | Ingestion légale + normalisation | Worker harvester, 2-3 sources (OpenAlex+Unpaywall+arXiv), schéma BDD, dédoublonnage |
| **2. IA de tri** | Placement assisté | Embeddings + classif. arbre auto-hébergés |
| **3. Arbre + API** | Exposer la connaissance | TreeNode, API Symfony, recherche |
| **4. Wiki** | Édition + révision | Articles, blocs, versioning, workflow comité/modération |
| **5. Mobile** | Lecture/navigation | App Flutter (lecture d'abord) |
| **6. Communauté** | Réputation, incitation chercheurs | Gouvernance, page dépôt, comités |

> Vous avez indiqué vouloir **commencer par la moissonneuse** : Phase 1 est donc
> le premier chantier de développement.

---

## 13. Questions ouvertes (prochain tour)

1. **Taxonomie de départ de l'arbre** : repartir des *concepts OpenAlex* (déjà
   alignés sur les publications) ? d'une classification UNESCO/Dewey ? ou bâtir
   une taxonomie maison validée par les comités ?
2. **Arbre strict vs graphe (DAG)** : autorise-t-on un nœud à avoir plusieurs
   parents (notions transverses) ?
3. **Front web** : rendu serveur Symfony/Twig (SEO, simplicité) **ou** SPA
   (réactivité) ? Le SEO est crucial pour une encyclopédie.
4. **Système de réputation** : modèle Wikipedia (droits par ancienneté/édits) ou
   StackExchange (points/badges) ? Comment recrute-t-on les comités ?
5. **Périmètre du MVP moissonneuse** : combien de sources au départ ? (je propose
   OpenAlex + Unpaywall + arXiv pour démarrer simple et légal.)
6. **Stockage du full-text** : copie locale (si licence CC le permet) ou
   lien + extraction à la volée ?
7. **Hébergement & budget** : auto-hébergement (souveraineté, coût infra IA) ?
   cloud ? association/fondation porteuse du projet ?
8. **Modération à grande échelle** : outils anti-vandalisme, validation des
   nouvelles sources externes de vulgarisation (qui décide qu'une vidéo est
   « sûre » ?).
9. **Nom & marque** : « SciencesWiki » est-il le nom retenu ?
```
