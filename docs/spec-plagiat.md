# Spec — Détection de plagiat & de doublons de contenu

> Outil d'intégrité scientifique : repérer, **dans notre corpus**, des publications
> qui **réutilisent le texte ou les idées** d'autres travaux sans le justifier —
> au-delà du simple doublon par DOI déjà géré par le `Deduplicator`. S'appuie sur
> l'existant : fragments plein-texte (`PublicationChunk`), embeddings pgvector,
> graphe auteur, namespace `Analysis`, pile async Messenger.
>
> Statut : **spec de conception** (à implémenter). Complète les signaux d'intégrité
> de `IDEA.md` (#7 rétractations, #10 hype-mètre) côté « plagiat hors citation ».

---

## 0. Cadrage — ce que l'outil fait et ne fait pas

Deux honnêtetés à graver dans la spec, sous peine de sur-promettre :

1. **Couverture = notre corpus, pas tout le savoir.** On compare un texte aux
   publications **déjà moissonnées** (résumés en masse + plein texte curé). On ne
   voit ni le web, ni les bases payantes, ni les copies d'étudiants. Donc
   **« rien trouvé » ≠ original** : beaucoup de **faux négatifs**. On n'est pas
   Turnitin ; on est un détecteur de **réutilisation intra-corpus**.
2. **La similarité n'est pas une preuve.** Deux études sur le même résultat précis
   se ressemblent légitimement. Tout signal est **non décisionnel** : il produit un
   **score + les passages incriminés** pour qu'un **humain** (comité, journaliste)
   tranche. Jamais un verdict automatique « plagiat ».

### Cas d'usage (par valeur décroissante)

| Cas | Description | Public |
|---|---|---|
| **A. Doublons intra-corpus** | Deux publications (DOIs différents) au contenu largement recouvrant : *duplicate publication*, *salami slicing*, usines à articles. | comité, veille intégrité |
| **B. Auto-plagiat** | Un même auteur réutilise massivement son propre texte d'un article à l'autre. | comité |
| **C. Vérification d'un texte soumis** | Au **dépôt auteur tokenisé** (cf. `spec-plateforme-auteur.md`), comparer le texte proposé au corpus → signal de réutilisation avant publication. | rédaction |

Le **cas A** est le premier à livrer : forte valeur, faux positifs maîtrisables,
zéro dépendance externe.

---

## 1. Objectif & valeur

Pour une publication (ou un texte soumis), produire un livrable **sourcé et
validable** :

- un **score de réutilisation** (part du texte recouvrant d'autres travaux),
- la liste des **publications-cibles** rapprochées, avec pour chacune le **type**
  (quasi-doublon / recouvrement verbatim / paraphrase / auto-recouvrement),
- les **passages précis** mis en regard (extrait source ↔ extrait cible),
- un **statut** de revue (à examiner / confirmé / écarté / recouvrement légitime).

### Pré-requis de données
Tout fonctionne sur le corpus **déjà stocké** : `PublicationChunk` (texte du
fragment + `embedding`), `Publication`, `Author`/`Authorship`. **Aucune nouvelle
requête OpenAlex par date** (cf. piège `from_updated_date`, MEMORY). La détection
verbatim n'a de sens que là où on a le **plein texte** (palier 1, GROBID) ; sur le
reste (palier 0), on ne peut comparer que les **résumés**.

---

## 2. Base technique — pourquoi deux étages

Le plagiat se décline en deux régimes que **deux techniques différentes** attrapent :

- **Verbatim / copier-coller** → recouvrement **littéral**. Outil : *shingling*
  (n-grammes de mots) + **MinHash/LSH** pour l'estimation rapide de **Jaccard**,
  puis alignement local exact pour extraire les passages. Robuste, peu de faux
  positifs, mais aveugle à la reformulation.
- **Paraphrase / mosaïque** → recouvrement **sémantique**. Outil : **similarité
  cosinus des embeddings** de `PublicationChunk` (déjà calculés pour le RAG). Attrape
  la réécriture, mais **conflate « même sujet » et « même texte »** → faux positifs.

D'où un pipeline en **deux étages** : un **rappel** large (sémantique OU LSH) qui
génère des candidats peu coûteux, puis une **confirmation** précise (Jaccard exact +
alignement) qui élimine la proximité purement thématique. Aucune des deux seule ne
suffit ; combinées, elles couvrent les deux régimes avec un bruit maîtrisé.

> Note d'échelle : on **ne compare jamais toutes les paires** (O(n²) sur des dizaines
> de millions de chunks). Le **LSH banding** ramène la génération de candidats à du
> sous-quadratique : un nouveau chunk n'interroge que les *buckets* qui partagent une
> bande de signature. Les scans sont **scopés** (par nœud, par nouvelle ingestion).

---

## 3. Emplacement dans l'arborescence

Nouveau sous-module `App\Analysis\Plagiarism`, calqué sur `App\Analysis` (services +
commandes). Réutilise `PublicationChunk` et la pile pgvector.

```
apps/api/src/
├── Entity/
│   ├── ChunkFingerprint.php       # signature MinHash + bandes LSH d'un chunk (1:1)
│   └── DuplicationFinding.php     # paire (source, cible) rapprochée + passages + statut
├── Enum/
│   ├── DuplicationType.php        # NearDuplicate | VerbatimOverlap | Paraphrase | SelfOverlap
│   └── FindingStatus.php          # Unreviewed | Confirmed | Dismissed | Legitimate
├── Repository/
│   ├── ChunkFingerprintRepository.php   # requêtes LSH (candidats par bande)
│   └── DuplicationFindingRepository.php
├── Analysis/Plagiarism/
│   ├── Shingler.php               # texte → n-grammes normalisés (k=5 mots)
│   ├── MinHasher.php              # n-grammes → signature MinHash (P=128) + bandes
│   ├── CandidateFinder.php        # rappel : LSH buckets ∪ kNN sémantique
│   ├── OverlapScorer.php          # confirmation : Jaccard exact + alignement de passages
│   ├── LegitimacyFilter.php       # écarte citations entre guillemets, boilerplate, biblio
│   └── PlagiarismScanner.php      # orchestration paire → DuplicationFinding
├── Analysis/Plagiarism/Message/
│   ├── FingerprintPublication.php # (+ Handler)  empreintes des chunks d'une publi
│   └── ScanPublication.php        # (+ Handler)  détection pour une publi
└── Analysis/Plagiarism/Command/
    ├── FingerprintCommand.php     # app:plagiarism:fingerprint
    ├── ScanCommand.php            # app:plagiarism:scan
    └── CheckTextCommand.php       # app:plagiarism:check-text  (cas C, ad-hoc)
apps/web/templates/admin/
└── _duplication.html.twig         # fiche back-office (paires + passages + statut)
docs/spec-plagiat.md               # ce document
```

---

## 4. Modèle de données

### 4.1 `ChunkFingerprint` — empreinte verbatim d'un fragment

Sidecar 1:1 de `PublicationChunk`. Porte la **signature MinHash** (pour estimer
Jaccard) et des **bandes LSH** indexées (pour le rappel par *bucket*). Additif :
aucune réécriture de `PublicationChunk`.

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ChunkFingerprintRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Empreinte de similarité verbatim d'un PublicationChunk (cf. spec-plagiat.md §4.1).
 * - signature : MinHash (P=128 permutations) sérialisée, pour estimer Jaccard.
 * - bands     : R hachages de bandes LSH (indexés) pour le rappel par bucket.
 * Calculé une fois à l'ingestion plein-texte, jeté avec le chunk (CASCADE).
 */
#[ORM\Entity(repositoryClass: ChunkFingerprintRepository::class)]
#[ORM\Table(name: 'chunk_fingerprint')]
#[ORM\Index(name: 'idx_fp_band', columns: ['band_index', 'band_hash'])]
class ChunkFingerprint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: PublicationChunk::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PublicationChunk $chunk;

    /** Dénormalisé depuis le chunk : borne les comparaisons à des publis distinctes. */
    #[ORM\ManyToOne(targetEntity: Publication::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Publication $publication;

    /** Signature MinHash (P=128 entiers) sérialisée en binaire. */
    #[ORM\Column(type: Types::BLOB)]
    private mixed $signature;

    /** Nombre de shingles distincts (pour pondérer les passages très courts). */
    #[ORM\Column(type: Types::INTEGER)]
    private int $shingleCount;

    // Les bandes LSH sont stockées en table fille (band_index, band_hash) indexée :
    // une ligne par bande, requêtée pour trouver les chunks co-bucketés.
}
```

> **Variante d'implémentation.** Les bandes peuvent vivre dans une table
> `chunk_fingerprint_band(fingerprint_id, band_index, band_hash)` indexée, ou être
> portées par un type tableau Postgres `int[]` + index GIN. La table fille est plus
> simple à requêter en SQL pur ; on tranche au lot 1.

### 4.2 `DuplicationFinding` — une paire rapprochée

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DuplicationType;
use App\Enum\FindingStatus;
use App\Repository\DuplicationFindingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Rapprochement entre deux publications au contenu recouvrant (cf. spec-plagiat.md
 * §4.2). NON DÉCISIONNEL : signal de risque, statut tranché par le comité.
 */
#[ORM\Entity(repositoryClass: DuplicationFindingRepository::class)]
#[ORM\Table(name: 'duplication_finding')]
#[ORM\UniqueConstraint(name: 'uniq_pair', columns: ['source_id', 'target_id'])]
#[ORM\Index(name: 'idx_finding_status', columns: ['status'])]
class DuplicationFinding
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Publication examinée (la plus récente, ou le texte soumis matérialisé). */
    #[ORM\ManyToOne(targetEntity: Publication::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Publication $source;

    /** Publication-source rapprochée (l'antériorité présumée). */
    #[ORM\ManyToOne(targetEntity: Publication::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Publication $target;

    #[ORM\Column(length: 16, enumType: DuplicationType::class)]
    private DuplicationType $type;

    /** Part du texte SOURCE recouvrant la cible (0..1), après filtre de légitimité. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $overlapRatio;

    /** Jaccard verbatim maximal observé sur un couple de fragments (0..1). */
    #[ORM\Column(type: Types::FLOAT)]
    private float $maxJaccard;

    /** Meilleure proximité sémantique (1 - distance cosinus, 0..1). */
    #[ORM\Column(type: Types::FLOAT)]
    private float $semanticSim;

    /** true si source et cible partagent ≥1 auteur (→ auto-recouvrement). */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $sharesAuthor = false;

    /**
     * Passages mis en regard : [{ srcChunkId, tgtChunkId, jaccard, srcText, tgtText }].
     * Bornés (top N) pour la lisibilité comité.
     * @var array<int, array{srcChunkId:int, tgtChunkId:int, jaccard:float, srcText:string, tgtText:string}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $passages = [];

    #[ORM\Column(length: 16, enumType: FindingStatus::class)]
    private FindingStatus $status = FindingStatus::Unreviewed;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $detectedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;
}
```

