# Spécification — Framework d'intégration de modules SciencesWiki

**Version :** 1.0.0
**Statut :** spécification de référence
**Portée :** contrat technique et fonctionnel permettant d'intégrer des applications autonomes (« modules ») à la plateforme SciencesWiki
**Langue de référence :** français

> Ce document définit **le socle d'intégration**, pas les modules eux-mêmes.
> Les deux premiers modules cibles ont leur propre spécification :
> - [`analyses/SPECS.md`](analyses/SPECS.md) — routeur et moteur d'analyse composite (remplace l'analyse actuelle) ;
> - [`figTrack/SPECS.md`](figTrack/SPECS.md) — plateforme de forensique d'images scientifiques.

---

## 1. Objet

Permettre l'ajout de fonctionnalités entières à SciencesWiki sous forme de **modules autonomes**, sans modifier le cœur, avec :

- une **isolation stricte** (données, routes, réglages, événements, files) via un préfixe propre au module ;
- un **contrat de compatibilité** versionné avec la plateforme hôte ;
- un **modèle d'accès par rôle** distinguant l'usage (utilisateur) de l'administration (configuration globale) ;
- des **points d'extension** déclaratifs (menu, assets, pages de réglages, page d'accès aux modules, événements) ;
- un **SDK de services hôte** (identité, corpus, LLM, PDF, recherche vectorielle, file d'attente…) accordé au moindre privilège.

Un module doit pouvoir être **activé, désactivé ou mis à jour** sans redéploiement du cœur et sans effet de bord sur les autres modules.

### 1.1 Exemple fondateur

> « Cette application est un module autonome de la plateforme SciencesWiki destiné aux chercheurs.
> Seuls les chercheurs peuvent en bénéficier. »

Ce cas — un module réservé au rôle `ROLE_RESEARCHER`, avec une section d'administration réservée à `ROLE_ADMIN` — est le scénario de référence du présent document.

---

## 2. Vocabulaire

| Terme | Définition |
|---|---|
| **Hôte** | La plateforme SciencesWiki (Symfony 8.1 / API Platform, `apps/api` + `apps/web`, PostgreSQL + pgvector, Messenger, FrankenPHP). |
| **Module** | Application autonome ajoutant une fonctionnalité, intégrée via ce framework. |
| **Manifeste** | Fichier de configuration déclaratif du module (`module.yaml`). |
| **Préfixe** | Identifiant de 6 lettres majuscules, unique, servant de **namespace** global du module. |
| **Point d'extension (hook)** | Emplacement déclaratif où le module s'insère dans l'hôte (menu, assets, réglages, événements…). |
| **Capacité (capability)** | Service hôte demandé par le module et accordé par l'administrateur (moindre privilège). |
| **Projection par rôle** | Vue de l'IHM/des résultats adaptée au rôle, sans altérer le fond. |
| **Registre** | Table cœur recensant les modules installés, leur état, version et capacités accordées. |

---

## 3. Principes directeurs

### 3.1 Isolation par préfixe

Tout artefact d'un module est **namespacé par son préfixe**. Le cœur et les autres modules ne partagent jamais un espace de noms avec lui (cf. §5).

### 3.2 Le module ne touche jamais le cœur

Un module **n'écrit ni ne migre** les tables du cœur (`publication`, `answer`, `tree_node`, `setting`, `app_user`…). Il accède aux données du cœur **uniquement** via les services hôte (§9), en lecture par défaut. Il possède ses propres tables (préfixées) pour son état.

### 3.3 Séparation du fond et de la restitution

Reprend le principe de la spec `analyses` : le **résultat produit** par un module est indépendant du rôle. Le rôle change la **restitution** (détail, vocabulaire, permissions d'action, exports), jamais le fond scientifique.

### 3.4 Deux styles d'intégration

Le manifeste déclare `integration: embedded | external`.

| Style | Description | Exemple |
|---|---|---|
| **embedded** | Bundle Symfony PHP exécuté **dans le processus hôte** (`apps/api` et/ou `apps/web`). Partage la DB, les workers Messenger, le rendu Twig. | *(non utilisé par les 2 premiers modules)* |
| **external** | Service **autonome**, déployé à part, intégré par **API HTTP + webhooks signés** et/ou **accès direct à la base SW partagée** (§3.7). L'hôte fournit une IHM de façade et proxifie l'accès. | `analyses` (standalone PHP), `figTrack` (Python/FastAPI + workers ML). |

Un même module peut être **hybride** (façade embedded + calcul external) ; il déclare alors les deux volets. **Les deux premiers modules sont standalone** (§3.7).

### 3.5 Compatibilité négociée

Chaque module déclare une plage `[scienceswiki_min, scienceswiki_max]` et les **capacités requises**. Au chargement, l'hôte vérifie la version et l'octroi des capacités ; sinon le module est **désactivé proprement** avec alerte admin (jamais de 500).

### 3.6 Sécurité par défaut

- Politique CSP stricte de l'hôte respectée (pas d'hôte externe ; scripts inline avec **nonce** ; cf. mémoire projet sur les scripts inline).
- Accès aux données limité au périmètre accordé.
- Secrets injectés par variable d'environnement, jamais en dur.
- Journalisation d'audit de toute action sensible et de tout traitement par IA.

### 3.7 Modules standalone sur base partagée

Décision d'implémentation pour les deux premiers modules : ils sont **standalone**
(applications déployées séparément, cycle de vie propre) mais **partagent la base de
données PostgreSQL de SciencesWiki**. Concrètement :

- chaque module possède ses **tables préfixées** (`xxxxxx_*`) dans la base SW, créées et
  migrées par le module lui-même ;
- il accède **en lecture** aux tables du cœur *via les ports du SDK* (jamais en écriture,
  jamais de migration du cœur) ;
- il s'authentifie auprès de l'hôte avec l'identité/rôles SW (JWT) ;
- il est intégré à l'IHM par la façade (menu, page hub, proxy) décrite en §8.

Ce choix combine l'autonomie de déploiement d'un module `external` avec l'accès direct
au corpus qu'offre la base partagée — sans dupliquer les 31 M+ publications.

### 3.8 Coexistence sans régression avec l'analyse *legacy*

**L'analyse existante de SciencesWiki (AXIS, RoB 2, AMSTAR 2, MMAT) n'est pas modifiée.**
Le module `analyses` est construit **en parallèle**, en standalone, sur ses propres tables
`analys_*`. Les deux coexistent tant que le nouveau module n'est pas validé.

La bascule — **suppression de l'analyse legacy** et **intégration** de la nouvelle analyse
dans SciencesWiki là où c'est nécessaire — n'intervient qu'**après validation** du module.
Aucune migration destructive (`axis_appraisal → analys_*`, retrait des points d'entrée
legacy) n'est réalisée avant cette étape ; elle est décrite en §12.1 comme travail *ultérieur*.

---

## 4. Architecture d'ensemble

```text
Hôte SciencesWiki
│
├── Noyau (cœur)
│   ├── Identité / rôles (JWT Lexik)
│   ├── Corpus (publication, chunks, embeddings, arbre)
│   ├── Services partagés (LLM auto-hébergé, PDF charté, Mailer, Vector search)
│   ├── Messenger (transports) · Settings · Audit
│   └── Module Kernel  ◄── ce document
│       ├── Registry (module_registry)
│       ├── Manifest Loader + validation
│       ├── Capability Broker (octroi/refus)
│       ├── Compatibility Gate (semver)
│       ├── Hook Dispatcher (menu, assets, settings, events)
│       ├── Lifecycle Manager (install/enable/disable/upgrade)
│       └── Module SDK (ports exposés)
│
├── Module « analyses »  (standalone PHP, base SW partagée, préfixe ANALYS)
│   └── plugins de référentiels : AXIS, RoB 2, AMSTAR 2, MMAT, STROBE, PRISMA…
│   (l'analyse legacy du cœur reste intacte et coexiste — §3.8)
│
└── Module « figTrack »  (standalone Python/ML, base SW partagée, préfixe FIGTRK)
    ├── Façade (IHM de revue, proxy API)
    └── Service Python/ML autonome (FastAPI + workers GPU/CPU)
```

---

## 5. Le préfixe module (6 caractères ALPHA)

### 5.1 Format

- Exactement **6 lettres majuscules** `A–Z`. Regex : `^[A-Z]{6}$`.
- **Unique** dans tout l'écosystème. Réservé au registre central (collision = refus d'installation).
- Immuable après la première publication (le renommer casserait les données existantes).

