# Référence fonctionnelle

> **Le document à lire en premier pour ne pas se perdre.** Il recense *ce que fait*
> SciencesWiki, fonctionnalité par fonctionnalité, et indique **où ça vit dans le
> code** : route / endpoint / commande → contrôleur, service ou module. Pour la
> structure d'ensemble, voir **[Architecture](01-architecture.md)**.

**Comment lire les colonnes « Point d'entrée » :**
- `web:` = route du front Symfony/Twig (`apps/web`, préfixe `/{_locale}` = `/fr`).
- `api:` = endpoint de l'API (`apps/api`). Les ressources CRUD sont générées par API
  Platform ; les endpoints listés ici sont les contrôleurs *métier* sur-mesure.
- `cmd:` = commande console (`php bin/console …`, dans `apps/api`).

La doc OpenAPI vivante de l'API est sur **`/api/docs`**.

---

## 1. Consultation publique (le wiki)

Le cœur lecture : un **arbre de connaissance** (domaine › champ › sous-champ › …) et,
sur chaque nœud, des **réponses sourcées** à des questions.

| Fonctionnalité | Point d'entrée | Code |
|---|---|---|
| Accueil (domaines, dernières questions, stats) | `web: home` (`/fr`) | `WikiController` ← `ApiClient` |
| Navigation d'une rubrique (fil d'Ariane, enfants, réponses, controverses) | `web: node` (`/fr/{path}`, catch-all `priority -10`) | `WikiController::node` ← `api: api_node_*` |
| Page d'une réponse Q/R | `web: answer` (`/fr/q/{id}`) | `WikiController` |
| Vote sur une réponse (pour/contre) | `web: answer_vote` → `api: api_answer_vote`, agrégats `api_answer_votes` | `ApiClient::voteAnswer` |
| Explorateur d'articles d'un domaine | `web: explorer` (`/fr/explorer/{slug}`) | `api: api_node_articles`, `api_article_detail` |
| Corpus / stats d'un nœud | `api: api_node_corpus`, `api_node_children_stats` | — |
| Stats globales de la plateforme | `api: api_stats` | — |

## 2. Recherche

