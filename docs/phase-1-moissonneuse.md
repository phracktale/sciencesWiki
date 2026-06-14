# Phase 1 — Spécifications détaillées de la moissonneuse

> **Rattaché à :** `docs/specifications.md` (§6).
> **Statut :** brouillon v0.1.
> **Périmètre :** ingestion **légale** de publications en libre accès, dédoublonnage,
> normalisation, enrichissement IA (embeddings + suggestion de placement).
> **Hors périmètre Phase 1 :** rédaction IA des articles, wiki, comités, mobile.

---

## 1. Objectif

Construire un service capable de :

1. **Découvrir** des publications via les API de 3 sources : **OpenAlex**,
   **Unpaywall**, **arXiv**.
2. **Récupérer** métadonnées + (full-text **si la licence l'autorise**).
3. **Dédoublonner** par DOI / identifiants.
4. **Normaliser** vers le schéma interne (`Publication`, `Author`, …).
5. **Enrichir** : embedding du contenu + **suggestion** de nœud dans l'arbre.
6. **Journaliser** chaque exécution (audit/provenance) et déposer les résultats
   en **file de validation** (rien n'est publié automatiquement).

**Définition de « terminé » (Phase 1) :** voir §11.

---

## 2. Position dans l'architecture

Pour la Phase 1, la moissonneuse est un **contexte borné** `App\Harvester` **dans
l'application Symfony API** (mêmes entités Doctrine, même base) — pas une seconde
application — afin de garder une **source de vérité unique** et d'éviter le
partage douloureux d'entités entre deux apps Symfony.

Elle s'exécute sous deux formes :

- **Commandes console** (`bin/console harvester:*`) pour le déclenchement manuel
  et planifié ;
- **Consommateurs Messenger** (workers) pour le traitement asynchrone par étapes.

> Le dossier `services/harvester/` du monorepo est **réservé** à une éventuelle
> extraction ultérieure en microservice ; non utilisé en Phase 1.

```
apps/api/
└── src/
    ├── Entity/                # Publication, Author, Source, ... (partagées)
    └── Harvester/
        ├── Connector/         # OpenAlexConnector, UnpaywallConnector, ArxivConnector
        ├── Message/           # DiscoverWorks, ProcessWork, ResolveOA, EnrichWork...
        ├── MessageHandler/    # un handler par étape
        ├── Pipeline/          # Normalizer, Deduplicator, LicenseGate
        ├── Ai/                # EmbeddingClient, PlacementSuggester (HTTP -> /ml)
        └── Command/           # harvester:discover, harvester:reprocess, ...
ml/                            # service d'inférence local (embeddings) — §8
```

---

## 3. Connecteurs de source

### 3.1 Interface commune

Tous les connecteurs implémentent `SourceConnector` :

```php
interface SourceConnector
{
    public function code(): string;               // "openalex" | "unpaywall" | "arxiv"

    /** Itère des identifiants/works à traiter (pagination incrémentale). */
    public function discover(DiscoveryCursor $cursor): iterable; // yield RawRef

    /** Métadonnées normalisables d'un work. */
    public function fetchMetadata(RawRef $ref): RawPublication;

    /** URL de full-text légal + licence, si disponible (sinon null). */
    public function resolveFullText(RawPublication $pub): ?FullTextLocation;
}
```

- `DiscoveryCursor` : porte l'état d'avancement (date de dernière moisson,
  curseur/`resumptionToken`) pour la **moisson incrémentale**.
- `RawRef` / `RawPublication` : DTO bruts (avant normalisation).
- Tous les connecteurs respectent le **rate-limit** et envoient un **User-Agent**
  + **mailto** identifiant le projet (cf. §9).

### 3.2 OpenAlex — *socle de découverte*

- **Endpoint** : `GET https://api.openalex.org/works`.
- **Pas de clé**. Rejoindre le **polite pool** via `mailto=` (meilleurs quotas).
- **Pagination** : `cursor=*` (cursor paging), 200 résultats/page.
- **Incrémental** : filtre `from_updated_date=YYYY-MM-DD`.
- **Champs exploités** : `id`, `doi`, `title`, `abstract_inverted_index`,
  `publication_date`, `language`, `type`, `authorships[]` (auteur + ORCID +
  institution), `primary_location` (revue/source, licence), `open_access`
  (`oa_status`, `oa_url`), `concepts[]` (→ amorçage de l'arbre + placement),
  `host_venue`.
- **Rôle** : fournit l'essentiel des métadonnées + le **mapping concepts** qui
  amorce l'arbre et nourrit la suggestion de placement.
- **Note** : l'abstract OpenAlex est un *index inversé* → reconstruction du texte.

### 3.3 Unpaywall — *résolution OA légale*

- **Endpoint** : `GET https://api.unpaywall.org/v2/{doi}?email=...`.
- **Limite** : ~100 000 requêtes/jour ; email obligatoire.
- **Champs exploités** : `is_oa`, `oa_status` (gold/green/hybrid/bronze/closed),
  `best_oa_location` (`url_for_pdf`, `license`, `host_type`,
  `version`). `host_type=repository` ⇒ Green OA (auto-archivage auteur).
- **Rôle** : pour un DOI, **confirme** l'existence d'une version **légalement**
  accessible et fournit l'URL + la **licence** — c'est le **portier légal** du
  full-text.

### 3.4 arXiv — *full-text STEM*

- **API** : `GET https://export.arxiv.org/api/query` (Atom) pour la recherche ;
  **OAI-PMH** (`https://export.arxiv.org/oai2`, `metadataPrefix=arXiv`) pour la
  **moisson en masse incrémentale** (`from=`/`until=` + `resumptionToken`).
- **Rate-limit strict** : ≤ 1 requête / 3 s ; respecter les en-têtes ; pas de
  parallélisme agressif.
- **Champs** : identifiant arXiv, DOI (si publié ailleurs), titre, résumé,
  auteurs, catégories (taxonomie arXiv → mapping vers l'arbre), date, licence.
- **Full-text** : disponible ; en Phase 1 on **n'indexe que titre + résumé +
  métadonnées** pour l'embedding (le PDF complet est une optimisation Phase 2).
- **Licence** : variable (arXiv non-exclusive ; certains articles en CC). On
  applique le **portier de licence** (§5) avant tout stockage de full-text.

---

## 4. Pipeline (étapes asynchrones via Messenger)

Chaque étape = un **message** + un **handler**, traitement **idempotent**,
**rejouable**, avec back-off sur erreur réseau.

```
harvester:discover <source>           (commande/cron)
        │  pour chaque RawRef
        ▼
[A] DiscoverWorks(source, cursor)  ──▶ émet ProcessWork(source, ref)
        ▼
[B] FetchMetadata(source, ref)     ──▶ RawPublication
        ▼
[C] ResolveOpenAccess(pub)         ──▶ (Unpaywall) URL + licence + statut OA
        ▼
[D] NormalizeAndDeduplicate(pub)   ──▶ upsert Publication (clé = DOI) + provenance
        ▼
[E] LicenseGate(pub)               ──▶ fulltext_stocke = (licence ∈ allowlist)
        ▼
[F] EnrichEmbedding(pubId)         ──▶ embedding (service /ml) -> pgvector
        ▼
[G] SuggestPlacement(pubId)        ──▶ PlacementSuggestion(s) (kNN) statut "proposé"
        ▼
   statut Publication = "en_validation"  (file de validation humaine)
```

**Règles transverses :**

- **Idempotence** : clé naturelle = DOI normalisé. Un work déjà au stade ≥ N
  n'est pas régressé ; ré-exécuter une étape produit le même résultat.
- **Reprise** : `harvester:reprocess --stage=F` rejoue une étape pour un lot.
- **Erreurs** : transport Messenger avec **retry** (3 essais, back-off
  exponentiel) puis **dead-letter** (`failed` transport) + trace dans
  `IngestionJob`.
- **Rate-limit** : limiteur par source (Symfony RateLimiter) honorant §3.

---

## 5. Portier de licence (`LicenseGate`)

Décide si l'on **stocke le full-text** ou si l'on se limite aux **métadonnées**.

- **Allowlist full-text** (stockage autorisé) : `cc0`, `cc-by`, `cc-by-sa`,
  `cc-by-nd`?, `cc-by-nc`? *(à arbitrer — voir question ouverte)*, licences
  éditeur explicitement « libre redistribution », `public-domain`.
- **Sinon** : `fulltext_stocke = false` → on conserve **DOI + métadonnées +
  résumé + lien OA** uniquement.
- Toujours conserver `licence` et `PublicationProvenance.licence_constatee`
  (audit). En cas de **licences divergentes** entre sources pour un même DOI, on
  retient la **plus permissive vérifiable** et on journalise le conflit.

> Rappel (cf. spec §3.4) : même sans full-text stocké, la publication reste
> **citable** et **vulgarisable** ; le full-text n'est pas requis pour la mission.

---

## 6. Modèle de données — détail Phase 1

> Types indicatifs PostgreSQL ; `pgvector` pour les embeddings.

### `source`
| colonne | type | notes |
|---|---|---|
| id | bigint PK | |
| code | varchar unique | openalex / unpaywall / arxiv |
| nom | varchar | |
| type_api | varchar | rest / oai-pmh |
| licence_defaut | varchar null | |
| actif | bool | |
| phase | smallint | 1/2/3 |
| config | jsonb | endpoints, quotas, mailto |

### `publication`
| colonne | type | notes |
|---|---|---|
| id | bigint PK | |
| doi | varchar unique null | clé de dédoublonnage (normalisée) |
| ids_externes | jsonb | {openalex, arxiv, pmcid, ...} |
| titre | text | |
| resume | text null | |
| date_publication | date null | |
| langue | varchar null | |
| revue | varchar null | |
| type | varchar null | article/préprint/... |
| licence | varchar null | |
| statut_oa | varchar | diamond/gold/green/bronze/closed |
| url_oa_legale | text null | |
| fulltext_disponible | bool | |
| fulltext_stocke | bool | portier licence |
| fulltext | text null | si stocké |
| embedding | vector(N) null | pgvector |
| statut_traitement | varchar | à_traiter/enrichi/en_validation/placé/rejeté |
| cree_le / maj_le | timestamptz | |

### `author`, `publication_author`
- `author(id, nom, orcid null, affiliation null)`
- `publication_author(publication_id, author_id, position)` (N-N ordonné).

### `publication_provenance`
| colonne | type | notes |
|---|---|---|
| publication_id | bigint FK | |
| source_id | bigint FK | |
| id_dans_source | varchar | |
| licence_constatee | varchar null | audit |
| recupere_le | timestamptz | |

### `ingestion_job`
| colonne | type | notes |
|---|---|---|
| id | bigint PK | |
| source_id | bigint FK | |
| requete | jsonb | filtres/curseur |
| debut / fin | timestamptz | |
| nb_traites / nb_nouveaux / nb_erreurs | int | |
| statut | varchar | en_cours/ok/échec/partiel |
| cursor_fin | varchar null | reprise incrémentale |
| log | text null | |

### `placement_suggestion`
| colonne | type | notes |
|---|---|---|
| id | bigint PK | |
| publication_id | bigint FK | |
| tree_node_id | bigint FK | |
| score | real | |
| methode | varchar | knn/llm |
| statut | varchar | proposé/accepté/rejeté |
| valide_par / valide_le | … | humain |

*(Les entités `TreeNode`/`TreeEdge` viennent de la spec §9.2 ; en Phase 1 on les
amorce depuis les concepts OpenAlex — voir §7.)*

---

## 7. Amorçage de l'arbre (graine OpenAlex)

- Commande `harvester:seed-tree` important la hiérarchie **concepts OpenAlex**
  (niveaux 0–2 pour commencer) en `TreeNode` + `TreeEdge` (DAG).
- Chaque `TreeNode` conserve `openalex_concept_id` → permet à `SuggestPlacement`
  de proposer un nœud même après réorganisation manuelle par le comité.
- L'arbre reste **éditable** ensuite (cf. spec §7) ; la graine n'est qu'un point
  de départ.

---

## 8. Enrichissement IA (Phase 1, auto-hébergé)

- **Service d'inférence local** dans `ml/` : petit service HTTP (ex. FastAPI)
  exposant `POST /embed` → renvoie le vecteur d'un texte (titre + résumé).
  Modèle : famille *sentence-transformers* **multilingue** (open source).
- `EmbeddingClient` (côté Symfony) appelle ce service ; l'embedding est stocké en
  `publication.embedding` (pgvector).
- **Suggestion de placement** (`SuggestPlacement`) : **kNN** (distance cosinus
  via pgvector) entre l'embedding de la publication et les **centroïdes des
  nœuds** (calculés depuis les concepts OpenAlex + publications déjà placées).
  On émet 1 à 3 `PlacementSuggestion` (statut *proposé*).
- **Non décisionnel** : aucune publication n'est « placée » sans validation
  humaine ; l'IA ne fait que **proposer**.
- **Pas d'API propriétaire** ; l'`EmbeddingClient` est abstrait (on peut changer
  de modèle/serveur).

---

## 9. Conformité & politesse (obligatoire)

- **User-Agent** explicite : `SciencesWiki/0.1 (+URL; mailto:contact@...)`.
- **mailto/email** transmis à OpenAlex (polite pool) et Unpaywall (obligatoire).
- **Rate-limit par source** : OpenAlex polite, Unpaywall ≤ quota/jour,
  **arXiv ≤ 1 req/3 s**. Limiteur centralisé + file dédiée par source.
- Respect `robots.txt` / CGU ; **aucune source pirate** (cf. spec §3.3/§3.4).
- **Provenance** intégralement tracée (`publication_provenance`, `ingestion_job`).
- **RGPD** : données d'auteurs publiques (nom, ORCID, affiliation) issues des API ;
  pas de données personnelles sensibles.

---

## 10. Configuration & exécution

- **Env** : `HARVESTER_CONTACT_EMAIL`, `ML_EMBED_URL`, `DATABASE_URL`,
  `MESSENGER_TRANSPORT_DSN`, quotas par source (dans `source.config`).
- **Seed** : `harvester:seed-sources` (enregistre les 3 sources + toutes les
  autres en `actif=false`, `phase=2/3`) puis `harvester:seed-tree`.
- **Découverte** : `harvester:discover openalex --since=2026-01-01`
  (idem arxiv) → alimente le pipeline.
- **Planification** : Messenger Scheduler ou cron (ex. quotidien incrémental).
- **Workers** : `messenger:consume harvester_discovery harvester_processing
  harvester_ai` (files séparées pour isoler les rate-limits).
- **Observabilité** : chaque run crée un `ingestion_job` ; logs structurés ;
  (dashboard de supervision = Phase ultérieure).

---

## 11. Définition de « terminé » (critères d'acceptation)

La Phase 1 est terminée quand :

1. Les 3 connecteurs (OpenAlex, Unpaywall, arXiv) découvrent et récupèrent des
   publications réelles, avec rate-limit et User-Agent conformes.
2. Le **dédoublonnage par DOI** fusionne les provenances multiples (un même
   papier vu via OpenAlex + arXiv = **une** `Publication`, deux provenances).
3. Le **portier de licence** décide correctement du stockage du full-text et
   journalise la licence.
4. Chaque publication reçoit un **embedding** et **≥ 1 suggestion de placement**
   (statut *proposé*), sans placement automatique.
5. Chaque exécution produit un **`ingestion_job`** complet (compteurs, curseur,
   erreurs) et la **moisson incrémentale** reprend là où elle s'est arrêtée.
6. Le pipeline est **idempotent** et **rejouable** (re-traitement sans doublon),
   les erreurs réseau passent en retry puis dead-letter.
7. Tests : unitaires (normalisation, dédoublonnage, portier licence) + un test
   d'intégration par connecteur (réponses API **mockées**).

---

## 12. Découpage en lots (proposition d'implémentation)

1. **Lot 0 — Socle** : entités Doctrine + migrations + seed sources + squelette
   `App\Harvester` + Messenger.
2. **Lot 1 — OpenAlex** : connecteur + découverte + normalisation + dédoublonnage
   + `ingestion_job`.
3. **Lot 2 — Unpaywall + portier de licence** : résolution OA + `LicenseGate`.
4. **Lot 3 — arXiv** : OAI-PMH incrémental + rate-limit strict.
5. **Lot 4 — IA** : service `ml/` d'embeddings + `EnrichEmbedding` +
   `SuggestPlacement` (kNN/pgvector) + seed de l'arbre.
6. **Lot 5 — Robustesse** : retries/dead-letter, planification, tests, doc d'exploitation.

---

## 13. Questions ouvertes spécifiques Phase 1

1. **Allowlist de licences full-text** : inclut-on les variantes `NC`/`ND`
   (non commercial / no derivatives) pour le **stockage** du full-text, ou
   full-text seulement pour les licences franchement libres (CC0/BY/BY-SA) et
   métadonnées sinon ?
2. **Modèle d'embeddings** précis (taille du vecteur, multilingue FR/EN) — choix
   dépendant du serveur GPU auto-hébergé.
3. **Volume cible initial** : démarre-t-on sur un **domaine pilote** (ex. une
   branche de l'arbre) pour valider le placement avant d'élargir ?