### 5.2 Rôle de namespace

Le préfixe (noté `XXXXXX` ci-dessous ; en base, sa forme *lowercase* `xxxxxx`) préfixe **tous** les artefacts du module :

| Artefact | Convention | Exemple (`ANALYS`) |
|---|---|---|
| Tables SQL | `xxxxxx_*` | `analys_assessment`, `analys_evidence` |
| Clés de réglages | `xxxxxx.*` | `analys.default_model`, `analys.threshold.human_review` |
| Noms de routes Symfony | `xxxxxx_*` | `analys_run`, `analys_admin_settings` |
| Chemin URL de montage | `/{_locale}/m/{slug}` | `/fr/m/analyses` |
| Assets statiques | `/modules/{slug}/…` | `/modules/analyses/app.css` |
| Événements | `xxxxxx.*` | `analys.assessment.completed` |
| Transports Messenger | `xxxxxx_*` | `analys_analysis` |
| Identifiants publics | `xxxxxx_<ulid>` | `analys_01J…` |
| Webhooks (external) | `/webhooks/{slug}/*` | `/webhooks/figtrack/finding` |

### 5.3 Registre des préfixes

Une table cœur `module_prefix_registry` (ou colonne unique sur `module_registry`) garantit l'unicité. La validation à l'installation **rejette** un préfixe déjà pris, non conforme, ou appartenant à une liste réservée (`SCIENC`, `SYSTEM`, `ADMINX`, `COREXX`…).

