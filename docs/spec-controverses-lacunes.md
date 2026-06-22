# Spec — Cartographe de controverses & de pistes inexplorées

> Outil chercheur : comparer les études d'un même sujet pour (1) détecter des
> **résultats contradictoires** et (2) révéler des **voies inexplorées** (pistes
> peu ou mal exploitées). S'appuie sur l'existant : embeddings pgvector, concepts
> OpenAlex, graphe de citations, pile RAG/LLM auto-hébergée.
>
> Statut : **spec de conception** (à implémenter). Reprend l'item « Consensus vs
> contradiction detection » de `IDEA.md`.

---

## 0. Parcours chercheur (du sujet au résultat)

Le moteur (§4–§7) opère **par nœud**. Cette section décrit comment le chercheur
arrive à un nœud et comment l'analyse s'initialise. Principe cardinal :
l'analyse est **collective et mise en cache** (propriété du nœud, pas de
l'utilisateur) — comme un wiki, le premier qui la déclenche paie le calcul, tous
en profitent, le comité valide.

### 0.1 Choisir son sujet — trois portes d'entrée

| Porte | Pour qui / quand | Mécanique (réutilise l'existant) |
|---|---|---|
| **A. Navigation de l'arbre** | exploration, sujet déjà cartographié | descente de la hiérarchie `TreeNode` ; un nœud = un sujet |
| **B. Recherche libre → ancrage** | chercheur avec une intention | embed de la requête → kNN contre `tree_node.embedding` (mode sémantique du `SearchController`) → proposer les 3 nœuds les plus proches, il confirme |
| **C. Mode hypothèse (ad-hoc)** | « mon idée A→C est-elle neuve ? » | saisie directe d'une relation A→C → branchée sur la **vérification croisée §6.5** → réponse *inexplorée / corroborée / contestée* (Phase B) |

La **porte B est la principale** pour un chercheur (il arrive avec une question,
pas en flânant). La **porte C** est la plus à forte valeur : elle répond
directement à la nouveauté d'une hypothèse personnelle.

**Garde-fou de granularité.** À la sélection, afficher un indicateur
« analysable » = nombre de publications placées (validées) dans le nœud, avec un
seuil minimal (ex. ≥ 30). En deçà, proposer le **nœud parent** : trop étroit ⇒
pas assez d'études pour qu'un désaccord soit significatif ; trop large ⇒ signal
noyé.

### 0.2 Initialisation — cycle de vie d'un nœud

État porté par `TreeNode.analysisStatus` (§7bis) :
`NotAnalyzed → Analyzing → Ready → Stale`.

1. **Première visite (`NotAnalyzed`)** — l'onglet « Controverses & pistes »
   affiche un CTA *« Analyser ce sujet »* avec une **estimation** (« 180 études →
   ~6 min »). Au clic : message `AnalyzeNodeMessage` → l'orchestrateur (§7bis)
   déroule **tout le pipeline** (Phase A + B) dans un seul job async.
2. **Pendant (`Analyzing`)** — progression affichée (« Extraction 42/180… »,
   via `IngestionJob`) ; le chercheur peut partir → **notification mail** à la fin
   (mailer existant).
3. **Prêt (`Ready`)** — lecture directe des `Controversy`/`ResearchGap` en cache,
   coût LLM nul, pour tous les visiteurs suivants.
4. **Périmé (`Stale`)** — quand `harvester:auto-harvest` ajoute des publis
   (`lastHarvestedAt > analyzedAt`) : bandeau *« Ré-analyser (12 nouvelles
   études) »* ; ré-extraction **incrémentale** (seules les nouvelles), puis
   recomposition des agrégats.

**Qui déclenche — hybride :** précalcul en lot par le pipeline de moisson pour
les nœuds populaires (les résultats « apparaissent » sans action), à la demande
pour la longue traîne. Verrou : un seul job d'analyse par nœud à la fois.

### 0.3 Couche personnelle (légère, au-dessus du collectif)

Le chercheur peut **suivre** un sujet (réutiliser la logique de
`LiteratureReview` / favoris) → **alertes** quand une controverse évolue ou
qu'une piste passe `Contested`. Son espace de travail = sujets suivis + pistes à
creuser. (Rejoint l'item « personalized alerts » d'`IDEA.md`.)

> En une phrase : il **choisit un nœud** (arbre ou recherche sémantique) **ou
> saisit son hypothèse** ; la première demande sur un nœud lance un **job async
> collectif mis en cache**, validé par le comité, rafraîchi de façon
> incrémentale au fil du moissonnage.

---

## 1. Objectif & valeur

Pour un nœud de l'arbre de connaissance (`TreeNode`), produire deux livrables
sourcés et validables par le comité :

1. **Fiche controverse** — un *score de consensus* + les couples de résultats qui
   se contredisent, avec **l'axe du désaccord** (vrai désaccord vs différence de
   population / méthode / dose / époque). Chaque affirmation est ancrée à un DOI.
