# SPEC d'architecture — Adoption native et incrémentale du bundle Symfony AI (pilote : chat + Open WebUI)

> **Statut : cadrage VALIDÉ par le propriétaire.** Cette spec n'est plus une comparaison de
> trajectoires : la décision centrale est prise. Elle détaille **comment** la mettre en œuvre
> sans big-bang, en partant de l'endpoint chat comme pilote, puis en migrant l'IA composant par
> composant. La spec challenge les **détails d'implémentation** (pas les décisions de cadrage),
> et se termine par une « RÉVISION POINT PAR POINT » + un tableau récapitulatif.
>
> Rédigée **après relecture du code réel** (références entre crochets, vérifiées).

---

## 0. Décision centrale (cadrage validé)

**Adopter NATIVEMENT le bundle Symfony AI** comme couche IA cible de SciencesWiki, et y migrer
**tout l'IA** progressivement :

- composants : `symfony/ai-platform`, `symfony/ai-agent`, `symfony/ai-store`, `symfony/ai-bundle` ;
- on **commence par l'endpoint chat** (pilote, branché à Open WebUI), **sans big-bang** ;
- on **réutilise l'index vectoriel existant** (`publication.embedding` + `publication_chunk`),
  **zéro ré-embed** (« Voie A », cf. §3) ;
- chaque composant migré reste **indépendamment testable** et **conserve les garde-fous**
  (seuil de distance 0,62, garde-fou « aucune source ⇒ pas de réponse », prompts FR, modèles
  configurables via `SettingsService`).

Ce qui n'est **pas** rediscuté ici : *faut-il* adopter le bundle (oui), *faut-il* commencer par le
chat (oui), *faut-il* ré-embedder (non, Voie A). Ce qui **est** challengé : la forme du Store
custom, la sûreté du forward-auth, le réalisme des efforts, l'ordre des étapes.

---

## 1. Constat technique vérifié (état des lieux)

Faits **vérifiés dans le code**, pas supposés. Ils conditionnent tous les choix d'implémentation.

| Brique | Réalité constatée | Référence (chemin absolu) |
|---|---|---|
| Corpus | **1 570 786** publications ; **756 332** embeddings de résumé (`publication.embedding`, `vector(384)`) ; **2 702 864** chunks plein-texte (`publication_chunk.embedding`, **halfvec**). → **~3,46 M vecteurs déjà calculés.** | DB Marvin |
| Modèle d'embedding | `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2`, **384 dim**, **L2-normalisé** (`normalize_embeddings=True`). Servi par un microservice **FastAPI** Python via **API maison `POST /embed` (NON compatible OpenAI)**. | `ml/app.py` (l.18, 41-66) |
| Client embed PHP | `EmbeddingClient::DIMENSIONS = 384` ; `HttpEmbeddingClient` → `POST {ML_EMBED_URL}/embed` (+ `/embed-batch`), valide la dimension 384. Driver via `EMBEDDING_DRIVER` (`http` prod / `hashing` test). | `apps/api/src/Harvester/Ai/EmbeddingClient.php`, `HttpEmbeddingClient.php`, `EmbeddingClientFactory.php` |
| Retrieval kNN | `PublicationRepository::nearestTo()` : CTE `abs` (`publication.embedding <=> CAST(:vec AS vector)`) **UNION** `chk` (`publication_chunk.embedding <=> CAST(:vec AS halfvec)`), **fusion `MIN(distance)` par publication**, filtre `retraction_status='none'`, `hnsw.ef_search` ajusté, sur-échantillonnage `min(k*4,120)`. Opérateur **`<=>`** (cosinus). | `apps/api/src/Repository/PublicationRepository.php` (l.137-212) |
| Seuil distance | `MAX_SOURCE_DISTANCE = 0.62` appliqué côté contrôleur via `retrieveSources(..., 0.62)`. | `apps/api/src/Controller/StreamAnswerController.php` (l.29) |
| RAG existant | `RagRetriever::retrieve(Question,$k,?float $maxDistance)` → `nearestTo` ; `PromptBuilder::build(Question,$sources)` (injecte `[n] Titre — Auteurs (Année). DOI:… Résumé:…`, prompt système `SettingsService::systemPrompt()`) ; `AnswerDrafter` orchestre retrieval→prompt→LLM→parse→persist (notes `[n]`/Footnotes). | `apps/api/src/Rag/{RagRetriever,PromptBuilder,AnswerDrafter}.php` |
| Endpoint chat actuel | `StreamAnswerController` : `GET /api/questions/{id}/stream`, **SSE** (`text/event-stream`, `X-Accel-Buffering: no`). Garde-fou aval : **aucune note `[n]` ⇒ event `nosource`, pas de réponse**. | `apps/api/src/Controller/StreamAnswerController.php` |
| LLM | `OpenAiCompatibleLlmClient(httpClient, LLM_BASE_URL, LLM_MODEL, LLM_API_TOKEN='')` → `{LLM_BASE_URL}/chat/completions`, **timeout 300s** (600s en stream), `complete()/stream()`. Ollama natif Marvin `http://192.168.1.171:11434/v1`. | `apps/api/src/Ai/Llm/OpenAiCompatibleLlmClient.php` |
| Réglages | `SettingsService` : `rag.system_prompt`, `rag.temperature`(0.6), `rag.max_tokens`(10000), `rag.neighbors`(6), `rag.model`(''→`LLM_MODEL`), `wiki.model`(`qwen3.6:latest`), `ai.light_model`(`llama3.1:8b`). | `apps/api/src/Service/SettingsService.php` |
| Analyse | `AnalysisOrchestrator::run(TreeNode,AnalysisOptions)` chaîne `ClaimExtractor`→`ControversyDetector`→`GapDetector`. `ClaimExtractor` : LLM→`ClaimJsonParser`→persist (`LLM_TIMEOUT=300`). `ControversyDetector` : `DEFAULT_THETA=0.15`. | `apps/api/src/Analysis/*` |
| Infra | Compose `scienceswiki` **sur Thor** (192.168.1.36) : `api`(8000), `web`(8090), `worker`×6, `fulltext-worker`×4, `analysis-worker`×1, `adminer`(8091). **Marvin** (192.168.1.171) héberge **Ollama**(11434), **embeddings FastAPI**(8001) et **PostgreSQL+pgvector**(5432, bind privé). | `infra/docker-compose.yml`, `infra/marvin/docker-compose.yml` |
| Reverse proxy | **Heimdall** (nginx, 192.168.1.195) termine le TLS ; `/`→web, `/api`→api ; SSE géré (`proxy_buffering off`, `proxy_read_timeout 600s`, `chunked_transfer_encoding on`). | `infra/heimdall/scienceswiki.eu.conf` |
| Isolation | **Règle homelab en vigueur** : base, embeddings (8001), Ollama (11434) **jamais publics**. | `infra/README.md` (l.138-139) |
| Bundle aujourd'hui | **Aucun** `symfony/ai-*` dans `apps/api/composer.json`. Symfony **8.1.\***. LLM via `http-client` natif. | `apps/api/composer.json` |
| Tests | `apps/api/tests/` : **uniquement des tests unitaires** (`PHPUnit\Framework\TestCase` + `MockHttpClient`). **Aucun WebTestCase/KernelTestCase, aucun test fonctionnel/DB.** | `apps/api/tests/**` |