### 4.3 Enums

```php
enum DuplicationType: string {
    case NearDuplicate   = 'near_duplicate';   // recouvrement global très élevé
    case VerbatimOverlap = 'verbatim_overlap';  // passages copiés mot à mot
    case Paraphrase      = 'paraphrase';        // sémantique élevée, verbatim faible
    case SelfOverlap     = 'self_overlap';      // idem mais auteur commun (auto-plagiat)
}

enum FindingStatus: string {
    case Unreviewed  = 'unreviewed';
    case Confirmed   = 'confirmed';   // plagiat/doublon avéré (comité)
    case Dismissed   = 'dismissed';   // faux positif
    case Legitimate  = 'legitimate';  // recouvrement justifié (méthode standard, corpus partagé…)
}
```

---

## 5. Pipeline en deux étages

### Étage 0 — Empreintes (à l'ingestion plein-texte)
À chaque chunk plein texte créé (worker `fulltext`), enfiler
`FingerprintPublication` → pour chaque chunk : `Shingler` (k=5 mots, normalisation
casse/ponctuation/espaces) → `MinHasher` (P=128) → `ChunkFingerprint` + bandes LSH.
Idempotent (rejeu = recalcul stable).

### Étage 1 — Rappel (candidats)
Pour une publication à examiner, réunir les candidats par **deux voies**, en
excluant la publication elle-même :