2. **Cartes de pistes** — hypothèses non (ou mal) testées, détectées par trois
   voies complémentaires : chaînons manquants (Swanson ABC), cellules creuses
   (variable × population × méthode), et lacunes auto-déclarées par les auteurs.

Principe directeur : **ne pas comparer des articles entiers** (trop bruité), mais
extraire de chaque étude une **assertion structurée** (`Claim`) et raisonner sur
ces assertions.

### Pré-requis de données
Tout fonctionne sur les publications **déjà stockées** (abstract + full-text
GROBID + `concepts[]` + embeddings). Aucune nouvelle requête OpenAlex par date :
on reste sur le corpus moissonné (cf. piège `from_updated_date`, MEMORY).

---

## 2. Base scientifique

- **Littérature-Based Discovery (Swanson, modèle ABC)** : si A→B est bien étudié
  et B→C aussi, mais A→C jamais directement, alors A→C est une hypothèse plausible
  non testée. Cas historique validé : huile de poisson / syndrome de Raynaud,
  découvert par seule analyse de la littérature. C'est le socle du détecteur de
  « chaînons manquants ».
- **Détection de contradictions** : regroupement par couple (exposition, résultat)
  puis comparaison de la *direction* de l'effet, modulée par les co-variables
  (population, méthode, dose, date) pour distinguer vrai désaccord et faux positif.

---

## 3. Emplacement dans l'arborescence

Nouveau module `App\Analysis`, calqué sur `App\Harvester` (même style :
services + commandes `analysis:*`).

```
apps/api/src/
├── Entity/
│   ├── Claim.php                 # assertion structurée extraite d'une publication
│   ├── Controversy.php           # désaccord détecté (cluster de claims opposés)
│   └── ResearchGap.php           # piste inexplorée détectée
├── Enum/
│   ├── ClaimDirection.php
│   ├── ClaimMethod.php
│   ├── ClaimConfidence.php
│   ├── DisagreementAxis.php
│   ├── GapType.php
│   └── AnalysisStatus.php        # partagé Controversy + ResearchGap
├── Repository/
│   ├── ClaimRepository.php
│   ├── ControversyRepository.php
│   └── ResearchGapRepository.php
├── Analysis/
│   ├── Claim/
│   │   ├── ClaimExtractor.php     # orchestration LLM → JSON → persistance
│   │   ├── ClaimPromptBuilder.php # prompt d'extraction structurée
│   │   └── ClaimJsonParser.php    # parsing/réparation JSON + validation
│   ├── Controversy/
│   │   └── ControversyDetector.php
│   ├── Gap/
│   │   ├── CooccurrenceBuilder.php
│   │   └── GapDetector.php
│   └── Command/
│       ├── ExtractClaimsCommand.php       # analysis:extract-claims
│       ├── DetectControversiesCommand.php # analysis:detect-controversies
│       ├── BuildCooccurrenceCommand.php   # analysis:build-cooccurrence
│       └── DetectGapsCommand.php          # analysis:detect-gaps
apps/web/templates/node/
│   ├── _controversies.html.twig
│   └── _gaps.html.twig
docs/spec-controverses-lacunes.md          # ce document
```

---

## 4. Modèle de données

### 4.1 Entité `Claim`

Une assertion normalisée extraite d'une publication. Plusieurs claims par publi
possibles. Embedding sur la chaîne canonique `"{exposure} → {outcome}"` pour le
regroupement flou.

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ClaimConfidence;
use App\Enum\ClaimDirection;
use App\Enum\ClaimMethod;
use App\Repository\ClaimRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Pgvector\Vector;

/**
 * Assertion scientifique structurée extraite d'une publication (abstract +
 * conclusion GROBID) par le LLM. Brique de base de la détection de controverses
 * et de lacunes (cf. docs/spec-controverses-lacunes.md §4.1).
 *
 * Non décisionnelle : alimente Controversy/ResearchGap, validés par le comité.
 */
