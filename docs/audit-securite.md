# Audit de sécurité — SciencesWiki (Symfony 8 / API Platform / FrankenPHP)

> **Périmètre.** Audit applicatif de `apps/api` (Symfony 8.1 + API Platform 4.3,
> servi par FrankenPHP) et `apps/web` (front Twig SSR, FrankenPHP), plus la couche
> reverse-proxy nginx (`infra/heimdall`). L'audit du durcissement Docker et de
> l'environnement conteneurisé fait l'objet d'un document dédié :
> [`audit-docker.md`](audit-docker.md).
>
> **Date.** 1ᵉʳ juillet 2026 · **Version applicative auditée.** v0.74
> **Méthode.** Lecture directe du code (chemins:lignes cités), pas de scan dynamique.
>
> **Barème de criticité.** 🟢 Faible · 🟡 Moyen · 🟠 Élevé · 🔴 Critique.
> La criticité tient compte du contexte réel : homelab derrière reverse-proxy nginx,
> exposition publique limitée à `scienceswiki.eu` (front) et `/api` (lecture publique),
> back-office `ROLE_ADMIN`, services internes (Adminer, Open WebUI, base) sur le LAN.

---

## 0. Synthèse exécutive

L'application est **globalement saine sur les classes de failles techniques classiques** :
pas d'injection SQL exploitable (paramètres bindés / whitelists), pas de
`shell_exec/system/passthru`, mot de passe jamais exposé par la sérialisation,
API Platform réduite à **3 entités en lecture seule** avec groupes explicites, JWT
jamais transmis au navigateur (stocké côté serveur en session), CSRF systématique sur
les écritures du back-office, rendu Markdown en mode échappé, et un proxy PDF avec
anti-SSRF robuste.

Les vrais points d'attention sont **au niveau de l'authentification et de
l'exploitation** plutôt que du code métier :

| # | Risque | Criticité | Où |
|---|--------|-----------|-----|
| A1 | Aucun rate limiting / login throttling (`/api/login_check`, `/api/register`) | 🟠 Élevé | `security.yaml`, `framework.yaml` |
| A2 | JWT TTL 8 h, sans refresh ni révocation | 🟡 Moyen | `lexik_jwt_authentication.yaml:7` |
| A3 | `/api/rag` hors firewall, `RAG_API_TOKEN` optionnel (vide par défaut) | 🟠 Élevé | `security.yaml:47-50`, `.env:79` |
| A4 | `JWT_PASSPHRASE` dev en clair committé + embarqué dans l'image | 🟠 Élevé | `apps/api/.env:85` |
| A5 | Token de bypass maintenance en dur dans le code | 🟡 Moyen | `web/.../MaintenanceSubscriber.php:23` |
| A6 | Headers HTTP de sécurité incomplets sur le front (HSTS/nosniff/XFO au niveau app) | 🟡 Moyen | `CspSubscriber.php`, Caddyfile |
| A7 | `WikiController::vote` sans CSRF (mitigé SameSite=lax) | 🟢 Faible | `web/.../WikiController.php:524` |
| A8 | Inscription sans vérification d'e-mail, politique MDP minimale (≥8) | 🟡 Moyen | `RegistrationController.php` |
| A9 | Pas de reset password, pas de 2FA | 🟢 Faible | (fonctionnels manquants) |
| A10 | `MERCURE_JWT_SECRET` / `MERCURE_CORS_ORIGINS` à défaut vide/wildcard | 🟡 Moyen | `mercure.yaml`, Caddyfile, compose |
| A11 | Doc API (Swagger/Hydra HTML) exposée en prod | 🟢 Faible | `api_platform.yaml:11` |
| A12 | Open redirect mineur via `back`/Referer non validés | 🟢 Faible | `web/.../ContribController.php:186,121` |
| A13 | Bourrage de votes via en-tête `X-Voter-Ip` usurpable | 🟡 Moyen | `AnswerVoteController.php:121` |
| A14 | `ROLE_RESEARCHER` auto-attribuable sans vérif e-mail → outils LLM ouverts | 🟡 Moyen | `RegistrationController.php`, `security.yaml:64` |

Les points d'infrastructure conteneurisée (root, Adminer/Open WebUI exposés, images non
épinglées, absence de `cap_drop`/`no-new-privileges`) sont traités dans `audit-docker.md`.

---

## 1. Authentification

### 1.1 Login (JWT)  — 🟢 sain sur le principe

- **Détecter :** `config/packages/security.yaml` firewall `login` (`^/api/login_check$`,
  `json_login`, `username_path: email`, handlers Lexik), `stateless: true`.
- **Constat :** login e-mail + mot de passe → JWT. Le front (`apps/web`) stocke le JWT
  **en session serveur** (`UserApiClient.php:22`, clé `auth_jwt`) et ne l'expose jamais
  au JavaScript. Bon design.
- **Correctif :** RAS sur le mécanisme. Voir 1.5 (throttling) et 1.6 (TTL).

### 1.2 Mots de passe & hashage — 🟡 Moyen

- **Constat :** `password_hashers: 'auto'` (`security.yaml:2-3`) → bcrypt/argon2id selon
  la plateforme, salage géré par Symfony. `User::eraseCredentials()` vide (normal, pas de
  credential en clair conservé). En test, `cost: 4` (acceptable, isolé par `when@test`).
- **Mauvaise pratique présente :** politique de mot de passe **réduite au minimum 8
  caractères** (`RegistrationController.php:59`), sans complexité, sans vérification de
  fuite (HIBP), sans `NotCompromisedPassword`.
- **Correctif :**
  ```php
  // RegistrationController — remplacer le contrôle « longueur >= 8 » par une contrainte
  use Symfony\Component\Validator\Constraints as Assert;

  #[Assert\Length(min: 12)]
  #[Assert\NotCompromisedPassword]           // vérifie la base Have I Been Pwned
  #[Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM)]
  public string $password;
  ```
