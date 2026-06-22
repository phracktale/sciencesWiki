# SciencesWiki — Architecture améliorée (passage à l'échelle du plein texte)

> Spec de travail, juin 2026. Objectif : indexer une part utile de la littérature
> scientifique (résumés en masse + texte intégral curé) **sans** stocker les
> corpus bruts (250 To PDF / 20 To TEI), avec le matériel du homelab.

## 1. Contraintes mesurées (état réel)

| Élément | Valeur |
|---|---|
| **Marvin** | 24 threads (Ryzen AI 9 HX 370, Zen5), **128 Go RAM**, `/data` 1,8 To (**1,7 To libre**), +4 To à installer |
| Marvin tourne déjà | `ml-embeddings` (sentence-transformers MiniLM 384d), `open-webui`, Ollama (LLM) |
| **Thor** | api + web + worker + PostgreSQL/pgvector (à migrer vers Marvin) |
| **Heimdall** | nginx + TLS (exposition publique) |
| Base actuelle | **477 022** publications · 91 695 fragments · **2 050** en texte intégral · **2,8 Go** |
| Coût/octet observé | métadonnées+résumé+1 vecteur ≈ **6 Ko/article** ; texte intégral ≈ **~45 fragments/article** |
| OpenAlex métadonnées | **gratuit**, ~100 000 req/j, 200 résultats/req → dizaines de milliers d'articles/j |
| OpenAlex contenu (PDF/TEI) | **0,01 $/fichier**, ~100/j gratuits — *plafond réservé à ce seul service* |
| Corpus TEI GROBID dispo | ~35 M (physique 11,4 · santé 10,9 · social 8,0 · vie 5,1) — tous OA |

## 2. Principes directeurs

1. **Découpler la taille de la source de la taille de l'index.** On ne stocke
   jamais le PDF/TEI brut : on en extrait des fragments + vecteurs, puis on **jette**
   le fichier. Les 20–250 To restent chez OpenAlex/éditeurs.
2. **Gratuit d'abord.** Métadonnées+résumés = gratuit et massif. Le texte intégral
   se récupère prioritairement via le **PDF OA éditeur + GROBID auto-hébergé**
   (gratuit, borné par le CPU), pas via l'API contenu payante.
3. **Curer, pas tout aspirer.** On classe par `fwci`/`cited_by_count`, on filtre
   `has_content.grobid_xml:true` + périmètre (domaines couverts), et on ne traite
   le texte intégral que du **haut du panier** + à la demande (paresseux).
4. **Conserver l'URL, jeter le fichier.** On garde `oa_url` + `landing_page_url`
   + les fragments + vecteurs. Jamais le PDF.
5. **Réutiliser l'existant.** Schéma, pipeline (mapper → importeur dédup → embeddings
   → placement), RAG : **conservés**. On ajoute des colonnes et on change la
   *stratégie d'alimentation* + la *source* + l'*extraction* (GROBID).

## 3. Vue d'ensemble des flux

```
                       ┌──────────────────────────────────────────────┐
                       │ OpenAlex                                       │
                       │  • API /works (métadonnées+résumé) — GRATUIT   │
                       │  • snapshot S3 (métadonnées) — GRATUIT         │
                       │  • content API (PDF/TEI) — 0,01$/fichier       │
                       └───────────────┬──────────────────────────────┘
                                       │ (1) moisson métadonnées (massif, gratuit)
                                       ▼
   éditeurs OA ──(PDF gratuit)──►  WORKER (Thor)                       
        ▲                          • import dédup (DOI)                 
        │ (3a) fetch PDF           • RawPublication → publication       
        │     poli                 • file « plein texte à faire »       
        │                                   │                           
        │                                   ▼                           
   ┌────┴───────────┐            GROBID (Marvin)  ──TEI──►  extraction sections
   │ API contenu    │ (3b)        (auto-hébergé)                 │       
   │ OpenAlex 0,01$ │ filet                                      ▼       
   └────────────────┘                       chunking → EMBEDDINGS (Marvin) 
                                                          │              
                                                          ▼              
                                   PostgreSQL/pgvector (Marvin) : 
                                   publication + journal/publisher + 
                                   publication_chunk(vector) + URLs 
                                   [PDF/TEI bruts JETÉS]            
                                                          │              
                                                          ▼              
                                   RAG (kNN pgvector) → LLM (Ollama/Marvin) → réponses
```