#[ORM\Entity(repositoryClass: ClaimRepository::class)]
#[ORM\Table(name: 'claim')]
#[ORM\Index(name: 'idx_claim_axis', columns: ['exposure_norm', 'outcome_norm'])]
class Claim
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Publication::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Publication $publication;

    /** Contexte thématique (placement validé) : borne le regroupement. */
    #[ORM\ManyToOne(targetEntity: TreeNode::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TreeNode $treeNode = null;

    /** Variable A — facteur/exposition, libellé tel quel. */
    #[ORM\Column(length: 255)]
    private string $exposureLabel;

    /** Variable B — résultat/effet, libellé tel quel. */
    #[ORM\Column(length: 255)]
    private string $outcomeLabel;

    /** Clé normalisée (minuscules, lemmes) pour le GROUP BY exact. */
    #[ORM\Column(length: 255)]
    private string $exposureNorm;

    #[ORM\Column(length: 255)]
    private string $outcomeNorm;

    #[ORM\Column(length: 16, enumType: ClaimDirection::class)]
    private ClaimDirection $direction;

    #[ORM\Column(length: 32, enumType: ClaimMethod::class)]
    private ClaimMethod $method;

    #[ORM\Column(length: 16, enumType: ClaimConfidence::class)]
    private ClaimConfidence $confidence;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $population = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $sampleSize = null;

    /** Taille d'effet en texte libre (incl. IC), telle que rapportée. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $effectSize = null;

    /** Limites déclarées par les auteurs (signal de lacune). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $statedLimitations = null;

    /**
     * Pistes futures explicitement réclamées par les auteurs.
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $futureWork = [];

    /** Phrase verbatim justifiant l'assertion (traçabilité). */
    #[ORM\Column(type: Types::TEXT)]
    private string $quote;

    /** Embedding de « exposure → outcome » (regroupement flou). */
    #[ORM\Column(type: 'vector', length: 384, nullable: true)]
    private ?Vector $embedding = null;

    /** Modèle LLM figé à l'extraction (immutabilité de la provenance). */
    #[ORM\Column(length: 128)]
    private string $extractionModel;

    /** JSON brut du LLM (audit / ré-analyse). */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $raw = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $extractedAt;

    public function __construct(Publication $publication, string $extractionModel)
    {
        $this->publication = $publication;
        $this->extractionModel = $extractionModel;
        $this->extractedAt = new \DateTimeImmutable();
    }

    // getters/setters fluides (cf. style PlacementSuggestion / Answer)…
}
```

> Note pgvector : le type Doctrine `'vector'` est déjà mappé dans le projet
> (cf. `Publication.embedding`, `pgvector/pgvector` + `Pgvector\Vector`).
> Reprendre exactement la déclaration de colonne utilisée sur `Publication`.

### 4.2 Enums

```php
// ClaimDirection : signe de l'effet rapporté A→B.
enum ClaimDirection: string {
    case Positive   = 'positive';    // A augmente / favorise B
    case Negative   = 'negative';    // A diminue / protège de B
    case Null       = 'null';        // pas d'effet significatif
    case Mixed      = 'mixed';       // dépend des conditions
    case Unclear    = 'unclear';     // non déterminable
}

// ClaimMethod : type d'étude (hiérarchie de preuve).
enum ClaimMethod: string {
    case MetaAnalysis  = 'meta_analysis';
    case Rct           = 'rct';
    case Cohort        = 'cohort';
    case CaseControl   = 'case_control';
    case Observational = 'observational';
    case InVivo        = 'in_vivo';
    case InVitro       = 'in_vitro';
    case Modeling      = 'modeling';
    case Review        = 'review';
    case Other         = 'other';
}

// ClaimConfidence : robustesse méthodologique estimée par le LLM.
enum ClaimConfidence: string {
    case High = 'high'; case Moderate = 'moderate'; case Low = 'low';
}

// DisagreementAxis : pourquoi deux claims divergent.
enum DisagreementAxis: string {
    case Genuine    = 'genuine';     // mêmes conditions, conclusions opposées
    case Population  = 'population';
    case Method      = 'method';
    case Dose        = 'dose';
    case Temporal    = 'temporal';   // une étude récente supersède
    case Unclear     = 'unclear';
}

// GapType : nature de la piste inexplorée.
enum GapType: string {
    case MissingLink   = 'missing_link';   // Swanson A–C
    case SparseCell    = 'sparse_cell';    // case creuse var × pop × méthode
    case SelfDeclared  = 'self_declared';  // lacune réclamée par les auteurs
}

// AnalysisStatus : flux de validation comité (partagé).
enum AnalysisStatus: string {
    case Detected     = 'detected';
    case UnderReview  = 'under_review';
    case Confirmed    = 'confirmed';
    case Dismissed    = 'dismissed';
}