- **Outils :** `symfony/validator` (déjà présent), contrainte `NotCompromisedPassword`.
- **Criticité :** 🟡 Moyen.

### 1.3 Reset password — 🟢 Faible (fonctionnel absent)

- **Constat :** **inexistant**. Aucun `symfonycasts/reset-password-bundle`, aucun flux
  « mot de passe oublié ». Un compte dont le mot de passe est perdu doit être réinitialisé
  par un admin (les MDP temporaires sont affichés en clair dans les flash BO, cf. 11.x).
- **Correctif :** si un reset self-service est ajouté, utiliser
  `symfonycasts/reset-password-bundle` (tokens à usage unique, expirants, hashés en base),
  **jamais** un token deviné/rejouable ; e-mail de reset via le Mailer déjà configuré.
- **Criticité :** 🟢 Faible (mais impact opérationnel).

### 1.4 Brute force / credential stuffing — 🟠 Élevé  **(priorité #1)**

- **Détecter :** chercher `login_throttling` dans `security.yaml` et `rate_limiter` dans
  `framework.yaml` → **absents** (seulement présents dans `config/reference.php`, doc générée).
- **Constat :** `/api/login_check` et `/api/register` (endpoints publics) n'ont **aucune
  limitation**. Bruteforce de mots de passe et création massive de comptes non bridés.
- **Correctif :**
  ```yaml
  # config/packages/security.yaml — throttling natif Symfony sur le firewall login
  security:
      firewalls:
          login:
              # …
              login_throttling:
                  max_attempts: 5          # par minute et par (IP + identifiant)
                  interval: '15 minutes'
  ```
  ```yaml
  # config/packages/rate_limiter.yaml — limiteur pour /api/register (et /api/rag)
  framework:
      rate_limiter:
          register:
              policy: 'sliding_window'
              limit: 5
              interval: '1 hour'
  ```
  Appliquer `register` dans `RegistrationController` via injection de
  `RateLimiterFactory $registerLimiter` et `->consume()->isAccepted()`.
- **Outils :** `composer require symfony/rate-limiter`. Test : `hydra`, `patator`, ou un
  simple script bouclant sur `/api/login_check` pour vérifier le `429`.
- **Criticité :** 🟠 Élevé.

### 1.5 Double authentification (2FA) — 🟢 Faible (absent)

- **Constat :** aucune implémentation (`scheb/2fa` n'apparaît qu'en dépendance transitive
  de `composer.lock`). Acceptable pour le public ; **recommandé pour `ROLE_ADMIN`**.
- **Correctif :** `scheb/2fa-bundle` + `scheb/2fa-totp` restreint aux rôles d'administration.
- **Criticité :** 🟢 Faible (🟡 pour les comptes admin).

### 1.6 Durée de session, usurpation, remember-me — 🟡 Moyen

- **Constat :**
  - JWT `token_ttl: 28800` (**8 h**, `lexik_jwt_authentication.yaml:7`), **sans refresh
    token ni liste de révocation**. Un JWT volé reste valide 8 h, impossible à invalider.
  - Session front `cookie_lifetime: 28800` alignée (`web/.../framework.yaml`).
  - **Pas de « remember me »** configuré (ni API ni front) → pas de surface associée.
- **Correctif :**
  - Réduire le TTL JWT (ex. 1 h) + ajouter `gesdinet/jwt-refresh-token-bundle` (refresh
    tokens en base, révocables).
  - Pour la révocation immédiate en cas de compromission : blacklist JWT (jti + cache Redis).
- **Criticité :** 🟡 Moyen.

### 1.7 API / JWT / OAuth — 🟢 sain, points à durcir

- **Constat :** JWT Lexik v3.2, clés RSA générées au 1ᵉʳ boot dans le volume `jwt_keys`.
  Passphrase via env. Pas d'OAuth/OIDC (SSO Open WebUI géré par forward-auth nginx).
- **Point dur :** l'endpoint `/api/rag` est **hors firewall** (`security: false`,
  `security.yaml:47-50`), protégé par un `RAG_API_TOKEN` **optionnel** (vide par défaut,
  `.env:79`). Si non renseigné en prod → **endpoint LLM ouvert** (coût, abus, fuite de
  prompt). Voir §2.4.

---

## 2. Autorisation

### 2.1 Rôles & hiérarchie — 🟢 sain

- **Fichier :** `security.yaml:16-25`. `ROLE_ADMIN` hérite de tout ; hiérarchie cohérente
  (auteur → chercheur/rédacteur → comité/modérateur → admin ; élève → enseignant).
- Le front `apps/web` **duplique** la hiérarchie (`UserApiClient.php:26-35`) mais uniquement
  pour l'affichage — commentaire explicite « la sécurité réelle est appliquée côté API »
  (`UserApiClient.php:16-17`). Correct, mais **veiller à garder les deux miroirs synchro**.

### 2.2 access_control — 🟢 sain, fail-open assumé

- **Fichier :** `security.yaml:60-72`. Ordre correct (règles spécifiques avant le fallback).
  Dernière règle `^/api → PUBLIC_ACCESS` : **toute l'API en lecture est publique par défaut**.
  C'est **voulu** (encyclopédie publique) mais implique que **toute nouvelle route sensible
  doit être explicitement placée sous `/api/me`, `/api/admin`, ou protégée par un voter** —
  sinon elle est publique.
- **Recommandation :** documenter cette convention (« deny by default » inversé) en tête de
  `security.yaml`, et ajouter un test qui vérifie qu'aucune route d'écriture n'est publique.

### 2.3 Voters & contrôle d'accès objet — 🟢 sain

- **Fichiers :** `src/Security/Voter/AnswerVoter.php`, `ArticleVoter.php`.
  - `ANSWER_VALIDATE` = `ROLE_COMITE` **+ `hasExpertiseOn(node)`** (contrôle fin par domaine).
  - `ARTICLE_EDIT` = `ROLE_REDACTEUR` ; `ARTICLE_VALIDATE` = `ROLE_MODERATEUR` ou comité+expertise ; bypass admin.