## 4. Paliers de données

| Palier | Contenu | Source | Coût | Volume cible | Stockage estimé |
|---|---|---|---|---|---|
| **0 — Largeur** | métadonnées + **résumé** + 1 vecteur | API/snapshot (gratuit) | 0 | périmètre couvert (1–5 M) | ~6 Ko/article → **6–30 Go** |
| **1 — Profondeur curée** | texte intégral (fragments+vecteurs) | PDF éditeur → **GROBID** | 0 (compute) | top-cités/OA par sous-domaine (100 k–1 M) | ~150 Ko/article → **15–150 Go** |
| **2 — Paresseux** | texte intégral à la demande | éditeur→GROBID, sinon API contenu | ~0 / 0,01$ filet | seulement les articles **réellement consultés** | négligeable, croît à l'usage |

Curation palier 1 = requête **gratuite** `sort=cited_by_count:desc` +
`filter=primary_topic.{domain|field|subfield}.id:…,has_content.grobid_xml:true`
+ `select=id` → liste classée → on traite le top-N budgété.

## 5. Acquisition du texte intégral — 3 voies

1. **PDF OA éditeur + GROBID auto-hébergé** *(voie principale, gratuite)*
   - Récupère le PDF direct (`best_oa_location.pdf_url`, ou découverte `citation_pdf_url`
     sur la landing — déjà implémenté), **anti-SSRF** (déjà durci).
   - Envoie à **GROBID** (service Java sur Marvin) → **TEI structuré** (titre, sections,
     références) → bien meilleur que `pdftotext`.
   - Borné par le **CPU** et la **politesse éditeur**, pas par un quota OpenAlex.
2. **API contenu OpenAlex** *(filet, 0,01 $/fichier, ~100/j gratuit)*
   - Pour les articles où le PDF éditeur n'est pas récupérable directement mais
     `has_content.grobid_xml:true`. On tire le **TEI** (pas le PDF) par ID.
3. **Archive R2 complète** — écartée (coût entreprise + 20 To non stockables/inutiles).

## 6. Capacité GROBID sur Marvin (chiffrage)

Hypothèses : modèles CRF (Wapiti) de GROBID (CPU, pas de GPU requis), ~moitié des
threads dédiés (12), le reste pour embeddings/Ollama.

| Étage | Débit réaliste | Par jour |
|---|---|---|
| **GROBID** (parsing TEI) | ~2–4 PDF/s soutenu | **~170 000–340 000/j** (capacité brute) |
| **Embeddings** (MiniLM CPU, ~45 frags/article) | ~3–5 articles/s | ~250 000–430 000/j |
| **Goulot réel = fetch PDF poli** (multi-hôtes, ~15–47 % de PDF directs) | — | **~5 000–20 000/j** *soutenable proprement* |

