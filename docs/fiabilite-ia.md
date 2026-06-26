# Pourquoi l'IA de SciencesWiki est fiable — garde-fous, attribution, juge de vérité

> Document de référence (et de preuve) sur la fiabilité de notre chaîne IA. Écrit pour
> des spécialistes, mais chaque terme technique est défini (voir le **§9 Glossaire**) et
> les phrases restent accessibles. Principe directeur : **la fiabilité ne vient pas d'un
> modèle « plus intelligent », mais d'une défense en profondeur** — plusieurs garde-fous
> indépendants qui se rattrapent les uns les autres.

---

## 1. Le problème que nous traitons

Un grand modèle de langage (**LLM**) génère du texte plausible, pas du texte vrai. Trois
défaillances connues nous concernent directement :

1. **Hallucination** : le modèle affirme un fait, un chiffre ou une référence qui n'existe
   pas. Le papier d'OpenAI *Why Language Models Hallucinate* (2025) montre que c'est
   **structurel** : les protocoles d'entraînement récompensent la réponse confiante et
   **pénalisent l'abstention** (« je ne sais pas »). Sans contre-mesure, un modèle devine.
2. **Erreur d'attribution** : la réponse cite une source… qui ne dit pas ce qu'on lui fait
   dire. C'est le maillon que la recherche (benchmark **REFIND**, SemEval 2025) a identifié
   comme le plus fragile.
3. **GIGO** (*garbage in, garbage out*) : même une IA parfaitement fidèle à ses sources
   reste fausse si les sources le sont (préprints non relus, articles rétractés, plagiats).

Notre système attaque les trois, à des **couches différentes**.

---

## 2. Cadre : le « RAG Triad »

Notre génération est en **RAG** (*Retrieval-Augmented Generation*) : on **récupère** des
passages d'un corpus, puis on **génère** la réponse à partir d'eux. Trois métriques
décomposent la fiabilité d'un tel système — et chacune pointe un réglage précis :

| Métrique | Question | Ce qu'elle cible |
|---|---|---|
| **Faithfulness / groundedness** | Chaque affirmation est-elle soutenue par les passages récupérés ? | le LLM générateur |
| **Attribution** | La citation pointe-t-elle vers un passage qui dit vraiment ça ? | l'alignement réponse↔preuve |
| **Context precision / recall** | La récupération a-t-elle ramené le bon passage ? | la taille de chunk, le top-K, l'embedding |

Un piège classique : la *faithfulness* peut sembler bonne alors que la **récupération a raté
la preuve clé**. Il faut donc suivre les trois ensemble. Les sections suivantes montrent
notre implémentation de chacune.

---

## 3. Couche 1 — Génération contrainte (faithfulness à la racine)

- **RAG strict** : le prompt interdit explicitement les connaissances internes du modèle ;
  il ne doit répondre qu'à partir des SOURCES fournies.
- **Abstention obligatoire** : si les sources ne permettent pas de répondre, le système le
  **dit** et n'élabore pas. En amont, un **garde-fou de récupération** (`MAX_SOURCE_DISTANCE`)
  refuse de générer quand aucune source n'est assez proche — on préfère le silence à
  l'invention. C'est exactement la vertu que l'entraînement standard sabote (§1).
- **Distinction établi / incertain** : le prompt impose de séparer ce qui est consensuel de
  ce qui est contesté.

## 4. Couche 2 — Attribution : le « locator de passage »

Derrière chaque citation **[n]** d'une réponse (chat) ou d'un article, on affiche
l'**extrait source exact** : le fragment de texte intégral (chunk) le plus proche de la
question, récupéré par **kNN** sur les embeddings des chunks. Le lecteur **vérifie de ses
yeux** que la citation est réellement ancrée. C'est la parade directe à l'erreur
d'attribution (§1.2) : on rend la preuve **inspectable**, on ne demande pas de faire
confiance.

## 5. Couche 3 — Vérification de fidélité (le cœur anti-hallucination)

Après rédaction, on **vérifie chaque affirmation contre les sources**, et on marque les
non soutenues avec **`[réf. nécessaire]`** (convention Wikipédia). Trois crans, du plus
simple au plus rigoureux :