**Contraintes dures qui en découlent :**

1. **Pas de test fonctionnel aujourd'hui** ⇒ tout refactor de la chaîne RAG est un **risque de
   régression silencieuse**. La migration doit livrer **ses propres tests** (cf. §5) et avancer
   par strangler pattern (l'ancien chemin reste vivant tant que le nouveau n'est pas validé).
2. La **DB et les embeddings sont sur Marvin**, pas sur Thor. Un Store du bundle qui « parle
   pgvector » devra pointer vers la base Marvin **et** appeler le service `/embed` Marvin — exactement
   ce que fait déjà l'API. **Aucun nouveau flux réseau** n'est requis par la Voie A.
3. Le retrieval réel n'est **pas** un simple kNN : c'est une **fusion abstract+chunks (vector +
   halfvec) avec `MIN` par publication, filtre rétractations et tuning HNSW**. Aucun Store
   « générique » ne reproduit cela : le Store custom **doit** s'appuyer sur `nearestTo()`.
4. Le service d'embedding est **non compatible OpenAI** (`POST /embed` maison) ⇒ on ne peut pas le
   brancher tel quel comme `Platform` OpenAI ; il faut le **bridger** (cf. §3.1).

---

## 2. Objectif et périmètre

### 2.1 Objectif
Faire converger l'IA de SciencesWiki vers le **bundle Symfony AI** (abstractions `Platform`,
`Agent`, `Store`, `Vectorizer`, `DocumentIndexer`, `SimilaritySearch`) **sans réécrire le RAG d'un
coup** et **sans recalculer un seul vecteur** au démarrage. Premier livrable concret : un
**endpoint chat** porté par un `Agent` du bundle, **sourcé**, branché à **Open WebUI** comme UI de
chat conversationnel (multi-tours, historique).

### 2.2 Qui accède, à quoi

| Public | Chat Open WebUI | RAG corpus | Justification |
|---|---|---|---|
| Visiteur anonyme | ❌ | ❌ | Le chat consomme du GPU Marvin (ressource unique). Le wiki public reste la voie anonyme. |
| `ROLE_USER` | ✅ | ✅ lecture | Cœur de cible. |
| `ROLE_RESEARCHER` | ✅ | ✅ + (option) plus de voisins / modèle « lourd » | Cohérent avec l'espace chercheur. |
| `ROLE_ADMIN` | ✅ + admin Open WebUI | ✅ | Gestion modèles/utilisateurs Open WebUI. |

### 2.3 Hors périmètre (pilote)
- Le flux wiki publié (`StreamAnswerController`/`AnswerDrafter`) **reste la source de vérité** des
  réponses relues. Tant que l'étape 3 n'est pas validée, **on n'y touche pas** (strangler pattern).
- Pas d'exposition publique de Marvin. Pas d'upload de documents par les utilisateurs dans Open
  WebUI (RAG natif Open WebUI désactivé — corpus parallèle interdit, risque licence).
- Pas de ré-embed au démarrage. (Voie C documentée mais non retenue tant que le modèle convient.)

---

## 3. Stratégie d'indexation — les 3 voies (Voie A retenue)

Le bundle `symfony/ai-store` fournit un `StoreInterface` (méthodes `add(VectorDocument…)` /
`query(Vector, …): VectorDocument[]`) et un store natif `symfony/ai-postgres-store` (pgvector). Le
`Vectorizer` transforme texte→vecteur via une `Platform`. La question : **où vivent les vecteurs et
comment on les interroge ?**

### 3.1 Voie A — Store custom au-dessus de l'index existant (ZÉRO ré-embed) — **RETENUE**

**Principe.** Implémenter une classe `SciencesWikiStore implements StoreInterface` **par-dessus les
tables existantes** `publication` / `publication_chunk`. `query(Vector $v, $options)` **délègue à
`PublicationRepository::nearestTo($v->getData(), $k)`** (donc conserve **exactement** la fusion
abstract+chunks, le `MIN`, le filtre rétractations, le tuning HNSW). Pour vectoriser la **requête**,
on bridge le service ML existant via un `PlatformInterface` custom (`MlEmbedPlatform`) qui appelle
`HttpEmbeddingClient` → `POST {ML_EMBED_URL}/embed` (même modèle, mêmes 384 dim L2). **Les vecteurs
de requête sont donc identiques à ceux du corpus** → le **seuil 0,62 reste valide tel quel**.

