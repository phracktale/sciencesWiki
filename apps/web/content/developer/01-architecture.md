# Architecture

> Vue d'ensemble technique de SciencesWiki. Pour le *pourquoi* de chaque choix,
> voir **[Choix techniques](02-choix-techniques.md)**.

## 1. Principe directeur

SciencesWiki transforme une masse de littérature scientifique en une encyclopédie
**vulgarisée et sourcée**. La chaîne de valeur :

```
OpenAlex (métadonnées + résumés)
   → moisson & déduplication (DOI)
   → embeddings (vecteurs 384d)
   → PostgreSQL/pgvector
   → recherche sémantique (kNN) + LLM = RAG sourcé
   → réponses & articles, chaque affirmation reliée à un DOI
```

Règle fondatrice : **on ne stocke jamais le PDF/TEI brut**. On en extrait des
fragments + vecteurs, on conserve l'**URL** (`oa_url`, `landing_page_url`), puis on
**jette** le fichier. L'index reste petit ; la source reste chez OpenAlex/l'éditeur.

## 2. Topologie de déploiement (générique)

L'application est un ensemble de conteneurs orchestrés par Docker Compose. La
topologie ne dépend d'aucun matériel particulier : elle se résume à **trois rôles**.

```
                    Internet
                       │  HTTPS
                       ▼
          ┌────────────────────────────┐
          │   Reverse proxy + TLS       │   (nginx/Caddy/Traefik…)
          │   + forward-auth (SSO chat) │
          └─────┬───────────────┬───────┘
                │               │
        ┌───────▼──────┐  ┌─────▼─────────┐
        │  web (BFF)   │  │  api          │   ── NŒUD APPLICATIF ──
        │ Symfony/Twig │→ │ Symfony +     │
        │ (FrankenPHP) │  │ API Platform  │
        └──────────────┘  │ (FrankenPHP)  │
                          │  + hub Mercure│
                          └──┬─────────┬──┘
              consomme files │         │ publie (temps réel)
        ┌──────────────┐     │         │
        │ workers      │◄────┘         │
        │ • harvester  │  (Messenger, transport Doctrine)
        │ • fulltext   │               │
        │ • analysis   │               │
        └──────┬───────┘               │
               │                       │
        ┌──────▼───────────────────────▼────────┐
        │  PostgreSQL 16 + pgvector              │  ── NŒUD DONNÉES / IA ──
        │  (publications, vecteurs, Q/R, arbre)  │
        └──────┬─────────────────────────────────┘
               │
        ┌──────▼───────┐ ┌──────────┐ ┌──────────────┐
        │ embeddings   │ │  LLM     │ │  GROBID      │
        │ FastAPI +    │ │  Ollama  │ │  (PDF → TEI) │
        │ s-transformers│ │(OpenAI-  │ │              │
        │              │ │ compat.) │ │              │
        └──────────────┘ └──────────┘ └──────────────┘
```

**Trois rôles, déployables sur 1 à N machines :**

