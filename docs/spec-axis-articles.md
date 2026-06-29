# Spec — Évaluation critique AXIS des articles (études transversales)

> Outil : pour chaque article, produire une **évaluation méthodologique automatique**
> par la grille **AXIS** (*Appraisal tool for Cross-Sectional Studies*, Downes MJ,
> Brennan ML, Williams HC, Dean RS. *BMJ Open* 2016;6:e011458.
> doi:10.1136/bmjopen-2016-011458). S'appuie sur l'existant : pile LLM auto-hébergée,
> extraction structurée de `Claim`, texte intégral GROBID (`publication_chunk`),
> validation comité (`ReviewStatus`).
>
> Statut : **implémenté** (Phase A). Reprend l'esprit « décoder une étude » d'`IDEA.md`
> et le motif d'analyse par publication de `docs/spec-plagiat.md`.

---

## 1. Objectif & valeur

Donner à chaque article une **fiche de lecture critique** sourcée et validable :
20 questions Oui/Non/Indéterminé sur la qualité méthodologique, ancrées sur des
**citations verbatim**, assorties d'une **bande indicative de fiabilité** et d'une
synthèse courte. Public visé : chercheurs, journalistes, enseignants — toute
personne devant juger la solidité d'une étude transversale.

Principe directeur (comme les controverses et le plagiat) : **signal non
décisionnel**. Rien n'est affiché publiquement avant validation d'un comité humain.

---

## 2. L'outil AXIS

- **20 items** répartis en : Introduction (1), Méthodes (2–11), Résultats (12–16),
  Discussion (17–18), Autre (19–20). Catalogue : {@see App\Catalog\AxisChecklist}.
- **Trois modalités** par item : *Oui / Non / Ne sait pas* ({@see App\Enum\AxisAnswer},
  où « Ne sait pas » = `Unclear`). Le « Ne sait pas » est une réponse **valide** —
  fréquente quand l'information manque du texte source — pas un échec.