// GapVerification : résultat de la vérification croisée d'une piste (§6.5).
enum GapVerification: string {
    case Unverified   = 'unverified';    // pas encore confronté ailleurs
    case Unexplored   = 'unexplored';    // introuvable ailleurs → vraie piste
    case Corroborated = 'corroborated';  // testée ailleurs, même conclusion → renforce
    case Contested    = 'contested';     // testée ailleurs, conclusion divergente → encart
}
```

### 4.3 Entité `Controversy`

Cluster de claims partageant un même axe (exposure_norm, outcome_norm) au sein
d'un nœud, dont les directions divergent. Membres figés (ManyToMany) pour la
relecture.

Champs : `treeNode`, `exposureNorm`, `outcomeNorm`, `consensusScore` (float,
ratio d'accord), `countPositive`/`countNegative`/`countNull` (int),
`disagreementAxis` (enum), `summary` (text, synthèse LLM courte),
`status` (AnalysisStatus, défaut Detected), `createdAt`, `claims` (ManyToMany
Claim, table `controversy_claim`).

`consensusScore = max(countPositive, countNegative, countNull) / total`
(1 = consensus total, ~0,5 = parfaitement disputé).

### 4.4 Entité `ResearchGap`

Champs : `type` (GapType), `treeNode` (nullable), `conceptA`, `conceptB`
(nullable), `conceptC` (nullable), `description` (text, généré), `maturityScore`
(float — robustesse des concepts flanquants), `rarityScore` (float),
`evidenceCount` (int — nb de signaux, ex. nb d'auteurs réclamant la piste),
`supportingPublicationIds` (json), `status` (AnalysisStatus), `createdAt`.

**Champs de vérification croisée** (renseignés en §6.5) :
`verification` (GapVerification, défaut `Unverified`), `expectedDirection`
(ClaimDirection nullable — l'intuition de l'IA, déduite de la chaîne ABC),
`observedDirection` (ClaimDirection nullable — ce qu'ont trouvé les études
externes), `corroboratingPublicationIds` (json), `contestingPublicationIds`
(json), `divergenceNote` (text nullable — l'**encart** généré : en quoi les
résultats divergent et avec quel protocole), `verifiedScope` (varchar —
`corpus` | `corpus+openalex`), `verifiedAt` (datetime nullable).

### 4.5 Table matérialisée `concept_cooccurrence`

Construite depuis les `concepts[]` des publications (pas une entité ORM ;
table technique rafraîchie par commande).

| colonne | type | sens |
|---|---|---|
| concept_a | varchar | id/clé concept OpenAlex |
| concept_b | varchar | id/clé concept OpenAlex (a < b) |
| pair_count | int | nb de publis citant A **et** B |
| a_count | int | nb de publis citant A |
| b_count | int | nb de publis citant B |

PMI (information mutuelle ponctuelle) calculée à la volée pour Swanson.

---

## 5. Extraction structurée (le cœur)

`LlmClient::complete()` ne renvoie **que du texte** (pas de JSON schema natif).
On force donc un JSON unique par prompt, puis on parse/répare comme
`AnswerDrafter::analyze()` (strip des fences ```), avec **un retry** si le parse
échoue.

### 5.1 Prompt système (`ClaimPromptBuilder`)

```
Tu es un assistant d'extraction de données scientifiques. À partir du TITRE, du
RÉSUMÉ et de la CONCLUSION d'un article, extrais chaque RELATION CAUSALE OU
CORRÉLATIONNELLE testée, sous forme d'un tableau JSON STRICT.

Règles :
- N'invente RIEN. Si une information est absente, mets null.
- Une entrée par couple (exposition, résultat) effectivement étudié.
- "direction" décrit le signe du résultat RAPPORTÉ par les auteurs, pas ton avis.
- "quote" doit être une phrase EXACTE de l'article justifiant l'entrée.
- Réponds UNIQUEMENT par le JSON, sans texte autour, sans bloc de code.

Schéma de chaque entrée :
{
  "exposure": "facteur/intervention étudié (string)",
  "outcome": "résultat/effet mesuré (string)",
  "direction": "positive|negative|null|mixed|unclear",
  "method": "meta_analysis|rct|cohort|case_control|observational|in_vivo|in_vitro|modeling|review|other",
  "confidence": "high|moderate|low",
  "population": "string|null",
  "sample_size": "integer|null",
  "effect_size": "string|null  (ex. 'OR 1.8, IC95 1.2-2.7')",
  "stated_limitations": "string|null",
  "future_work": ["pistes futures explicitement réclamées", "..."],
  "quote": "phrase verbatim de l'article"
}

Sortie attendue : {"claims": [ …entrées… ]}  (tableau vide si rien d'extractible)
```

Prompt utilisateur : `TITRE`, `RÉSUMÉ` (700+ car.), `CONCLUSION` (extrait GROBID
si `fulltextStored`), à la `PromptBuilder::user()`. Options LLM :
`temperature => 0.0`, `max_tokens => 1500`.

### 5.2 Normalisation & embedding
- `exposureNorm` / `outcomeNorm` : minuscules, trim, suppression d'articles,
  lemmatisation simple (réutiliser le pipeline FTS PostgreSQL si dispo, sinon
  `lower()` + table de synonymes minimale).
- `embedding` : `EmbeddingClientFactory::create()->embed("$exposure → $outcome")`.

### 5.3 Garanties
- Modèle figé (`extractionModel`) comme `Answer::generationModel`.
- Idempotence : ré-extraction d'une publi ⇒ purge de ses claims puis ré-insert.
- `StubLlmClient` renvoie un JSON déterministe pour les tests.

---

## 6. Algorithmes

### 6.1 Détection de controverses (`ControversyDetector`)
1. Pour un `TreeNode`, charger les claims (`exposureNorm`, `outcomeNorm`,
   `direction`, co-variables).
2. Grouper par couple exact `(exposureNorm, outcomeNorm)` ; fusionner les groupes
   dont les embeddings sont à distance cosinus < `θ` (≈ 0,15) pour absorber les
   reformulations.
3. Un groupe est **litigieux** si ≥ 2 directions distinctes parmi
   {positive, negative, null} sont présentes (mixed/unclear ignorés du vote).