---

## 6. Manifeste du module (`module.yaml`)

Fichier obligatoire à la racine du module. Source de vérité déclarative.

### 6.1 Schéma (référence)

```yaml
# ---- Identité ----
prefix: ANALYS                 # 6 ALPHA, unique, immuable
slug: analyses                 # kebab-case, unique, sert d'URL
name: "Analyses scientifiques"
description: "Routage et évaluation méthodologique composite des publications."
icon: "🔬"                     # emoji ou chemin d'asset du module
category: research             # research | integrity | editorial | tools | …

# ---- Version & compatibilité ----
version: 1.0.0                 # semver du module
compat:
  scienceswiki_min: "8.1.0"
  scienceswiki_max: "9.x"      # borne haute (majeure) autorisée
sdk_api: "1"                   # version majeure du SDK de services hôte requis

# ---- Style d'intégration ----
integration: embedded          # embedded | external | hybrid
mount: "/m/analyses"           # préfixé par la locale à l'exécution → /fr/m/analyses
entrypoints:
  main: analys_home            # nom de route de l'écran principal

# ---- Accès (sections gardées par rôle) ----
# Chaque section = un périmètre fonctionnel gardé par un ou plusieurs rôles.
# La section 'admin' est TOUJOURS réservée à ROLE_ADMIN et invisible aux autres.
access:
  sections:
    - key: use                 # utilisation du module
      label: "Utiliser les analyses"
      roles: [ROLE_RESEARCHER, ROLE_COMITE]
      route: analys_home
    - key: review              # actions de validation
      label: "Valider les analyses"
      roles: [ROLE_COMITE]
    - key: admin               # configuration GLOBALE du module
      label: "Paramétrage du module"
      roles: [ROLE_ADMIN]      # imposé ; ne peut être élargi
      route: analys_admin_settings

# ---- Capacités hôte demandées (moindre privilège) ----
capabilities:
  required:
    - identity                 # utilisateur courant + rôles
    - publications:read        # métadonnées + fulltext embed/chunks
    - vector-search            # kNN pgvector
    - llm:generate             # modèles auto-hébergés (rédaction/analyse)
    - pdf:render               # génération PDF à la charte
    - queue                    # transport Messenger dédié
  optional:
    - tree:read
    - mailer

# ---- Points d'extension ----
hooks:
  menu:
    - placement: primary       # primary | hub | account | admin
      label: "Analyses"
      icon: "🔬"
      route: analys_home
      roles: [ROLE_RESEARCHER, ROLE_COMITE]
      order: 40
      badge: analys.badge.pending   # clé de comptage dynamique (optionnel)
  assets:
    css:
      - path: "app.css"        # servi depuis /modules/analyses/app.css
        scope: module          # module | global
    js:
      - path: "app.js"
        scope: module
        defer: true
  settings_pages:
    admin: analys_admin_settings   # réglages globaux (ROLE_ADMIN)
    user:  analys_user_prefs       # préférences personnelles (rôle d'usage)
  dashboard_widgets:
    - id: analys_coverage
      roles: [ROLE_ADMIN]
  events:
    subscribes: [core.publication.fulltext_ready]
    publishes:  [analys.assessment.completed, analys.assessment.requires_review]

# ---- Persistance (le module possède ses tables) ----
persistence:
  tables_prefix: analys        # == lowercase(prefix) ; imposé
  migrations: "migrations/"    # migrations propres au module

# ---- Réglages exposés (namespacés, avec portée) ----
settings:
  - key: analys.default_model
    scope: admin               # admin (global) | user (perso)
    type: string
    default: "glm-5.2:cloud"
  - key: analys.threshold.human_review
    scope: admin
    type: float
    default: 0.75
  - key: analys.user.default_role_projection
    scope: user
    type: enum
    values: [researcher, methodologist, reviewer]
    default: researcher

# ---- Volet service externe (uniquement si integration: external|hybrid) ----
service:
  base_url_env: FIGTRK_BASE_URL   # jamais d'URL en dur
  auth: bearer                    # bearer | hmac
  secret_env: FIGTRK_API_KEY
  health: "/healthz"
  webhooks:
    signing_secret_env: FIGTRK_WEBHOOK_SECRET
    events: [analysis.completed, finding.created, report.ready]
```