- **Contrôle d'accès horizontal (IDOR) :**
  - **Bon :** `LiteratureReviewStoreController.php:110` vérifie l'ownership
    (`$review->getUser()->getId() !== currentUser && !isGranted('ROLE_ADMIN')`).
  - `MeController` : renvoie uniquement `security->getUser()` (pas d'ID en paramètre → pas d'IDOR).
  - `MeClassController.php:196` : accès classe restreint `ROLE_TEACHER` + user courant.
  - Les `/api/me/axis|rob2|amstar2|mmat` opèrent sur des **publications publiques** (pas de
    ressource « détenue ») → autorisation par rôle, pas d'IDOR.
- **Remarque :** **aucun `#[IsGranted]`** attribut — l'autorisation objet passe par des appels
  `isGranted()` impératifs dans les contrôleurs. Fonctionnel, mais **plus facile à oublier**
  sur une nouvelle route. Préférer l'attribut `#[IsGranted('ARTICLE_EDIT', subject: 'article')]`
  quand c'est possible (auto-documenté, moins d'oubli).

### 2.4 Routes d'administration & endpoints API — 🟠 Élevé (RAG)

- **Constat :** `^/api/admin → ROLE_ADMIN` (blanket firewall, correct). Le back-office web
  double la garde (`AdminApiClient::isLogged()` = connecté **et** `ROLE_ADMIN`).
- **Point dur — `/api/rag` :** hors firewall (§1.7). **Correctif :**
  ```env
  # .env.prod — RENDRE OBLIGATOIRE le jeton du RAG (généré : openssl rand -hex 32)
  RAG_API_TOKEN=<jeton_fort>
  ```
  et faire échouer le démarrage si `APP_ENV=prod` et `RAG_API_TOKEN` vide (garde dans
  `RagChatController` ou un compiler pass). Restreindre l'accès réseau à Open WebUI (LAN).
- **Criticité :** 🟠 Élevé tant que le token n'est pas imposé en prod.

---

## 3. Sessions & cookies

- **Fichier :** `apps/web/config/packages/framework.yaml:25-35`.
  - `save_path: var/sessions` (volume Docker → survit aux rebuilds).
  - `cookie_lifetime: 28800`, `gc_maxlifetime: 28800` (8 h).
  - `cookie_secure: auto` (✅ Secure derrière HTTPS), `cookie_samesite: lax` (✅ bloque le
    POST cross-site → mitige le CSRF), `cookie_httponly` **non défini → défaut Symfony `true`**
    (✅ cookie inaccessible au JS).
  - `cookie_domain: '%env(SESSION_COOKIE_DOMAIN)%'` — vide en dev, **`.scienceswiki.eu` en
    prod** pour partager la session avec le sous-domaine `chat` (SSO Open WebUI).
- **Fixation de session :** Symfony régénère l'ID à l'authentification (comportement natif).
- **Points de vigilance :**
  - 🟡 **Partage de session cross-subdomain** (`.scienceswiki.eu`) : élargit la surface — tout
    sous-domaine compromis peut lire le cookie de session. Limiter le nombre de sous-domaines
    et s'assurer qu'ils sont tous de confiance.
  - 🟢 Rendre `cookie_httponly: true` **explicite** (ne pas dépendre du défaut).
- **Criticité :** 🟢–🟡.

---

## 4. CSRF