4. `consensusScore` = part de la direction majoritaire.
5. **Axe du désaccord** (`disagreementAxis`) : heuristique d'abord
   (populations différentes ? méthodes différentes ? écart de dates > 10 ans ?),
   puis un appel LLM de synthèse courte (`summary`) qui tranche genuine vs
   artefact et résume en 2 phrases sourcées `[n]`.
6. Persister `Controversy` (status `Detected`) + liens claims. Réutiliser le
   système de `Footnote` pour les DOI dans `summary`.

### 6.2 Chaînons manquants — Swanson ABC (`GapDetector`)
1. À partir de `concept_cooccurrence`, calculer le PMI de chaque paire.
2. Trouver les triplets A–B–C où PMI(A,B) et PMI(B,C) sont élevés (top quantile)
   mais `pair_count(A,C)` ≈ 0 et A,C jamais co-cités dans le même nœud.
3. `maturityScore` = f(a_count, c_count, force des deux liens) — une piste est
   « mûre » si A et C sont chacun bien établis.
4. `rarityScore` = 1 − normalisé(pair_count(A,C)).
5. Générer `description` via LLM (« A pourrait influencer C via B ; non testé
   directement ») et persister `ResearchGap(type=MissingLink)`.

### 6.3 Cellules creuses (`GapDetector`)
1. Pour un nœud, tableau croisé `outcomeNorm × population × method` à partir des
   claims.
2. Cellules à 0 dont les marges sont fortes (résultat bien étudié *globalement*,
   mais jamais pour cette population, ou jamais par RCT) ⇒
   `ResearchGap(type=SparseCell)`.

### 6.4 Lacunes auto-déclarées (`GapDetector`)
1. Agréger les `Claim.futureWork` du nœud ; clustering par embedding.
2. Un cluster réclamé par ≥ N publis (ex. 5) ⇒ `ResearchGap(type=SelfDeclared)`,
   `evidenceCount` = taille du cluster, `supportingPublicationIds` renseignés
   (signal fort et citable).

### 6.5 Vérification croisée des pistes (`GapVerifier`)

But : confirmer qu'une piste « inexplorée » dans le nœud ne l'a pas été
**ailleurs**, et confronter le résultat réel à l'intuition de l'IA. Tourne après
`detect-gaps`, sur chaque `ResearchGap` à `verification = Unverified`.

1. **Direction attendue** (`expectedDirection`) : pour un `MissingLink`, composer
   les deux liens connus de la chaîne — A→B et B→C — pour déduire le signe
   plausible de A→C (positif∘positif ⇒ positif ; positif∘négatif ⇒ négatif ;
   présence de `null`/`mixed` ⇒ `unclear`). Pour `SparseCell`, l'attendu est le
   signe majoritaire des claims voisins (autres populations/méthodes).

2. **Recherche hors périmètre** : formuler la relation (`"A → C"`) puis chercher
   des publications qui la **testent réellement**, en dehors du nœud d'origine :
   - kNN sémantique sur l'embedding de la relation contre **tout** le corpus
     `publication` (réutiliser `PublicationRepository::nearestTo()`), filtré
     `treeNode != nœud`, distance < seuil ;
   - + FTS sur `A` ET `C` (recoupement lexical) ;
   - **option** `--openalex` : requête OpenAlex *par termes* (recherche, pas
     moisson incrémentale par date — cf. MEMORY) pour élargir au-delà du corpus.

3. **Confrontation** : sur les candidats, exécuter `ClaimExtractor` (réutilisé)
   pour obtenir leur `direction` réelle sur le couple A–C, puis :
   - **aucun candidat probant** ⇒ `verification = Unexplored`, `status` reste
     `Detected` : la piste est confirmée comme vraie voie inexplorée ;
   - **candidats, direction = expectedDirection** ⇒ `verification = Corroborated` :
     **renforce l'intuition** ; remonter `maturityScore`/`evidenceCount`,
     stocker `corroboratingPublicationIds` ;
   - **candidats, direction ≠ expectedDirection** ⇒ `verification = Contested` ;
     stocker `observedDirection` + `contestingPublicationIds` et **générer
     l'encart** `divergenceNote`.