### 6.2 Champs obligatoires

`prefix`, `slug`, `name`, `version`, `compat`, `integration`, `access.sections` (dont au moins `admin`), `capabilities.required`, `persistence.tables_prefix`.

### 6.3 Validation

Au chargement/à l'installation, l'hôte **rejette** un manifeste si : préfixe non conforme/déjà pris ; `tables_prefix ≠ lowercase(prefix)` ; section `admin` élargie au-delà de `ROLE_ADMIN` ; capacité inconnue ; route inexistante ; asset introuvable ; `sdk_api` incompatible.

---

## 7. Contrôle d'accès et rôles

### 7.1 Rôles de l'hôte

Hiérarchie SciencesWiki : `ROLE_USER` ⊂ `ROLE_AUTEUR` ⊂ { `ROLE_RESEARCHER`, `ROLE_TEACHER`, `ROLE_STUDENT`, `ROLE_REDACTEUR` }, plus `ROLE_COMITE`, `ROLE_MODERATEUR`, `ROLE_ADMIN`. L'hôte reste **seul maître** de l'attribution des rôles ; un module ne crée pas de rôles mais **compose** avec eux.

### 7.2 Modèle « sections gardées »

Chaque **section** du manifeste est un périmètre fonctionnel protégé par une liste de rôles. L'hôte applique le contrôle **avant** de router vers le module (deny-by-default). Le module n'implémente pas l'authentification ; il consomme l'identité fournie par la capacité `identity`.

### 7.3 Section d'administration réservée

La section `admin` est **toujours** réservée à `ROLE_ADMIN`. Elle contient la **configuration globale** du module : activation, réglages `scope: admin`, capacités, clés/secrets, quotas, modèles. **Aucun rôle d'usage ne peut y accéder ni modifier un réglage `admin`.** Les réglages `scope: user` sont des préférences personnelles, modifiables par l'utilisateur dans sa propre section.

### 7.4 Projection par rôle

Le module expose un **résultat canonique** unique et des projections par rôle (chercheur, méthodologiste, relecteur, éditeur, clinicien, vulgarisateur, administrateur — cf. `analyses/SPECS.md §20`). Le rôle change la vue, jamais le résultat.

---

## 8. Points d'extension (hooks)

### 8.1 Entrée de menu

Le module déclare où il s'insère :

- `placement: primary` — entrée dans la barre principale (ex. « Analyses »), soumise aux rôles ;
- `placement: hub` — carte dans la **page d'accès aux modules** (§8.3) ;
- `placement: account` — entrée dans le menu « Mon compte » ;
- `placement: admin` — entrée dans le back-office.

Attributs : `label`, `icon`, `route`, `roles`, `order`, `badge` (clé de comptage dynamique). L'hôte n'affiche l'entrée que si le rôle courant satisfait `roles` **et** que le module est activé et compatible.

### 8.2 Injection d'assets

CSS/JS déclarés dans `hooks.assets`, servis depuis `/modules/{slug}/…` :

- `scope: module` → chargés uniquement sur les pages du module ;
- `scope: global` → chargés partout (réservé, soumis à validation admin).
- Les **scripts inline** du module doivent porter le **nonce CSP** fourni par l'hôte (`csp_nonce`). Aucune ressource externe (CSP `default-src 'self'`). Voir la mémoire projet « Turbo + nonce inline script ».
- Versioning de cache : assets servis avec suffixe de version (mtime), comme le cœur (`asset_v`).