Trois modes coexistent : **sémantique** (kNN pgvector sur l'embedding de la requête),
**plein-texte**, et **hybride** (fusion RRF des deux).

| Fonctionnalité | Point d'entrée | Code |
|---|---|---|
| Recherche d'articles (sémantique / texte) | `api: api_search` (`?q=…&type=publications&mode=semantic|text`) | `PublicationRepository::nearestTo` / `nearestHybrid` |
| Recherche de rubriques (nœuds proches) | `api: api_search` (`type=nodes`) | `TreeNodeRepository::nearestTo` |
| Recherche d'articles wiki (langage naturel, hybride) | `web: wiki_search` (`/fr/wiki`) → `api: api_wiki_search` | recherche hybride |

## 3. Questions & Réponses (RAG sourcé)

Génération **ancrée sur les sources** : récupération pgvector → prompt sourcé → LLM →
sortie en sections (titre / vulgarisation / académique) → vérification anti-hallucination
(`[réf. nécessaire]`) → notes de bas de page reliant chaque affirmation à un DOI.

| Fonctionnalité | Point d'entrée | Code |
|---|---|---|
| Poser une question sur une rubrique | `api: api_question_ask` | crée `Question`, déclenche la génération |
| Suggestions de questions | `api: api_question_suggest` | `QuestionSuggester` |
| Génération **streamée** de la réponse (effet machine à écrire, SSE) | `api: api_question_stream` (`/api/questions/{id}/stream`) | `StreamAnswerController` ← `AnswerDrafter`, `RagRetriever`, `FaithfulnessChecker` |
| Dernières questions (fil temps réel) | `api: api_questions_latest` ; `web: latest_frame` (Turbo) | — |
| Brouillon de réponse en CLI | `cmd: wiki:draft-answer --node=… --question=… -k 5` | `AnswerDrafter` |
| Suggérer des questions en masse (CLI) | `cmd: wiki:suggest-questions` | `QuestionSuggester` |

➡️ Détail du pipeline : `Rag/` (cf. [Architecture §4 & §6.2](01-architecture.md)).

## 4. Assistant de chat (Open WebUI)

Interface de chat pour profils connectés, branchée sur un endpoint **compatible
OpenAI** qui n'expose **que du RAG sourcé** (et, en option, des modèles bruts via Ollama).

| Fonctionnalité | Point d'entrée | Code |
|---|---|---|
| Page chat (iframe Open WebUI) | `web: chat` (`/fr/chat`, `ROLE_USER`) | — |
| Liste des « modèles » RAG | `api: api_rag_models` (`/api/rag/models`) | `RagChatController` |
| Complétion de chat (sourcée) | `api: api_rag_chat` (`/api/rag/chat/completions`) | `RagChatController` (hors firewall JWT, `RAG_API_TOKEN` optionnel) |
| SSO (forward-auth du proxy) | `web: auth_openwebui` (`/auth/openwebui`) | `AuthForwardController` (en-têtes `X-SW-Auth-*`) |

## 5. Outils chercheur (`ROLE_RESEARCHER`)

| Fonctionnalité | Point d'entrée | Code |
|---|---|---|
| Tableau de bord chercheur | `web: researcher_dashboard` (`/fr/chercheur`) | — |
| **Revue de littérature** RAG (synthèse sourcée d'un sous-domaine) | `web: literature_review` → flux `api: api_literature_review` (SSE) | génération RAG multi-sources |
| Sauvegarder / lister / supprimer une revue | `web: literature_review_save` / `literature_reviews` / `literature_review_delete` ; `api: api_litreview_*` | entité `LiteratureReview` |
| Export **PDF** / **Markdown** d'une revue | `web: literature_review_pdf`, `literature_review_saved_pdf`, `…_md` | `Pdf/TemplatePdf` (FPDI+TCPDF) |
| Import d'une bibliographie Zotero (CLI) | `cmd: wiki:import-zotero` | — |

## 6. Contribution & édition éditoriale

Cycle de vie d'une réponse : `Unreviewed` → `InCommitteeReview` → `Validated`.
L'édition exige `ROLE_REDACTEUR` ; la validation exige `ROLE_COMITE` **et** la
compétence sur le domaine du nœud (`DomainExpertise`).

| Fonctionnalité | Point d'entrée | Code |
|---|---|---|
| Connexion / déconnexion (JWT en session) | `web: login` / `logout` → `api: /api/login_check` | `UserApiClient`, Lexik JWT |
| Profil courant | `api: api_me` | — |
| Éditer un article de rubrique | `web: article_edit` → `api: api_node_article_edit` | `ArticleVoter` |
| Valider un article de rubrique | `web: article_validate` → `api: api_node_article_validate` | — |
| Réviser une réponse | `web: answer_edit` → `api: api_answer_revise` | crée `AnswerRevision` |
| Valider une réponse (comité) | `web: answer_validate` → `api: api_answer_validate` | `AnswerValidator`, `AnswerVoter` |
| **Dépôt « version auteur » tokenisé** (l'auteur d'un article dépose son texte via lien sécurisé) | `web: contribute` (`/fr/contribuer/{token}`) → `api: api_contribute_info` / `api_contribute_upload` | token 32–64 hex, anti-SSRF |
| Traduction d'un article | `api: api_article_translate` ; arbre entier : `cmd: app:translate-tree` | — |

## 7. Analyse « controverses & lacunes »

Détection LLM, par nœud, des **désaccords** entre études et des **lacunes** de
recherche. File asynchrone dédiée (`analysis`).

| Fonctionnalité | Point d'entrée | Code |
|---|---|---|
| Controverses d'un nœud (lecture) | `api: api_node_controversies` | — |
| Lancer l'analyse d'un nœud | `api: api_node_analyze` (`/api/tree_nodes/{slug}/analyze`) | message `AnalyzeNodeMessage` → `AnalysisOrchestrator` |
| Extraction de claims (CLI) | `cmd: analysis:extract-claims` | `ClaimExtractor` |
| Détection de controverses (CLI) | `cmd: analysis:detect-controversies` | `ControversyDetector` |
| Détection de lacunes (CLI) | `cmd: analysis:detect-gaps` | `GapDetector` |
| Run complet (CLI) | `cmd: analysis:run` | `AnalysisOrchestrator` |

➡️ Entités : `Claim`, `Controversy`, `ResearchGap`.

## 8. Moisson & enrichissement (pipeline de données)

Toute la chaîne d'alimentation du corpus. Voir [Architecture §6.1 & §6.4](01-architecture.md).

| Étape | Commande | Code |
|---|---|---|
| Amorcer le registre des sources | `cmd: harvester:seed-sources` | (idempotent, lancé au démarrage) |
| Amorcer l'arbre (taxonomie OpenAlex) | `cmd: harvester:seed-tree --max-level=2` | `OpenAlexTaxonomySeeder` |
| Moisson de découverte (OpenAlex, arXiv) | `cmd: harvester:discover openalex|arxiv [--since|--resume|--async]` | `OpenAlexConnector`, pagination par **curseur** |
| Moisson automatique (cron-safe, par rubrique) | `cmd: app:harvest:auto` | enfile `HarvestRubric` |
| Réparer les moissons bloquées | `cmd: app:harvest:reap-stale` | — |
| Résoudre l'accès ouvert légal | `cmd: harvester:resolve-oa --limit=N` | `UnpaywallResolver` |
| Embeddings (titre + résumé) | `cmd: harvester:embed --limit=N` | `PublicationEmbedder`, `EmbeddingClient` |
| Suggestion de placement dans l'arbre (kNN) | `cmd: harvester:suggest-placement -k 3` | `PlacementSuggester` (non décisionnel) |
| Texte intégral : sélection (curation) | `cmd: app:fulltext:enqueue` | enfile `IngestFulltext` |
| Texte intégral : fetch PDF → GROBID → vecteurs | `cmd: app:fulltext:fetch` / `app:fulltext:retry` | `FulltextIngester`, `GrobidExtractor` (PDF **jeté**) |
| Compléter les métadonnées de revues | `cmd: app:journals:backfill` | — |
| Vérifier les rétractations | `cmd: app:retractions:check` | exclut les études rétractées du RAG |
| Lier les publications satellites (errata…) | `cmd: app:satellites:link` | — |
| Recompter les publications par auteur | `cmd: app:authors:recount` | — |
| Rafraîchir les stats agrégées | `cmd: app:stats:refresh` | — |
| Générer un article encyclopédique | `cmd: app:wiki:generate` | génération RAG multi-sources |

> Ces commandes sont conçues pour des **crons** à débit poli (respect des éditeurs et
> du *polite pool* OpenAlex). Le travail lourd passe par les files Messenger
> (`harvester`, `fulltext`, `analysis`) et leurs workers dédiés.

## 9. Back-office (administration, `ROLE_ADMIN`)

Front d'admin (`apps/web`, `AdminController`) consommant l'API admin
(`apps/api`, `/api/admin/*`).

| Domaine | Routes web | Endpoints API |
|---|---|---|
| Tableau de bord (volumétrie) | `admin_dashboard` | `admin_dashboard_data` |
| Réglages (général / IA / moisson) | `admin_settings_*` | `admin_settings_get|save` |
| Questions (éditer / régénérer / supprimer / déplacer) | `admin_questions`, `admin_question_op` | `admin_question_edit|regenerate|delete|move` |
| Articles wiki | `admin_wiki`, `admin_wiki_detail` | `admin_wiki_list|detail` |
| Publications (filtres, détail, PDF proxy anti-SSRF) | `admin_articles`, `admin_article`, `admin_article_pdf` | `admin_publications`, `admin_publication_detail` |
| Revues / éditeurs / auteurs | `admin_journals`, `admin_authors` | `admin_journals_search`, `admin_authors` |
| Arbre (renommer, image, déplacer, greffer) | `admin_node`, `admin_node_cover`, `admin_action` | `admin_node_create|update|move` |
| Moisson (statut live, relancer / annuler / nettoyer) | `admin_harvest`, `admin_harvest_status`, `admin_harvest_op`, `admin_harvest_cleanup` | `admin_harvest_status`, `admin_harvest_cleanup` |
| Recherche OpenAlex (greffe de rubriques) | `admin_openalex_search` | — |
| Utilisateurs & rôles | `admin_users` | `admin_users_list|create|update` |
| Journal d'activité (audit) | `admin_activity` | `admin_activity` |
| Candidatures (« on recrute ») | `admin_join`, `admin_join_op` | `admin_join_list|promote|reject` |
| Roadmap (propositions) | `admin_roadmap`, `admin_roadmap_status` | `admin_roadmap_list|status` |
| Newsletter (inscrits) | `admin_newsletter` | `admin_newsletter_list` |
| Token de contribution auteur | `admin_article_contribution` | `admin_contribution_token` |
| Modèles RAG disponibles | — | `admin_models` |

## 10. Pages de présentation & engagement (thème « CRT »)

| Fonctionnalité | Point d'entrée | Code |
|---|---|---|
| Découvrir / pages par public (chercheurs, journalistes, enseignants, grand public) | `web: crt_discover`, `crt_researchers`, `crt_journalists`, `crt_teachers`, `crt_public` | `MarketingController` |
| Manifeste, tarifs, changelog, mentions légales | `web: crt_manifesto`, `crt_pricing`, `crt_changelog`, `crt_legal` | — |
| Roadmap publique + proposition | `web: crt_roadmap` → `api: api_roadmap_propose` | entité `RoadmapProposal` |
| Soutenir (don) | `web: crt_donate` | — |
| Contact | `web: crt_contact` → `api: api_contact` | `Mailer/` |
| Inscription newsletter | `api: api_newsletter_signup` | entité `NewsletterSignup` |
| Candidature à rejoindre l'équipe | `api: api_join_request` | entité `JoinRequest` |
| Le projet / process de publication | `web: project`, `process` | `ContentController` |
| **Documentation Développeur** (ce que tu lis) | `web: developer`, `developer_doc` | `DeveloperController` |

## 11. Identité, sécurité & temps réel (transverses)

| Fonctionnalité | Point d'entrée | Code |
|---|---|---|
| Authentification JWT (TTL 8 h) | `api: /api/login_check`, `api_me` | Lexik JWT, `security.yaml` |
| Création de compte / rôle (CLI) | `cmd: app:user:create`, `app:user:grant-domain` | `Security/` |
| Réglages publics (thème…) | `api: api_public_settings` | `ThemeService` (web) |
| Notifications temps réel (avancement moisson, barre admin) | Mercure (hub hébergé par l'`api`) | `HarvestTicker` |
| E-mails (test, notifications) | `cmd: app:mail:test` | `Mailer/`, Brevo/DSN |

---

## Par où commencer à lire le code

1. **Une fonctionnalité = une ligne ci-dessus** → ouvre la route/commande citée, puis
   remonte au service nommé.
2. Pour le **front** : tout passe par les `*ApiClient` (`apps/web/src/Service`) — le
   web ne touche jamais la base.
3. Pour le **métier** : commence par le namespace concerné dans `apps/api/src`
   (`Harvester`, `Rag`, `Analysis`) — chaque dossier a une responsabilité unique
   (cf. [Architecture §4](01-architecture.md)).
4. Pour **étendre** proprement : voir les recettes dans
   [Conventions de code §11](04-conventions-de-code.md).