4. **Encart de divergence** (`divergenceNote`, généré LLM, sourcé `[n]`) : en quoi
   le résultat réel diffère de l'intuition, **et avec quel protocole** —
   réutiliser l'axe d'analyse de `DisagreementAxis` (population, méthode, dose,
   époque). Gabarit :
   > « L'intuition (A favorise C via B) est **contredite** par {n} étude(s) :
   >  {DOI} rapporte {direction observée} sur {population}, via {méthode}
   >  ({taille d'effet}). Divergence attribuée à : {axe}. »

5. Persister ; un `Contested` part en relecture comité (`UnderReview`) car c'est
   le cas le plus instructif.

> Les trois issues sont toutes utiles : *Unexplored* = piste à creuser,
> *Corroborated* = hypothèse renforcée (priorisable), *Contested* = l'IA s'est
> avancée mais le terrain dit autre chose → l'encart évite de proposer une fausse
> piste et documente pourquoi.

---

## 7. Commandes (CLI)

Style `#[AsCommand]` + `SymfonyStyle` + `FLUSH_EVERY` (cf. `SuggestPlacementCommand`).

| Commande | Rôle | Options clés |
|---|---|---|
| `analysis:extract-claims` | LLM → claims + embeddings | `--node=slug`, `--limit`, `--reextract` |
| `analysis:build-cooccurrence` | (re)matérialise la matrice concepts | `--node=slug` |
| `analysis:detect-controversies` | clusters de claims opposés | `--node=slug`, `--theta` |
| `analysis:detect-gaps` | Swanson + cellules creuses + auto-déclarées | `--node=slug`, `--types=` |
| `analysis:verify-gaps` | confronte chaque piste hors périmètre (§6.5) | `--node=slug`, `--openalex`, `--limit` |
| `analysis:run` | **orchestrateur** : enchaîne tout le pipeline sur un nœud | `--node=slug`, `--reextract`, `--openalex`, `--force` |

Toutes scoppables par `--node`. `analysis:extract-claims` est le seul gros
consommateur LLM ⇒ traiter par lots + flush périodique, journaliser via un
`IngestionJob` (réutiliser l'entité d'audit existante). Les commandes granulaires
restent utiles pour le debug/backfill, mais **ne sont pas lancées à la main par
le chercheur** : c'est l'orchestrateur qui les enchaîne (§7bis).

### 7bis. Orchestration automatique (un seul déclenchement)

Le découpage Phase A / Phase B (§10) est un **ordre de construction du code**,
pas un découpage à l'exécution. À l'exécution, **tout s'enchaîne dans un seul
job** : le chercheur clique une fois, la Phase B (pistes + vérification croisée)
se déroule automatiquement à la suite de la Phase A.

- **Point d'entrée unique** : `AnalysisOrchestrator::run(TreeNode $node, $opts)`
  appelle en séquence, en interne (pas en shellant des sous-commandes) :
  `ClaimExtractor` → `ControversyDetector` → `CooccurrenceBuilder` →
  `GapDetector` → `GapVerifier`.
- **Deux habillages du même orchestrateur** :
  - CLI/cron : `analysis:run --node=…` (précalcul en lot, intégré à
    `harvester:auto-harvest`, cf. Phase C §13) ;
  - UI chercheur : message Messenger `AnalyzeNodeMessage(nodeId, opts)` +
    `AnalyzeNodeHandler` (async, comme le moissonnage), qui exécute le même
    orchestrateur.
- **Machine à états du nœud** (champ `analysisStatus` sur `TreeNode`, à côté du
  `lastHarvestedAt` existant) : `NotAnalyzed → Analyzing → Ready → Stale`.
  L'orchestrateur fait la transition, journalise l'avancement dans un
  `IngestionJob`, et notifie à la fin (mailer existant).
- **Idempotent & verrouillé** : un seul job d'analyse par nœud à la fois ; en
  `Stale` (nouvelles publis moissonnées), seules les nouvelles sont ré-extraites
  puis les agrégats recomposés.
- Le chercheur ne voit **jamais** les étapes : il déclenche (ou trouve déjà
  `Ready`), et lit le résultat. Aucune action manuelle entre les phases.

---

## 8. Exposition (API + Web)

### 8.1 API Platform (lecture seule + actions comité)
- `GET /api/controversies?node={slug}&status=detected` (tri par `consensusScore` asc).
- `GET /api/research_gaps?node={slug}&type=missing_link` (tri par `maturityScore` desc).
- `PATCH …/{id}` : transition `status` (Confirmed/Dismissed) réservée au rôle comité
  (cf. `UserRole`), comme la validation des réponses.
- `POST /api/nodes/{slug}/analyze` : déclenche `AnalyzeNodeMessage` (§0.2) si
  `NotAnalyzed`/`Stale` ; renvoie `analysisStatus`.
- `POST /api/hypotheses` (Phase B, porte C §0.1) : `{exposure, outcome}` →
  `GapVerifier` → verdict *inexplorée / corroborée / contestée* + encart.

### 8.2 Web (Twig)

**Entrée (§0.1)** : barre de recherche (porte B → ancrage nœud sémantique) en
plus de l'arbre (porte A) ; sur la page nœud, selon `analysisStatus` : CTA
*« Analyser ce sujet »* (`NotAnalyzed`), progression (`Analyzing`), résultats
(`Ready`), bandeau *« Ré-analyser »* (`Stale`). Porte C (mode hypothèse) en
Phase B.

Une fois `Ready`, deux panneaux (templates `_controversies.html.twig`,
`_gaps.html.twig`) :
- **Controverses** : jauge de consensus + cartes « A vs B » montrant les deux
  camps, l'axe du désaccord, les DOI, et la synthèse.
- **Pistes inexplorées** : cartes triées par maturité, badge du type
  (chaînon manquant / case creuse / réclamée N×), liens vers les publis
  flanquantes. Badge de **vérification** (§6.5) : ✅ *inexplorée confirmée*,
  ⬆️ *intuition renforcée* (montre les DOI corroborants), ⚠️ *contestée* — ce
  dernier déplie l'**encart de divergence** (`divergenceNote`) en pied de carte,
  avec la direction observée, les DOI et le protocole des études contradictoires.
- Tableau de bord chercheur (optionnel) : nœuds les plus disputés / plus de
  pistes mûres.

---

## 9. Migrations

Nouvelle migration `DoctrineMigrations\Version2026MMDDHHMMSS` (timestamp >
`20260622240000`). `up()` :
- `claim` (+ colonne `embedding vector(384)`, index HNSW
  `USING hnsw (embedding vector_cosine_ops)`, index B-tree sur
  `(exposure_norm, outcome_norm)`).
- `controversy` + `controversy_claim` (join).
- `research_gap`.
- `concept_cooccurrence` (table technique, PK `(concept_a, concept_b)`).

Suivre exactement le SQL pgvector des migrations existantes de `publication.embedding`.

---

## 10. Plan de livraison

### Phase A — MVP « controverses » (1 nœud pilote)
1. Enums + entité `Claim` + repo + migration `claim`.
2. `ClaimPromptBuilder` + `ClaimJsonParser` + `ClaimExtractor` (+ stub).
3. `analysis:extract-claims --node=pilote`.
4. Entité `Controversy` + `ControversyDetector` + `analysis:detect-controversies`.
5. Panneau Twig `_controversies.html.twig` + jauge de consensus, avec **CTA
   « Analyser ce sujet »** + affichage de `TreeNode.analysisStatus` (§0.2).
6. **Sélection du sujet** (§0.1) : portes A (arbre, existante) + B (recherche
   sémantique → ancrage nœud, réutilise `SearchController`) ; indicateur
   « analysable » (seuil de corpus).
7. **DoD** : sur le nœud pilote, fiche controverse sourcée affichée ; tests verts ;
   bump `app_version` (twig.yaml) — pied de page (cf. MEMORY version-tag-footer).

### Phase B — pistes inexplorées
8. `concept_cooccurrence` + `CooccurrenceBuilder` + `analysis:build-cooccurrence`.
9. `ResearchGap` + `GapDetector` (3 voies) + `analysis:detect-gaps`.
10. **Vérification croisée** : `GapVerifier` + `analysis:verify-gaps` (réutilise
    `nearestTo()` hors nœud + `ClaimExtractor`) → `verification` + encart de
    divergence ; option `--openalex` (recherche par termes, pas par date).
11. Panneau Twig `_gaps.html.twig` avec badges de vérification + encart divergence.
12. **Porte C — mode hypothèse** (§0.1) : saisie d'une relation A→C → `GapVerifier`
    direct → verdict *inexplorée / corroborée / contestée*.

### Phase C — industrialisation
13. Endpoints API + transitions comité (`AnalysisStatus`) + rôle.
14. Tableau de bord chercheur ; **suivi de sujet + alertes** (§0.3) ; éventuel
    mobile Flutter.
15. Intégration au cycle `harvester:auto-harvest` (extraction incrémentale des
    nouvelles publis + précalcul des nœuds populaires).

---

## 11. Tests

- **Unit** `ClaimJsonParser` : JSON valide, JSON entouré de fences, JSON cassé →
  retry, tableau vide. Via `StubLlmClient`.
- **Unit** `ControversyDetector` : fixture de claims (positive/negative/null) →
  consensusScore et litige attendus ; fusion par embedding.
- **Unit** `GapDetector` : matrice de cooccurrence fixture → triplet Swanson
  attendu ; cellule creuse ; cluster auto-déclaré ≥ N.
- **Func** : commande `analysis:extract-claims` sur 2 publis stub → 2 claims.

---

## 12. Risques & garde-fous

- **Hallucination d'extraction** → `quote` verbatim obligatoire, `temperature=0`,
  validation que la quote existe dans le texte source (sinon claim rejeté).
- **Faux positifs de contradiction** → l'axe du désaccord est obligatoire ; une
  divergence non « genuine » est présentée comme *piste*, pas comme erreur.
- **Coût LLM** → extraction par lots, scoppée par nœud, idempotente, journalisée
  (`IngestionJob`). Tourne sur le corpus stocké (aucun appel OpenAlex par date).
- **Validation humaine** → rien n'est publié sans passage comité
  (`AnalysisStatus`), comme les réponses RAG.

---

## 13. Prompts de démarrage (sessions d'implémentation)

> ⚠️ Ces blocs sont des **amorces de sessions de développement** (pour écrire le
> code), **pas** un déclenchement à l'exécution. À l'exécution, tout s'enchaîne
> automatiquement via l'orchestrateur (§7bis) : le chercheur ne colle rien.
>
> Les phases sont un **ordre de construction**. L'orchestrateur `AnalysisRun`
> est bâti dès la Phase A (avec une seule étape branchée), puis la Phase B y
> **ajoute** les étages pistes + vérification — sans changer le déclenchement.

### Prompt Phase A — moteur « controverses » + ossature d'orchestration

```
Lis docs/spec-controverses-lacunes.md puis implémente la PHASE A de l'outil
Cartographe de controverses & pistes inexplorées.

Contexte : projet sous sciencesWiki/ (Symfony 8 API + Twig). Respecte les
conventions existantes : declare(strict_types=1), docblocks FR référençant les
§ de la spec, enums string-backed (cf. App\Enum\PlacementStatus), entités/repos
Doctrine (cf. PlacementSuggestion), pgvector via Pgvector\Vector et
« embedding <=> CAST(:vec AS vector) » + index HNSW (cf. PublicationRepository),
commandes #[AsCommand] + SymfonyStyle + FLUSH_EVERY (cf. SuggestPlacementCommand),
jobs async via Symfony Messenger (cf. App\Harvester\Message), LLM texte-seul
parsé/réparé comme AnswerDrafter::analyze() (pas de JSON schema natif),
StubLlmClient pour les tests.

Périmètre Phase A :
1. Enums ClaimDirection/ClaimMethod/ClaimConfidence/DisagreementAxis/AnalysisStatus.
2. Entité Claim + ClaimRepository + migration (table claim, embedding vector(384),
   index HNSW + B-tree (exposure_norm, outcome_norm)).
3. App\Analysis\Claim : ClaimPromptBuilder, ClaimJsonParser (avec 1 retry),
   ClaimExtractor (orchestration → persistance, modèle figé, idempotent).
4. Entité Controversy + ControversyDetector.
5. ORCHESTRATEUR (§7bis) dès maintenant : AnalysisOrchestrator::run(node, opts)
   qui appelle pour l'instant ClaimExtractor → ControversyDetector ; commande
   analysis:run (--node, --reextract, --force) ; message AnalyzeNodeMessage +
   AnalyzeNodeHandler (async) ; champ TreeNode.analysisStatus
   (NotAnalyzed→Analyzing→Ready→Stale) avec verrou « un job par nœud » ;
   journalisation via IngestionJob réutilisé. Les étages Phase B viendront
   simplement s'insérer dans run() — laisse les points d'extension explicites.
6. Panneau Twig apps/web/templates/node/_controversies.html.twig (jauge de
   consensus + cartes sourcées) + bouton « Analyser ce sujet » (déclenche le
   message ; affiche l'état du nœud).
7. Sélection du sujet (§0.1) : portes A (arbre, existante) + B (recherche
   sémantique → ancrage nœud via SearchController) ; indicateur « analysable »
   (compteur de publications placées, seuil mini).
8. Tests unit (ClaimJsonParser, ControversyDetector) via StubLlmClient.
9. Bump app_version dans twig.yaml (pied de page).

Commence par me proposer la liste de fichiers à créer/modifier et la migration,
puis attends mon feu vert avant d'écrire le code.
```

### Prompt Phase B — pistes inexplorées + vérification croisée (s'enchaîne)

```
Lis docs/spec-controverses-lacunes.md puis implémente la PHASE B, en
RÉUTILISANT l'orchestrateur AnalysisOrchestrator déjà en place (§7bis). Mêmes
conventions que la Phase A.

Périmètre Phase B :
1. Enum GapVerification ; champs de vérification sur ResearchGap (§4.4) ;
   table concept_cooccurrence + migration.
2. Entité ResearchGap + ResearchGapRepository.
3. App\Analysis\Gap : CooccurrenceBuilder, GapDetector (3 voies : Swanson ABC,
   cellules creuses, lacunes auto-déclarées), GapVerifier (§6.5 : direction
   attendue via composition de chaîne, recherche hors nœud avec
   PublicationRepository::nearestTo() + FTS, option OpenAlex par TERMES — jamais
   par date, ré-extraction ClaimExtractor, encart divergenceNote).
4. INSÉRER ces étages dans AnalysisOrchestrator::run() À LA SUITE des étages
   Phase A : ClaimExtractor → ControversyDetector → CooccurrenceBuilder →
   GapDetector → GapVerifier. Aucun nouveau déclenchement : le même
   analysis:run / AnalyzeNodeHandler doit désormais produire aussi les pistes.
5. Commandes granulaires analysis:build-cooccurrence / detect-gaps / verify-gaps
   (debug/backfill uniquement).
6. Panneau Twig _gaps.html.twig (badges ✅/⬆️/⚠️ + encart de divergence dépliable).
7. Tests unit (GapDetector : Swanson, cellule creuse, cluster ; GapVerifier :
   corroboré vs contesté) via StubLlmClient.
8. Bump app_version dans twig.yaml.

Commence par me confirmer les points d'insertion dans l'orchestrateur existant
puis attends mon feu vert.
```
