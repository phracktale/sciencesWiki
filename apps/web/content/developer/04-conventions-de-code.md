# Conventions de code

> Règles à suivre pour toute contribution. L'objectif : un code **homogène,
> lisible et cohérent avec l'existant**. La meilleure convention est *« écris du
> code qui ressemble au code autour de lui »*.

## 1. Langue

- **Identifiants** (classes, méthodes, variables) : **anglais**.
- **Commentaires, messages de commit, documentation, libellés produit** :
  **français**. C'est un projet francophone, assumé jusque dans le code.

## 2. Mise en forme (EditorConfig)

Un `.editorconfig` est présent dans chaque app PHP. **Configure ton éditeur pour le
respecter** :

```
charset = utf-8
end_of_line = lf            # LF partout (jamais CRLF)
indent_style = space
indent_size = 4             # 2 pour les fichiers compose*.yaml
insert_final_newline = true
trim_trailing_whitespace = true   # sauf .md
```

Les fins de ligne sont aussi normalisées par `.gitattributes` (`eol=lf` sur les
scripts/configs consommés sous Linux ; `binary` sur les polices).

## 3. PHP / Symfony

### Règles de base
- `declare(strict_types=1);` en tête de **chaque** fichier PHP.
- Classes **`final`** par défaut (on n'ouvre à l'héritage que si c'est voulu).
- **Typage explicite** partout : arguments, retours, propriétés. Documenter la
  *forme* des tableaux en docblock (`@return array{id:int, label:string}`) quand utile.
- **PSR-4** : namespace `App\` → `src/`. Un fichier = une classe, nom = nom de classe.
- Style de référence : **PSR-12** + *Symfony coding standards*.
- **Injection de dépendances par constructeur**, autowiring activé. Pas de
  `new` d'un service, pas de service locator, pas d'état statique mutable.
- **Contrôleurs minces** : ils orchestrent, la logique vit dans des services. Pas de
  requête Doctrine ni d'appel HTTP dans un contrôleur.
- **Enums** pour tout état fermé du domaine (`src/Enum`), jamais de « magic strings ».
- Réglages produit : via **`SettingsService`** (éditable en base), **jamais** de
  constante codée en dur pour un paramètre susceptible de changer (prompt,
  température, k voisins, cadences…).

### Conventions de nommage par rôle
Le code s'appuie sur des **suffixes signifiants**. Une nouvelle classe doit adopter
le suffixe de son rôle :

| Suffixe | Rôle | Exemples |
|---|---|---|
| `*Message` / `*Handler` | DTO Messenger + son handler `#[AsMessageHandler]` | `ProcessWork` / `ProcessWorkHandler` |
| `*Mapper` | transformation **pure** (données → données, sans I/O) | `OpenAlexMapper` |
| `*Factory` | sélection/instanciation de driver | `EmbeddingClientFactory`, `LlmClientFactory` |
| `*Builder` | assemblage (prompt, message) | `PromptBuilder` |
| `*Extractor` / `*Detector` | analyse LLM | `ClaimExtractor`, `ControversyDetector`, `GapDetector` |
| `*Checker` | vérification | `FaithfulnessChecker` |
| `*Drafter` | génération de contenu | `AnswerDrafter` |
| `*Repository` | accès données Doctrine | `PublicationRepository` |
| `*Command` | commande CLI `#[AsCommand]` | préfixes `harvester:`, `app:`, `analysis:`, `wiki:` |
| `*Connector` | source de moisson (taggée `app.source_connector`) | `OpenAlexConnector` |

### Règles d'architecture (à ne pas enfreindre)
- **Messages immuables** : un `*Message` ne porte que des scalaires/identifiants
  (jamais d'entité Doctrine).
- **Handlers idempotents** : rejouer un message ne doit pas créer de doublon (la
  moisson dédoublonne par DOI).
- **Mappers purs** : aucune I/O, donc testables avec des fixtures JSON.
- **Drivers derrière une interface** : tout service externe (embeddings, LLM) passe
  par une interface + fabrique pilotée par variable d'environnement. Le métier ne
  référence jamais une implémentation concrète.
- **Sourçage obligatoire** : toute génération RAG doit s'appuyer sur des sources
  récupérées et porter ses marqueurs `[n]` → DOI (cf. `FaithfulnessChecker`).

## 4. Doctrine & migrations

- Le schéma évolue **uniquement par migration**. **Ne jamais modifier une migration
  déjà commitée** ni l'historique : on ajoute une nouvelle `VersionYYYYMMDDhhmmss`.
- Les changements sont **additifs** quand c'est possible (nouvelles colonnes
  nullables / valeurs par défaut) pour ne pas casser la base de production.
- Générer puis **relire** la migration avant de commiter (ne pas livrer du SQL
  auto-généré non vérifié).
- Vecteurs : colonne `vector(384)` (cible `halfvec(384)`). Les requêtes kNN passent
  par les repositories (`nearestTo`, `nearestHybrid`) — pas de SQL vectoriel éparpillé.

## 5. API Platform

- Exposer une entité via les attributs `#[ApiResource]` ; restreindre la
  visibilité via des **extensions** (ex. `PublicAnswerExtension`) plutôt que par des
  conditions dispersées.
- Sérialisation par **groupes** (`#[Groups([...])]`) pour contrôler finement les
  champs exposés.
- Vérifier le rendu dans `/api/docs` après tout changement de ressource.

## 6. Front web (Twig / BFF)

- Le web **ne touche pas la base** : il appelle l'API via les services `*ApiClient`
  (`ApiClient`, `UserApiClient`, `AdminApiClient`). Les « DTO » sont de simples
  tableaux PHP.
- Le **JWT** reste **en session serveur** ; il n'est jamais rendu au navigateur.
- **CSP stricte** : tout script inline doit porter le `nonce` (`csp_nonce`). Pas de
  `style`/`script` externe non déclaré dans `CspSubscriber`.
- Markdown via le filtre Twig **`| md`** (CommonMark durci : échappement HTML, liens
  non sûrs neutralisés). Le marqueur `[réf. nécessaire]` est stylé automatiquement.
- Navigation via **Hotwire Turbo** ; privilégier les *Turbo Frames* pour les mises à
  jour partielles. JS **vanilla** (pas de framework lourd).
- Assets versionnés par **`asset_v()`** (cache-busting par mtime).
- Le thème « CRT » est **isolé** (`crt-theme.css`, `crt.js`) : ne pas y mêler la
  logique de contenu.

## 7. Dart / Flutter (`apps/mobile`)

- Respecter `flutter_lints` (le `analysis_options.yaml` du projet).
- Nommage Dart standard : `PascalCase` (types), `camelCase` (membres), `_préfixe`
  (privé). Constructeurs `const` quand possible.
- Async via `FutureBuilder` (pas de lib de state management pour l'instant — rester
  simple tant que l'app reste de la consultation).
- L'app ne consomme que l'**API publique** (pas d'authentification).

## 8. Tests

- **PHPUnit** (suite dans `apps/api/tests`, miroir de `src/` :
  `App\Tests\{Domaine}\{Sous-domaine}`).
- Classe `…Test` (final), méthodes `public function testXxx(): void`.
- Privilégier l'**unitaire** sur les briques pures (mappers, parsers, normalisation
  DOI, portier de licence) et le **mock HTTP** (`MockHttpClient`) pour les clients.
- Le mode strict fait échouer sur *deprecation/notice/warning* : **un test qui
  émet une deprecation est un test qui casse**. Garder le code à jour.
- Toute correction de bug s'accompagne idéalement d'un **test de non-régression**.

## 9. Sécurité

- **Secrets** dans le coffre Symfony chiffré ; jamais en clair, jamais commités
  (les clés de déchiffrement et `*.pem` JWT sont dans `.gitignore`).
- Tout *fetch* d'URL externe (PDF, image distante) passe par la validation
  **anti-SSRF** existante (résolution DNS + liste blanche d'IP). Ne pas la contourner.
- Respecter la **politesse** envers les sources externes : `User-Agent` avec contact,
  `mailto` OpenAlex, *rate-limit* par hôte, backoff sur 429/503.

## 10. Outillage : état actuel

> **Important.** Le dépôt **n'a pas encore** de *linter*, d'analyse statique, ni de CI
> configurés (pas de PHP-CS-Fixer, PHPStan, Rector, ni de `.github/workflows`).

En conséquence :
- Les conventions ci-dessus sont **appliquées à la main** lors de la revue. Sois
  d'autant plus rigoureux.
- **Avant de commiter** : exécute `php bin/phpunit` (api) et relis ton diff.
- **Contributions bienvenues** pour ajouter cet outillage : `php-cs-fixer` (style),
  `phpstan` (niveau élevé), et un workflow CI (tests + analyse). Voir
  **[Contribuer](05-contribution-et-pr.md)**.

## 11. Étendre le système (recettes)

| Objectif | Marche à suivre |
|---|---|
| **Nouvelle source de moisson** | Implémenter `SourceConnector` (auto-taggé), écrire un `*Mapper` pur, router son `*Message` vers la file `harvester`. |
| **Nouveau job asynchrone** | Créer `*Message` (scalaires) + `*Handler`, le router dans `messenger.yaml`, choisir/ créer la file adaptée. |
| **Nouveau driver IA** | Implémenter l'interface (`EmbeddingClient` / `LlmClient`), l'ajouter à la fabrique, le piloter par variable d'environnement. |
| **Nouvelle capacité RAG** | Étendre `RagRetriever` ou ajouter un `*Drafter` ; conserver le sourçage et la passe de fidélité. |
| **Nouvelle entité** | Entité + Repository + (option) `#[ApiResource]` + migration **additive**. |
| **Nouvelle règle d'accès** | Voter dédié ou `role_hierarchy`/`access_control` dans `security.yaml`. |
| **Nouvelle page de contenu (web)** | Route explicite dans un contrôleur (cf. `ContentController`) + template Twig ; les routes explicites priment sur la *catch-all* des rubriques. |