- **LSH** : pour chaque chunk, requêter les chunks co-bucketés (même `band_hash` sur
  ≥1 bande) → candidats verbatim.
- **Sémantique** : `nearestTo` sur l'embedding de chaque chunk (déjà en place) avec
  distance < seuil serré → candidats paraphrase.

On agrège au niveau **publication-cible** (compte de chunks rapprochés).

### Étage 2 — Confirmation & scoring
Pour chaque couple (chunk source, chunk candidat) :

1. **Jaccard exact** sur les ensembles de shingles (le MinHash n'a servi qu'au
   rappel ; ici on confirme).
2. **Filtre de légitimité** (`LegitimacyFilter`) : on **retire** des passages
   - citations entre guillemets correctement attribuées,
   - boilerplate méthodologique standard et phrases ultra-communes (stop-shingles
     fréquents dans le corpus → liste de fréquence),
   - sections de **références/bibliographie** (déjà hors-citation = hors périmètre).
3. **Agrégation publication** : `overlapRatio` = part des shingles source couverts
   après filtre ; `maxJaccard` ; `semanticSim`.
4. **Typage** (`DuplicationType`) selon la grille §9, `sharesAuthor` via le graphe
   `Authorship` → `SelfOverlap` si auteur commun.
5. Persistance d'un `DuplicationFinding` (top-N passages), statut `Unreviewed`.

