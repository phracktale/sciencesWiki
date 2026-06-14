# Spécifications — Plateforme « SciencesWiki »

> **Statut :** brouillon v0.5 — document vivant, soumis à vos réponses.
> **Date :** 2026-06-14
> **Devise du projet :** *éducation populaire, savoir libre, sources ouvertes.*

---

## 1. Vision

Construire une plateforme **libre et open source** qui :

1. **Répertorie** l'ensemble des notions scientifiques sous forme d'un **arbre des
   connaissances** navigable.
2. **Vulgarise** ces savoirs dans des articles wiki, **adossés à des publications
   de recherche** (sources primaires), avec un **bloc clairement identifié**
   séparant le travail académique sourcé du travail de vulgarisation non
   académique.
3. **S'appuie sur un comité scientifique** par domaine de compétence pour la
   relecture, et sur une **modération communautaire de type Wikipedia** pour
   l'édition.
4. **Incite les chercheurs** à déposer librement leurs travaux comme sources, au
   nom d'une démarche d'éducation populaire, libre et ouverte.

Le projet se compose de :

- une **application web** (Symfony 8, API + back-office + front public) ;
- des **applications mobiles** iOS & Android (Flutter) ;
- une **moissonneuse** (harvester) qui alimente la base à partir de sources
  d'articles scientifiques en libre accès, assistée par IA pour le tri et le
  classement dans l'arbre.

---

## 2. Principes directeurs

| Principe | Implication concrète |
|---|---|
| **Légalité stricte des sources** | On ne moissonne **que** des contenus légalement diffusés en open access (déposés par les auteurs/éditeurs). Aucune source piratée. |
| **Transparence des savoirs** | Toute affirmation vulgarisée renvoie à au moins une source primaire identifiable (DOI). |
| **Séparation académique / vulgarisation** | Deux blocs visuellement distincts : *Sources & faits établis* vs *Vulgarisation (travail non académique)*. |
| **Gouvernance ouverte** | Édition communautaire + validation par comité scientifique du domaine. |
| **Libre & ouvert** | Code sous licence libre ; contenu sous **CC BY-SA 4.0**. |
| **Francophone d'abord** | UI et contenus en français au lancement ; architecture i18n prête pour l'anglais ensuite. |
| **Souveraineté technique** | Tri IA réalisé via **modèles open source auto-hébergés** (pas de dépendance à une API propriétaire). |

---

## 3. Cadre légal & éthique des sources

### 3.1 Sources retenues (libre accès légal)

> **Stratégie d'implémentation (décidée) :** **toutes** les sources ci-dessous
> sont *référencées* dans le registre de sources dès la conception (modèle de
> données + configuration), mais seules les **3 premières** (OpenAlex, Unpaywall,
> arXiv) sont **développées** en Phase 1. Les connecteurs suivants se branchent
> ensuite sans changer l'architecture (interface `SourceConnector` commune).

| Source | Type | Accès | Rôle dans le pipeline | Phase |
|---|---|---|---|---|
|---|---|---|---|
| **OpenAlex** | Index méta (250M+ travaux) | API gratuite | Socle de découverte, métadonnées, graphe de citations, lien OA | **1 (codé)** |
| **Unpaywall** | Résolveur OA légal | API gratuite | Trouve la version **légalement** déposée d'un DOI | **1 (codé)** |
| **arXiv** | Préprints STEM | Full-text libre, API | Moisson full-text (physique, maths, info, bio…) | **1 (codé)** |
| **Europe PMC / PMC** | Biomédical | Full-text OA, API | Moisson full-text biomédical | 2 (référencé) |
| **HAL** | Archive ouverte FR | Full-text OA, API | Forte couverture francophone | 2 (référencé) |
| **DOAJ** | Annuaire de revues OA | API | Filtrage revues 100 % OA | 2 (référencé) |
| **CORE** | Agrégateur OA mondial | API | Complément de couverture | 2 (référencé) |
| **OpenAIRE** (EU Open Research) | Agrégateur européen | API | Projets/financements européens | 3 (référencé) |
| **Persée** | SHS francophone | OA | Sciences humaines et sociales | 3 (référencé) |
| **Diamond OA** (revues sans frais) | Revues | OA | Cible prioritaire (ni paywall, ni APC) | 3 (référencé) |

> *Nature, Google Scholar et autres : voir §3.3.*

### 3.2 Principe « Diamond / Green / Gold OA »

