# Serveur RAG (Retrieval-Augmented Generation) auto-hébergé

> **Rattaché à :** `docs/specifications.md` (§6.3 IA, §8.2 rédaction).
> **Statut :** brouillon v0.1.
> **Principe :** auto-hébergé, open source, **sourcé par construction**.

---

## 1. Pourquoi un serveur RAG

Une plateforme de vulgarisation **scientifique** ne peut pas se permettre une IA
qui « invente ». Le RAG répond précisément à ça :

- **Ancrage (grounding)** : la génération s'appuie sur des **passages réellement
  récupérés** dans le corpus moissonné → **chaque affirmation porte ses sources
  (DOI)**. C'est l'exigence même du *bloc académique* (cf. spec §8.1).
- **Anti-hallucination** : le LLM reformule/explique des passages cités, il
  n'invente pas de faits.
- **Unification** : un seul service réunit ce qui était dispersé — embeddings
  (déjà prévus en Phase 1), **recherche sémantique**, **aide au placement**, et
  **rédaction des brouillons** de vulgarisation.
- **Souveraineté** : 100 % **auto-hébergé**, modèles **open source** (cohérent
  avec les décisions de la spec).

---

## 2. Ce que le serveur RAG dessert

> Modèle éditorial = **vulgarisation pilotée par les questions** (cf. spec §8).
> L'unité produite est une **Q/R** rattachée à un nœud, pas un article par
> publication.

1. **Suggestion de questions** : pour un nœud, le RAG propose **quelques questions
   évidentes** tirées du corpus du nœud (questions canoniques candidates).
2. **Rédaction de réponses sourcées** : pour une question (suggérée ou libre), il
   récupère les passages pertinents et génère une **réponse vulgarisée** avec
   **notes de bas de page (DOI)**.
   - question **suggérée** → brouillon **pré-rédigé** soumis au **comité** ;
   - question **libre** → réponse **à la volée**, publique avec **bandeau « non
     relu »** (cf. spec §8.4).
3. **Garde-fou de domaine + réorientation** : détermine si la question relève du
   nœud courant ; sinon **réoriente vers le bon nœud** et y rattache la Q/R.
4. **Déduplication sémantique** : avant de générer, cherche une Q/R existante
   proche → réutilise plutôt que dupliquer.