---

## 6. Cas C — vérification d'un texte soumis

Au **dépôt auteur tokenisé** (`spec-plateforme-auteur.md`), avant publication :

1. Découper le texte soumis en fragments (même *chunker* que l'ingestion).
2. Embeddings + shingles **à la volée** (pas de persistance tant que non publié).
3. Étages 1–2 contre le corpus → restituer un **rapport de similarité** à la
   rédaction (passages + cibles), en **avertissement non bloquant**.

Commande `app:plagiarism:check-text --file=… [--node=…]` pour l'usage ad-hoc.

---

## 7. Asynchrone & commandes

Nouvelle file Messenger **`plagiarism`** (isolée, comme `analysis` : un scan lourd
ne doit ni affamer la moisson ni être affamé par elle).

| Message | File | Déclencheur |
|---|---|---|
| `FingerprintPublication` | `plagiarism` | fin d'ingestion plein-texte d'une publi |
| `ScanPublication` | `plagiarism` | après empreinte, ou en lot/cron |

Commandes :

| Commande | Rôle |
|---|---|
| `app:plagiarism:fingerprint --limit=N` | calcule les empreintes manquantes (drain) |
| `app:plagiarism:scan [--node= \| --publication= \| --since=]` | détection scopée |
| `app:plagiarism:check-text --file=…` | rapport ad-hoc sur un texte soumis (cas C) |

Intégration moisson : le worker `fulltext` enfile `FingerprintPublication` puis
`ScanPublication` pour chaque nouvelle publi indexée → détection **incrémentale**
(le nouvel arrivant est comparé à l'existant, pas l'inverse). Cron de rattrapage
pour les empreintes manquantes (corpus antérieur à la feature).

---

## 8. Restitution

- **Back-office** : onglet « Doublons & plagiat » → file des `DuplicationFinding`
  `Unreviewed`, triés par `overlapRatio`. Fiche : passages **source ↔ cible** en
  vis-à-vis (surlignage des shingles communs), métadonnées des deux publis, boutons
  comité → `Confirmed` / `Dismissed` / `Legitimate`.
- **Fiche publication** : badge discret « contenu recouvrant (n) » si ≥1 finding
  `Confirmed`, avec lien vers les cibles. **Jamais** affiché public tant que non
  validé (présomption + risque diffamatoire).
- **Soumission auteur** : encart d'avertissement avec les passages signalés.

---

## 9. Seuils & garde-fous (grille de typage)

Valeurs **éditables via `SettingsService`** (jamais en dur), calibrées au lot 1.

| Type | Condition (après filtre de légitimité) |
|---|---|
| `NearDuplicate` | `overlapRatio ≥ 0.60` (recouvrement massif) |
| `VerbatimOverlap` | `maxJaccard ≥ 0.50` sur ≥3 fragments, `overlapRatio ≥ 0.20` |
| `Paraphrase` | `semanticSim ≥ 0.92` **et** `maxJaccard < 0.30` (réécrit, pas copié) |
| `SelfOverlap` | l'un des trois ci-dessus **et** `sharesAuthor = true` |

Garde-fous anti-faux-positifs :
- **Filtre de légitimité** obligatoire avant scoring (§5.2).
- **Plancher de longueur** : ignorer les fragments < k·3 shingles (titres, légendes).
- **Auteur commun ≠ plagiat** : reclassé `SelfOverlap` (problème éditorial distinct).
- **Corpus partagé légitime** : méthodes standard, descriptions d'instruments, clauses
  d'éthique → liste de boilerplate maintenue + fréquence de shingles.
- **Antériorité** : `source` = la plus **récente** (par `publicationDate`) ; un
  recouvrement n'impute rien sans ordre temporel.

---

## 10. Coût & passage à l'échelle

- **Empreintes** : MinHash P=128 ≈ 512 o/chunk. À 45 M chunks (cible palier 1 à
  1 M articles), ≈ **23 Go** de signatures sur disque — acceptable (le disque n'est
  pas la contrainte, cf. `architecture-amelioree.md §7`). Les bandes indexées
  dominent le coût requête, pas le stockage.
- **Calcul** : shingling + MinHash sont **CPU, locaux, parallélisables** (comme
  GROBID/embeddings sur Marvin). Borné par le débit d'ingestion, pas un goulot neuf.
- **Détection** : LSH rend le rappel sous-quadratique ; les scans sont **scopés**
  (nœud / nouvelle publi). Pas d'all-pairs global.
- **Compatibilité `halfvec`** : l'étage sémantique réutilise tel quel les embeddings
  (et leur future bascule `halfvec`) — rien de spécifique côté plagiat.

---

## 11. Phases de livraison (lots)

1. **Lot 1 — Doublons intra-corpus (cas A), verbatim.** `ChunkFingerprint`,
   MinHash/LSH, `app:plagiarism:fingerprint` + `:scan`, `DuplicationFinding`,
   fiche back-office. Valeur immédiate, faux positifs faibles.
2. **Lot 2 — Étage sémantique (paraphrase) + auto-plagiat.** Branchement `nearestTo`,
   `SelfOverlap` via graphe auteur, grille de typage complète.
3. **Lot 3 — Vérification de texte soumis (cas C).** `check-text` + intégration au
   dépôt auteur (avertissement rédaction).
4. **Lot 4 — Industrialisation.** Filtre de légitimité affiné (liste de boilerplate,
   fréquence de shingles), seuils calibrés sur cas réels, cron de rattrapage.

---

## 12. Risques & limites

- **Faux négatifs structurels** : source hors corpus (web, payant, autre langue) →
  invisible. À documenter dans l'UI (« comparé à N publications indexées »).
- **Faux positifs thématiques** : maîtrisés par la confirmation verbatim + le filtre
  de légitimité, jamais à 100 %. → **statut comité obligatoire** avant tout affichage.
- **Traduction inter-langue** : non couvert au départ (dépend d'un embedding
  multilingue ; le modèle actuel est mono/limité). Lot ultérieur éventuel.
- **Risque réputationnel/diffamatoire** : un « plagiat » affiché à tort est grave.
  → présomption stricte, rien de public sans `Confirmed`, vocabulaire prudent
  (« contenu recouvrant » plutôt que « plagiat » dans l'UI publique).
- **Sections sans plein texte** : sur le palier 0 (résumé seul), seul le **résumé**
  est comparable — couverture partielle, à signaler.
- **Boilerplate légitime** : sans liste de fréquence, les méthodes standard polluent ;
  d'où le filtre de légitimité comme brique de premier ordre, pas une option.

---

## 13. Rattachements

- Réutilise : `PublicationChunk` (texte + `embedding`), pgvector `nearestTo`,
  `Authorship` (auteur commun), `SettingsService`, files Messenger, validation comité.
- Distinct de : `Deduplicator` (doublon **exact** par DOI/identifiant à l'ingestion) —
  ici on cible des **œuvres différentes au contenu recouvrant**.
- Complète : signaux d'intégrité (`RetractionStatus`, hype-mètre) ; un `DuplicationFinding`
  `Confirmed` est un signal de fiabilité de plus pour le RAG et le comité.