La moissonneuse privilégie, dans l'ordre :
1. **Diamond OA** (libre pour le lecteur ET l'auteur),
2. **Gold OA** (libre pour le lecteur, licence CC explicite),
3. **Green OA** (version auteur légalement auto-archivée, via Unpaywall).

Chaque article moissonné porte sa **licence d'origine** et son **statut OA** ;
on ne réutilise le *full-text* que si la licence l'autorise, sinon on ne stocke
que **métadonnées + lien + citation**.

### 3.3 Sources écartées (et pourquoi) — ⚠️ important

| Source | Décision | Motif |
|---|---|---|
| **Sci-Hub** | **Pas de connecteur dans la moissonneuse** (décidé) | La *plateforme* ne télécharge/stocke pas de PDF depuis Sci-Hub : une infrastructure publique et systématique de téléchargement crée une responsabilité **institutionnelle** (≠ usage individuel transitoire). Mais le **but étant de vulgariser, pas de redistribuer**, la plateforme n'en a pas besoin. Voir la note §3.4. |
| **Google Scholar** | **Exclue comme moissonneuse** | N'est pas une source OA mais un index ; son scraping est **interdit par ses CGU** et bloqué techniquement. Le même besoin de découverte est couvert **légalement** par OpenAlex + Unpaywall. |
| **Nature** (contenu paywall) | **Métadonnées seulement** | Seuls les articles Nature explicitement OA seront full-text ; le reste = métadonnées + lien éditeur. |

> Cette frontière n'est pas un détail technique : c'est **l'argument commercial
> et éthique** auprès des chercheurs. « Nous ne diffusons que ce qui est
> légalement libre » est précisément ce qui les rassure pour déposer.

**Maximiser la couverture légalement (réponse au besoin couvert par Sci-Hub) :**
au lieu de pirater les articles sous paywall, on couvre le même besoin par des
moyens légaux et on en fait un **levier d'éducation populaire** :

1. **Unpaywall + OpenAlex** récupèrent la version Green OA **légalement
   auto-archivée par l'auteur** (forte couverture, 0 € de risque).
2. **Open Access Button / CORE** complètent la recherche de copies légales.
3. Pour un article **sans aucune version OA légale** : on ne stocke que
   **métadonnées + lien éditeur**, et l'absence devient une **invitation au
   dépôt** adressée à l'auteur — c'est la mission même du projet en action.

### 3.4 Note sur Sci-Hub et l'éthique de l'accès ouvert

Cette plateforme **partage l'éthos de l'accès ouvert** : la recherche, souvent
financée par l'argent public, devrait être librement accessible, et Sci-Hub est
de fait massivement utilisé par la communauté scientifique. Le projet **milite**
pour cet idéal — mais par des moyens qui ne l'exposent pas juridiquement.

Distinction juridique clé, **favorable au projet** :

> Le droit d'auteur protège l'**expression** (le texte d'un article), **pas les
> faits ni les idées scientifiques**. **Lire** un article — par quelque moyen que
> ce soit — puis **rédiger sa propre vulgarisation** de ses résultats, en le
> **citant**, est **légal**. Une découverte n'appartient à personne.

Conséquences de conception :

- La plateforme **ne redistribue jamais le PDF** d'un article sous paywall ;
  elle publie un **article de vulgarisation original** + la **citation (DOI)**.
- Pour un papier non OA, un **contributeur l'ayant lu par ses propres moyens**
  peut en rédiger la vulgarisation ; la plateforme n'héberge que ce texte
  original et la référence.
- L'**activisme** s'exprime dans le **discours** (bandeau « ce savoir devrait
  être libre », plaidoyer Open Access) et le **levier** (invitation au dépôt),
  **pas** dans l'hébergement de copies illicites.
- Décision de risque finale = celle du porteur de projet ; l'agent de
  développement **n'implémentera pas** de connecteur vers une source pirate.

---

## 4. Acteurs & rôles

| Rôle | Droits | Notes |
|---|---|---|
| **Visiteur** | Lecture, recherche, navigation, **poser une question** | Anonyme autorisé (lecture + question) |
| **Contributeur** (compte) | Rédiger/éditer des réponses, suggérer sources, signaler | **Identité vérifiée obligatoire** (nom réel **ou** pseudo, jamais anonyme en interne) |
| **Relecteur expert** | Valider les blocs « académiques » de son domaine | Rattaché à un/des nœuds de l'arbre |
| **Comité scientifique (domaine)** | Adouber un article comme « validé scientifiquement », trancher les litiges | Élargi par domaine de compétence |
| **Modérateur** | Gérer signalements, conflits d'édition, vandalisme | Type Wikipedia |
| **Administrateur** | Gestion plateforme, rôles, taxonomie de haut niveau | — |
| **Moissonneuse / IA (système)** | Ingestion automatique, propositions de placement, **rédaction des brouillons de vulgarisation** | Agent non humain ; sa production passe toujours par validation comité |

> **Système de réputation** (à confirmer §13) : gains de droits par contributions
> validées, à la manière de Wikipedia / StackExchange.

**Principe d'identité (décidé) : « rien d'anonyme » côté contenu.**
- **Lecture** et **pose de question** : **anonymes autorisées**.
- **Rédaction/édition de contenu** : exige un **compte à identité vérifiée**
  (nom réel **ou** pseudonyme, mais identité **vérifiée et traçable** en interne).
- **Toute réponse publiée est SIGNÉE** : par le **modèle IA** (si l'IA en est
  l'auteur principal) ou par l'**auteur principal humain** (1er auteur, ou celui
  ayant **rédigé le plus de texte** — déterminé par le versionnage fin, §8.6).
- **Tous les participants sont listés** et crédit, avec leur **rôle/contribution**.

---

## 5. Architecture générale

Monorepo proposé :

```
sciencesWiki/
├── apps/
│   ├── api/            # Symfony 8 + API Platform — API durcie (sécurité forte)
│   ├── web/            # Front public Symfony/Twig — consomme l'API
│   └── mobile/         # Flutter (iOS + Android) — consomme l'API
├── services/
│   └── harvester/      # Moissonneuse (worker autonome)
├── ml/                 # Modèles IA auto-hébergés (embeddings, classif.)
├── infra/              # Docker, IaC, CI/CD
└── docs/               # Spécifications, ADR, gouvernance
```

**Flux de données :**

```
Sources OA ──▶ Moissonneuse ──▶ File d'ingestion ──▶ Normalisation
   (API)                                              │
                                                      ▼
                              IA auto-hébergée (embeddings + classif.)
                                                      │
                          Suggestion de placement dans l'arbre
                                                      │
                                                      ▼
              Base (PostgreSQL) ◀──▶ API Symfony 8 + API Platform (durcie)
                                                      ▲
                          tous les clients passent par l'API (HTTPS, auth)
                        ┌─────────────────────────────┼─────────────┐
                        │                             │             │
                  Front Web Symfony/Twig        App Flutter    Back-office
                   (SSR, SEO, lecture)         (iOS/Android)  (révision/modération)
```

> **Décision d'architecture :** l'**API Symfony 8 + API Platform** est l'unique
> source de vérité, **durcie** (voir §10.1 sécurité). Le **front web Symfony/Twig**
> (rendu serveur pour le SEO, essentiel à une encyclopédie) et les **apps Flutter**
> sont des **clients** de cette API. Aucun client n'accède directement à la base.

---

## 6. La moissonneuse (priorité n°1)

> 📄 **Spécifications détaillées :** voir [`docs/phase-1-moissonneuse.md`](phase-1-moissonneuse.md)
> (connecteurs, pipeline Messenger, modèle de données, portier de licence,
> enrichissement IA, critères d'acceptation).

### 6.1 Responsabilités

1. Interroger périodiquement les **API des sources retenues** (§3.1).
2. Récupérer **métadonnées** (titre, auteurs, DOI, date, revue, licence, statut
   OA, résumé) et, **si la licence le permet**, le **full-text** (HTML/PDF/XML
   JATS).
3. **Dédoublonner** par DOI / identifiants (un même papier peut apparaître dans
   plusieurs sources).
4. **Normaliser** vers un schéma interne commun.
5. Faire appel à la couche **IA auto-hébergée** pour :
   - générer un **embedding** du contenu,
   - **proposer un (des) emplacement(s)** dans l'arbre des connaissances,
   - extraire **mots-clés / concepts** et **niveau de difficulté**.
6. Déposer le résultat en **file de validation** (rien n'est publié sans contrôle).

### 6.2 Pipeline détaillé

```
[1] Découverte   → liste de DOI/identifiants à traiter (par source, par requête)
[2] Récupération → métadonnées + (full-text si licence OK)
[3] Filtrage OA  → Unpaywall/licence : full-text autorisé ? sinon méta seules
[4] Dédoublon    → clé DOI ; fusion des provenances
[5] Normalisation→ schéma interne (voir §9 modèle de données)
[6] Enrichisst IA→ embeddings + classification arbre + concepts
[7] Mise en file → statut "à valider" / suggestions de placement
```

Architecture worker : **messages/queue** (Symfony Messenger + transport, ou
worker dédié), traitement **idempotent**, **rate-limiting** respectueux des API
sources, **journalisation** complète de la provenance (audit/transparence).

### 6.3 Couche IA (auto-hébergée)

- **Embeddings** : modèle open source (ex. famille *sentence-transformers* /
  multilingue) servi en local.
- **Classification** : k-NN sur embeddings vers les nœuds de l'arbre + (option)
  petit LLM open source local pour justifier/affiner le placement.
- **Rédaction de vulgarisation (pilotée par les questions)** : un **LLM open
  source auto-hébergé** génère des **réponses sourcées** (notes DOI) à des
  **questions** rattachées à un nœud — questions **suggérées** (→ brouillon validé
  par le comité, label ✅) ou **libres** (→ public immédiat avec bandeau ⚠️ « non
  relu »). Voir §8 et le **serveur RAG**
  ([`docs/rag-server.md`](rag-server.md)) qui **ancre** la génération (passages
  réels + citations obligatoires).
- **Pas de dépendance à une API propriétaire** ; abstraction permettant de
  changer de modèle.
- Toute production IA est **non décisionnelle** : un humain (comité) valide le
  placement *et* le contenu avant publication.

### 6.4 Conformité & politesse

- Respect du `robots.txt`, des quotas et CGU de chaque API.
- En-tête `User-Agent` identifiant le projet + contact.
- Conservation de la **licence et de l'attribution** de chaque source.

---

## 7. L'arbre des connaissances

- Structure **hiérarchique** (taxonomie) des domaines → sous-domaines → notions.
- Un nœud = une **notion scientifique** ; peut porter un ou plusieurs **articles**.
- **Modèle retenu : graphe orienté acyclique (DAG) (décidé).** Un nœud peut avoir
  **plusieurs parents** (ex. « théorie de l'information » ↔ maths/info/physique).
  Implémentation : table d'arêtes `parent_id → child_id` (relation N-N), avec
  **prévention des cycles** à l'écriture et un **fil d'Ariane** multiple à
  l'affichage. Un parent peut être marqué « **principal** » pour l'URL canonique
  (SEO) tout en conservant les rattachements transverses.
- **Base taxonomique de départ (décidée) :** **amorçage** depuis les *concepts
  OpenAlex* (déjà alignés sur les publications, donc le placement IA fonctionne
  immédiatement), **puis taxonomie éditable** : le **comité scientifique** ou le
  **référent scientifique du domaine** peut **discuter, renommer, fusionner,
  scinder, déplacer** les nœuds. L'arbre OpenAlex n'est qu'une *graine*, pas une
  cassure figée.
- **Gouvernance de la taxonomie :** toute modification structurelle d'un nœud
  (création/fusion/déplacement) passe par un **workflow de validation** assuré
  par le référent/comité du domaine concerné, avec **historique versionné** (au
  même titre que les articles). Un mapping `concept OpenAlex → nœud local` est
  conservé pour que la moissonneuse continue de proposer des placements même
  après réorganisation manuelle.

### 7.1 Export bibliographique (Zotero & interopérabilité)

Depuis **n'importe quel nœud**, on peut **exporter le nœud et ses descendants**
sous forme de bibliographie importable dans **Zotero** (et autres gestionnaires).

- **Portée :** parcours du **DAG** à partir du nœud sélectionné, collecte de
  **toutes les publications rattachées** au nœud **et à ses descendants**, avec
  **dédoublonnage par DOI** (un même papier rattaché à plusieurs nœuds n'apparaît
  qu'une fois). Option `récursif` (nœud + descendants) ou nœud seul.
- **Formats produits** (tous importables par Zotero) :
  - **RIS** (`.ris`) — interopérable, robuste ;
  - **BibTeX** (`.bib`) ;
  - **CSL-JSON** — pivot interne canonique ;
  - **Zotero RDF** — format natif Zotero.
- **Représentation pivot :** chaque `Publication` est mappée en **CSL-JSON**
  (auteurs, titre, DOI, date, conteneur/revue, URL OA, licence), puis convertie
  vers RIS / BibTeX / Zotero RDF.
- **Endpoint API (proposé) :**
  `GET /api/tree-nodes/{slug}/export?format=ris|bibtex|csljson|rdf&recursive=true`
  → fichier téléchargeable (`Content-Disposition`).
- **Intégration directe Zotero (bonus) :** exposer les **métadonnées de
  citation** dans le HTML des pages (balises type *Dublin Core* / *Highwire* /
  **COinS**, voire **unAPI**) pour que le **connecteur de navigateur Zotero**
  capture les références sans téléchargement de fichier.
- **Volumétrie :** export d'un nœud haut de l'arbre = potentiellement des
  milliers de références → génération **paginée/asynchrone** (job) avec lien de
  téléchargement quand prêt, au-delà d'un seuil.

> Disponible dès que des publications sont placées dans l'arbre (post-Phase 1) ;
> ne dépend ni du wiki ni des comités.

---

## 8. Le wiki — vulgarisation pilotée par les questions

### 8.1 Principe (décidé)

La vulgarisation **n'est pas** « un article par publication » (trop volumineux,
inexploitable). **L'unité de contenu est la question concrète + sa réponse
(Q/R)**, rattachée à un **nœud** de l'arbre. On répond à ce que les gens se
demandent réellement.

- **Questions suggérées** : sur le nœud où se trouve l'utilisateur, l'IA (via le
  **RAG**, cf. [`docs/rag-server.md`](rag-server.md)) propose **quelques questions
  évidentes** tirées du corpus du nœud.
- **Clic → rédaction** : l'IA rédige la **réponse vulgarisée**, ancrée RAG, avec
  les **sources en notes de bas de page** (DOI).
- **Questions libres** : l'utilisateur pose les siennes, avec **garde-fous de
  domaine**.
- **Arbre des vulgarisations = projection simplifiée de l'arbre de référence** :
  même structure de nœuds, mais chaque nœud ne porte qu'une **poignée de Q/R**,
  pas les milliers de publications sous-jacentes.

### 8.2 Deux types de questions

| Type | Origine | Réponse |
|---|---|---|
| **Suggérée (canonique)** | L'IA propose les questions évidentes du nœud | L'IA **pré-rédige** un brouillon → **comité valide** → Q/R **canonique publique** (label *validé*) |
| **Libre** | L'utilisateur saisit sa question | L'IA répond **à la volée** (RAG, sourcée) → **publique immédiatement avec bandeau** *« généré par IA, non relu par un comité »* → file de validation |

**Garde-fou de domaine (décidé : réorientation) :** si une question libre relève
d'un **autre nœud**, l'IA répond en **réorientant vers le bon nœud** et y
**rattache la Q/R** — le savoir atterrit toujours au bon endroit de l'arbre.

### 8.3 Anatomie d'une réponse (Q/R)

```
┌────────────────────────────────────────────────────┐
│  ❓ QUESTION  (+ nœud de rattachement dans l'arbre)  │
├────────────────────────────────────────────────────┤
│  ✍️ SIGNÉ PAR : Dr. Untel / @pseudo  (auteur principal)│
│     ou « Modèle IA — SciencesWiki » si IA principale  │
├────────────────────────────────────────────────────┤
│  🌟 BANDEAU D'IDENTITÉ / PROMOTION (si vulgarisateur  │
│     ou scientifique) : nom, titre, affiliation, ORCID,│
│     bio courte, liens (site, chaîne, réseaux), photo  │
├────────────────────────────────────────────────────┤
│  STATUT :  ✅ Validé par le comité                   │
│        ou  ⚠️ Généré par IA — non relu (bandeau)     │
├────────────────────────────────────────────────────┤
│  🔵 BLOC ACADÉMIQUE (sourcé)                         │
│     Faits établis, chacun lié à un DOI               │
├────────────────────────────────────────────────────┤
│  🟡 BLOC VULGARISATION (pédagogique, non académique) │
├────────────────────────────────────────────────────┤
│  ¹ ² ³  Notes de bas de page → publications (DOI)    │
│  🔗 Ressources de vulgarisation « sûres » (bloc id.) │
├────────────────────────────────────────────────────┤
│  👥 PARTICIPANTS : tous les contributeurs listés      │
│     (auteur principal, co-auteurs, relecteurs,        │
│      comité validateur) + part de contribution        │
└────────────────────────────────────────────────────┘
```

### 8.4 Cycle de vie & statuts

Deux statuts **publics** coexistent (assouplissement assumé : réactivité +
liberté de questionnement, encadrées par le **label** et le **sourcing**) :

```
A) Q/R CANONIQUE (suggérée)
   IA propose la question + pré-rédige la réponse (RAG, sourcée)
        → DRAFT relu par le COMITÉ (annote, corrige, itère)
        → VALIDÉ  → public, label ✅ « validé par le comité »

B) Q/R LIBRE (utilisateur)
   IA répond à la volée (RAG, sourcée, garde-fous + réorientation)
        → PUBLIC immédiat, bandeau ⚠️ « non relu »  + file de validation
        → le comité peut : VALIDER (→ devient canonique ✅),
                            CORRIGER, ou RETIRER
```

- **Mur de publication ciblé** : le **label ✅ « validé »** et toute écriture du
  **bloc académique** exigent une `Review` comité enregistrée (contrôle
  applicatif). Le statut ⚠️ « non relu » est, lui, **autorisé en public** mais
  **toujours** marqué et sourcé.
- **Déduplication des questions** : avant de générer, on cherche une Q/R existante
  **sémantiquement proche** (RAG) sur le nœud → on réutilise/renvoie plutôt que de
  dupliquer. Les questions libres fréquentes **remontent** comme candidates
  canoniques.
- **Auteur initial = l'IA** ; comité et contributeurs produisent des **révisions**
  (traçabilité complète : qui, quand, diff, restauration).
- **Discussion / annotations** attachées au draft et conservées.
- **Après publication** : corrections communautaires (modèle wiki) sous
  modération ; toute modification du **bloc académique** repasse par l'aval comité.
- **Signalement / modération** (réponse douteuse, source faible, hors-sujet,
  vandalisme).

### 8.5 Arbre des vulgarisations (projection simplifiée)

L'arbre des Q/R **réutilise les mêmes nœuds** que la base de référence, mais
n'expose que les Q/R (canoniques ✅ d'abord, ⚠️ ensuite). C'est une **vue
allégée** de la connaissance : navigable par notion, peuplée par les questions,
adossée — en profondeur — aux publications sourcées.

### 8.6 Attribution, signature & valorisation (décidé)

Objectif : **promouvoir le travail** des vulgarisateurs et scientifiques,
**signer** chaque réponse et **créditer tous les participants** — rien d'anonyme
côté contenu.

- **Versionnage fin de l'authorship.** Chaque édition d'une réponse crée une
  **révision immuable** ; on calcule le **diff** (texte ajouté / supprimé /
  modifié) et **qui** l'a fait. On en déduit, par contributeur, une **part de
  contribution** (caractères/mots nets rédigés).
- **Auteur principal = signataire.** C'est le **modèle IA** si l'IA reste l'auteur
  principal, sinon l'**humain ayant rédigé le plus de texte** (à défaut, le 1er
  auteur). Si un vulgarisateur réécrit l'essentiel d'une réponse initialement IA,
  **il devient l'auteur principal** et la signature bascule sur lui (méritocratie
  au texte).
- **Bandeau d'identité / promotion.** Quand l'auteur principal est un
  **vulgarisateur** ou un **scientifique** (profil vérifié), on affiche un
  **bandeau de valorisation** : nom (réel ou pseudo), titre, affiliation, ORCID,
  **bio courte**, **liens** (site, chaîne vidéo, réseaux, publications), photo,
  bouton **suivre/soutenir**.
- **Liste des participants.** Chaque Q/R affiche **tous** les contributeurs
  (auteur principal, co-auteurs, relecteurs experts, comité validateur) avec leur
  **rôle** et leur **part**. L'**IA** est créditée explicitement comme telle.
- **Identité vérifiée (jamais anonyme côté contenu).** Tout rédacteur a une
  identité **vérifiée** (e-mail + ORCID pour les scientifiques ; vérification
  renforcée possible) ; le **pseudonyme public** est autorisé **mais adossé** à
  cette identité vérifiée et traçable.
- **Question anonyme, réponse signée.** Poser une question peut être anonyme ;
  la **réponse publiée porte toujours une signature** (IA ou auteur principal).
- **Page profil contributeur** : portfolio public listant ses Q/R, son impact
  (vues, Q/R validées, publications citées) — un véritable **CV de vulgarisation**.

---

## 9. Modèle de données (entités principales)

### 9.1 Domaine « Moisson » (Phase 1)

- **Source** — un connecteur OA.
  - `code` (openalex, unpaywall, arxiv…), `nom`, `type_api`, `licence_defaut`,
    `actif`, `phase`, `config` (endpoints, quotas).
- **Publication** — un travail scientifique (clé de dédoublonnage : `doi`).
  - `doi` (unique), `ids_externes` (openalex_id, arxiv_id, pmcid…), `titre`,
    `resume`, `date_publication`, `langue`, `revue`, `type` (article, préprint…),
    `licence`, `statut_oa` (diamond/gold/green/closed), `url_oa_legale`,
    `fulltext_disponible` (bool), `fulltext_stocke` (bool, ssi licence OK),
    `embedding` (vecteur, pgvector), `statut_traitement` (à_traiter/enrichi/
    en_validation/placé/rejeté), `horodatages`.
- **Author** — auteur d'une publication.
  - `nom`, `orcid`, `affiliation` ; relation N-N `Publication`↔`Author` (ordre).
- **PublicationProvenance** — quelle Source a fourni quelle Publication.

---

## 9. Modèle de données (entités principales)

### 9.1 Domaine « Moisson » (Phase 1)

- **Source** — un connecteur OA.
  - `code` (openalex, unpaywall, arxiv…), `nom`, `type_api`, `licence_defaut`,
    `actif`, `phase`, `config` (endpoints, quotas).
- **Publication** — un travail scientifique (clé de dédoublonnage : `doi`).
  - `doi` (unique), `ids_externes` (openalex_id, arxiv_id, pmcid…), `titre`,
    `resume`, `date_publication`, `langue`, `revue`, `type` (article, préprint…),
    `licence`, `statut_oa` (diamond/gold/green/closed), `url_oa_legale`,
    `fulltext_disponible` (bool), `fulltext_stocke` (bool, ssi licence OK),
    `embedding` (vecteur, pgvector), `statut_traitement` (à_traiter/enrichi/
    en_validation/placé/rejeté), `horodatages`.
- **Author** — auteur d'une publication.
  - `nom`, `orcid`, `affiliation` ; relation N-N `Publication`↔`Author` (ordre).
- **PublicationProvenance** — quelle Source a fourni quelle Publication.
  - `publication_id`, `source_id`, `id_dans_source`, `recupere_le`,
    `licence_constatee` (audit de provenance ; une publi peut venir de plusieurs).
- **IngestionJob** — trace d'exécution de la moisson.
  - `source_id`, `requete`, `debut`, `fin`, `nb_traites`, `nb_nouveaux`,
    `nb_erreurs`, `statut`, `log`.
- **PlacementSuggestion** — proposition IA de placement dans l'arbre.
  - `publication_id`, `tree_node_id`, `score`, `methode` (knn/llm), `statut`
    (proposé/accepté/rejeté), `valide_par`, `valide_le`.

### 9.2 Domaine « Arbre des connaissances »

- **TreeNode** — une notion scientifique.
  - `slug` (URL), `label`, `description`, `domaine`, `parent_principal_id`
    (pour l'URL canonique), `openalex_concept_id` (mapping graine), `statut`.
- **TreeEdge** — arête du DAG (multi-parents).
  - `parent_id`, `child_id`, `principal` (bool) ; contrainte **anti-cycle**.
- **TreeNodeRevision** — versionnage des modifications structurelles/éditoriales
  du nœud (qui, quand, diff, validé par référent/comité).

### 9.3 Domaine « Wiki » (vulgarisation par questions)

- **Question** — une question rattachée à un `TreeNode`.
  - `tree_node_id`, `texte`, `embedding` (vector, pour la déduplication
    sémantique), `origine` (`suggeree_ia` / `libre_utilisateur`), `auteur_id`
    (null si IA), `nb_demandes` (popularité → candidate canonique), `cree_le`.
- **Answer** (Q/R) — la réponse vulgarisée à une `Question`.
  - `question_id`, `tree_node_id`, `langue`,
    `statut_validation` : `non_relu` (⚠️ public avec bandeau) /
    `en_relecture_comite` / `valide` (✅), `type` (`canonique` / `libre`),
    `genere_par_ia` (bool), `bloc_academique_valide` (bool),
    `valide_par_comite_le`,
    `auteur_principal_type` (`ia` / `user`), `auteur_principal_id` (signataire,
    recalculé selon la part de contribution — §8.6). *(Le label ✅ `valide` exige
    une `Review` comité ; `non_relu` est public mais toujours marqué — §8.4.)*
- **AnswerRevision** — une version d'une réponse (immuable).
  - `answer_id`, `contenu_academique`, `contenu_vulgarisation`,
    `auteur_type` (ia/comite/contributeur), `auteur_id` (null si IA, mais l'IA
    est créditée explicitement), `cree_le`, `resume_modif`,
    `parent_revision_id`, `diff` (ajouts/suppr./modifs vs parent),
    `chars_ajoutes`, `chars_supprimes` (mesure de contribution).
- **AuthorshipContribution** — part de rédaction par contributeur (dérivée des
  révisions ; sert au calcul de l'auteur principal et à la liste des participants).
  - `answer_id`, `user_id` (ou `ia`), `chars_nets`, `mots_nets`, `part` (%),
    `roles` (auteur/co-auteur/correcteur), `maj_le`.
- **Participation** — crédit affiché d'un acteur sur une Q/R (vue de présentation).
  - `answer_id`, `acteur` (user/IA), `role` (auteur_principal/co-auteur/
    relecteur/comité), `part` (si rédacteur).
- **Footnote / Citation** — note de bas de page liant une affirmation du bloc
  académique à une `Publication`.
  - `answer_revision_id`, `publication_id`, `marqueur` (¹²³), `ancre` (passage),
    `doi`, `passages_rag` (traçabilité des extraits utilisés par le RAG).
- **Annotation** — annotation/correction du comité sur une révision en draft.
  - `answer_revision_id`, `auteur_id`, `ancre`, `commentaire`, `statut`
    (ouverte/résolue), `cree_le`.
- **ExternalResource** — ressource de vulgarisation « sûre » (bloc identifié).
  - `url`, `type` (vidéo, article, podcast…), `editeur`, `niveau_fiabilite`,
    `statut_validation`, `valide_par`, relation N-N avec `Answer`.
- **Discussion / Comment** — page de discussion par `Question`/`Answer`.

### 9.4 Domaine « Communauté & gouvernance »

- **User** — compte (jamais anonyme côté contenu).
  - `email`, `nom_reel`, `pseudo` (public, optionnel), `type_profil`
    (`scientifique` / `vulgarisateur` / `contributeur`), `is_chercheur`,
    `reputation`, `2fa_actif`,
    `identite_verifiee` (bool), `methode_verification` (email/orcid/renforcée).
- **UserProfile** — données de valorisation (bandeau d'identité / page profil).
  - `user_id`, `titre`, `affiliation`, `orcid`, `bio`, `photo`,
    `liens` (jsonb : site, chaîne vidéo, réseaux, publications),
    `promotion_opt_in` (bool), métriques publiques (vues, Q/R validées,
    publications citées).
- **Role** — `ROLE_CONTRIBUTEUR`, `ROLE_RELECTEUR`, `ROLE_COMITE`,
  `ROLE_MODERATEUR`, `ROLE_ADMIN`.
- **DomainExpertise** — rattache un `User` (relecteur/référent/comité) à un ou des
  `TreeNode`/domaines (périmètre de ses droits de validation).
- **Review** — relecture experte / validation comité.
  - `answer_revision_id`, `reviewer_id`, `type` (experte/comité), `statut`
    (approuvé/rejeté/demande_modif), `commentaire`, `cree_le`.
- **Report** — signalement de modération (`cible`, `motif`, `statut`, traitement).

---

## 10. Stack technique (proposition)

| Brique | Choix proposé | Statut |
|---|---|---|
| API & back | **Symfony 8** (PHP 8.3+), **API Platform**, Messenger | ✓ décidé |
| Base de données | **PostgreSQL** (+ `pgvector` pour embeddings) | proposé §13 |
| Recherche | OpenSearch/Elasticsearch ou pg full-text | §13 |
| File / workers | Symfony Messenger (+ RabbitMQ/Redis) | §13 |
| Front web | **Symfony/Twig** (SSR, SEO) consommant l'API | ✓ décidé |
| Mobile | **Flutter** (iOS + Android) consommant l'API | ✓ décidé |
| IA | Modèles open source auto-hébergés (embeddings + LLM léger) | ✓ décidé |
| Conteneurisation | Docker / Docker Compose, CI/CD | §13 |
| Auth | JWT/OAuth2 pour API ; comptes wiki | §13 |
| Hébergement | **Auto-hébergement** (souveraineté) ; serveur GPU pour l'IA | ✓ décidé |

### 10.1 Sécurité de l'API (exigence : « hyper sécurisée »)

L'API étant l'unique porte d'entrée (web, Flutter, back-office), elle est durcie :

- **Authentification** : JWT courts + *refresh tokens* rotatifs ; OAuth2 pour les
  intégrations ; option **2FA** pour rôles sensibles (comité, modérateurs, admin).
- **Autorisation** : contrôle d'accès **par rôle et par domaine** (Voters Symfony)
  — un relecteur n'agit que sur ses nœuds.
- **Surface & transport** : HTTPS/HSTS obligatoire, CORS strict, en-têtes de
  sécurité (CSP, etc.), **rate-limiting** et anti-bruteforce.
- **Entrées** : validation/sérialisation API Platform, protection injection/XSS,
  *upload* contrôlé, anti-CSRF côté formulaires Twig.
- **Audit** : journalisation des actions sensibles (édition, validation,
  modération) ; traçabilité de la provenance des données moissonnées.
- **Secrets & dépendances** : *Symfony Secrets*/vault, scans de vulnérabilités
  (SCA) en CI, mises à jour suivies.
- **Données** : RGPD (minimisation, droit à l'effacement), chiffrement au repos
  des données sensibles.

---

## 11. Inciter & valoriser les chercheurs et vulgarisateurs

- **Bandeau d'identité / promotion** sur chaque Q/R rédigée par un vulgarisateur
  ou un scientifique (nom, titre, affiliation, ORCID, bio, liens, photo) —
  cf. §8.6. Met en avant **leur** travail, pas seulement le contenu.
- **Page profil = CV de vulgarisation** : portfolio public des Q/R signées,
  métriques d'impact (vues, Q/R validées, publications citées), liens externes.
- **Signature & crédit systématiques** : auteur principal signataire + **tous**
  les participants listés (rien d'anonyme côté contenu).
- **Page « Déposer ma recherche »** expliquant la démarche libre/open source.
- **Attribution forte des sources** : le chercheur reste cité comme source
  primaire de chaque affirmation vulgarisée (visibilité, ORCID).
- **Statut de contributeur-chercheur** et rattachement au comité de son domaine.
- **Garantie légale** : « nous ne diffusons que des versions légalement OA » —
  argument de confiance central (cf. §3.3).
- **Métriques d'impact** : nombre de Q/R s'appuyant sur leurs travaux, vues,
  portée pédagogique.

---

## 12. Feuille de route (phasage proposé)

| Phase | Objectif | Livrables |
|---|---|---|
| **0. Cadrage** | Valider la spec | Ce document finalisé + ADR |
| **1. Moissonneuse (MVP)** | Ingestion légale + normalisation | Worker harvester, 2-3 sources (OpenAlex+Unpaywall+arXiv), schéma BDD, dédoublonnage |
| **2. IA de tri** | Placement assisté | Embeddings + classif. arbre auto-hébergés |
| **3. Arbre + API + RAG** | Exposer la connaissance | TreeNode, API Symfony, **recherche sémantique (RAG)**, **export bibliographique Zotero (nœud + descendants)** |
| **4. Wiki + rédaction RAG** | Édition + révision | Articles, blocs, versioning, workflow comité/modération, **brouillons IA sourcés (RAG)** |
| **5. Mobile** | Lecture/navigation | App Flutter (lecture d'abord) |
| **6. Communauté** | Réputation, incitation chercheurs | Gouvernance, page dépôt, comités |

> Vous avez indiqué vouloir **commencer par la moissonneuse** : Phase 1 est donc
> le premier chantier de développement.

---

## 13. Décisions prises & questions ouvertes

### Décidé
- **Sources** : toutes référencées dans le registre ; **3 codées en Phase 1**
  (OpenAlex, Unpaywall, arXiv).
- **Sci-Hub** : **pas de connecteur dans la moissonneuse** ; éthos open access
  assumé via le discours et l'invitation au dépôt (cf. §3.3 / §3.4).
- **Taxonomie** : amorcée sur les *concepts OpenAlex*, puis **éditable par le
  comité/référent scientifique** (versionnée).
- **Arbre** : **graphe DAG** (multi-parents, anti-cycle, parent principal pour SEO).
- **Front** : **API Symfony + API Platform durcie** + **front Symfony/Twig** ;
  les **apps Flutter** consomment l'API.
- **Hébergement** : **auto-hébergement** (serveur GPU pour l'IA).
- **Licence contenu** : CC BY-SA 4.0. **Langue** : francophone d'abord.
  **IA** : modèles open source auto-hébergés.

### À trancher au prochain tour
1. **Système de réputation** : modèle Wikipedia (droits par ancienneté/édits) ou
   StackExchange (points/badges) ? Comment recrute-t-on les comités/référents ?
2. **Stockage du full-text** : copie locale (si licence CC le permet) ou
   lien + extraction à la volée ?
3. **Budget & structure porteuse** : association/fondation ? coût du serveur GPU ?
4. **Modération à grande échelle** : outils anti-vandalisme ; qui décide qu'une
   ressource de vulgarisation externe (vidéo, blog) est « sûre » ?
5. **Nom & marque** : « SciencesWiki » est-il le nom retenu ?
```
