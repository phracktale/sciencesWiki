# Architecture du système SciencesWiki

> Vue d'ensemble à jour (acquisition → enrichissement → analyse → restitution) et des
> **couches de fiabilité** de l'IA. Compléments : [infra-marvin.md](infra-marvin.md)
> (machines), [deploiement.md](deploiement.md) (mise en prod),
> [fiabilite-ia.md](fiabilite-ia.md) (preuves anti-hallucination).

---

## 1. Topologie

| Nœud | Rôle |
|---|---|
| **Thor** (192.168.1.36) | Application : **API Symfony 8 / FrankenPHP**, **front web Twig**, **Open WebUI**, workers Messenger, crons. |
| **Marvin** (192.168.1.171) | Données + IA : **PostgreSQL/pgvector**, **Ollama** (LLM, GPU), **embeddings** (MiniLM), **GROBID** (PDF→texte), **HHEM** (garde-fou), snapshot OpenAlex. |
| **Heimdall** (192.168.1.195) | Reverse-proxy **nginx** (TLS, SSO forward-auth, vhosts publics). |

Seul Thor (via Heimdall) est public ; Marvin reste sur le réseau privé.

## 2. Pipeline de données

```
ACQUISITION ──► IMPORT ──► ENRICHISSEMENT ──► ANALYSE ──► RESTITUTION
```

### 2.1 Acquisition (3 sources)
- **Snapshot OpenAlex** (mensuel, local) → `app:openalex:ingest-snapshot`, sélectif
  (taxonomie + qualité). **Voie privilégiée** : pas de quota API, pas de paywall.
- **Moisson API** (arXiv, PubMed, OpenAlex) → workers `harvester`. *En voie de retrait*
  au profit du snapshot pour les métadonnées (cf. transition « snapshot-first »).
- **Upload PDF admin** → `AdminPdfUploadController` (source absente d'OpenAlex, ou plein
  texte d'un PDF en main).

> Ce qu'apporte chaque source : OpenAlex = **titre + résumé + métadonnées** (PAS le plein
> texte) ; **GROBID** = le **plein texte** (extrait des PDF open access). Voir §2.3.

### 2.2 Import — dédoublonnage (`Deduplicator`, `PublicationImporter`)
Dédup **exact** par DOI puis par identifiant externe (`external_ids ->> 'openalex'…`),
**indexé** (sinon scan séquentiel = O(n²), ingérable à l'échelle OpenAlex). Provenance
tracée (source, idInSource).

### 2.3 Enrichissement (drains découplés)
| Étape | Mécanisme | Produit |
|---|---|---|
| **Embeddings** | service MiniLM 384-dim (Marvin) ; `harvester:embed` par lots | vecteur `publication.embedding` |
| **Plein texte** | PDF OA → **GROBID** → `FulltextIngester` | fragments `publication_chunk` (+ embeddings) |
| **Placement** | kNN cosinus contre l'arbre (`tree_node`) | suggestions de rattachement (validation humaine) |
| **Empreintes plagiat** | MinHash/LSH des chunks | `chunk_fingerprint` (+ bandes LSH) |

### 2.4 Analyse (namespace `App\Analysis`)
- **Controverses & lacunes** : extraction de *claims*, clustering, désaccords.
- **Plagiat / doublons** (`App\Analysis\Plagiarism`) : rappel LSH → confirmation Jaccard →
  `DuplicationFinding` (non décisionnel, validé par le comité).
- **Rétractations** : `retraction_status` (signal d'intégrité).

### 2.5 Restitution
- **Chat RAG** (Open WebUI, contrat OpenAI) : récupération hybride + garde-fous +
  **locator** (extrait derrière chaque [n]).
- **Articles wiki** (`app:wiki:generate`, ou bouton admin du wiki public) : rédaction IA
  ancrée corpus, vérifiée.
- **Q/R** : rédaction streamée (`StreamAnswerController`), persistée avec notes de bas de page.

## 3. Stockage

PostgreSQL/pgvector (sur Marvin, disque `/data`) :
- `publication` (métadonnées, `embedding vector(384)`, `external_ids` JSON, `oa_url`…),
  `publication_chunk` (plein texte + `embedding`), `tree_node` (taxonomie + embedding).
- Index : **HNSW** (recherche sémantique kNN), **GIN** (FTS lexicale), expression
  (`external_ids ->> clé`), partiels (files embed/placement), **LSH** (`chunk_fingerprint_band`).
- `duplication_finding`, `chunk_fingerprint`, `answer`/`article_revision`, etc.

## 4. Couches de fiabilité de l'IA (résumé)

1. **Génération contrainte** : RAG strict, abstention obligatoire hors sources.
2. **Attribution** : locator (passage source par citation).
3. **Vérification de fidélité** : `FaithfulnessChecker` (LLM-juge) → **HHEM** (détecteur NLI
   dédié) en primaire ; marqueurs `[réf. nécessaire]`.
4. **Récupération** : hybride vecteur + lexical (RRF) → meilleur *context recall*.
5. **Qualité du corpus** (« juge de la vérité ») : controverses, rétractations, **antiplagiat**.
6. **Liens vérifiables** : promotion des marqueurs en liens Wikipédia réels (jamais inventés).

→ Détail, preuves et limites : **[fiabilite-ia.md](fiabilite-ia.md)**.

## 5. Asynchrone (Messenger)

Files **isolées** (une lourde ne doit pas affamer les autres) : `harvester`, `fulltext`,
`analysis` (controverses + génération d'article à la demande), `plagiarism`. DSN Doctrine
(table de file), workers dédiés, `redeliver_timeout` long pour les tâches > 1 h.