- **Polarité.** Pour la plupart des items, un « Oui » est favorable. Deux items sont
  **inversés** (`AxisChecklist::REVERSE`) : q13 (inquiétude de biais de non-réponse)
  et q19 (conflits d'intérêts) — un « Oui » y est *défavorable*.
- **Pas de score.** Les auteurs d'AXIS **déconseillent explicitement** une note
  numérique globale. On affiche donc une **checklist** + une **bande indicative**
  (`high|moderate|low|insufficient`) calculée sur la part d'items favorables parmi
  les items réellement évaluables — toujours accompagnée de l'avertissement
  (`AxisSerializer::DISCLAIMER`).

---

## 3. Verrou d'applicabilité

AXIS n'a été conçu et validé **que pour les études transversales**. L'appliquer à un
RCT, une cohorte, une méta-analyse, une étude in vitro… produirait un résultat
trompeur. Le pipeline classe donc d'abord le **design** ({@see App\Enum\AxisApplicability}) :

- `NotApplicable` (autre design) → la grille **n'est pas exécutée** ; la fiche
  indique simplement « non applicable (étude : … ) ».
- `Applicable` (transversale) → les 20 items sont évalués.
- `Uncertain` (design ambigu) → grille exécutée, marquée basse confiance.

---

## 4. Modèle de données

Entité `AxisAppraisal` — **une par publication** (FK unique, `ON DELETE CASCADE`).
Champs : `publication`, `treeNode` (contexte de scoping), `applicability`,
`studyDesign`, `answers` (JSON `{q1..q20 → yes|no|unclear}`), `justifications`
(JSON `{q → citation verbatim}`), `favorableCount`, `assessableCount`,
`reliabilityBand`, `sourceScope` (`abstract` | `abstract+fulltext`), `summary`,
`appraisalModel` (figé), `status` ({@see App\Enum\ReviewStatus}, défaut `Detected`),
`createdAt`, `reviewedBy`. Migration : `Version20260628110000`.

---

## 5. Évaluation (le cœur)

`LlmClient::complete()` ne renvoie que du texte : on force un JSON unique
(`AxisPromptBuilder`), on parse/répare (`AxisJsonParser`, **un retry** si le JSON est
indécodable), puis on persiste (`AxisAppraiser`). Modèle léger
(`SettingsService::lightModel()`), température 0, idempotent (purge avant ré-insert).

### 5.1 Prompt (`AxisPromptBuilder`)
Système : rôle d'évaluateur AXIS ; **étape 0** d'applicabilité (design transversal ?) ;
puis les 20 items (injectés depuis `AxisChecklist`, items inversés signalés) ;
règle de citation verbatim obligatoire pour toute réponse défavorable ; JSON strict.
Utilisateur : `TITRE` + texte source (résumé + extrait texte intégral si conservé).

### 5.2 Source du texte
Résumé (langue d'origine, repli FR) **+ texte intégral GROBID** quand
`fulltextStored` (les 20 items exigent souvent méthodes/résultats). À défaut, résumé
seul → de nombreux items légitimement `Unclear`, `sourceScope = abstract`, bande
souvent `insufficient`.

---

## 6. Algorithme & bande de fiabilité

1. Classer le design → applicabilité (verrou §3).
2. Si applicable : pour chaque item, retenir la réponse du LLM.
3. **Bande indicative** : `frac = favorables / évaluables` (évaluable = réponse ≠
   `Unclear`). `high ≥ 0,8`, `moderate ≥ 0,6`, sinon `low`. Si moins de 10 items
   évaluables → `insufficient` (texte trop pauvre pour conclure).

---

## 7. Commande (CLI)

`analysis:appraise-axis --node=<slug> [--limit] [--reappraise]`
({@see App\Analysis\Command\AppraiseAxisCommand}). Debug/backfill : en exploitation,
c'est l'orchestrateur (`analysis:run`) qui enchaîne cette étape, à la suite de
l'extraction des claims, dans le même job ({@see App\Analysis\AnalysisOrchestrator}).

---

## 8. Exposition (API + Web)

- **Public** : `GET /api/articles/{id}` inclut un bloc `axis` **uniquement** si
  l'évaluation est `Confirmed` (comité). Mise en forme : `AxisSerializer`.
- **Back-office** (`ROLE_ADMIN`) : `GET /api/admin/axis` (file d'examen,
  prévisualisation des `Detected`/`UnderReview`) + `POST /api/admin/axis/{id}/review`
  (transition `Confirmed`/`Dismissed`) — {@see App\Controller\Admin\AdminAxisController}.
- **Front** : panneau repliable « Évaluation méthodologique (AXIS) » dans la fiche
  article de l'explorateur (`templates/wiki/explorer.html.twig`) : badge
  d'applicabilité, bande de fiabilité, synthèse, 20 items ✓/✗/? + citations, et
  l'avertissement « checklist, pas un score ».

---

## 12. Risques & garde-fous

- **Hallucination** → toute réponse **défavorable** doit être ancrée par une citation
  **présente dans le texte source** (vérification verbatim, comme `ClaimExtractor`) ;
  sinon la réponse est rétrogradée en `Unclear`. Température 0, modèle figé.
- **Hors-sujet** → verrou d'applicabilité (§3) : AXIS uniquement aux transversales.
- **Sur-interprétation** → pas de score global ; bande *indicative* + avertissement.
- **Coût LLM** → 20 items/article : scoppé par nœud, par lots, idempotent, modèle léger.
- **Validation humaine** → rien de public sans passage comité (`ReviewStatus`).

---

## 11. Tests

- **Unit** `AxisJsonParser` : JSON valide / fences / cassé→retry / non-applicable /
  forme `"qN":"yes"` / valeur d'enum inconnue.
- **Unit** `AxisAppraiser` : design non transversal → `NotApplicable` sans items ;
  réponse défavorable **ancrée** conservée ; réponse défavorable **non ancrée**
  rétrogradée en `Unclear`. Via un `LlmClient` factice.