- **Reverse proxy / TLS** — termine HTTPS, expose `web` et `api`, gère le
  *forward-auth* (SSO de l'assistant de chat). Le code de l'app ne s'occupe pas du TLS.
- **Nœud applicatif** — conteneurs `web`, `api` et les pools de `workers`. Sans état
  (les sessions et médias sont sur des volumes ; tout le reste est en base).
- **Nœud données / IA** — PostgreSQL+pgvector et les services d'inférence
  (embeddings, LLM, GROBID). Joints par le réseau privé via des URL configurables
  (`ML_EMBED_URL`, `LLM_BASE_URL`, `GROBID_URL`, `DATABASE_URL`).

> En développement, **tout tourne sur une seule machine** via Docker Compose. La
> répartition ci-dessus n'est qu'une variable d'environnement (`DB_HOST`, `*_URL`).

## 3. Composants

### 3.1 `apps/api` — le cœur métier
Application **Symfony 8.1 / API Platform 4.3** servie par **FrankenPHP**. Elle expose
l'API REST (JSON / JSON-LD Hydra), héberge le **hub Mercure**, et porte toute la
logique : moisson, embeddings, RAG, analyse, sécurité. La **même image** sert aussi
les workers (un conteneur = soit le serveur HTTP, soit `messenger:consume`).

### 3.2 `apps/web` — front public (BFF)
Application **Symfony/Twig** servie par FrankenPHP. C'est un **Backend-For-Frontend** :
il ne contient **aucune base de données** ; il appelle l'API *server-side* sur le
réseau interne (`API_BASE_URL=http://api`) et rend du HTML. Rendu instantané via
**Hotwire Turbo**, Markdown via **CommonMark**, export PDF (dompdf / TCPDF+FPDI).
Thème rétro « CRT » optionnel (`crt-theme.css` + `crt.js`).

### 3.3 `apps/mobile` — application Flutter
Client **Flutter/Dart** de consultation publique. Tape directement l'API publique
(sans authentification) : domaines, nœuds, réponses, recherche. Modèles dans
`lib/models.dart`, client HTTP dans `lib/api_client.dart`.

### 3.4 `ml` — micro-service d'embeddings
Service **FastAPI** exposant `sentence-transformers` (MiniLM, **384 dimensions**).
Endpoints `/embed` et `/embed-batch`. C'est la seule brique Python ; elle est
volontairement minimale et remplaçable (l'API ne la connaît que par une URL HTTP).

### 3.5 Services IA tiers (auto-hébergés)
- **LLM** : un serveur **Ollama** (ou tout endpoint compatible OpenAI) pour la
  rédaction et la vérification.
- **GROBID** : service Java qui transforme un PDF en **TEI structuré** (titre,
  sections, références) — bien supérieur à un `pdftotext`.
- **Open WebUI** : interface de chat pour les profils connectés, branchée sur notre
  endpoint RAG sourcé (`/api/rag`).

## 4. Modules de l'API (`apps/api/src`)

L'API est découpée en namespaces à responsabilité unique :

| Namespace | Responsabilité |
|---|---|
| `Harvester` | Moisson OpenAlex : connecteurs, mappers, déduplication, import, embeddings, OA (Unpaywall), ingestion texte intégral (GROBID). |
| `Rag` | Pipeline RAG : récupération kNN, rédaction de réponse, vérification de fidélité, construction de prompt, validation éditoriale. |
| `Analysis` | Détection de **controverses** et de **lacunes** par LLM : extraction de claims, clustering, détection de désaccords/gaps. |
| `Ai` | Abstraction LLM : interface `LlmClient`, fabrique de driver (openai/stub). |
| `Entity` / `Repository` | Modèle de données Doctrine et accès (dont les requêtes vectorielles `nearestTo` / `nearestHybrid`). |
| `Controller` | Points d'entrée HTTP non-CRUD : streaming de réponse, endpoint RAG compatible OpenAI, back-office. |
| `ApiPlatform` | Extensions API Platform (ex. `PublicAnswerExtension` : seules les réponses publiables sont exposées). |
| `Security` | Authentification JWT, voters, commandes de gestion d'utilisateurs. |
| `Service` | Transverses : `SettingsService` (config éditable en base), `ActivityLogger`, ticker Mercure. |
| `Enum` | États typés du domaine (statuts de traitement, validation, OA, confiance…). |
| `Mailer` / `Catalog` | E-mails ; registre des types de publication. |

### Conventions de nommage (transverses)
Le code suit des **suffixes signifiants**, à respecter pour toute contribution :
`*Message` / `*Handler` (Messenger), `*Mapper` (transformation **pure**),
`*Factory` (sélection de driver), `*Builder` (assemblage de prompt), `*Extractor` /
`*Detector` (analyse LLM), `*Checker` (vérification), `*Drafter` (génération de
contenu), `*Repository` (accès données), `*Command` (CLI).

## 5. Modèle de données (entités principales)

```
Publisher 1───* Journal 1───* Publication *───* Author        (via Authorship)
                                  │  1
                                  │  *
                            PublicationChunk(vector 384)        ← texte intégral

TreeNode ──TreeEdge──► TreeNode   (arbre de connaissance : domaine > champ > sous-champ)
   │ 1
   │ *
Question 1───* Answer 1───* AnswerRevision 1───* Footnote ──► Publication
                                                   (chaque note relie une affirmation à un DOI)

TreeNode ──► Claim / Controversy / ResearchGap    (sorties de l'analyse)
User (rôles, réputation) ; Setting ; Source ; IngestionJob ; ActivityLog
```

Points clés :
- **`Publication`** porte un vecteur (titre+résumé) ; **`PublicationChunk`** porte un
  vecteur par fragment de texte intégral (colonne `embedding`, `halfvec(384)` visé).
- La déduplication se fait sur le **DOI normalisé** (repli sur les identifiants
  externes : OpenAlex, Crossref, PMID, arXiv).
- **`TreeNode`** porte aussi un vecteur : le **placement** d'une publication ou la
  **réorientation** d'une question se fait par kNN sur l'arbre.
- **`Answer`** suit une machine à états de validation (`Unreviewed` →
  `InCommitteeReview` → `Validated`) ; seules certaines sont exposées publiquement.

## 6. Flux principaux

### 6.1 Moisson (asynchrone)
```
Commande/BO → message HarvestRubric (file « harvester »)
  → OpenAlexConnector.discover()  (pagination par CURSEUR, reprise sur incident)
  → pour chaque œuvre : message ProcessWork
      → OpenAlexMapper (JSON → RawPublication, fonction pure)
      → PublicationImporter (déduplication DOI, résolution Journal/Publisher)
  → drain asynchrone : embeddings (batch) puis placement (kNN sur l'arbre)
```
La pagination OpenAlex se fait **toujours par curseur**, jamais par date de mise à
jour (le filtre `from_updated_date` est réservé au plan payant).

### 6.2 RAG — génération d'une réponse (streaming)
```
Question → embedding → RagRetriever (pgvector kNN, ou recherche HYBRIDE :
    fusion RRF vecteur + plein-texte) → top-k publications (garde-fou de distance)
  → PromptBuilder (système éditable + sources numérotées [1]…[k])
  → LlmClient.complete()  (réponse en sections : titre / vulgarisation / académique)
  → FaithfulnessChecker : 2ᵉ passe LLM (modèle léger, t=0) → insère [réf. nécessaire]
    après les affirmations non étayées (chiffres, dates, causalités, noms propres)
  → persistance Answer + AnswerRevision + Footnotes (marqueurs [n] → DOI)
```
La réponse est diffusée en **SSE** (effet machine à écrire) et persiste même si le
client se déconnecte. L'endpoint `/api/rag` est de surcroît **compatible OpenAI**,
ce qui permet de brancher Open WebUI dessus.

### 6.3 Analyse « controverses & lacunes » (asynchrone, file dédiée)
```
message AnalyzeNodeMessage (file « analysis »)
  → AnalysisOrchestrator
      → ClaimExtractor (claims factuels depuis les Q/R d'un nœud)
      → ControversyDetector (clustering + axes de désaccord)
      → GapDetector (liens manquants, conditions non testées, questions ouvertes)
```
File **séparée** de la moisson : un run d'analyse peut durer > 1 h sans affamer ni
être affamé par les milliers de messages de moisson.

### 6.4 Texte intégral (asynchrone, file dédiée)
```
curation (top fwci/citations) → message IngestFulltext (file « fulltext »)
  → fetch du PDF OA (anti-SSRF) → GROBID (TEI) → découpage en sections
  → embeddings des fragments → PublicationChunk → **le PDF/TEI est jeté**
```

## 7. Asynchrone (Symfony Messenger)

Transport **Doctrine** (la file vit dans PostgreSQL — pas de broker externe).
Quatre files, pour **isoler les charges** :

| File | Contenu | Workers |
|---|---|---|
| `harvester` | `HarvestRubric`, `ProcessWork`, `ResolveOpenAccess` | pool (plusieurs réplicas) |
| `fulltext` | `IngestFulltext` (PDF→GROBID→vecteurs) | pool dédié |
| `analysis` | `AnalyzeNodeMessage` (long, borné par le LLM) | 1 worker dédié |
| `failed` | messages en échec (rejeu manuel) | — |

Chaque worker est un `bin/console messenger:consume <file> failed` avec
`--time-limit` et `--memory-limit` (redémarrage propre avant fuite mémoire).

## 8. Temps réel (Mercure)

L'`api` **héberge** le hub Mercure (via Caddy/FrankenPHP) **et** y publie. Le
back-office s'abonne pour suivre en direct l'avancement de la moisson, des analyses,
etc. (`HarvestTicker`).

## 9. Sécurité & rôles

- **Authentification** : JWT (LexikJWTAuthenticationBundle), TTL 8 h. Connexion via
  `POST /api/login_check` (email + mot de passe) → jeton. L'API est **stateless** ;
  le BFF web stocke le JWT en **session** et le réémet en `Authorization: Bearer`.
- **Hiérarchie des rôles** (cf. `security.yaml`) :
  ```
  ROLE_USER
    └ ROLE_AUTEUR (contributeur identifié)
        ├ ROLE_RESEARCHER (outils de recherche)
        └ ROLE_REDACTEUR (éditeur wiki)
            ├ ROLE_COMITE
            └ ROLE_MODERATEUR
                              ROLE_ADMIN (hérite COMITE + MODERATEUR + RESEARCHER)
  ```
- **Accès** : lecture **publique** par défaut ; `/api/me` exige `ROLE_USER` ;
  `/api/admin` exige `ROLE_ADMIN` ; `/api/literature-reviews` exige `ROLE_RESEARCHER`.
- **Endpoint RAG** (`/api/rag`) : **hors** firewall JWT (Open WebUI envoie un Bearer
  qui n'est pas un JWT) ; protégé par un `RAG_API_TOKEN` optionnel dans le contrôleur.
- **Secrets** : coffre Symfony chiffré (clés API OpenAlex, Brevo…), monté en volume
  persistant. **Jamais** commités.
- **Anti-SSRF** : tout *fetch* d'URL externe (PDF, proxy d'images) valide l'IP de
  destination (résolution DNS + liste blanche) pour éviter les requêtes internes.

## 10. Frontières (qui parle à qui)

- Le **navigateur** ne parle qu'au `web` (HTML) ou à l'`api` (lectures publiques /
  votes). Il ne voit jamais la base ni les services IA.
- Le **web (BFF)** parle à l'`api` server-side. Il ne touche pas la base.
- L'**api** et les **workers** sont les seuls à toucher PostgreSQL et les services IA.
- Les **services IA** ne sont jamais exposés publiquement (réseau privé / LAN).

→ Pour étendre le système, voir la section « Étendre » dans
**[Conventions de code](04-conventions-de-code.md)**.