**Ce qu'on n'écrit PAS :** pas de `add()` qui copie des données (le pilote est *read-only* sur
l'index : `add()` peut lever `LogicException('indexation gérée par les moissons')` au pilote, ou
être branché plus tard sur le pipeline `fulltext-worker`). Pas de migration de schéma. Pas de resync.

**Coût :** un mapping `VectorDocument` ↔ ligne `publication` (id, distance, métadonnées pour les
sources `[n]`). C'est tout. **Aucune donnée déplacée, aucun vecteur recalculé.**

**Pourquoi c'est correct et pas un hack :** `StoreInterface` est *fait* pour ça — c'est un port. Le
bundle ne suppose nulle part que le store doit posséder ses données ; il suppose qu'il sait
`query(Vector)`. Notre `nearestTo()` est une implémentation **supérieure** au store générique
(fusion 2 espaces + filtre métier). Le brancher derrière l'interface est l'inverse d'un hack.

### 3.2 Voie B — ETL vers le store natif `ai-postgres-store`

**Principe.** Utiliser `DocumentIndexer` + `symfony/ai-postgres-store` : créer une **nouvelle table**
gérée par le bundle et **y copier** les ~3,46 M vecteurs, puis **resynchroniser** à chaque moisson.

**Coût réel :** copie de **~3,46 M lignes** (756 k vector + 2,70 M halfvec) ; perte de la **fusion
abstract+chunks** sauf à la reconstruire dans le schéma du bundle ; **double source de vérité** (la
moisson écrit dans `publication`/`publication_chunk`, il faut un *resync* vers la table du bundle) ;
risque de **divergence** et de double maintenance. Le filtre rétractations et le `MIN` par
publication ne sont pas natifs → à réimplémenter côté requête.

**Verdict :** plus de code, plus de stockage, plus de risque, **pour zéro gain de qualité**.
Écartée tant qu'on n'a pas une raison de posséder un index séparé (ex. multi-tenant, store distant).

### 3.3 Voie C — Ré-embed complet avec un modèle neuf (ex. `bge-m3` via Ollama)

**Principe.** Choisir un meilleur modèle d'embedding, **tout recalculer** (1,57 M abstracts + 2,70 M
chunks) et réindexer.

**Coût réel :** **plusieurs heures → ~1 jour de GPU Marvin** (qui sert déjà l'`analysis-worker` et le
chat), réécriture des migrations (dimension ≠ 384), invalidation du seuil 0,62 (à re-calibrer),
fenêtre où l'ancien et le nouvel index coexistent.

**Quand C se justifie (et seulement alors) :** si on **mesure** que `paraphrase-multilingual-MiniLM`
plafonne en qualité de retrieval (rappel/précision sur un jeu de questions de référence) et qu'un
modèle plus fort (bge-m3, e5-large) apporte un gain net. C'est une **décision produit**, pas une
contrainte d'adoption du bundle. **À ne lancer qu'avec un protocole d'évaluation et hors heures de
charge.**

### 3.4 Tableau des 3 voies

| Critère | **A — Store custom (RETENU)** | B — ETL vers ai-postgres-store | C — Ré-embed modèle neuf |
|---|---|---|---|
| Ré-embed / recalcul | **Aucun** | Aucun (copie seulement) | **Tout** (~3,46 M, ~h→1j GPU) |
| Données déplacées | **0** | ~3,46 M lignes | Réécriture complète |
| Fusion abstract+chunks | **Conservée (`nearestTo`)** | À réimplémenter | À réimplémenter |
| Seuil 0,62 | **Valide tel quel** | Valide (même vecteurs) | **À recalibrer** |
| Filtre rétractations | **Conservé** | À reporter | À reporter |
| Source de vérité | **Unique** (index existant) | **Double** (resync) | Nouvelle |
| Effort | **Faible** | Moyen-élevé | Élevé + GPU |
| Risque | **Faible** | Divergence / double maint. | Régression qualité + fenêtre |
| Gain qualité | Neutre (identique) | Neutre | **Potentiel** (si mesuré) |
| Quand | **Maintenant** | Si index séparé requis | Si MiniLM mesuré insuffisant |

**TransformersPHP — option ULTÉRIEURE (pas maintenant).** `symfony/ai-platform` propose un bridge
**TransformersPHP** (HF/sentence-transformers en PHP/ONNX) qui permettrait de **retirer le
microservice Python** `ml/app.py`. **Mise en garde dure :** une ré-implémentation ONNX en PHP **ne
reproduit pas au bit près** les vecteurs déjà stockés (tokenizer, pooling, normalisation, précision
float peuvent différer) ⇒ l'activer impliquerait une **ré-indexation = Voie C**. Donc :
TransformersPHP n'est envisageable **qu'avec** un ré-embed assumé, pas comme un swap transparent du
service Python. Au pilote : **on garde le service FastAPI** et on le bridge (`MlEmbedPlatform`).

---

## 4. Architecture cible (schéma textuel)

```
                          Internet ──HTTPS──> Heimdall (nginx, TLS, 192.168.1.195)
                                                  ├── /        → web        (Thor:8090)
                                                  ├── /api     → api        (Thor:8000)
                                                  └── /chat    → openwebui  (Thor:8082)   [LAN-only, auth via forward-auth]
                                                                     │
                                                                     │ (UI chat : Open WebUI = pur client OpenAI-compatible)
                                                                     ▼
   ┌───────────────────────────────  API SciencesWiki (Thor, Symfony 8.1)  ───────────────────────────────┐
   │                                                                                                       │
   │   POST /api/rag/chat  (OpenAI-compatible /v1/chat/completions, SSE)                                   │
   │        └─► Agent  (symfony/ai-agent)                                                                  │
   │               ├─ tool/retrieval : SimilaritySearch ──► SciencesWikiStore (StoreInterface)            │
   │               │                                            └─► PublicationRepository::nearestTo()     │
   │               │                                                   (vector + halfvec, MIN, retraction, │
   │               │                                                    seuil 0,62, HNSW)                  │
   │               ├─ Vectorizer(requête) ──► MlEmbedPlatform (PlatformInterface custom)                   │
   │               │                               └─► HttpEmbeddingClient ──POST /embed──┐                │
   │               ├─ PromptBuilder (sources [n], prompt FR via SettingsService)          │                │
   │               ├─ Platform LLM = symfony/ai-ollama-platform (ou bridge Generic)       │                │
   │               └─ garde-fous : seuil 0,62 + "aucune note [n] ⇒ nosource"              │                │
   │                                                                                      │                │
   └──────────────────────────────────────────────────────────────────────────┬─────────┘                │
                                                                                │                          │
                       [LAN privé homelab — jamais public]                      ▼                          ▼
                                                              Ollama (Marvin 192.168.1.171:11434)   Embeddings FastAPI
                                                              qwen3.6 / llama3.1:8b / gemma4         (Marvin :8001 /embed)
                                                                                                            │
                                                              PostgreSQL+pgvector (Marvin :5432) ◄──────────┘
                                                              publication.embedding vector(384)
                                                              publication_chunk.embedding halfvec
```