- **Cran 1 — LLM-juge** (`FaithfulnessChecker`) : un second modèle, distinct du rédacteur,
  relit et marque les affirmations non étayées (chiffres, dates, relations causales).
  Limite connue : un LLM-juge généraliste plafonne autour de **~50 % de précision** en
  détection (benchmark *FaithBench*). D'où le cran suivant.
- **Cran 2 — vérification contre le PLEIN TEXTE** : la vérification se fait non plus contre
  le seul résumé, mais contre les **passages de texte intégral** (locator GROBID) — ancrage
  bien plus strict.
- **Cran 3 — HHEM (garde-fou NLI dédié)** : un modèle spécialisé, **HHEM-2.1-Open** de
  Vectara (~110 M paramètres, multilingue **FR/EN/DE**), calcule l'**entailment** (cohérence
  logique) entre chaque phrase générée et les passages sources. Il **surpasse les LLM-juges
  et GPT-4** sur la détection d'hallucination, pour une fraction du coût, et tourne **en
  local** sur Marvin. Toute phrase dont le score d'entailment est **< 0,5** est signalée.
  Propriété cruciale : la vérification est **append-only** — on n'ajoute que des marqueurs,
  on ne réécrit jamais le texte → **corruption impossible** (contrairement à un LLM-juge qui
  peut « bavarder » et abîmer le contenu).

Ces trois crans sont **gradués** : HHEM est utilisé en primaire dès qu'il est disponible, le
LLM-juge sert de repli. C'est le passage de « espérer qu'il n'hallucine pas » à
**« détecter et signaler l'hallucination »**.

## 6. Couche 4 — Récupération hybride (context recall)

Si la récupération rate la preuve, le modèle hallucine ou répond à côté — quelle que soit
la qualité des couches suivantes. On combine donc **deux signaux**, fusionnés par **RRF**
(*Reciprocal Rank Fusion*) :

- **vectoriel** : similarité sémantique des embeddings (attrape la reformulation) ;
- **lexical** : recherche plein texte FTS (attrape les termes rares/exacts qu'un embedding
  générique dilue, ex. « COVID »).

Aucun des deux seul ne suffit ; combinés, ils maximisent le *context recall*. Le **découpage
par section via GROBID** améliore mécaniquement l'attribution : un chunk propre = une preuve
nette.

## 7. Couche 5 — Le « juge de la vérité » : qualité du corpus (fidélité ≠ vérité)

**Attention au piège** : la *faithfulness* garantit la fidélité **au corpus**, pas la
**vérité dans le monde**. Notre corpus (OpenAlex + plein texte curé) est très supérieur au
web ouvert, mais il contient des contradictions, des préprints, des articles rétractés. On
distingue donc deux niveaux et on attaque le second :

- **Détection de controverses & lacunes** : on extrait les *claims* des publications, on les
  regroupe, on fait remonter les **désaccords** — au lieu de moyenner naïvement des sources
  qui se contredisent.
- **Rétractations** (`retraction_status`) : signal d'intégrité ; une source devenue non
  fiable est signalée jusque dans les réponses qui s'y appuyaient.
- **Antiplagiat / doublons intra-corpus** (`App\Analysis\Plagiarism`) : repère, **dans notre
  corpus**, les œuvres au texte largement recouvrant (duplications, *salami slicing*,
  auto-plagiat) qui ne sont **pas** de simples doublons de DOI. Pipeline en deux étages :
  - **rappel** par **MinHash / LSH** (signatures compactes + *buckets* → candidats en
    sous-quadratique, pas de comparaison toutes-paires) ;
  - **confirmation** par **Jaccard exact** sur les n-grammes de mots + **filtre de
    légitimité** (citations, boilerplate, bibliographie écartés).

  Le résultat est **non décisionnel** : un score + les passages en regard, qu'un **humain
  (comité)** tranche. Jamais de verdict automatique « plagiat » ; rien n'est affiché
  publiquement avant validation (présomption + risque diffamatoire).

## 8. Couche 6 — Liens vérifiables, jamais inventés

Quand une affirmation manque de source interne, on promeut le marqueur `[réf. nécessaire]`
en **lien Wikipédia réel**, résolu via l'API officielle de Wikipédia (`opensearch`). Le
modèle ne **fabrique jamais** d'URL : soit le lien existe et est résolu, soit on garde le
marqueur honnête.

---

## 9. Ce que nous NE prétendons pas (limites assumées)