→ **GROBID n'est PAS le facteur limitant** : il peut parser bien plus que ce qu'on
peut **télécharger poliment**. On dimensionne donc le palier 1 sur **~5 000–20 000
articles/jour** (vs **100/j** par l'API contenu) — **50 à 200×** plus, gratuitement.
RAM : GROBID ~4–8 Go, embeddings ~2–4 Go → confortable sur 128 Go.

## 7. Stockage & index vectoriel (budgets)

- **pgvector** reste le moteur (déjà en place). Optimisations :
  - **`halfvec(384)`** (float16) → **÷2** disque **et** RAM d'index, qualité quasi nulle perte.
  - Index **HNSW** : ~vecteurs en RAM. Budget : 1 M articles × 45 frags = **45 M vecteurs**
    × 1,5 Ko = **~68 Go RAM** (float32) / **~34 Go** (halfvec) → **tient sur 128 Go**.
- **Bascule Qdrant** seulement au-delà de ~1–2 M articles plein texte (quantization
  PQ/binaire → ÷8 à ÷32 RAM). Postgres garde alors les métadonnées.
- **Budgets disque** (sur 1,7 To + 4 To) :
  - Palier 0 à 5 M résumés ≈ **30 Go**.
  - Palier 1 à 1 M plein texte ≈ **150 Go** (halfvec ~100 Go).
  - **Très large marge** — le disque n'est pas la contrainte ; la RAM d'index l'est.

## 8. Modèle de données — ajouts (additif, pas de réécriture)

Sur `publication` (migration unique) :

| Colonne | Type | Source OpenAlex |
|---|---|---|
| `cited_by_count` | INT | `cited_by_count` |
| `fwci` | DOUBLE PRECISION NULL | `fwci` |
| `type_crossref` | VARCHAR(64) NULL | `type_crossref` |
| `referenced_works_count` | INT | `referenced_works_count` |
| `has_pdf` | BOOLEAN | `has_content.pdf` |
| `has_grobid_xml` | BOOLEAN | `has_content.grobid_xml` |
| `any_repo_fulltext` | BOOLEAN | `open_access.any_repository_has_fulltext` |
| `fulltext_source` | VARCHAR(16) NULL | 'grobid_self' / 'openalex_api' / 'author' / 'publisher' |

Conservé tel quel : `doi, title, abstract, publication_date, language, type,
oa_status, oa_url, landing_page_url, license, retraction_status, journal_id,
author_pdf_at` + tables `journal`/`publisher` + `publication_chunk(vector)`.

`publication_chunk` : `embedding vector(384)` → **`halfvec(384)`** ; ajouter
`section VARCHAR NULL` (titre de section TEI, pour un chunking « par section »).

## 9. Pipelines & cadence

- **Moisson métadonnées** (gratuite, massive) : worker Messenger, par sous-domaine,
  reprise par curseur. Filtre `has_content.grobid_xml:true` recommandé pour cibler.
- **Curation** : commande `app:fulltext:curate --domain=… --top=N` → sélectionne les
  N meilleurs (fwci/citations) sans texte intégral → les pousse dans la file plein texte.
- **Ingestion plein texte** : worker dédié → fetch PDF poli → GROBID → chunks → embeddings
  → `publication_chunk` → **jette PDF/TEI**. Borné/run, débit poli configurable.
- **Plein texte paresseux** : au moment de répondre, si une source pertinente n'a pas
  de texte intégral, déclencher son ingestion (asynchrone) + cache.
- **Crons** (à réactiver après bascule) : embed-drain, curate, reap-stale, retractions.

## 10. Plan de migration (phases)

1. **Geler** (fait) : workers + crons en pause.
2. **Disque 4 To sur Marvin** → volume `/data2` (ou étendre `/data`).
3. **Migrer PostgreSQL Thor → Marvin** (dump/restore ; `DATABASE_URL` des services
   api/worker pointe vers Marvin sur le réseau privé). Marvin = nœud données+IA.
4. **Service GROBID** sur Marvin (`grobid/grobid:0.8.x`, port interne, modèles CRF).
5. **Migration colonnes** (§8) + mapper OpenAlex enrichi (lit les nouveaux champs).
6. **`halfvec`** sur `publication_chunk.embedding`.
7. **Nouvel ingesteur plein texte** : PDF→GROBID (remplace/complète `pdftotext`),
   conserve le chunking+embeddings+anti-SSRF existants.
8. **Commande de curation** + réactivation des crons à débit poli.
9. (Optionnel, plus tard) **Qdrant** si > 1–2 M plein texte.

## 11. Conservé vs nouveau

- **Conservé** : entités/migrations, mapper/importeur/dédup, embeddings par lots,
  RAG kNN (`nearestTo` UNION résumé+chunks), exclusion rétractations, explorateur
  d'articles, dépôt version auteur tokenisé, BO, moisson par rubrique.
- **Nouveau** : DB sur Marvin, **service GROBID**, ingesteur PDF→TEI structuré,
  6+ colonnes métadonnées, `halfvec`, commande de curation top-cités, plein texte
  paresseux, (option) connecteur snapshot S3 pour moissonner sans API.

## 12. Risques & garde-fous

- **Politesse éditeurs** : limiter le débit par hôte, `User-Agent` contact, respecter
  robots/headers ; sinon bannissement IP. → débit configurable + backoff.
- **RAM d'index** : surveiller ; basculer halfvec puis Qdrant avant saturation.
- **Qualité TEI** : GROBID échoue sur certains PDF (scans image) → repli `pdftotext`,
  sinon résumé seul.
- **Coût API contenu** : le filet 0,01 $/fichier reste plafonné par le quota gratuit ;
  ne jamais lancer `openalex download` sur un filtre large non borné.