### 8.3 Page d'accès aux modules (« hub »)

Route cœur `module_hub` → `/{_locale}/labo` : liste, sous forme de cartes, les modules **activés, compatibles et accessibles** au rôle courant (nom, description, icône, bouton d'accès, badge). C'est le point d'entrée par défaut d'un module qui ne déclare pas d'entrée `primary`. Un module réservé aux chercheurs n'y apparaît que pour `ROLE_RESEARCHER`.

### 8.4 Pages de réglages

- `settings_pages.admin` → intégrée au back-office, gardée `ROLE_ADMIN` (réglages `scope: admin`).
- `settings_pages.user` → intégrée à l'espace utilisateur (réglages `scope: user`).

L'hôte fournit le rendu du formulaire à partir du bloc `settings` du manifeste (types, valeurs, défauts), ou le module fournit sa propre page (embedded).

### 8.5 Widgets de tableau de bord

`hooks.dashboard_widgets` : encarts optionnels injectés dans le dashboard admin, gardés par rôle.

### 8.6 Événements

Bus d'événements hôte, namespacé. Un module `subscribes` à des événements cœur/autres modules et `publishes` les siens (`xxxxxx.*`). Les événements externes sont livrés au module `external` par **webhooks signés**.

### 8.7 Cycle de vie

Hooks facultatifs appelés par le Lifecycle Manager : `onInstall`, `onEnable`, `onDisable`, `onUpgrade(fromVersion)`, `onUninstall(purge: bool)`, `health()`.

---

## 9. SDK de services hôte (ports)

Contrat stable exposé aux modules. Chaque service correspond à une **capacité** demandée dans le manifeste et accordée par l'admin. Versionné (`sdk_api`).

| Capacité | Service exposé | Usage typique |
|---|---|---|
| `identity` | Utilisateur courant, rôles, `hasRole`, `can(section)` | Contrôle d'accès, projection par rôle. |
| `publications:read` | Métadonnées, `oa_status`, plein texte **embeddé** (chunks), figures/PDF | Entrée d'analyse (analyses, figTrack). |
| `vector-search` | kNN pgvector (`vector(384)` / `halfvec(384)`) | RAG, recherche de similarité. |
| `tree:read` | Arbre des savoirs (domaines, rubriques) | Contexte de classement. |
| `llm:generate` | Génération via modèles **auto-hébergés** (mode chat/JSON) | Extraction de faits, rédaction, analyse. |
| `pdf:render` | Génération PDF à la **charte** (template + logo + URL) | Rapports, exports. |
| `mailer` | Envoi d'e-mails (relais SMTP hôte) | Notifications asynchrones. |
| `storage` | Stockage objet/fichiers scoping module | Dérivés, artefacts (figTrack). |
| `queue` | Transport Messenger dédié `xxxxxx_*` + worker | Traitements asynchrones lourds. |
| `settings` | Lecture/écriture des réglages **namespacés** du module | Config admin/user. |
| `audit` | Journal d'audit hôte | Traçabilité, signalement IA. |
| `events` | Publication/abonnement bus d'événements | Orchestration inter-modules. |

**Règles :** accès **en lecture** au corpus par défaut ; aucun accès direct aux tables du cœur ; toute écriture passe par un port explicite ; le broker refuse toute capacité non accordée (erreur claire, pas de fuite).

---

## 10. Cycle de vie et registre

### 10.1 Découverte

- **embedded** : présence de `modules/{slug}/module.yaml` dans le dépôt (monorepo), chargé au boot du Module Kernel.
- **external** : enregistrement via le back-office (URL de service + secrets par env) ; le manifeste peut être servi par le service (`GET /manifest`) et mis en cache.

### 10.2 États et registre

Table cœur `module_registry` : `slug`, `prefix`, `version`, `integration`, `status` (`installed` | `enabled` | `disabled` | `incompatible`), `granted_capabilities` (JSON), `installed_at`, `updated_at`, `health` (external).

Transitions : `install → enabled/disabled`, `enable`, `disable`, `upgrade`, `uninstall(purge?)`. Toute transition est **auditée**.

### 10.3 Barrière de compatibilité

Au chargement : si la version de l'hôte ∉ `[scienceswiki_min, scienceswiki_max]` ou `sdk_api` incompatible → statut `incompatible`, module **non monté**, alerte admin. Jamais de 500 : un module fautif est isolé, pas propagé.

### 10.4 Migrations

Le module possède ses migrations (préfixées `xxxxxx_*`), exécutées à `install`/`upgrade`. `uninstall(purge: true)` supprime ses tables et ses réglages ; `purge: false` conserve les données (réactivation possible). Le module **ne migre jamais** une table du cœur.

### 10.5 Santé (external)

Sonde `health()` périodique (`GET /healthz`). Un service externe indisponible dégrade proprement la façade (message « service momentanément indisponible »), sans casser l'hôte.

---

## 11. Sécurité, isolation, conformité

- **Données** : périmètre d'accès limité aux capacités accordées ; tables du module isolées par préfixe ; pas de jointure directe sur les tables du cœur (passer par les ports).
- **CSP** : `default-src 'self'` ; scripts inline avec nonce ; aucune ressource distante ; assets servis en `self`.
- **Secrets** : uniquement par variable d'environnement (`*_ENV`) ; jamais dans le manifeste ni en base en clair.
- **Isolation des traitements** (external, ex. figTrack) : conteneur dédié, sans réseau sauf connecteurs autorisés, lecture seule sur les originaux, limites CPU/RAM/GPU/temps, filesystem temporaire détruit après usage.
- **Webhooks** : signés (HMAC) et vérifiés ; horodatés ; rejeu bloqué.
- **RGPD / intégrité** : journal d'audit de toute action sensible ; **signalement explicite** des traitements par IA ; conservation configurable ; anonymisation optionnelle des données transmises aux analyseurs.
- **Neutralité** (modules d'intégrité type figTrack) : vocabulaire descriptif ; aucune conclusion automatique de faute ; décision humaine documentée.

---

## 12. Intégration des deux modules cibles

### 12.1 Module `analyses` — préfixe `ANALYS` — **standalone (base partagée)**

- **But :** offrir un **routeur composite universel** d'évaluation méthodologique (cf. `analyses/SPECS.md`), destiné à **remplacer à terme** l'analyse legacy — sans la modifier avant validation.
- **Style :** **standalone** sur la base SW partagée (§3.7). Tables propres `analys_*` ; lecture du corpus via les ports (`publications:read`, `vector-search`) ; LLM/PDF via le SDK. **Ne touche pas** au code ni aux tables de l'analyse legacy (§3.8).
- **Accès :** `use` = `ROLE_RESEARCHER`, `ROLE_COMITE` ; `review` = `ROLE_COMITE` ; `admin` = `ROLE_ADMIN`.
- **Capacités :** `identity`, `publications:read`, `vector-search`, `llm:generate`, `pdf:render`, `queue`, `tree:read`.
- **Menu :** entrée `primary` « Analyses » (chercheurs/comité) + carte dans la page hub ; réglages admin (modèles, seuils, référentiels activés).
- **Référentiels :** AXIS, RoB 2, AMSTAR 2, MMAT, STROBE, PRISMA… déclarés comme **plugins** dans le Framework Registry du module — écrits **from scratch** dans le module, pas repris du code legacy.

> **Travail ultérieur (après validation, hors périmètre immédiat) :** migration éventuelle
> `axis_appraisal → analys_*`, retrait des points d'entrée legacy, et branchement de la
> nouvelle analyse dans SciencesWiki là où c'est utile. Rien de destructif avant cette étape.

### 12.2 Module `figTrack` — préfixe `FIGTRK` — **external (hybride)**

- **But :** détection/documentation d'anomalies dans les images scientifiques (cf. `figTrack/SPECS.md`).
- **Style :** hybride — **façade embedded** (IHM de revue, proxy) + **service Python/ML autonome** (FastAPI + workers GPU/CPU, index vectoriel, stockage objet).
- **Accès :** `use` = `ROLE_RESEARCHER` ; `expert` = `ROLE_COMITE` ; `admin` = `ROLE_ADMIN`.
- **Capacités :** `identity`, `publications:read` (figures/PDF), `storage`, `queue`, `events`, `mailer` (optionnel).
- **Service :** `FIGTRK_BASE_URL`, auth bearer (`FIGTRK_API_KEY`), webhooks signés (`FIGTRK_WEBHOOK_SECRET`) pour `analysis.completed` / `finding.created` / `report.ready`.
- **Isolation :** conteneur ML séparé, sans réseau sauf connecteurs autorisés, conforme au §11 et à `figTrack/SPECS.md §34.3`.

---

## 13. Contrat d'intégration minimal (résumé)

**Entrée module → hôte (à l'activation) :** manifeste valide + migrations + assets.
**Sortie hôte → module (à l'exécution) :** identité + rôles, capacités accordées, réglages namespacés, transport de file, contexte de locale.

```json
{
  "module": { "slug": "analyses", "prefix": "ANALYS", "version": "1.0.0" },
  "host":   { "scienceswiki_version": "8.1.0", "sdk_api": "1" },
  "grant":  { "capabilities": ["identity","publications:read","vector-search","llm:generate","pdf:render","queue"] },
  "context":{ "user": { "id": 42, "roles": ["ROLE_RESEARCHER"] }, "locale": "fr-FR" }
}
```

---

## 14. Critères d'acceptation

1. **Isolation** — un module ne peut créer/modifier aucune table du cœur ; toutes ses tables/réglages/routes/événements sont préfixés ; deux modules ne partagent aucun namespace.
2. **Accès** — un rôle d'usage ne peut jamais atteindre la section `admin` ni modifier un réglage `scope: admin` ; un module réservé aux chercheurs est invisible aux autres rôles (menu, hub, routes → 403/absent).
3. **Compatibilité** — un module hors plage de version est marqué `incompatible` et **non monté**, sans casser l'hôte ni les autres modules.
4. **Moindre privilège** — une capacité non accordée est refusée par le broker avec une erreur claire ; aucune donnée hors périmètre n'est exposée.
5. **Hooks** — l'entrée de menu, les assets (CSP-conformes) et la page hub apparaissent/disparaissent selon activation + rôle + compatibilité.
6. **Cycle de vie** — install/enable/disable/upgrade/uninstall sont idempotents, audités, et n'exigent pas de redéploiement du cœur.
7. **External** — un service externe indisponible dégrade la façade proprement (pas de 500) ; les webhooks non signés/rejoués sont rejetés.
8. **Refactor analyses** — les analyses actuelles (AXIS/RoB 2/AMSTAR 2/MMAT) fonctionnent à l'identique via le module, données migrées, et un nouveau référentiel s'ajoute **sans toucher au cœur**.

---

## 15. Feuille de route

### Phase 1 — Noyau du framework
Module Kernel, `module_registry`, chargement + validation du manifeste, préfixe/namespacing, barrière de compatibilité, SDK minimal (`identity`, `settings`, `publications:read`), page hub, injection menu/assets, sections gardées + section admin.

### Phase 2 — SDK complet & cycle de vie
Capacités `vector-search`, `llm:generate`, `pdf:render`, `queue`, `events`, `storage`, `mailer` ; migrations de module ; install/enable/disable/upgrade/uninstall ; audit.

### Phase 3 — Module `analyses` (standalone, base partagée)
Construction **from scratch** du routeur composite et des plugins de référentiels (cf. `analyses/SPECS.md`), sur tables `analys_*`, **sans toucher à l'analyse legacy** ; projections par rôle. Validation en coexistence.

### Phase 4 — Module `figTrack` (standalone Python/ML)
Enregistrement de service, webhooks signés, health, proxy/façade, isolation conteneur, accès corpus via ports/base partagée.

### Phase 4bis — Bascule legacy (après validation)
Uniquement une fois les modules validés : migration/retrait de l'analyse legacy et branchement de la nouvelle analyse dans SciencesWiki (§12.1, travail ultérieur).

### Phase 5 — Durcissement
Quotas/limits par module, observabilité (métriques par préfixe), marketplace interne de modules, tests de conformité automatisés du contrat.

---

## 16. Décision d'architecture structurante

> Le **préfixe** isole tout ce que produit un module.
> La **compatibilité versionnée** protège le cœur des modules, et les modules entre eux.
> Les **capacités** donnent au module le strict nécessaire, accordé par l'administrateur.
> Les **rôles** décident de l'accès ; la section **admin** reste hors de portée des utilisateurs.
> Les **hooks** insèrent le module dans l'hôte sans que le cœur connaisse le module.
> Le **SDK** (ports) est le seul chemin vers les données et services du cœur.

Cette architecture permet d'ajouter des modules — y compris des services non-PHP (Python/ML) — **sans modifier le cœur**, tout en garantissant isolation, sécurité et maintenabilité.