- **API :** stateless JWT (`stateless: true` sur tous les firewalls) → CSRF classique non
  applicable (pas de cookie d'auth ambiant côté API). Les écritures exigent le `Bearer`.
- **Back-office / contrib (front web) :** `App\Service\AdminCsrf` — un jeton par session
  (`bin2hex(random_bytes(16))`), injecté dans les formulaires (`_csrf`) et vérifié par
  `hash_equals` (`AdminCsrf.php:42`). **Toutes** les écritures admin/contrib vérifient le
  token (formulaires **et** fetch JSON via `isValidToken`).
- **Exception — 🟢 Faible :** `WikiController::vote` (`/fr/q/{id}/vote`,
  `web/.../WikiController.php:524`) est un **POST sans vérification CSRF**, contrairement à
  toutes les autres écritures. Mitigé par `cookie_samesite: lax` (bloque le POST cross-site),
  mais **incohérent**. **Correctif :** ajouter `$this->csrf->isValidToken(...)` comme ailleurs.
- **Désactivation involontaire :** aucune trouvée (`csrf_protection` non désactivé globalement).
- **Criticité :** 🟢 Faible.

---

## 5. XSS

- **Autoescape Twig :** activé partout (défaut). **Aucun** `{% autoescape false %}`,
  `|nl2br`, ni `html_classes`.
- **`|raw` :** tous les usages sont `... |json_encode|raw` (encodage JSON injecté dans du
  JS/JSON), jamais du HTML utilisateur brut — ex. `explorer.html.twig:82`,
  `contribute.html.twig:32`, `harvest.html.twig:163,169`, `wiki_detail.html.twig:43`,
  `article.html.twig:49,51,59`.
  - 🟢 **Nuance :** `json_encode` **sans `JSON_HEX_TAG`** reste théoriquement sensible si une
    valeur contenait `</script>`. Ici les données sont des slugs/tokens/valeurs d'API
    contrôlées → risque faible. **Durcissement :** ajouter le flag.
    ```twig
    {# harden: échappe <, >, &, ' pour un contexte <script> #}
    var SLUG = {{ slug|json_encode(constant('JSON_HEX_TAG') b-or constant('JSON_HEX_APOS'))|raw }};
    ```
- **Markdown → HTML (`src/Twig/MarkdownExtension.php`, filtre `|md`) :** **configuration
  sûre** — `html_input => 'escape'` (le HTML brut du contenu est échappé),
  `allow_unsafe_links => false`, `max_nesting_level => 50`, League/CommonMark. Utilisé sur du
  contenu wiki/réponses. RAS.
- **Rendu Markdown côté client (`node.html.twig:335-361`, `mdToHtml` → `innerHTML`) :**
  applique `esc()` (échappe `& < >`) **avant** les regex, et la regex de lien n'autorise que
  `https?://`. Surface XSS-DOM limitée mais réelle si l'API renvoyait du contenu malveillant
  (flux SSE d'une réponse IA générée côté serveur). 🟢 acceptable, à garder sous revue.
- **Rendu client explorer (`explorer.html.twig`) :** données API passées par `esc()`
  (`textContent`) avant `innerHTML`. Bien fait.
- **CSP (voir §12.2)** apporte une deuxième barrière (`script-src 'self' 'nonce-…'`, pas de
  `unsafe-inline` sur les scripts).
- **Criticité :** 🟢 Faible.

---

## 6. Injection SQL — 🟢 sain

- **Doctrine ORM / QueryBuilder / DQL :** paramètres bindés (`setParameter`) partout.
- **SQL natif (`Connection::executeQuery/executeStatement`) :** revu — les rares
  concaténations portent sur :
  - des **entiers** calculés (`PublicationRepository.php:82,254`, `AdminSnapshotController`
    `LIMIT %d`) → non injectable ;
  - des **littéraux fixes** (`$typeClause` = `''` ou clause figée, valeurs bindées,
    `PublicationRepository.php:241-246,280`) ;
  - des **clés whitelistées par regex** `^[a-z0-9_]{1,32}$` avant inlining
    (`PublicationRepository.php:688`) ;
  - des **tableaux d'IDs internes** (`'{'.implode(',',$pageIds).'}'`, `:669`).
- **Recherche/tri utilisateur :** `/api/search` (`SearchController` → `textSearch`) passe `q`
  utilisateur **uniquement via `LIKE :q` bindé** (`PublicationRepository.php:453-454`).
  `OrderFilter`/`SearchFilter` d'API Platform sont sur des propriétés whitelistées.
- **Aucun `createNativeQuery`.** **Verdict :** pas d'injection SQL identifiée.
- **Vérifier à l'avenir :** toute nouvelle clause `ORDER BY`/`LIMIT` dynamique doit rester
  sur des colonnes/valeurs whitelistées.
- **Criticité :** 🟢 Faible.

---

## 7. Injection de commandes — 🟡 Moyen (à surveiller)

- **`AdminSnapshotController.php:64` — `exec('sh -c '.escapeshellarg($inner))` :**
  route `POST /api/admin/harvest/snapshot/relaunch` (**ROLE_ADMIN**). `$inner` est construit
  avec `escapeshellarg($console)` (chemin binaire) et un `%d` (`$skip`, **entier** dérivé
  d'une valeur en base `done_files`, `:50-51`). **Aucune entrée HTTP n'alimente la commande**
  → **non injectable par le client**. Reste une **exécution shell arbitraire côté serveur**
  (setsid/nohup/kill) dont l'intégrité dépend d'une valeur en base et de l'accès admin.
  - **Correctif recommandé :** remplacer `exec('sh -c ...')` par un `Symfony\Component\Process`
    en **forme tableau** (pas de shell), ou déclencher la relance via un **message Messenger**
    plutôt qu'un process détaché piloté par HTTP. Cela supprime la surface shell.
- **`FulltextIngester.php:305` — `proc_open(['pdftotext', ...], ...)` :** **forme tableau
  (pas de shell)**, `$pdfPath` = fichier temporaire passé en argument séparé → **non
  injectable**. 🟢 RAS.
- **Aucun `shell_exec`, `system`, `passthru`, `popen`.**
- **Traitement d'archives / PDF externes :** le risque résiduel est le **parsing d'un PDF
  malveillant par GROBID / `pdftotext`** (surface de dépendance, pas d'injection web).
- **Criticité :** 🟡 Moyen (durcir l'`exec`).

---

## 8. Upload de fichiers — 🟢 sain

- **Pas de VichUploader.** Deux points d'upload, tous deux **PDF uniquement** :
  - **Contrib public gaté par token** (`ContributeController.php`, `POST /api/contribute/{token}`,
    token `[a-f0-9]{32,64}` à usage unique) : taille ≤ 30 Mo, `guessExtension() == 'pdf'`
    (basé sur le contenu), **magic byte `%PDF`** (`:73`), écrit en `tempnam` puis `@unlink`
    → **pas de stockage persistant ni de chemin contrôlé par l'utilisateur**.
  - **Upload admin** (`AdminPdfUploadController.php`, ROLE_ADMIN) : `MAX_PDF_BYTES = 30M`,
    ext `pdf`, magic byte `%PDF`, non persisté (envoyé à GROBID).
  - **Cover d'image admin** (`web/.../AdminController::uploadCover`) : extensions whitelist
    `jpg/jpeg/png/webp`, ≤ 5 Mo, **nommée `{node.id}.{ext}`** (nom non contrôlé par
    l'utilisateur), dans `public/uploads/domains`, ROLE_ADMIN + CSRF.
- **Exécution de fichiers uploadés :** impossible — PDF non persistés ; images nommées par
  l'app, servies en statique (pas d'exécution PHP dans `public/uploads`).
- **Antivirus :** absent. **Recommandation** (durcissement) : passer les PDF entrants à
  **ClamAV** avant traitement (surtout la voie contrib publique).
- **Criticité :** 🟢 Faible.

---

## 9. Exposition de données

- **`APP_DEBUG` / profiler :** `APP_ENV=prod` forcé par les images (`Dockerfile:21/27`) et le
  compose. Pas de `web-profiler-bundle` en prod (dev only). `doctrine_migrations.yaml:6`
  `enable_profiler: false`. `doctrine.yaml` `profiling_collect_backtrace: '%kernel.debug%'`
  (off en prod). **Pas de profiler exposé.** 🟢
- **Erreurs / stack traces :** en prod, Symfony masque les traces (page d'erreur générique).
  À **vérifier** qu'aucun `dump()`/`dd()` ne traîne (grep recommandé au CI, cf. §Outils).
- **Doc API — 🟢 Faible :** `api_platform.yaml:11` expose la doc HTML (Swagger/Hydra) en prod
  (`docs_formats.html`). Fuite d'information sur la structure de l'API. **Correctif** si
  souhaité : `api_platform.enable_docs: false` en prod, ou restreindre par IP au reverse-proxy.
- **Variables d'env / `.env` :** `.env`, `.env.dev`, `.env.test` versionnés (valeurs dev).
  **`JWT_PASSPHRASE` dev en clair** dans `apps/api/.env:85` (cf. A4 / §Secrets). En prod,
  `APP_SECRET` vide dans `.env` et injecté par l'environnement.
- **RGPD / données personnelles :** l'entité `User` stocke email, nom réel, ORCID,
  affiliation, IP des votants (`X-Voter-Ip`). **Recommandations :** registre des traitements,
  politique de rétention (logs, IP), procédure d'export/suppression, base légale des e-mails
  (newsletter → double opt-in). L'audit log (`ActivityLog`) capture des actions — définir la
  durée de conservation.
- **Exports CSV :** vérifier l'échappement anti « CSV injection » (préfixer par `'` les
  cellules commençant par `= + - @`) si des exports utilisateur existent.
- **Criticité :** 🟢–🟡.

---

## 10. API (API Platform) — 🟢 sain

- **Entités exposées :** **seulement 3** — `Publication`, `Answer`, `TreeNode` — toutes en
  **lecture seule** (`operations: [GetCollection, Get]`), avec `normalizationContext` +
  `#[Groups]` **explicites sur chaque propriété** (whitelist). Pagination bornée
  (`Answer` : 30/page). Filtres `SearchFilter`/`OrderFilter` sur propriétés whitelistées.
- **`User` n'est PAS un ApiResource** et ne porte aucun `#[Groups]` → **`password` jamais
  sérialisé**. Aucune exposition involontaire d'entité.
- **Écritures :** **aucune** opération POST/PUT/PATCH/DELETE via API Platform ; toutes les
  mutations passent par des contrôleurs custom protégés (`/api/admin`, `/api/me`, voters).
- **CORS :** `nelmio_cors.yaml` — `allow_origin: ['%env(CORS_ALLOW_ORIGIN)%']`,
  `origin_regex: true`, appliqué à `^/`. Prod : `^https://scienceswiki\.eu$`. **Pas de
  `allow_credentials`** (défaut false, cohérent avec l'auth Bearer). **Vérifier** que la
  valeur de prod ne devienne jamais permissive (`.*`).
- **Rate limiting API :** absent (cf. §1.4) — au minimum protéger `/api/search`, `/api/rag`,
  et les endpoints LLM coûteux (`/api/me/axis`…).
- **Criticité :** 🟢 (design), 🟡 sur le rate limiting.

---

## 11. Configuration Symfony

- **`framework.yaml` :** `trusted_proxies: '%env(TRUSTED_PROXIES)%'` (défaut `127.0.0.1`),
  `trusted_headers` limités aux `x-forwarded-*` utiles. **Pas de `trusted_hosts`** → 🟡
  ajouter une contrainte pour bloquer le Host spoofing / cache poisoning :
  ```yaml
  framework:
      trusted_hosts: ['^(.*\.)?scienceswiki\.eu$']
  ```
- **`TRUSTED_PROXIES` par défaut trop large** (`.env.prod.example:25` :
  `10.0.0.0/8,172.16.0.0/12,192.168.0.0/16`) — 🟡 restreindre à l'**IP de Heimdall**
  (`192.168.1.195/32` + réseau Docker) comme le note le README. Un `trusted_proxies` trop
  large permet à un client interne de forger `X-Forwarded-For` (usurpation d'IP dans les logs,
  contournement de futurs contrôles par IP).
- **`security.yaml` firewall `dev`** (`^/(_profiler|_wdt|assets|build)/`, `security: false`) :
  inoffensif hors env dev.
- **Secrets :** Symfony Secrets vault utilisé (dev committé chiffré **sans clé privée**) ;
  en prod, `SYMFONY_DECRYPTION_SECRET` + volume `secrets_vault`. Bon usage.
- **Criticité :** 🟡 (trusted_proxies / trusted_hosts).

---

## 12. Front-end (Twig / assets)

### 12.1 Communication front → API — 🟢 sain
JWT stocké **en session serveur**, envoyé en `Authorization: Bearer` **uniquement** dans les
appels serveur→API. Les écritures navigateur passent par des routes proxy **même-origine** qui
rattachent le JWT côté serveur. **Aucun token en `localStorage`/`sessionStorage`** — vérifié
dans `public/js/crt.js` (n'y stocke que l'état cosmétique du thème).

### 12.2 CSP & headers — 🟡 Moyen
- **CSP** posée par `web/.../EventSubscriber/CspSubscriber.php` sur les réponses `text/html` :
  `default-src 'self'` ; `script-src 'self' 'nonce-…' https://analytics.phracktale.com` (pas
  de `unsafe-inline` sur les scripts ✅) ; `object-src 'none'` ; `frame-ancestors 'self'` ;
  `base-uri 'self'` ; `form-action 'self'`. Faiblesses **assumées** :
  `style-src 'self' 'unsafe-inline'` (attributs `style=` omniprésents) et
  `img-src 'self' data: https:` (toute origine HTTPS).
- 🟡 **Nonce stable par session** (`CspNonce.php`, choix compat Turbo Drive) plutôt que par
  requête → plus faible qu'un nonce per-requête (mais reste imprévisible). Documenté.
- 🟡 **Headers manquants au niveau applicatif** (front) : `Strict-Transport-Security`,
  `X-Frame-Options`, `X-Content-Type-Options: nosniff` **global**, `Referrer-Policy`. **Ils
  sont bien posés par nginx/Heimdall** (`scienceswiki.eu.conf:50-54`, HSTS `preload` inclus),
  donc **couverts en prod** — mais l'app est **dénuée de filet** si un jour elle est servie
  sans ce proxy. **Correctif :** poser un socle de headers dans `CspSubscriber` (défense en
  profondeur) :
  ```php
  $h = $response->headers;
  $h->set('X-Content-Type-Options', 'nosniff');
  $h->set('X-Frame-Options', 'SAMEORIGIN');
  $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
  // HSTS : laisser nginx le poser (il connaît le contexte TLS réel).
  ```
- **Criticité :** 🟡 (couvert par nginx, mais fragile).

### 12.3 Dépendances JS — 🟢
Pas de build npm (aucun `package.json`). JS vendored statique (Turbo, 3d-force-graph, PDF.js
`v4.7.76` épinglé, `crt.js`). **Recommandation :** consigner les versions/SHA des libs
vendored et les mettre à jour comme les autres dépendances (PDF.js notamment).

### 12.4 Open redirect — 🟢 Faible
`ContribController::postLoginTarget` valide le paramètre `back` (doit commencer par `/` et pas
`//`) → pas d'open redirect. **Mais** `ContribController::back()` (`:186`) et `logout()`
(`:121`) redirigent vers `back`/`Referer` **sans cette validation**. **Correctif :** appliquer
la même validation « local-safe » partout.

---

## 13. Infrastructure & reverse-proxy

- **HTTPS / TLS :** terminé par **nginx (Heimdall)**, Let's Encrypt, `TLSv1.2/1.3`, ciphers
  ECDHE/GCM, OCSP stapling. FrankenPHP écoute en HTTP clair `:80` (`auto_https off`) — **OK
  car derrière le proxy sur réseau privé**.
- **HSTS :** `max-age=63072000; includeSubDomains; preload` (`scienceswiki.eu.conf:50`). ✅
- **Headers de sécurité :** posés par nginx (nosniff, XFO SAMEORIGIN, Referrer-Policy,
  Permissions-Policy) + CSP par l'app. ✅
- **Forward-auth SSO (Open WebUI)** : nginx **efface** tout `X-SW-Auth-*` entrant et ne pose
  que la valeur de confiance (`chat.scienceswiki.eu.conf:90-95`) → anti-usurpation correct.
  ⚠️ **Dépend d'une config nginx hors dépôt** ; si Open WebUI est joignable **en direct**
  (port LAN `:8092`), l'auth est contournée (cf. `audit-docker.md` §Réseau).
- **Permissions fichiers / propriétaire / droits d'écriture / cron / workers Messenger /
  Redis / SSH / pare-feu :** traités dans `audit-docker.md`.
- **Criticité :** 🟢 (proxy bien configuré), points Docker reportés.

---

## 14. Base de données

- **Constat :** PostgreSQL + pgvector, migrée sur Marvin (`DB_HOST`), **liée explicitement à
  l'IP LAN** `192.168.1.171:5432` (pas `0.0.0.0`) → non exposée WAN. Bind-mount persistant
  `/data/scienceswiki-pgdata`, `--data-checksums`.
- **Privilèges SQL :** l'app se connecte avec `POSTGRES_USER` (`scienceswiki`) — **superuser
  de sa base**. 🟡 **Recommandation :** créer un rôle applicatif **non-propriétaire** avec
  seulement `SELECT/INSERT/UPDATE/DELETE` sur le schéma métier (les migrations DDL peuvent
  utiliser un rôle distinct au déploiement). Réduit l'impact d'une injection/compromission.
- **Chiffrement :** pas de chiffrement au repos mentionné. Selon la sensibilité (données
  personnelles auteurs/élèves), envisager le chiffrement du volume (LUKS) ou `pgcrypto` sur
  les colonnes sensibles.
- **Migrations / fixtures :** migrations Doctrine **auto au boot** (`RUN_INIT=1`, cf.
  `audit-docker.md`). **Pas de fixtures chargées en prod** (bon). Sauvegardes : voir
  `audit-docker.md`.
- **Criticité :** 🟡 (privilèges SQL).

---

## 15. E-mails

- **Constat :** Mailer via `MAILER_DSN` (Brevo SMTP en prod, secrets `BREVO_*` dans le vault
  chiffré), défaut `null://null` (n'envoie rien). Un `MailRerouteListener` existe (reroutage
  en non-prod). **Pas de reset password ni de lien magique** (§1.3).
- **Injection d'en-têtes :** Symfony Mailer construit les en-têtes de façon sûre (pas de
  concaténation manuelle de `To`/`Subject`). Vérifier que **toute** adresse/nom provenant de
  l'utilisateur (contact, newsletter, invitation classe) passe bien par l'objet `Address` de
  Symfony (pas de `->getHeaders()->addTextHeader()` avec entrée brute).
- **SPF / DKIM / DMARC :** à configurer côté DNS/Brevo (hors code) — **indispensable** pour la
  délivrabilité et l'anti-spoofing du domaine `scienceswiki.eu`/`.org`.
- **Secrets SMTP :** dans le vault Symfony chiffré (bon), pas en clair dans le compose.
- **Criticité :** 🟢–🟡 (dépend de la conf DNS).

---

## 16. Paiement

**Non applicable** — aucune intégration Stripe/PayPal/Systempay détectée. Le token
`OPENAI_API_KEY: "sk-scienceswiki-rag"` du compose est un **jeton interne du RAG**, pas un
moyen de paiement (voir `audit-docker.md` / A3).

---

## 17. Sécurité métier

- **Contournement de workflow / changement de statut :** les transitions sensibles
  (validation de réponse/article, promotion de rôle via `join-requests`, review de
  duplication) passent par des endpoints `ROLE_ADMIN`/voters — corrects. **Vérifier** qu'un
  `ROLE_REDACTEUR` ne peut pas **auto-valider** son propre contenu (l'`AnswerVoter` exige
  `ROLE_COMITE` + expertise pour `VALIDATE` : ✅).
- **Manipulation de prix :** N/A (pas de paiement).
- **Modification d'identifiants / privilege escalation :** l'auto-inscription
  (`RegistrationController`) est **strictement limitée** à researcher/teacher/student
  (`SELF_ROLES`, `:27-31`) — **pas d'escalade vers ADMIN**. La promotion de rôle est
  `ROLE_ADMIN` only. ✅
- **Double soumission / replay / concurrence :** le token de contribution est **à usage
  unique** (`used_at`, `ContributeController.php:89`). Les votes basculent (idempotents par
  design). **Vérifier** l'atomicité des upserts concurrents (moisson auteurs : commentée comme
  « sûre en concurrence via upsert ORCID »).
- **🟡 Bourrage de votes (A13) — `AnswerVoteController.php:115-124` :** pour un votant
  **anonyme**, la clé de déduplication dérive de l'en-tête **`X-Voter-Ip` fourni par le
  client** (`:121`). En temps normal cet en-tête est posé par le front (`ApiClient.php:249`,
  IP réelle du visiteur). **Mais rien côté serveur ne force cette provenance :** si l'API est
  joignable **en direct** (port `:8000` hors proxy, cf. `audit-docker.md`), un attaquant
  **forge librement `X-Voter-Ip`** → une infinité de clés distinctes → votes illimités sur une
  réponse. **Correctifs :** (1) ne dériver l'IP anonyme que du **`X-Forwarded-For` de
  confiance** (via `Request::getClientIp()` avec `trusted_proxies` correct) et **ignorer
  `X-Voter-Ip`** comme entrée directe ; (2) restreindre l'accès réseau à l'API au seul
  reverse-proxy/front ; (3) idéalement, plafonner les votes anonymes (rate limit) plutôt que
  se fier à l'IP seule.
- **🟡 Barrière de rôle « recherche » cosmétique (A14) :** `ROLE_RESEARCHER` est
  **librement auto-attribuable** à l'inscription (`RegistrationController.php:27-31`) **sans
  vérification d'e-mail ni d'identité** (compte `identityVerified=false` mais JWT délivré
  immédiatement). Or ce rôle déverrouille `^/api/literature-reviews` et les outils AXIS/RoB2…
  (`security.yaml:64`), qui déclenchent des **générations LLM coûteuses**. La barrière
  d'accès est donc de fait ouverte à quiconque s'inscrit. **Correctifs :** double opt-in
  e-mail obligatoire avant activation des rôles à coût, **+ rate limiting** sur ces endpoints
  (§1.4), et distinguer « inscrit » de « vérifié » dans l'autorisation des outils LLM.
- **Validation côté serveur :** présente (register, upload, tailles/MIME) — ne pas se fier au
  front (le front annonce lui-même que ses contrôles de rôle sont « UX seulement »).
- **Criticité :** 🟢 (bien pensé).

---

## 18. Observabilité & réponse à incident

- **Constat :** `ActivityLog` (journal d'audit paginé, `/api/admin/activity`) capture des
  actions sensibles. Les logs applicatifs vont vers stderr (FrankenPHP → logs Docker).
- **Manques / recommandations :**
  - 🟡 **Journaliser explicitement** : échecs de login (avec IP/user-agent), créations de
    compte, promotions de rôle, uploads, suppressions, exports, accès admin. Aujourd'hui sans
    rate limiting, les échecs de login ne sont pas comptés.
  - 🟡 **Alertes** : pas de mécanisme d'alerte (pic d'échecs de login, 5xx, quota LLM). À
    brancher sur la stack homelab (Grafana/Loki/Prometheus si disponible).
  - **Attention RGPD** : l'IP des votants (`X-Voter-Ip`) et les IP de login sont des données
    personnelles → durée de rétention définie.
  - **Plan de réponse à incident** : documenter la rotation d'urgence des secrets (APP_SECRET,
    JWT keypair, MDP DB, tokens RAG/LLM), l'invalidation des JWT (nécessite une blacklist,
    cf. §1.6), et la restauration depuis sauvegarde.
- **Criticité :** 🟡 Moyen.

---

## 19. Checklist d'audit Symfony (prête à l'emploi)

```
Authentification
[ ] login_throttling activé sur le firewall login (max_attempts/interval)
[ ] rate_limiter sur /api/register et /api/rag
[ ] TTL JWT réduit (<= 1 h) + refresh token révocable
[ ] blacklist JWT (jti) pour révocation immédiate
[ ] politique MDP: min 12, NotCompromisedPassword, PasswordStrength
[ ] 2FA (TOTP) pour ROLE_ADMIN
[ ] flux reset password sécurisé (tokens usage unique, expirants, hashés) si ajouté
[ ] vérification e-mail / double opt-in à l'inscription

Autorisation
[ ] access_control: aucune route d'écriture ne tombe dans le fallback PUBLIC_ACCESS
[ ] /api/rag protégé par RAG_API_TOKEN OBLIGATOIRE en prod
[ ] voters testés (edit/validate/ownership) ; #[IsGranted] sur nouvelles routes
[ ] contrôle d'ownership sur toute ressource « détenue » par un user (IDOR)

Sessions / cookies
[ ] cookie_httponly: true explicite
[ ] cookie_secure: auto (ok) ; SameSite=lax/strict
[ ] SESSION_COOKIE_DOMAIN limité aux sous-domaines de confiance

CSRF / XSS
[ ] CSRF sur TOUTES les écritures front (corriger WikiController::vote)
[ ] |raw uniquement avec json_encode(JSON_HEX_TAG|JSON_HEX_APOS)
[ ] Markdown en html_input=escape, allow_unsafe_links=false (ok)
[ ] CSP sans unsafe-inline sur script-src (ok) ; envisager nonce per-requête

Injection
[ ] SQL: params bindés / whitelists (ok) — revoir tout ORDER BY/LIMIT dynamique
[ ] exec(): remplacer sh -c par Process[] ou Messenger (AdminSnapshotController)
[ ] proc_open en forme tableau (ok)

Upload / données
[ ] MIME par magic byte (ok) ; ClamAV sur PDF entrants (contrib public)
[ ] api_platform docs HTML désactivée/restreinte en prod
[ ] pas de dump()/dd() résiduel (grep CI)
[ ] trusted_hosts configuré ; TRUSTED_PROXIES = IP Heimdall
[ ] RGPD: rétention logs/IP, export/suppression, base légale e-mails

Config / secrets
[ ] JWT_PASSPHRASE dev retiré de .env (versionné) — cf. Docker
[ ] APP_SECRET/JWT/MDP DB rotables ; procédure documentée
[ ] MERCURE_JWT_SECRET défini (non vide) ; MERCURE_CORS_ORIGINS restreint
```

## 20. Commandes utiles

```bash
# Dépendances vulnérables (à faire tourner en CI)
composer audit --working-dir=apps/api
composer audit --working-dir=apps/web
symfony security:check                      # base advisories FriendsOfPHP

# Recherche de patterns risqués
grep -rn "dump(\|dd(" apps/*/src            # débogage résiduel
grep -rn "|raw" apps/web/templates          # audit XSS
grep -rnE "\b(exec|shell_exec|system|passthru|proc_open)\b" apps/*/src

# Analyse statique (à ajouter au projet)
vendor/bin/phpstan analyse src              # niveau max
# Règles sécurité: phpstan + rules, ou psalm --taint-analysis (taint SQL/XSS)

# Vérifs Symfony
php bin/console debug:firewall
php bin/console debug:config security
php bin/console lint:yaml config
php bin/console lint:twig templates

# Test brute force (doit renvoyer 429 une fois le throttling en place)
for i in $(seq 1 20); do curl -s -o /dev/null -w "%{http_code}\n" \
  -X POST https://scienceswiki.eu/api/login_check \
  -H 'Content-Type: application/json' \
  -d '{"email":"a@b.c","password":"x"}'; done
```

## 21. Outils recommandés

- **Dépendances :** `composer audit`, `symfony security:check`, Dependabot/Renovate.
- **SAST :** PHPStan (+ règles), **Psalm `--taint-analysis`** (détecte les flux SQL/XSS),
  `roave/security-advisories` en `require-dev`.
- **DAST :** OWASP ZAP (baseline scan contre l'URL de préprod), Nikto.
- **Secrets :** `gitleaks` / `trufflehog` sur l'historique git (cf. A4).
- **Mots de passe :** contrainte `NotCompromisedPassword` (HIBP).
- **CI :** intégrer `composer audit`, PHPStan, `lint:*`, gitleaks en pré-merge.

## 22. Priorisation & plan d'action

### 22.1 Corrections immédiates (jours) — bloquantes
1. **Rate limiting** login + register (+ `/api/rag`) — A1. *(config, faible effort)*
2. **Rendre `RAG_API_TOKEN` obligatoire en prod** + restreindre Open WebUI au LAN — A3.
3. **Retirer `JWT_PASSPHRASE` de `apps/api/.env`** (versionné) et **rotationner** la passphrase
   + regénérer le keypair JWT ; exclure `.env*` du contexte d'image — A4 (cf. Docker).
4. **Retirer le token de bypass en dur** `MaintenanceSubscriber.php:23` → variable d'env
   secrète, ou supprimer — A5.
5. **CSRF sur `WikiController::vote`** — A7.
6. Définir **`MERCURE_JWT_SECRET`** (non vide) et **restreindre `MERCURE_CORS_ORIGINS`** — A10.
7. **Ne plus faire confiance à `X-Voter-Ip`** en entrée directe (dériver l'IP du
   `X-Forwarded-For` de confiance) + restreindre l'accès réseau à l'API au proxy — A13.

### 22.2 Durcissement moyen terme (semaines)
7. **TTL JWT ≤ 1 h + refresh token révocable** + blacklist (jti/Redis) — A2/§1.6.
8. **Politique de mot de passe** (min 12, NotCompromisedPassword) + **vérification e-mail** — A8.
9. **`trusted_hosts`** + **`TRUSTED_PROXIES` = IP Heimdall** — §11.
10. **Socle de headers de sécurité applicatif** (défense en profondeur) — §12.2.
11. **Rôle SQL applicatif non-propriétaire** (privilèges minimaux) — §14.
12. **Remplacer l'`exec('sh -c')`** par `Process[]`/Messenger — §7.
13. **2FA TOTP pour `ROLE_ADMIN`** — §1.5.
14. **Journalisation** des événements sensibles + alertes — §18.
15. **Désactiver/restreindre la doc API HTML** en prod — §9/§10.

### 22.3 Amélioration DevSecOps long terme (trimestre)
16. **CI sécurité** : `composer audit`, PHPStan/Psalm-taint, gitleaks, ZAP baseline, hadolint/Trivy
    (cf. Docker) en pré-merge, build bloquant.
17. **Gestion des secrets** : bascule complète vers Symfony Secrets vault **prod** + rotation
    documentée + `SYMFONY_DECRYPTION_SECRET` hors dépôt.
18. **RGPD** : registre des traitements, rétention (logs/IP), export/suppression self-service.
19. **SPF/DKIM/DMARC** sur le domaine ; monitoring de délivrabilité.
20. **Plan de réponse à incident** écrit (rotation secrets, invalidation JWT, restauration
    sauvegarde) + exercices.
21. **Antivirus (ClamAV)** sur les PDF entrants de la voie contrib publique.
22. **SBOM** (CycloneDX) + scan continu des images (cf. Docker).

---

*Suite : [`audit-docker.md`](audit-docker.md) — durcissement Docker & environnement conteneurisé.*
