# SciencesWiki — Spécification « Plateforme Auteur » + Recherche tolérante aux fautes

> Statut : proposition à valider. Cible : un **espace dédié aux chercheurs** (rôle
> `ROLE_AUTEUR`), avec un **tableau de bord séparé** (favoris, réceptions d'e-mail,
> outils de recherche), adossé au corpus existant (1,3 M publications, pgvector,
> métadonnées éditeurs/revues/auteurs).

---

## 0. Rôle et accès

### 0.1 Rôle `ROLE_AUTEUR`
- **Nouveau rôle** distinct de `ROLE_REDACTEUR` (qui édite le wiki) : l'auteur est un
  **chercheur** qui utilise les outils de recherche, pas forcément un rédacteur du wiki.
- Hiérarchie proposée (security.yaml) : `ROLE_AUTEUR: [ROLE_USER]` ; un `ROLE_REDACTEUR`
  ou `ROLE_COMITE` peut aussi être auteur (on ajoute `ROLE_AUTEUR` à leurs rôles, ou on
  l'inclut dans la hiérarchie `ROLE_REDACTEUR: [ROLE_AUTEUR]`). **Décision A** (cf. fin).
- Attribution : via la promotion BO « Demandes » existante (ajouter `ROLE_AUTEUR` aux
  rôles attribuables) + auto-attribution possible à l'inscription « chercheur vérifié ».

### 0.2 Tableau de bord auteur (séparé)
- URL : `/{_locale}/auteur` (front, session `user_jwt` déjà en place), réservé `ROLE_AUTEUR`.
- Sections : **Recherche** · **Revue de littérature** · **Consensus** · **Related work**
  · **Veille & alertes** · **Favoris** · **Cartographie** · **Détecteur de rétractations**
  · **Profil & e-mails**.
- Distinct du back-office admin (`/admin`) et de l'édition wiki.

---

## 1. Moteur de recherche TOLÉRANT AUX FAUTES (préalable)

### 1.1 Choix : **Meilisearch** (auto-hébergé)
- Léger, rapide, **typo-tolérance native**, *instant-search*, facettes, surlignage,
  tri par attributs. Plus simple à exploiter qu'Elasticsearch pour un homelab.
- Service Docker sur **Marvin** (RAM dispo), lié au réseau privé ; jamais exposé
  publiquement (l'API passe par un endpoint Symfony qui filtre/limite).

### 1.2 Périmètre d'indexation → **DÉCISION B**
| Option | Docs | RAM/disque estimés | Usage |
|---|--:|--:|---|
| **B1 — Articles wiki seulement** | ~quelques centaines | négligeable | recherche grand public des articles encyclopédiques |
| **B2 — Corpus complet** | ~1,3 M (titre+résumé+méta) | ~6–15 Go index | recherche bibliographique chercheurs (le cœur des features 1–7) |
| **B3 — Sous-ensemble** (OA + top-cités, ex. ≥ N citations) | ~200–400 k | ~2–4 Go | compromis |

> Les 7 fonctionnalités auteur visent le **corpus** → **B2** (ou B3 pour démarrer).
> Indexation initiale en arrière-plan (curseur), puis maintenue par la moisson.

### 1.3 Champs indexés (corpus)
`id, doi, title, abstract, authors[], journal, publisher, year, type, oa_status,
cited_by_count, fwci, retraction_status, tree_node_slugs[]`.
- **Searchable** : title, abstract, authors. **Filterable** : year, type, oa_status,
  journal, publisher, retraction_status, fwci, cited_by_count, tree_node_slugs.
- **Sortable** : cited_by_count, fwci, year. **Typo-tolerance** : on (titres/auteurs).

### 1.4 Synchronisation
- Commande `app:search:index` (batch initial, borné, repris au curseur).
- Hook d'incrément : à chaque import de moisson, pousser le document (ou file
  Messenger `index` consommée par un worker), + suppression sur rétractation.
- Cron de réconciliation nocturne (diff DB ↔ index).

### 1.5 API + UX
- `GET /api/search/keyword?q=&filters…` → proxy Symfony vers Meilisearch (typo-tolérant,
  facettes, surlignage). Public (grand public) + utilisé par le dashboard auteur.
- Front public : barre de recherche instantanée (déjà `/fr/wiki` pour les articles ;
  ajouter l'onglet « publications » tolérant aux fautes).
- L'actuel `/api/search` (sémantique pgvector) reste pour la **recherche par le sens** ;
  les deux sont **complémentaires** (mot-clé tolérant vs sémantique).

---

## 2–8. Fonctionnalités `ROLE_AUTEUR`

### 2. Recherche sémantique trans-domaine *(socle déjà là)*
- Réutilise `/api/search` (kNN pgvector HNSW). Ajouter : **filtres** (année, OA, type,
  domaine), tri (FWCI/citations), et la possibilité de chercher **hors discipline**.
- UI dédiée dans le dashboard auteur avec résultats riches (méta complètes).

### 3. Revue de littérature assistée (RAG sourcé)
- Entrée : un sous-domaine (nœud) ou une requête.
- Pipeline : récupération kNN (top N, OA prioritaires) → LLM (qwen3.6) produit une
  **synthèse structurée** : *consensus établi · méthodes dominantes · lacunes/controverses*,
  chaque affirmation **citée [n]** + **bibliographie** (DOI).
- **Export Markdown + PDF** (lib PHP `dompdf` ou rendu HTML→PDF). **Décision C** (PDF).
- Long traitement → asynchrone (Messenger) + notification quand prêt.

### 4. Consensus vs contradictions
- Entrée : une question fermée (« le jeûne intermittent réduit-il la mortalité ? »).
- Pipeline : kNN → le LLM **classe** chaque source en *appuie / nuance / contredit* →
  regroupement + **niveau de preuve** par source (FWCI, cited_by_count, OA, rétraction).
- Sortie : 3 colonnes (Pour / Nuancé / Contre) + score de confiance global + sources.

### 5. « Related work » d'un manuscrit
- Entrée : un résumé collé (texte libre).
- Pipeline : embedding du texte (service ML Marvin) → `nearestTo` → articles proches +
  **références manquantes probables** (les plus cités du voisinage non couverts).
- Export liste BibTeX/Markdown.

### 6. Veille personnalisée (alertes e-mail)
- Abonnements (entité `Subscription`) : par **sous-domaine**, **auteur suivi**, **revue suivie**.
- Cron quotidien/hebdo : nouveaux articles top-cités du périmètre, ou citant l'entité suivie
  → e-mail digest (Brevo). Respecte le réglage de réception de l'auteur (cf. §9).

### 7. Cartographie éditeurs / revues / auteurs
- Données déjà là (`publisher`, `journal`, `author`, `authorship`).
- Vues : top revues/éditeurs d'un domaine, **co-auteurs** d'un auteur (graphe de
  collaboration), revues d'un éditeur. Rendu graphe (lib JS légère, ex. vis-network).

### 8. Détecteur de rétractations dans une biblio
- Entrée : liste de DOIs (collés ou fichier).
- Croisement : `retraction_status` du corpus + **Retraction Watch / Crossref** (API) pour
  les DOIs hors corpus → signale *rétracté* / *expression of concern*.
- Sortie : tableau par DOI (statut, source, date) + export.

---

## 9. Profil auteur, favoris, e-mails

- **Favoris** (entité `Favorite`) : articles/auteurs/revues mis de côté → liste dans le dashboard.
- **Réception d'e-mails** : préférences par type (digests de veille, réponses à ses
  propositions, notifications comité) — toggles dans « Profil & e-mails ».
- **Profil** : domaines d'expertise (déjà `DomainExpertise`), ORCID, nom public.

---

## 10. Modèle de données (ajouts)
- `Subscription` (user_id, kind: domain|author|journal, target, frequency, active).
- `Favorite` (user_id, kind: publication|author|journal, target_id, created_at).
- `EmailPref` (user_id, key, enabled) — ou colonnes JSON sur `app_user`.
- `LiteratureReview` (user_id, scope, status, content_md, created_at) — jobs RAG asynchrones.
- (Meilisearch : index externe, pas en base.)

## 11. Phasage proposé
1. **P0 — Meilisearch** : service + `app:search:index` + endpoint keyword + UI publique.
2. **P1 — Dashboard auteur** (coquille) + rôle `ROLE_AUTEUR` + Favoris + Profil/e-mails.
3. **P2 — Features 2 (recherche), 4 (related work)** : réutilisent l'existant, rapides.
4. **P3 — Revue de littérature (3) + Consensus (5)** : RAG + export PDF (asynchrone).
5. **P4 — Veille/alertes (6)** : abonnements + crons + digests.
6. **P5 — Cartographie (7) + Détecteur rétractations (8)**.

## 12. Décisions à trancher
- **A** — `ROLE_AUTEUR` : rôle **distinct** (recommandé) ou alias de `ROLE_REDACTEUR` ?
- **B** — Périmètre Meilisearch : **B1** (articles wiki), **B2** (corpus complet ~1,3 M),
  ou **B3** (sous-ensemble OA/top-cités) ? *(impacte RAM/disque sur Marvin)*
- **C** — Export PDF des revues de littérature : `dompdf` (PHP, simple) accepté ?
- **D** — On garde la **double recherche** (mot-clé tolérant Meili + sémantique pgvector) ?