5. **Recherche sémantique** pour les utilisateurs (web + Flutter via l'API).
6. **Aide au placement** : réutilise la similarité vectorielle pour proposer le
   nœud d'une nouvelle publication (déjà décrit en Phase 1).

---

## 3. Architecture & composants

```
              ┌────────────────────── Serveur RAG (ml/, auto-hébergé) ─────────────────────┐
              │                                                                             │
Publications  │  [1] Ingestion+Chunking → [2] Embeddings → [3] Vector store (pgvector)      │
 (corpus)  ───┼────────────────────────────────────────────────┐                           │
              │                                                  ▼                           │
   Requête ───┼─▶ [4] Retrieval HYBRIDE (vecteur + texte/BM25) ─▶ [5] Rerank (opt.) ─┐       │
              │                                                                      ▼       │
              │                                       [6] Génération (LLM local) + [7] Contrainte de citation
              └─────────────────────────────────────────────────────────────────────┼──────┘
                                                                                      ▼
                                          API Symfony  ◀── réponse + passages + DOIs sources
```

1. **Ingestion & chunking** — découpe le texte exploitable (full-text **si la
   licence l'autorise**, sinon **résumé**) en *chunks* avec chevauchement ;
   conserve le lien `chunk → publication (DOI)`.
2. **Embeddings** — **réutilise le service `/embed` de la Phase 1**
   (sentence-transformers multilingue). Pas de duplication.
3. **Vector store** — **pgvector** (déjà dans la base) : pas d'infra
   supplémentaire à introduire.
4. **Retrieval hybride** — combine **similarité vectorielle** (pgvector) et
   **recherche plein-texte** (PostgreSQL FTS ou OpenSearch) pour la précision
   terminologique scientifique.
5. **Rerank** (optionnel) — *cross-encoder* open source pour réordonner le top-k.
6. **Génération** — **LLM open source auto-hébergé** (le même que pour la
   rédaction) ; reçoit la question + les passages récupérés.
7. **Contrainte de citation** — la sortie **doit** rattacher chaque affirmation
   aux passages/DOIs récupérés ; sinon l'affirmation est écartée/signalée.

---

## 4. API (proposée)

Service `ml/` exposant (consommé **uniquement** par l'API Symfony, jamais
directement par les clients) :

| Endpoint | Rôle |
|---|---|
| `POST /embed` | Vecteur d'un texte (déjà Phase 1) |
| `POST /search` | Retrieval hybride → passages + scores + DOIs |
| `POST /suggest-questions` | Pour un nœud → quelques questions évidentes (candidates canoniques) |
| `POST /answer` | Pour une question (nœud + texte) → réponse **sourcée** (notes DOI) + `domaine_ok`/`noeud_suggere` (garde-fou/réorientation) + `doublon_de` (dédup) |
| `POST /draft` | Brouillon canonique **pré-rédigé** d'une question suggérée (→ comité) |

Le garde-fou de domaine, la réorientation et la déduplication sont portés par
`/answer` (champs de réponse) ; l'API Symfony applique la décision (rattachement
au bon nœud, réutilisation d'une Q/R existante, statut `non_relu` vs `canonique`).

Côté Symfony : interface `RagClient` **abstraite** (on peut changer de
moteur/modèle), appels server-to-server authentifiés, réseau interne.

### 4.1 Branchement du LLM (implémenté)

La couche de **génération** est déjà branchable : l'API expose une abstraction
`App\Ai\Llm\LlmClient` avec une implémentation **compatible OpenAI/Ollama**
(`OpenAiCompatibleLlmClient`) et un **stub** déterministe pour le dev/les tests.
Le moteur est choisi par `LLM_DRIVER` (`openai` | `stub`) ; la cible est
configurée par `LLM_BASE_URL` / `LLM_MODEL` / `LLM_API_TOKEN` et pointe vers la
**machine IA dédiée** (cf. spec §5.1). Le pipeline RAG (récupération → assemblage
du prompt → génération sourcée) viendra s'appuyer sur ce client.

---

## 5. Modèle de données (ajouts)

- **DocumentChunk** — `publication_id`, `ordre`, `texte`, `embedding`
  (vector, pgvector), `source_span` (passage d'origine pour la citation),
  `origine` (fulltext / resume).
- (Réutilise `Publication`, `PlacementSuggestion` de la Phase 1 ; aucune base
  externe : tout reste dans PostgreSQL + pgvector.)

---

## 6. Garde-fous (essentiels pour la crédibilité)

- **Ne récupère/indexe que du contenu autorisé** : full-text seulement si la
  licence le permet (cf. *LicenseGate*, Phase 1 §5) ; sinon **résumé** seul.
- **Pas de publication automatique** : tout brouillon RAG passe par la
  **validation du comité** avant accès public (workflow spec §8.2 **inchangé**).
- **Citations obligatoires** : une réponse/brouillon sans source rattachée est
  **rejeté** — c'est la règle d'or.
- **Traçabilité** : on conserve, pour chaque génération, les **passages et DOIs**
  utilisés (audit + affichage des sources).
- **Auto-hébergé, open source, sans API propriétaire** ; `RagClient` abstrait.

---

## 7. Phasage

| Quand | Capacité RAG activée |
|---|---|
| **Phase 1** | Embeddings + similarité (placement) — *déjà prévu* |
| **Phase 2-3** | Chunking + **retrieval hybride** + **recherche sémantique** publique |
| **Phase 4** | **Génération sourcée** : brouillons de vulgarisation + Q&A sourcé |

> Conclusion : oui, un serveur RAG est pertinent — et il **n'ajoute quasiment pas
> d'infrastructure** (il s'appuie sur le service d'embeddings et pgvector déjà
> introduits en Phase 1). Il **devient indispensable** dès qu'on active la
> rédaction IA des articles.