L'honnêteté fait partie de la fiabilité :

- **Couverture = notre corpus**, pas tout le savoir. « Rien trouvé » ≠ « original » ou
  « faux » : beaucoup de **faux négatifs** (sources hors corpus : web, bases payantes,
  autres langues). Nous ne sommes pas Turnitin ; l'antiplagiat détecte la **réutilisation
  intra-corpus**.
- **Fidélité ≠ vérité** : on minimise l'hallucination, pas l'erreur du corpus sous-jacent.
- **Plein texte partiel** : l'attribution et l'antiplagiat verbatim n'opèrent que là où on a
  le plein texte (PDF open access passés par GROBID) — une fraction du corpus.
- **La similarité n'est pas une preuve** : tout signal d'intégrité reste soumis à validation
  humaine.

## 10. Mesure continue (évaluation)

Pour ne pas « espérer » mais **mesurer**, l'évaluation prévue :
- **RAGAS / DeepEval** (*reference-free* pour l'essentiel) sur un jeu de questions de
  référence **propre à notre corpus** : faithfulness, context recall, attribution.
- **Jeu de questions-pièges** dont la réponse n'est PAS dans le corpus → mesure du **taux
  d'abstention correcte** (refuser au lieu d'inventer).
- Réserve : le *leaderboard* d'hallucination de Vectara est construit sur du résumé de
  presse, pas de la science → **signal de départ pour pré-sélectionner un modèle**, pas une
  mesure de notre système. Notre propre éval tranche.

---

## 11. Glossaire (lexicographie)

- **LLM** (*Large Language Model*) — grand modèle de langage ; produit du texte plausible
  token par token, sans garantie de vérité.
- **RAG** (*Retrieval-Augmented Generation*) — on récupère des passages d'un corpus puis on
  génère la réponse à partir d'eux, plutôt que de la « mémoire » du modèle.
- **Hallucination** — affirmation (fait, chiffre, citation, URL) que rien ne soutient.
- **Faithfulness / groundedness** — degré auquel la réponse est **étayée par le contexte
  récupéré** ; 1,0 = chaque affirmation est soutenue.
- **Attribution** — lien entre une affirmation et le **passage précis** censé la justifier.
- **Abstention** — répondre « ce n'est pas dans les sources » plutôt que deviner.
- **Embedding** — vecteur numérique (ici 384 dimensions) qui représente le **sens** d'un
  texte ; deux textes proches en sens ont des vecteurs proches.
- **kNN** (*k-nearest neighbors*) — recherche des k vecteurs les plus proches (≈ les passages
  les plus pertinents).
- **HNSW** — index qui rend la recherche kNN rapide à grande échelle (graphe navigable).
- **FTS** (*Full-Text Search*) — recherche lexicale (mots exacts), complémentaire du
  sémantique.
- **RRF** (*Reciprocal Rank Fusion*) — fusionne deux classements (vectoriel + lexical) en
  combinant les **rangs**, robuste aux échelles de score différentes.
- **Chunk** — fragment de texte intégral (issu de GROBID) ; unité de récupération et de
  preuve.
- **GROBID** — outil qui extrait le **texte structuré** d'un PDF scientifique.
- **Entailment (NLI)** — *Natural Language Inference* : une hypothèse est-elle **logiquement
  impliquée** par un premise ? C'est la mesure qu'utilise HHEM.
- **HHEM** (*Hughes Hallucination Evaluation Model*) — modèle dédié de Vectara qui score
  l'entailment réponse↔sources ; notre garde-fou anti-hallucination principal.
- **MinHash / LSH** (*Locality-Sensitive Hashing*) — signatures compactes + *buckets* qui
  rapprochent les textes similaires **sans** comparer toutes les paires (sous-quadratique).
- **Jaccard** — taille de l'intersection / taille de l'union de deux ensembles (ici de
  n-grammes de mots) ; mesure exacte du recouvrement verbatim.
- **Shingle / n-gramme** — séquence de n mots consécutifs (ici 5) ; unité de comparaison
  verbatim.
- **GIGO** (*Garbage In, Garbage Out*) — des entrées fausses produisent des sorties fausses,
  quelle que soit la qualité du traitement.
- **`[réf. nécessaire]`** — marqueur (convention Wikipédia) signalant une affirmation non
  encore étayée, à vérifier par un humain.