Points clés du schéma : Open WebUI ne joint **ni** Marvin **ni** la base — il ne parle qu'à
`/api/rag/chat`. Toute l'IA (embed requête, kNN, prompt, LLM, garde-fous) reste **dans l'API**,
désormais **portée par le bundle** (Agent + Store + Platform), pas réinventée.

---

## 5. Plan d'adoption incrémental (par composant : effort + tests)

**Règle transverse :** il n'existe **aucun test fonctionnel** aujourd'hui. Chaque étape **livre ses
propres tests** (unitaires sur les classes du bundle qu'on écrit + au moins un `KernelTestCase`/
`WebTestCase` sur l'endpoint) et **ne supprime l'ancien chemin qu'une fois le nouveau validé**
(strangler). Les efforts sont en **jours-dev** (1 dev expérimenté Symfony, hors imprévus
d'intégration du bundle qui est jeune).

| # | Composant / étape | Livrables | Tests de validation | Effort |
|---|---|---|---|---|
| **1** | **Socle bundle** : `composer require symfony/ai-bundle symfony/ai-platform symfony/ai-agent symfony/ai-store symfony/ai-ollama-platform symfony/ai-postgres-store` ; config `ai.yaml` (Platform Ollama Marvin) ; **`MlEmbedPlatform`** (bridge `/embed`) ; **`SciencesWikiStore`** (Voie A, délègue `nearestTo`). **Aucun branchement sur l'existant** (pilote isolé). | Services bundle déclarés ; commande/test de fumée : `Store::query(embed("vaccin ARNm"))` renvoie les mêmes ids que `nearestTo` direct. | Test unitaire : `SciencesWikiStore` renvoie un `VectorDocument[]` aligné sur `nearestTo` (même ordre/ids, mock repo). Test : `MlEmbedPlatform` mappe bien `/embed` → `Vector(384)`. | **3–5 j** (dont apprivoisement d'un bundle jeune, doc parfois mince). |
| **2** | **Endpoint chat pilote** `POST /api/rag/chat` (OpenAI-compatible, **SSE**) porté par un **Agent** du bundle (retrieval via Store + prompt FR + LLM Ollama + garde-fous 0,62 / nosource). **Branché à Open WebUI** comme « OpenAI connection ». SSO **forward-auth** (cf. §6). | Contrôleur émettant le contrat `/v1/chat/completions` (events `delta`/`[DONE]`) ; service dans Open WebUI ; vhost `/chat` + WS. | `WebTestCase` : POST style `/v1/chat/completions` → flux sourcé `[n]` ; question hors-corpus → garde-fou nosource. Test navigateur : `/chat` connecté → réponse sourcée. **Forge de header X-SW-Auth ⇒ ignorée.** | **4–6 j** (endpoint + SSO + Open WebUI + WS Heimdall). |
| **3** | **Migration Q/R wiki** : réécrire `AnswerDrafter` sur l'Agent du bundle **en préservant** SSE, sections (TITRE/VULGARISATION/ACADEMIQUE), notes `[n]`/Footnotes, garde-fous, persistance `Answer`/`AnswerRevision`. Strangler : nouveau chemin derrière un flag, ancien gardé jusqu'à parité. | Agent « rédaction wiki » + parsing sections + persistance. Bascule `StreamAnswerController` sur l'Agent quand parité prouvée. | **Tests de non-régression** (nouveaux) : mêmes sources, mêmes sections, même comportement nosource sur un corpus de questions de référence. Diff sortie ancien/nouveau. | **5–8 j** (chemin le plus sensible : persistance + parsing + zéro test existant). |
| **4** | **Revues de littérature** (génération multi-sources structurée) portées par un Agent. | Agent « revue » réutilisant Store + prompts FR. | Test : revue produit des sections sourcées cohérentes ; garde-fou nosource. | **3–5 j**. |
| **5** | **Extraction claims/controverses** : migrer `ClaimExtractor`/`ControversyDetector` vers le **structured output** du bundle (schéma typé au lieu du parsing JSON maison `ClaimJsonParser`). Conserver `DEFAULT_THETA=0.15`, fusion par embedding. | Agent + sortie structurée typée ; adaptateur vers entités existantes. | Tests unitaires : sortie structurée → `ParsedClaim` ; non-régression sur un échantillon annoté ; `analysis-worker` inchangé. | **5–8 j** (qualité du structured output sur modèles Ollama à valider). |
| **6** | **Recherche sémantique / placement** (recherche dans l'arbre, placement de nœud) sur `SimilaritySearch` + Store. | Service de recherche/placement via le bundle. | Test : résultats alignés sur l'implémentation actuelle (mêmes voisins). | **2–4 j**. |

**Total indicatif** : **~22–36 j-dev**, étalés, **chaque étape livrable et réversible**. L'étape 1
n'a **aucun impact** sur la prod ; l'étape 2 est le **pilote** ; les étapes 3–6 migrent l'existant
**sous parité prouvée**.

---

## 6. Open WebUI — hébergement, exposition, SSO, sécurité

### 6.1 Hébergement
**Sur Thor**, service `openwebui` du compose `scienceswiki` (app web stateless, GPU-less ; même
réseau que l'API, même cycle `git pull && docker compose up -d`). Image
`ghcr.io/open-webui/open-webui`, **tag versionné épinglé** (cohérent avec `pgvector:pg16`,
`adminer:5`), volume `openwebui_data` (SQLite : comptes auto-créés, historiques, réglages).

```yaml
# Extrait À AJOUTER dans infra/docker-compose.yml (illustratif)
  openwebui:
    image: ghcr.io/open-webui/open-webui:v0.x.y     # tag épinglé (jamais :main en prod)
    environment:
      ENABLE_SIGNUP: "false"                         # pas d'inscription ouverte
      ENABLE_LOGIN_FORM: "false"                     # pas de login local mot de passe
      WEBUI_AUTH_TRUSTED_EMAIL_HEADER: X-SW-Auth-Email
      WEBUI_AUTH_TRUSTED_NAME_HEADER:  X-SW-Auth-Name
      WEBUI_URL: ${PUBLIC_URL}/chat
      ENABLE_RAG_WEB_SEARCH: "false"                 # RAG natif / upload désactivés
      ENABLE_OLLAMA_API: "false"                     # Open WebUI ne parle PAS à Ollama en direct
      # Seule "connexion" déclarée : /api/rag/chat (OpenAI-compatible) → modèle "SciencesWiki (sources)"
      WEBUI_SECRET_KEY: ${OPENWEBUI_SECRET_KEY}
    volumes:
      - openwebui_data:/app/backend/data
    ports:
      - "127.0.0.1:${OPENWEBUI_PORT:-8082}:8080"     # bind LAN/loopback ; jamais routé hors Heimdall
    restart: unless-stopped
# volumes: openwebui_data:
```

> **Détail challengé (vs spec initiale).** L'ancienne version branchait Open WebUI directement sur
> Ollama (`OLLAMA_BASE_URL`). **On le retire** : au pilote, le **seul** modèle exposé est
> `/api/rag/chat` (sourcé, garde-fous). Donner à Open WebUI un accès Ollama direct créerait un
> chemin **non sourcé, hors quota, hors garde-fous** — à éviter. Si un jour on veut un « chat libre
> non sourcé », on l'exposera comme un **second Agent du bundle** côté API (toujours via `/api`,
> jamais Ollama direct), pour garder quota et audit au même endroit.

### 6.2 Exposition sous `/chat`
Même domaine `scienceswiki.eu` (cookie same-site, CSP cohérente, pas de CORS, un seul certificat).
Nouveau `location /chat/` dans `scienceswiki.eu.conf` :
- `proxy_pass http://192.168.1.36:8082/;`
- **WebSocket** (Open WebUI streame en WS) : `proxy_set_header Upgrade $http_upgrade;`
  `proxy_set_header Connection "upgrade";` `proxy_read_timeout 600s;` `proxy_buffering off;`
- **forward-auth** : `auth_request` (cf. §6.3) qui valide la session et **injecte**
  `X-SW-Auth-Email`/`X-SW-Auth-Name`.

Repli documenté : si Open WebUI gère mal le base-path `/chat`, basculer sur sous-domaine
`chat.scienceswiki.eu` avec cookie `Domain=.scienceswiki.eu` (à trancher à l'étape 2).

### 6.3 SSO — forward-auth « trusted header » (reco maintenue), comparée à OIDC
L'API est **JWT stateless, sans OIDC** (`security.yaml`). Le **front web détient déjà la session**
authentifiée (JWT en session PHP, `UserApiClient`). Donc :

**Forward-auth (RETENU).** Open WebUI fait confiance à un header e-mail de confiance posé par le
reverse proxy. L'autorité de session **existe déjà = le front web**. Mise en œuvre **portée par le
front** : endpoint `GET /sso/verify` côté **web** qui lit la session et répond `204 + X-SW-Auth-*`
(ou `401`). Heimdall, sur `/chat/*`, exécute `auth_request` vers cet endpoint et recopie les headers
(`auth_request_set`) vers Open WebUI. Création auto du compte Open WebUI au 1er passage (clé =
e-mail, identifiant **immuable** côté mapping ; purge des comptes orphelins en audit). **Effort
faible** (~1 endpoint + ~10 lignes nginx + 2 vars), **zéro composant à maintenir**, **zéro secret
long terme**.

**OIDC complet (option, reportée — YAGNI).** Ajouter un IdP (`league/oauth2-server`+OIDC, ou
Authelia/Keycloak) : endpoints `/authorize`/`/token`/`/userinfo`/JWKS/discovery, rotation de clés,
écrans de consentement. **Plusieurs jours + maintenance permanente + plus grande surface
d'attaque.** Ne se justifie que si **plusieurs services tiers** consomment le SSO. Pas le cas → on
documente comme évolution.

**Recommandation : forward-auth.** Sa seule vraie faiblesse (usurpation de header) est **entièrement
neutralisée** par l'isolation réseau (déjà une règle homelab).

### 6.4 Sécurité réseau (Marvin jamais public)
- Ollama (11434), embeddings (8001), base (5432) restent **LAN privé** ; **aucun** `location`
  Heimdall ne route vers Marvin. Au pilote, **Open WebUI n'a même pas besoin de joindre Marvin**
  (il ne parle qu'à `/api/rag/chat`).
- Open WebUI (Thor:8082) **bind loopback/LAN**, joignable **uniquement par Heimdall**.
- **Anti-usurpation header (critique).** Heimdall **efface** systématiquement les `X-SW-Auth-*`
  **entrants** (`proxy_set_header X-SW-Auth-Email "";`) puis les **repositionne** via
  `auth_request_set` — **jamais** de passthrough du header client. Test d'intrusion obligatoire
  (étape 2) : forger `X-SW-Auth-Email: admin@…` depuis le navigateur ⇒ **doit être ignoré**.

### 6.5 Quotas (choke-point GPU)
- **Auth obligatoire** ⇒ traçabilité par e-mail.
- Rate-limit **dans `/api/rag/chat`** (Symfony RateLimiter, N req/h par `X-SW-Auth-Email`) — c'est
  **notre** choke-point GPU, pas Open WebUI. `429` propre au dépassement.
- **Famine GPU** : l'`analysis-worker` partage l'unique GPU Marvin. Chat = **modèle léger par
  défaut** (`ai.light_model`=`llama3.1:8b`), modèle lourd réservé `RESEARCHER`/`ADMIN`.
  `max_tokens`/`temperature` plafonnés via `SettingsService`.

### 6.6 CSP `/chat`
Open WebUI sert **son propre HTML** : la CSP nonce du front (`CspSubscriber`) ne s'y applique pas.
Poser une **CSP dédiée** dans le `location /chat/` (`script-src 'self'`, `connect-src 'self'`
incluant `wss:` même origine, éviter `unsafe-eval` si Open WebUI le tolère). **Ne pas relâcher** la
CSP stricte du reste du site.

### 6.7 RGPD
- `openwebui_data` stocke les **historiques de chat** (potentiellement sensibles) → **rétention/
  purge**, mention en politique de confidentialité, **suppression liée** à la suppression du compte
  SciencesWiki.
- E-mail en header **interne** (LAN, derrière TLS Heimdall) — acceptable.
- Audit minimal côté API (e-mail, horodatage, nb tokens) — **pas** le texte des prompts en clair.
- `openwebui_data` ajouté au périmètre de **backup**.

---

## 7. Sécurité transversale

| Domaine | Mesure |
|---|---|
| Moindre privilège connecteur RAG | Open WebUI appelle `/api/rag/chat` avec un compte/JWT **de service, lecture seule** ; l'identité utilisateur (`X-SW-Auth-Email`) sert au **quota/audit**, pas au droit de lire. |
| Posture licence | Prompt = **résumé tronqué (≈700 c.) + métadonnées + DOI** (posture `PromptBuilder` actuelle). Le **plein-texte** (`publication_chunk`) sert **au retrieval uniquement**, **jamais** restitué dans la réponse. Invariant **testé** (étape 2/3). |
| Secrets | `OPENWEBUI_SECRET_KEY` (`openssl rand -hex 32`), JWT de service, en `.env.prod` (coffre `secrets_vault`), jamais commités, jamais dans l'image. |
| Isolation réseau | Marvin (11434/8001/5432) jamais public ; Open WebUI LAN-only ; règle homelab maintenue. |
| Surface bundle | Le bundle est jeune : épingler des **versions exactes**, suivre les advisories, ne pas activer de bridge non utilisé. |

---

## 8. Risques & garde-fous

| Risque | Impact | Garde-fou |
|---|---|---|
| **Régression silencieuse** (aucun test fonctionnel aujourd'hui) | Élevé | Chaque étape **livre ses tests** ; strangler (ancien chemin vivant jusqu'à parité) ; diff ancien/nouveau sur corpus de référence (étape 3). |
| **Bundle Symfony AI jeune** (API instable, doc mince) | Moyen-élevé | Versions **épinglées** ; le pilote (étape 1) isole le risque **avant** tout branchement prod ; abstractions custom (`SciencesWikiStore`, `MlEmbedPlatform`) testables hors bundle. |
| **Store custom diverge de `nearestTo`** | Moyen | Le Store **délègue** à `nearestTo` (pas de réimplémentation) ; test d'égalité ids/ordre (étape 1). |
| **Vecteurs requête ≠ corpus** (si on bridge mal l'embed) | Élevé (seuil 0,62 faussé) | `MlEmbedPlatform` appelle **le même** `/embed` (même modèle, L2) ; test : `embed(q)` identique via bundle et via `HttpEmbeddingClient`. |
| **Usurpation header SSO** | Élevé | Open WebUI LAN-only + Heimdall **efface** `X-SW-Auth-*` entrants + `auth_request_set`. Test d'intrusion étape 2. |
| **Fuite contenu sous licence** | Élevé (juridique) | Résumé tronqué + DOI uniquement ; plein-texte = retrieval interne. |
| **Famine GPU** (chat vs analysis-worker) | Moyen | Modèle léger par défaut + quotas + un seul GPU Marvin. |
| **Réponses non sourcées / hallucinations** | Moyen | Garde-fous conservés : distance ≤ 0,62 + « aucune note `[n]` ⇒ pas de réponse ». |
| **Open WebUI → Ollama direct** (chemin non sourcé) | Moyen | `ENABLE_OLLAMA_API=false` ; seul `/api/rag/chat` exposé comme modèle. |
| **Tentation TransformersPHP transparente** | Moyen | Documenté : ≠ au bit près ⇒ ré-indexation (Voie C) ; **pas** un swap transparent. |
| **Image Open WebUI `:main` instable** | Moyen | Tag versionné épinglé, staging avant prod. |
| **RGPD historiques** | Moyen | Rétention/purge + suppression liée au compte. |

---

# RÉVISION POINT PAR POINT (détails d'implémentation challengés)

Le **cadrage** est validé ; je challenge les **détails**. Chaque point : *est-ce le plus simple /
fiable / sûr ? alternative ?*

**§3.1 — Le Store custom est-il vraiment le plus simple ?**
*Challenge :* écrire un `StoreInterface` n'est-il pas plus de travail que d'utiliser le store natif ?
*Réponse :* le store natif (Voie B) **n'a pas** la fusion abstract+chunks ni le filtre rétractations ;
l'utiliser imposerait de **recopier ~3,46 M lignes** *puis* de **réécrire** la logique de requête par
dessus. Le Store custom, lui, est une **façade de ~1 classe** qui délègue à un `nearestTo()` déjà
écrit et testé. Il est **strictement plus simple** ET conserve la qualité. **Maintenu : Voie A.** ✅
*Ajustement :* `add()` non implémenté au pilote (read-only) — on ne fait pas semblant d'indexer.

**§3.1 — Bridger `/embed` (non-OpenAI) plutôt qu'exposer un `/embed` OpenAI-compatible ?**
*Challenge :* ne serait-il pas plus « standard » de rendre `ml/app.py` compatible OpenAI
(`/v1/embeddings`) pour le brancher comme Platform native ?
*Réponse :* modifier `ml/app.py` toucherait un service **en prod** utilisé par les moissons → risque
hors périmètre pilote. Un `MlEmbedPlatform` de ~30 lignes qui enveloppe `HttpEmbeddingClient`
**existant** est plus sûr et plus rapide, et **n'impacte pas** la moisson. **Maintenu : bridge.**
Rendre `/embed` OpenAI-compatible reste une **amélioration future** (utile si d'autres consommateurs
arrivent), pas un prérequis. ✅

**§3.3/§3.4 — TransformersPHP « pour virer Python » : tentant, dangereux.**
*Challenge :* retirer le microservice Python simplifierait l'infra, pourquoi attendre ?
*Réponse :* parce que les vecteurs **déjà stockés** ne seraient **pas reproduits à l'identique** →
le seuil 0,62 et 3,46 M vecteurs deviendraient incohérents ⇒ **ré-indexation forcée (Voie C)**. Le
« gain infra » cache un **coût de ré-embed** masqué. **Décision : pas au pilote ;** seulement si on
décide par ailleurs un Voie C (changement de modèle). ✅

**§5 — Les efforts sont-ils sous-estimés ? (point crucial.)**
*Challenge :* « 22–36 j » paraît optimiste pour un bundle jeune et **zéro test fonctionnel**.
*Réponse honnête :* le risque principal **n'est pas** le RAG (briques existantes) mais (a) la
**maturité du bundle** (API qui bouge, doc mince → temps d'apprivoisement, étape 1 volontairement
gonflée à 3–5 j) et (b) **l'écriture des tests de non-régression qui n'existent pas** (étape 3 à
5–8 j surtout pour *prouver la parité*, pas pour coder l'Agent). Les fourchettes **intègrent** ces
deux risques, mais l'**étape 3 (migration Q/R) peut déraper** si le parsing des sections
(TITRE/VULGARISATION/ACADEMIQUE) + Footnotes diverge subtilement. *Ajustement :* prévoir un **buffer
explicite** et **ne pas supprimer `StreamAnswerController`/`AnswerDrafter`** avant 2 semaines de
parité observée en prod (feature flag). Honnêtement : **borne haute (36 j) plus probable que la
basse** si on découvre des limites du bundle. ✅ (avec réserve assumée)

**§5 étape 1 — Pilote isolé : vraiment aucun impact ?**
*Challenge :* installer 5 paquets ne risque-t-il pas de casser l'autowiring/la prod ?
*Réponse :* `composer require` + config peut introduire des services/conflits. *Garde-fou :*
installer et valider l'étape 1 **sur une branche**, lancer la **suite de tests unitaire existante**
(elle doit rester verte) + un `cache:warmup` avant tout merge. ✅

**§6.1 — Retirer l'accès Ollama direct d'Open WebUI : régression de fonctionnalité ?**
*Challenge :* l'utilisateur perd le « chat libre » non sourcé.
*Réponse :* au pilote, on **veut** que tout passe par `/api/rag/chat` (quota, audit, garde-fous). Le
« chat libre » reviendra **proprement** comme **second Agent côté API** (toujours via `/api`),
préservant choke-point unique. Exposer Ollama à Open WebUI créerait un chemin **hors gouvernance**.
**Maintenu : `ENABLE_OLLAMA_API=false`.** ✅ (durcissement vs spec initiale)

**§6.3 — Forward-auth est-il *sûr* ? (objection sécurité centrale.)**
*Challenge :* « faire confiance à un header » est un anti-pattern classique (usurpation).
*Réponse :* l'anti-pattern, c'est de faire confiance à un header **routable depuis l'extérieur**.
Ici : Open WebUI est **LAN-only**, Heimdall **efface** l'entrant et **repose** le header lui-même
après `auth_request`. Le seul moyen d'usurper serait d'**atteindre Open WebUI sans passer par
Heimdall** — ce que le pare-feu/bind loopback interdit (même posture qu'`adminer`, déjà en prod).
Reste **un** point dur à **tester explicitement** (étape 2, forge de header). **Sûr *sous condition*
de l'isolation réseau** — qui est une règle déjà en vigueur. Si cette règle venait à faiblir, OIDC
deviendrait nécessaire. ✅ (sûr, mais dépendant de l'isolation — clairement documenté)

**§6.3 — Forward-auth porté par le *front web* plutôt qu'un endpoint *API* ?**
*Challenge :* pourquoi le web et pas l'API ?
*Réponse :* l'autorité de **session** est le **front** (il détient le JWT en session PHP). L'API est
**stateless** (pas de session). Mettre `/sso/verify` côté API obligerait à re-transmettre/valider un
JWT ; côté web, on **lit la session existante** — chemin le plus court, zéro duplication. **Maintenu :
front.** ✅

**§6.5 — Quota dans l'API et non Open WebUI : redondant ?**
*Challenge :* Open WebUI a ses propres limites.
*Réponse :* le choke-point GPU réel est `/api/rag/chat`. Le quota **doit** être au plus près de la
ressource → API. Open WebUI peut limiter **en plus** (défense en profondeur). **Maintenu : quota
API.** ✅

**§4 — Un *Agent* du bundle vs réutiliser directement `RagRetriever`+`OpenAiCompatibleLlmClient` ?**
*Challenge :* puisque tout existe, l'Agent du bundle n'est-il pas une couche en trop au pilote ?
*Réponse :* au strict pilote, on **pourrait** câbler l'endpoint sur les briques existantes en 1 jour.
Mais l'**objectif validé** est l'adoption du bundle ; l'étape 2 est précisément là pour **prouver
que l'Agent du bundle marche** sur un cas réel avant d'y migrer le reste. *Ajustement pragmatique :*
si le bundle bloque à l'étape 2, **livrer un fallback** « endpoint OpenAI-compatible sur les briques
existantes » pour ne pas retarder Open WebUI, et y revenir. Le pilote ne doit pas être **otage** de
la maturité du bundle. ✅ (chemin de repli prévu)

**§6.2 — `/chat` même domaine vs sous-domaine.**
*Challenge :* un sous-domaine isolerait mieux cookies/CSP.
*Réponse :* même domaine = cookie de session **partagé** (clé du SSO simple), pas de CORS, un seul
cert. **Maintenu : `/chat`,** repli sous-domaine `Domain=.scienceswiki.eu` si base-path mal géré. ✅

**Décision transverse — Open WebUI plutôt qu'une UI Twig maison ?**
*Challenge :* le front a déjà le SSE RAG du wiki ; pourquoi un composant de plus ?
*Réponse :* le besoin est la **conversation** (multi-tours, historique, multi-modèles, UX éprouvée) —
réécrire ça en Twig coûterait bien plus. Pour « 1 question → 1 réponse sourcée », le flux wiki suffit
déjà ; Open WebUI cible le **chat**. **Maintenu : Open WebUI.** ✅

---

## Tableau récapitulatif des décisions finales

| Sujet | Décision finale | Pourquoi (simple / fiable / sûr) |
|---|---|---|
| **Couche IA cible** | **Adoption native du bundle Symfony AI** (`ai-platform`/`ai-agent`/`ai-store`/`ai-bundle`), **incrémentale**, pilote = chat | Cadrage validé ; converge toute l'IA vers des abstractions standard sans big-bang |
| **Indexation** | **Voie A — Store custom** (`SciencesWikiStore` délègue à `nearestTo`), **zéro ré-embed** | Réutilise 3,46 M vecteurs + fusion + filtres ; seuil 0,62 valide ; source unique |
| Voie B (ETL natif) | **Écartée** (sauf besoin d'index séparé) | Copie 3,46 M lignes + double source de vérité, zéro gain |
| Voie C (ré-embed) | **Conditionnelle** : seulement si MiniLM mesuré insuffisant, hors charge | Coût GPU + recalibrage 0,62 ; décision produit, pas d'adoption |
| Embedding requête | **`MlEmbedPlatform`** bridge le service `/embed` **existant** (même modèle, L2, 384) | Vecteurs requête identiques au corpus ; n'impacte pas la moisson |
| TransformersPHP | **Ultérieur, lié à Voie C** (≠ au bit près ⇒ ré-indexation) | Pas un swap transparent du service Python |
| Endpoint chat | **`POST /api/rag/chat`** OpenAI-compatible, SSE, porté par un **Agent** du bundle ; **fallback** sur briques existantes si bundle bloque | Pilote prouvant le bundle, sans otage de sa maturité |
| Garde-fous | **Conservés partout** : seuil **0,62**, « aucune note `[n]` ⇒ pas de réponse », prompts FR, modèles via `SettingsService` | Invariants testés à chaque étape |
| Migration existant | **Strangler** : ancien chemin vivant jusqu'à **parité prouvée** (flag, diff de sortie) | Couvre l'absence totale de test fonctionnel |
| Tests | **Chaque étape livre ses tests** (unitaires bundle + `WebTestCase` endpoint + non-régression Q/R) | Aucun test fonctionnel aujourd'hui = risque n°1 |
| Open WebUI | **Sur Thor**, `/chat`, bind LAN, tag épinglé, `openwebui_data` sauvegardé | Même cycle/réseau que l'API ; pas de surface web sur le nœud GPU |
| Open WebUI ↔ Ollama | **Désactivé** (`ENABLE_OLLAMA_API=false`) ; seul modèle = `/api/rag/chat` | Pas de chemin non sourcé / hors quota |
| **SSO** | **Forward-auth** « trusted header » **porté par le front web** ; login/signup Open WebUI off | Réutilise la session existante ; zéro composant/secret long terme |
| Anti-usurpation | Open WebUI **LAN-only** + Heimdall **efface** `X-SW-Auth-*` entrants + `auth_request_set` ; test d'intrusion | Neutralise la seule faiblesse du forward-auth |
| OIDC | **Reporté** (YAGNI) | Justifié seulement si plusieurs services tiers |
| RAG natif Open WebUI | **Désactivé** (upload/web search off) | Évite corpus parallèle + fuite licence |
| Posture licence | Résumé tronqué (~700 c.) + métadonnées + **DOI** ; plein-texte = retrieval seul | Posture wiki déjà en prod |
| Quotas | Rate-limit **dans `/api/rag/chat`** par e-mail + modèle léger par défaut | Choke-point au plus près du GPU ; protège `analysis-worker` |
| Isolation Marvin | 11434 / 8001 / 5432 **jamais publics** ; aucun `location` vers Marvin | Règle homelab maintenue |
| CSP `/chat` | CSP **dédiée** au `location /chat/`, sans relâcher la CSP du reste du site | Isole la contrainte d'Open WebUI |
| RGPD | Rétention/purge historiques + suppression liée au compte | Conformité |
| Effort total | **~22–36 j-dev**, borne haute plus probable | Maturité bundle + écriture des tests absents |

---

*Fin de spec. Le cadrage (bundle natif, incrémental, Voie A) n'est pas rediscuté ; les détails
d'implémentation ont été challengés un par un (Store custom = façade `nearestTo` ; bridge `/embed`
plutôt que modifier `ml/app.py` ; Ollama direct retiré d'Open WebUI ; forward-auth sûr sous
isolation réseau ; efforts honnêtement bornés avec fallback bundle et strangler pour couvrir
l'absence de tests fonctionnels).*
