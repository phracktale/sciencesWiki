# Spécification fonctionnelle et technique  
## Plateforme de détection d’anomalies et de manipulations dans les images scientifiques

**Version :** 1.1  
**Date :** 12 juillet 2026  
**Statut :** spécification de conception  
**Public cible :** équipe produit, développeurs Python/ML, experts en intégrité scientifique, éditeurs, documentalistes et chercheurs.

---

---

# Introduction

## But de cette spécification

Cette spécification définit les exigences fonctionnelles, techniques, scientifiques et organisationnelles d’une plateforme d’analyse de l’intégrité des images utilisées dans les publications scientifiques.

La plateforme a pour finalité d’aider à détecter, localiser, comparer et documenter des anomalies visuelles susceptibles de correspondre notamment à :

- des duplications exactes ou partielles ;
- des copier-déplacer internes à une image ;
- des réutilisations entre plusieurs figures ou publications ;
- des rotations, miroirs, recadrages ou redimensionnements masquant une réutilisation ;
- des assemblages ou raccords non déclarés ;
- des modifications locales, suppressions, clonages ou inpainting ;
- des traitements de contraste ou de luminosité susceptibles de masquer des données ;
- des incohérences entre une figure publiée et ses données sources.

Le système est conçu comme un **outil d’investigation assistée**. Il ne doit pas conclure automatiquement à une fraude, à une falsification ou à une intention fautive. Il produit des indices techniques reproductibles, localisés, hiérarchisés et explicables, qui doivent ensuite être examinés par un analyste ou un expert scientifique.

Cette spécification sert de référence commune pour :

- la définition du produit ;
- la conception de l’architecture logicielle ;
- le développement des composants Python et d’apprentissage automatique ;
- l’intégration avec une application métier ;
- la constitution des jeux de données ;
- la validation scientifique et statistique des détecteurs ;
- la sécurité, la traçabilité et la gouvernance des analyses ;
- la recette et le déploiement du système.

## Périmètre fonctionnel

Le périmètre couvre l’ensemble de la chaîne d’analyse, depuis l’import d’un document scientifique jusqu’à la production d’un rapport d’expertise.

Il comprend notamment :

1. **L’ingestion documentaire**
   - PDF natifs ou scannés ;
   - images individuelles ;
   - figures multipanneaux ;
   - données sources ;
   - corpus d’articles.

2. **L’extraction et la structuration**
   - extraction des figures et légendes ;
   - segmentation des panneaux ;
   - détection des textes, axes, annotations et barres d’échelle ;
   - classification des types d’images.

3. **Les analyses forensiques générales**
   - doublons exacts ;
   - quasi-doublons ;
   - copier-déplacer ;
   - recherche de réutilisation entre documents ;
   - splicing et montages ;
   - nettoyage et suppression locale ;
   - incohérences de contraste, de bruit ou de compression.

4. **Les analyses spécialisées**
   - western blots et gels ;
   - microscopie ;
   - histologie ;
   - cytométrie/FACS ;
   - photographies expérimentales ;
   - graphiques et courbes ;
   - images médicales, dans un module spécifique.

5. **La comparaison aux données sources**
   - alignement entre figure publiée et image originale ;
   - identification des recadrages, suppressions et transformations ;
   - traçabilité des fichiers sources.

6. **La recherche à l’échelle d’un corpus**
   - indexation perceptuelle ;
   - recherche de similarités ;
   - regroupement de figures apparentées ;
   - graphe de provenance probable.

7. **La validation humaine**
   - revue visuelle ;
   - confirmation ou rejet des alertes ;
   - annotation ;
   - demande de données complémentaires ;
   - documentation des décisions.

8. **La production de livrables**
   - rapport d’analyse ;
   - dossier de preuve ;
   - exports JSON, CSV, HTML et PDF ;
   - journal d’audit.

9. **L’intégration technique**
   - API ;
   - traitement asynchrone ;
   - stockage objet ;
   - index vectoriel ;
   - gestion des utilisateurs, organisations et droits d’accès.

## Limites du périmètre

La plateforme ne couvre pas, à elle seule :

- la qualification juridique ou disciplinaire d’une faute ;
- l’évaluation globale de la qualité méthodologique d’une étude ;
- la vérification complète des analyses statistiques ;
- l’établissement de l’intention d’un auteur ;
- la conduite d’une enquête institutionnelle ;
- le contournement des droits d’accès ou des licences ;
- la preuve d’authenticité d’une image en l’absence de signal détecté.

L’analyse des images doit donc s’intégrer à une démarche plus large comprenant l’étude du protocole, des données sources, des méthodes, des statistiques, des explications des auteurs et de l’impact éventuel sur les conclusions scientifiques.

---

## 1. Résumé exécutif

Le produit décrit ici analyse les figures et les données visuelles issues d’articles scientifiques afin de détecter et documenter des anomalies potentielles : duplication d’images ou de régions, copier-déplacer, réutilisation entre publications, montage de panneaux, découpage ou raccord de gels et western blots, nettoyage local, inpainting, contraste excessif, incohérences de compression ou de bruit, réutilisation de contrôles et discordances entre une figure publiée et ses données sources.

Le système **ne déclare jamais qu’une fraude a eu lieu**. Il produit des indices techniques localisés, reproductibles et hiérarchisés, destinés à une validation humaine. Une anomalie peut provenir d’une manipulation acceptable, d’une erreur honnête, d’un problème de mise en page, d’une conversion PDF ou d’une falsification. Les recommandations STM, COPE et ORI distinguent précisément l’observation d’une anomalie de la conclusion de faute scientifique [R1–R4].

L’architecture doit être « human-in-the-loop » : le calcul automatise la recherche, la comparaison et la documentation, tandis qu’un analyste qualifié interprète le contexte expérimental, la légende, la méthode, les données sources et l’impact scientifique. Cette approche est également celle du système académique SILA/RIVIEW [R5].

Le produit repose sur un **ensemble de détecteurs spécialisés**, et non sur un unique score opaque :

1. extraction des figures et légendes ;
2. segmentation des figures multipanneaux ;
3. classification du type d’image ;
4. détection de doublons exacts et quasi-doublons ;
5. détection de copier-déplacer interne ;
6. recherche de réutilisation entre documents ;
7. détection de raccords, d’inpainting et d’incohérences locales ;
8. analyses spécifiques aux blots/gels, à la microscopie, à l’histologie et aux graphiques ;
9. comparaison avec les données sources lorsqu’elles sont disponibles ;
10. génération d’un dossier de preuve vérifiable.

---

## 2. Objectifs

### 2.1 Objectifs principaux

Le système doit :

- traiter un article PDF, un lot de figures ou un corpus documentaire ;
- extraire les figures, leurs légendes, numéros, pages et relations documentaires ;
- préserver les fichiers originaux et leur empreinte cryptographique ;
- séparer les panneaux composant une figure ;
- masquer les textes, axes, lettres de panneaux, échelles et annotations avant les comparaisons visuelles ;
- détecter les duplications complètes, partielles, transformées, recadrées, retournées, pivotées, redimensionnées ou modifiées en contraste ;
- détecter les régions copiées-collées à l’intérieur d’une même image ;
- détecter les réutilisations entre panneaux, figures, articles et corpus ;
- rechercher les traces de montage, d’insertion, de suppression, de floutage ou d’inpainting ;
- analyser les histogrammes, saturations, contrastes et discontinuités locales ;
- appliquer des analyses propres aux catégories d’images scientifiques ;
- fournir une localisation précise de chaque anomalie ;
- expliquer quel détecteur a produit le résultat, avec quelle version et quels paramètres ;
- permettre la validation, le rejet ou l’annotation d’un signalement par un humain ;
- générer un rapport d’audit reproductible ;
- conserver un historique immuable des analyses et des décisions ;
- fonctionner sur des images publiées de faible résolution tout en exploitant les données sources lorsqu’elles existent.

### 2.2 Objectifs secondaires

- enrichir les documents avec DOI, métadonnées OpenAlex/Crossref et date de publication ;
- construire un index de similarité visuelle à l’échelle d’un corpus ;
- représenter la provenance probable des images sous forme de graphe ;
- proposer une API pour intégration à une plateforme de revue scientifique ;
- permettre un déploiement local, institutionnel ou SaaS ;
- entraîner ou recalibrer les modèles sur des jeux de données spécialisés ;
- mesurer les performances par modalité d’image et non uniquement sur un score global.

---

## 3. Hors périmètre et limites

Le produit ne doit pas :

- conclure automatiquement à une falsification, une fabrication ou une fraude ;
- attribuer une intention à un auteur ;
- publier automatiquement une accusation ;
- remplacer une enquête institutionnelle ;
- prétendre qu’une image est authentique parce qu’aucune anomalie n’a été détectée ;
- contourner un paywall ou les droits d’accès d’un éditeur ;
- considérer une réutilisation comme fautive sans analyser la légende, la licence, le protocole et le contexte ;
- utiliser un modèle génératif pour « reconstruire » des données absentes ;
- modifier le fichier source ;
- produire une « probabilité de fraude » à partir d’un simple score visuel ;
- confondre illustration scientifique, photographie expérimentale, graphique, image médicale clinique et image synthétique.

L’absence de détection n’est pas une preuve d’intégrité. Les images de publication sont souvent redimensionnées, recompressées, aplaties, annotées ou converties, ce qui peut effacer les traces forensiques. SILA souligne qu’aucune technologie actuelle ne peut statuer de manière satisfaisante et entièrement automatisée sur la légitimité d’une modification [R5].

---

## 4. Principes de conception

### 4.1 Présomption de neutralité

Le vocabulaire de l’interface et des rapports doit rester descriptif :

- « similarité inhabituelle » ;
- « région potentiellement dupliquée » ;
- « raccord possible » ;
- « incohérence locale de traitement » ;
- « données sources nécessaires » ;
- « signal à examiner ».

Les termes « fraude », « falsification » ou « fabrication » ne peuvent apparaître que dans une décision humaine documentée, fondée sur un cadre institutionnel approprié.

### 4.2 Explicabilité native

Chaque résultat doit contenir :

- l’image et le panneau concernés ;
- les coordonnées de la ou des régions ;
- une visualisation de superposition ;
- les transformations estimées ;
- le score brut ;
- le score calibré ;
- le seuil utilisé ;
- le détecteur et sa version ;
- les limites connues ;
- le lien vers les éléments comparés ;
- une empreinte des fichiers analysés ;
- la date et l’identité du processus d’analyse.

### 4.3 Détecteurs indépendants

Un score agrégé ne doit jamais masquer les sorties individuelles. Les détecteurs doivent pouvoir se contredire. Une conclusion robuste peut nécessiter plusieurs éléments indépendants : similarité géométrique, concordance locale, incohérence de bruit, rupture de bord et absence de données sources.

### 4.4 Reproductibilité

À version de code, modèle, paramètres et entrée identiques, le résultat doit être reproductible. Toute non-déterminisme GPU doit être désactivé dans les analyses destinées à un dossier de preuve, ou explicitement signalé.

### 4.5 Priorité à la précision opérationnelle

Le système doit limiter les faux positifs coûteux. Il peut conserver un mode « recherche exploratoire » à rappel élevé, mais les alertes prioritaires doivent être calibrées pour une précision élevée et un volume compatible avec la revue humaine.

### 4.6 Validation par modalité

Les performances doivent être mesurées séparément pour :

- western blots et gels ;
- microscopie ;
- histologie ;
- macroscopie ;
- cytométrie en flux sous forme raster ;
- photographies expérimentales ;
- images médicales ;
- graphiques et diagrammes ;
- figures composites ;
- images synthétiques ou schématiques.

BioFors distingue notamment microscopie, blot/gel, FACS et macroscopie, et montre que des algorithmes efficaces sur des photographies ordinaires peuvent être moins robustes sur l’imagerie biomédicale [R6].

---

## 5. Utilisateurs et rôles

### 5.1 Analyste

- lance une analyse ;
- consulte les résultats ;
- ajuste les seuils d’exploration ;
- valide ou rejette un signalement ;
- ajoute une justification ;
- demande les données sources ;
- génère un rapport.

### 5.2 Expert disciplinaire

- interprète la pertinence scientifique ;
- évalue si la modification est acceptable dans la discipline ;
- juge l’impact potentiel sur les conclusions ;
- examine les données sources et les contrôles.

### 5.3 Administrateur

- gère les utilisateurs, rôles, organisations et espaces ;
- configure les politiques de conservation ;
- active les modèles ;
- gère les corpus et les connecteurs ;
- consulte les journaux de sécurité.

### 5.4 Opérateur ML

- publie une nouvelle version de modèle ;
- documente son jeu d’entraînement ;
- exécute les évaluations ;
- surveille les dérives ;
- retire une version défaillante.

### 5.5 Auditeur

- accède en lecture aux preuves, versions, logs et décisions ;
- vérifie la reproductibilité ;
- exporte un dossier signé.

---

## 6. Sources et formats d’entrée

### 6.1 Documents

- PDF natif ;
- PDF scanné ;
- JATS XML avec figures associées ;
- HTML d’article ;
- DOCX, uniquement comme format documentaire secondaire ;
- ZIP de fichiers sources ;
- lot de documents avec manifeste CSV ou JSON.

### 6.2 Images

- TIFF/TIF, y compris multipage ;
- PNG ;
- JPEG/JPG ;
- BMP ;
- WebP ;
- SVG ;
- EPS/PDF vectoriel ;
- DICOM, en module spécialisé ;
- formats propriétaires de microscopie, via Bio-Formats dans une phase ultérieure ;
- fichiers bruts de gels ou microscopes, lorsqu’ils sont disponibles.

### 6.3 Données sources complémentaires

- données brutes ou minimalement traitées ;
- fichiers FCS de cytométrie ;
- images originales de microscope ;
- scans non recadrés de gels et western blots ;
- CSV de quantification ;
- notebooks, scripts et paramètres d’acquisition ;
- légendes et méthodes ;
- dates d’acquisition et identifiants d’échantillons.

Les recommandations STM définissent les données sources comme les données minimalement traitées sous-jacentes à une figure et considèrent leur disponibilité comme un élément central de l’évaluation [R1].

### 6.4 Connecteurs documentaires

Connecteurs facultatifs :

- OpenAlex pour les métadonnées et relations bibliographiques ;
- Crossref pour DOI et métadonnées ;
- Unpaywall pour localiser les versions légalement ouvertes ;
- Europe PMC et PubMed Central ;
- dépôts institutionnels ;
- stockage S3/MinIO ;
- dépôt interne d’un éditeur ou d’une institution.

Aucun connecteur ne doit contourner les contrôles d’accès. Le système ne télécharge que les contenus accessibles légalement au compte ou à l’organisation.

---

## 7. Chaîne de traitement globale

```text
Ingestion
  └── Validation de format
      └── Conservation de l’original + SHA-256
          └── Extraction métadonnées/document
              └── Extraction figures et légendes
                  └── Normalisation non destructive
                      └── Segmentation des panneaux
                          └── Détection/masquage du texte
                              └── Classification de modalité
                                  ├── Doublons exacts
                                  ├── Quasi-doublons
                                  ├── Copier-déplacer interne
                                  ├── Réutilisation entre images
                                  ├── Raccords / splicing
                                  ├── Nettoyage / inpainting
                                  ├── Contraste / histogramme
                                  ├── Bruit / compression
                                  ├── Analyse spécifique blot/gel
                                  ├── Analyse spécifique microscopie
                                  ├── Analyse graphique
                                  └── Comparaison aux données sources
                                      └── Fusion des indices
                                          └── Triage
                                              └── Revue humaine
                                                  └── Rapport et dossier de preuve
```

---

## 8. Ingestion et conservation des preuves

### 8.1 Conservation

Pour chaque fichier :

- conserver l’octet original ;
- calculer SHA-256 ;
- enregistrer taille, MIME réel, nom, date de dépôt et source ;
- interdire toute réécriture silencieuse ;
- stocker les dérivés séparément ;
- appliquer un stockage WORM facultatif pour les dossiers sensibles ;
- signer le manifeste de preuve au moment de l’export.

### 8.2 Validation

- contrôler l’extension et le MIME ;
- analyser les archives contre les chemins relatifs malveillants ;
- limiter taille, nombre de pages et nombre de fichiers ;
- détecter les fichiers chiffrés ou corrompus ;
- isoler l’analyse dans un conteneur sans réseau par défaut ;
- désactiver les macros et contenus actifs ;
- journaliser les erreurs d’extraction.

### 8.3 Normalisation

La normalisation crée une copie de travail :

- orientation correcte ;
- profil de couleur explicite ;
- conversion contrôlée vers sRGB ou niveaux de gris selon le détecteur ;
- profondeur conservée si possible ;
- aucune accentuation, réduction de bruit ou rééchantillonnage irréversible avant conservation de la version originale ;
- génération de pyramides d’image pour les très grands fichiers.

Chaque dérivé doit mémoriser la transformation exacte appliquée.

---

## 9. Extraction des figures et légendes

### 9.1 Stratégie multi-moteur

Le moteur doit tenter successivement :

1. extraction directe des objets image embarqués dans le PDF ;
2. extraction structurée des figures, tableaux et légendes ;
3. reconstruction des figures composées de plusieurs objets vectoriels ou bitmap ;
4. rendu de page à haute résolution et détection de zones ;
5. traitement OCR pour les PDF scannés.

PDFFigures 2.0 fournit un socle reconnu pour extraire figures, tableaux, légendes et titres de section dans des PDF scientifiques [R7]. DeepFigures constitue une autre approche, fondée sur la détection par réseau neuronal, mais son propre dépôt le décrit comme du code de recherche plutôt que comme un composant de production [R8].

### 9.2 Métadonnées de figure

Pour chaque figure :

- identifiant interne ;
- document et page ;
- numéro de figure ;
- légende brute ;
- légende nettoyée ;
- boîte englobante dans la page ;
- résolution extraite et résolution rendue ;
- méthode d’extraction ;
- confiance ;
- liste de panneaux ;
- liens vers la méthode, les résultats et le texte citant la figure ;
- empreinte perceptuelle et cryptographique.

### 9.3 Détection d’échec

Une extraction est suspecte si :

- la légende est trouvée sans figure ;
- la figure occupe une fraction anormale de la page ;
- plusieurs figures se chevauchent ;
- le nombre de figures diffère fortement des références textuelles ;
- une image embarquée est très petite mais affichée très grande ;
- le rendu et l’objet PDF diffèrent fortement.

### 9.4 Critères d’acceptation

Sur un corpus interne représentatif :

- rappel d’extraction des figures natives : objectif ≥ 95 % ;
- précision d’association figure-légende : objectif ≥ 97 % ;
- rappel sur PDF scannés : objectif ≥ 85 % ;
- 100 % des échecs doivent être signalés, jamais ignorés silencieusement.

Ces valeurs sont des objectifs produit à valider sur le corpus réel, non des performances garanties par les outils académiques existants.

---

## 10. Segmentation des figures multipanneaux

### 10.1 Besoin

Les figures scientifiques regroupent souvent plusieurs panneaux. Une comparaison globale provoquerait des faux positifs dus aux lettres, axes, légendes, barres d’échelle et répétitions de mise en page. SILA segmente les panneaux et retire les textes avant la détection de copier-déplacer [R5].

### 10.2 Approche hybride

Le système combine :

- détection de séparateurs horizontaux/verticaux ;
- analyse des espaces vides ;
- composantes connexes ;
- détection des étiquettes `(a)`, `(b)`, `A`, `B`, etc. ;
- modèle de détection d’objets pour les panneaux ;
- correction par contraintes de mise en page ;
- édition manuelle.

### 10.3 Sortie

Chaque panneau contient :

- coordonnées dans la figure ;
- label ;
- image originale du panneau ;
- masque de texte ;
- masque d’axes et annotations ;
- classe d’image ;
- score de segmentation ;
- éventuelles relations avec la légende.

### 10.4 Gestion des cas ambigus

- panneaux sans séparation visible ;
- encarts superposés ;
- zooms d’une zone ;
- légendes intégrées à l’image ;
- images irrégulières ;
- panneaux partageant un axe ;
- montages de bandes de gel ;
- figure entièrement vectorielle.

L’analyste doit pouvoir fusionner, diviser ou redessiner un panneau sans perdre la trace de la segmentation automatique initiale.

---

## 11. Détection et masquage du texte

Le texte, les flèches et les échelles génèrent des correspondances artificielles. Le système doit :

- détecter les zones textuelles ;
- distinguer texte scientifique, axe, label de panneau, annotation et watermark ;
- créer un masque sans détruire l’image originale ;
- fournir aux détecteurs soit l’image masquée, soit une représentation inpaintée uniquement pour la recherche de similarité ;
- conserver le masque exact utilisé.

Le contenu masqué ne doit jamais être présenté comme une restauration de données. Il sert uniquement à empêcher les détecteurs de comparer les annotations.

---

## 12. Classification du type d’image

### 12.1 Classes minimales

- blot/gel ;
- microscopie fluorescence ;
- microscopie champ clair ;
- histologie ;
- macroscopie ;
- cytométrie/FACS raster ;
- photographie expérimentale ;
- imagerie médicale ;
- graphique quantitatif ;
- diagramme/schéma ;
- tableau ;
- carte ;
- figure composite mixte ;
- inconnue.

### 12.2 Méthode

Combiner :

- classifieur visuel ;
- mots de la légende ;
- OCR ;
- contexte de la méthode ;
- règles explicites.

Le classifieur doit retourner plusieurs classes avec probabilités calibrées. Une figure mixte peut déclencher plusieurs pipelines.

### 12.3 Règle de sécurité

Un détecteur spécifique ne doit pas être appliqué comme preuve forte à une modalité hors domaine. Exemple : une analyse PRNU sur une capture de graphique ou une figure recompressée ne doit pas produire une alerte prioritaire.

---

## 13. Détection de doublons exacts

### 13.1 Niveaux

1. **Binaire exact** : SHA-256 identique.
2. **Pixels exacts** : mêmes pixels après décodage.
3. **Canonique exact** : identique après suppression de métadonnées, conversion de profil ou orientation.
4. **Transformation discrète** : identique après rotation de 90°, miroir ou inversion.
5. **Quasi-exact** : différence faible due à une compression ou une variation globale de luminosité.

### 13.2 Empreintes

- hash cryptographique ;
- pHash ;
- dHash ;
- wHash ;
- histogrammes normalisés ;
- miniatures multi-échelles ;
- hashes calculés pour rotations et miroirs.

### 13.3 Faux positifs à filtrer

- logos ;
- barres d’échelle ;
- cadres ;
- icônes ;
- panneaux de contrôle explicitement réutilisés ;
- fonds blancs ;
- schémas standards ;
- éléments graphiques de l’éditeur.

---

## 14. Recherche de quasi-doublons et réutilisations entre images

### 14.1 Index global

Chaque panneau alimente :

- un index d’empreintes perceptuelles ;
- un index de descripteurs locaux ;
- un index vectoriel d’embeddings visuels ;
- un index de métadonnées documentaires.

### 14.2 Recherche en deux étages

**Étape 1 — rappel élevé**

- pHash et variantes ;
- embedding global ;
- histogramme ;
- couleur/texture ;
- recherche approximate nearest neighbor.

**Étape 2 — vérification géométrique**

- SIFT, AKAZE ou équivalent ;
- appariement des descripteurs ;
- test du ratio ;
- RANSAC ;
- estimation d’homographie ou transformation affine ;
- mesure de la surface concordante ;
- vérification bidirectionnelle ;
- localisation source/cible.

### 14.3 Transformations à prendre en charge

- recadrage ;
- translation ;
- rotation arbitraire ;
- miroir ;
- redimensionnement ;
- changement de contraste ;
- gamma ;
- inversion noir/blanc ;
- légère déformation ;
- flou ;
- bruit ;
- compression JPEG ;
- ajout d’annotations ;
- suppression partielle.

### 14.4 Provenance

Pour un groupe d’images liées :

- utiliser les dates de publication ;
- calculer les relations de contenu partagé ;
- construire un graphe orienté probable ;
- indiquer les transformations estimées ;
- distinguer « antériorité documentaire » de « source réelle » ;
- ne jamais prétendre qu’une date de publication prouve la direction de copie.

SILA représente ces relations sous forme de graphes de provenance, avec des nœuds image et des arêtes correspondant à des histoires probables de transformation et de réutilisation [R5].

---

## 15. Détection de copier-déplacer interne

### 15.1 Définition opérationnelle

Une opération de copier-déplacer réutilise une région d’une image dans une autre zone de la même image, avec ou sans rotation, miroir, redimensionnement, contraste, flou ou recouvrement.

### 15.2 Moteurs complémentaires

#### Moteur A — dense, basé sur les patches

- extraction dense de patches multi-échelles ;
- caractéristiques RGB, niveaux de gris, gradients et moments invariants ;
- recherche de champs de plus proches voisins ;
- contrainte de distance minimale entre source et cible ;
- correspondance bidirectionnelle ;
- regroupement géométrique ;
- morphologie et composantes connexes.

SILA utilise une approche dense inspirée de PatchMatch, avec fusion de caractéristiques Zernike et RGB et vérification bidirectionnelle pour réduire les faux appariements [R5].

#### Moteur B — points d’intérêt

- SIFT/AKAZE ;
- descripteurs invariants ;
- appariement ;
- clustering spatial ;
- RANSAC ;
- détection de groupes cohérents.

Ce moteur est utile lorsque les régions contiennent des structures distinctives, mais moins efficace sur les fonds uniformes ou les blots très simples.

#### Moteur C — réseau spécialisé

- modèle de segmentation entraîné sur imagerie scientifique ;
- sortie pixel à pixel ;
- prise en charge de plusieurs échelles ;
- seuil calibré par catégorie.

BioFors et MONet constituent des bases pertinentes pour l’entraînement et l’évaluation de modèles spécialisés en duplication biomédicale [R6, R9].

### 15.3 Fusion

Un signal fort de copier-déplacer requiert idéalement :

- deux régions localisées ;
- un nombre suffisant de correspondances ;
- une transformation cohérente ;
- une surface minimale ;
- une concordance dans les deux directions ;
- une faible probabilité que le motif soit structurellement répétitif ;
- une confirmation par au moins deux familles de caractéristiques, ou un modèle spécialisé validé.

### 15.4 Répétitions naturelles

Les tissus, cellules, bandes, cristaux, motifs périodiques et arrière-plans peuvent être naturellement répétitifs. Le système doit :

- estimer le caractère répétitif global ;
- diminuer la priorité si de nombreuses régions similaires existent ;
- exclure les textures trop uniformes ;
- comparer la géométrie fine et le bruit local ;
- afficher les régions sources et cibles côte à côte.

---

## 16. Détection de splicing, raccords et montages

### 16.1 Types

- assemblage de plusieurs images ;
- collage d’un objet provenant d’une autre image ;
- fusion de pistes de gel ;
- remplacement d’un panneau ;
- insertion d’une zone ;
- superposition partielle ;
- raccord non déclaré.

### 16.2 Indices

- rupture de continuité de bord ;
- discontinuité d’arrière-plan ;
- différence locale de bruit ;
- grille JPEG incohérente ;
- différence de netteté ;
- différence de profil colorimétrique ;
- incohérence de compression ;
- transition rectiligne anormale ;
- ombre ou halo ;
- répétition locale ;
- mismatch géométrique.

### 16.3 Valeur probante

Les analyses de bruit, compression et ELA sont des **indices faibles à modérés**, en particulier sur les figures extraites d’un PDF. Elles ne doivent jamais constituer seules une alerte critique.

Une alerte forte nécessite une convergence : raccord visible, contenu partagé, données sources incompatibles, incohérence de légende ou montage non déclaré.

---

## 17. Détection de nettoyage, suppression et inpainting

### 17.1 Cas visés

- suppression d’un objet ;
- clonage d’un fond pour masquer un signal ;
- floutage local ;
- inpainting ;
- effacement d’une bande ;
- lissage local ;
- remplacement par texture synthétique.

### 17.2 Détecteurs

- auto-similarité de fond ;
- anomalies de texture ;
- incohérence de bruit résiduel ;
- différence de fréquence ;
- traces de répétition ;
- frontières de masque ;
- modèle spécialisé d’inpainting ;
- comparaison avec données sources.

### 17.3 Limites

Une image de publication recompressée peut effacer ces traces. La localisation doit être présentée avec un niveau de confiance et le résultat ne doit pas être formulé comme une suppression certaine sans données sources.

---

## 18. Analyse du contraste, de la luminosité et des histogrammes

### 18.1 Mesures

- proportion de pixels saturés en noir ou blanc ;
- histogramme global et local ;
- clipping ;
- gamma probable ;
- plage dynamique ;
- contraste local ;
- égalisation d’histogramme ;
- différences de traitement entre panneaux ;
- différence entre contrôle et expérimental.

### 18.2 Règles

Un ajustement global et uniforme peut être acceptable s’il ne masque pas de données. Les recommandations STM et Nature insistent sur le fait que le traitement doit être appliqué à l’ensemble de l’image, ne pas obscurcir l’information et être décrit lorsqu’il est substantiel [R1, R10].

### 18.3 Sortie

- histogramme ;
- zones saturées ;
- comparaison avant/après lorsque la source est disponible ;
- estimation des transformations ;
- avertissement si l’image publiée ne permet pas une analyse quantitative fiable.

---

## 19. Analyses spécifiques aux western blots et gels

### 19.1 Prétraitement

- détection du panneau blot/gel ;
- conversion en niveaux de gris ;
- correction d’orientation ;
- détection du rectangle du gel ;
- suppression des labels ;
- estimation du fond ;
- segmentation des pistes ;
- segmentation des bandes ;
- détection de bordures et raccords.

### 19.2 Détections

- duplication de piste complète ;
- duplication de bande ;
- miroir ou inversion ;
- réutilisation d’un contrôle ;
- raccord vertical entre pistes ;
- raccord horizontal ;
- différences de fond entre pistes ;
- espacement anormal ;
- contours rectangulaires ;
- contraste local non uniforme ;
- répétition entre figures ou articles ;
- control lane issue ;
- absence de membrane complète dans les données sources.

### 19.3 Comparaison de pistes

Pour chaque piste :

- profil d’intensité vertical ;
- positions et largeurs des bandes ;
- texture du fond ;
- descripteurs locaux ;
- corrélation après alignement ;
- transformation estimée ;
- score de similarité.

### 19.4 Précautions

Deux bandes biologiques peuvent se ressembler. Une duplication ne doit pas être conclue sur la seule forme d’une bande. La concordance doit inclure les irrégularités du fond, les bords et plusieurs caractéristiques.

### 19.5 Données sources

Lorsque des scans non recadrés sont disponibles :

- aligner la figure publiée sur la source ;
- vérifier la correspondance de chaque piste ;
- repérer les pistes omises ;
- documenter les coupes ;
- vérifier la présence de marqueurs et bordures ;
- comparer le traitement de toutes les conditions.

---

## 20. Analyses spécifiques à la microscopie et à l’histologie

### 20.1 Détections

- duplication de région ;
- réutilisation de champ ;
- recadrage d’une même image présenté comme expérience différente ;
- miroir/rotation ;
- clonage d’objets ou cellules ;
- répétition de groupes cellulaires ;
- mosaïque ou tiling ;
- nettoyage du fond ;
- suppression locale ;
- superposition d’un canal ;
- incohérence entre canaux ;
- duplication entre temps, conditions ou marqueurs.

### 20.2 Multi-canaux

- vérifier dimensions et alignement ;
- comparer les canaux individuels et la fusion ;
- détecter les canaux identiques ou quasi-identiques ;
- contrôler les translations systématiques ;
- vérifier les LUT et saturations ;
- comparer aux fichiers bruts.

### 20.3 Objets biologiques répétitifs

La détection d’instances doit être prudente :

- segmenter les cellules/objets ;
- calculer un embedding local ;
- rechercher des paires très similaires ;
- confirmer par la texture interne et le fond ;
- estimer la probabilité de similitude naturelle ;
- ne pas remonter des objets très petits ou peu informatifs comme preuve forte.

### 20.4 Échelles

- détecter et masquer les barres d’échelle avant comparaison ;
- vérifier leur cohérence avec la résolution source si connue ;
- signaler une barre identique copiée seulement comme élément d’annotation, pas comme duplication de données.

---

## 21. Cytométrie/FACS sous forme d’image

### 21.1 Détections raster

- réutilisation du même nuage de points ;
- recadrage ;
- modification des axes ;
- miroir ;
- duplication d’un panneau ;
- réutilisation de gates ;
- superposition de labels.

### 21.2 Limites

Une image raster ne suffit pas pour vérifier la distribution événementielle ou le gating. Le système doit recommander le fichier FCS lorsqu’une conclusion dépend de la distribution.

### 21.3 Avec fichier FCS

- vérifier le nombre d’événements ;
- reconstruire les graphes ;
- comparer les gates ;
- vérifier les transformations ;
- comparer la figure reconstruite à la publication ;
- documenter les écarts.

---

## 22. Graphiques, courbes et diagrammes

### 22.1 Séparation de domaine

Les graphiques ne doivent pas être évalués uniquement par des détecteurs photographiques.

### 22.2 Détections

- duplication de graphique ;
- courbes identiques sous étiquettes différentes ;
- points copiés ;
- panneaux recadrés ou recolorés ;
- axes tronqués ou incohérents ;
- barres d’erreur dupliquées ;
- mismatch entre valeurs affichées et tableau source ;
- montage non déclaré.

### 22.3 Numérisation

- détecter axes, graduations, légende et séries ;
- vectoriser les courbes lorsque possible ;
- extraire les points avec incertitude ;
- comparer aux données tabulaires ;
- ne jamais présenter des valeurs numérisées comme exactes sans intervalle d’erreur.

### 22.4 Module statistique ultérieur

Un module séparé peut contrôler :

- cohérence moyenne/écart-type ;
- tailles d’échantillon ;
- barres d’erreur ;
- distributions impossibles ;
- incohérences entre texte, tableau et figure.

Ce module sort du cœur « image forensics » et doit être versionné séparément.

---

## 23. Images médicales et données cliniques

### 23.1 Précautions

- dé-identification ;
- suppression des informations patient visibles ;
- chiffrement ;
- accès minimal ;
- conservation configurable ;
- journal d’accès ;
- traitement local possible ;
- séparation stricte entre corpus public et données cliniques privées.

### 23.2 DICOM

En module spécialisé :

- préserver le DICOM original ;
- analyser les tags ;
- détecter les séries ;
- comparer les pixels avant fenêtrage ;
- conserver le window/level ;
- distinguer export clinique et capture d’écran ;
- ne pas utiliser les métadonnées seules comme preuve d’intégrité.

---

## 24. Détection d’images générées par IA

Cette fonction doit être expérimentale et séparée.

Exigences :

- ne jamais conclure sur la base d’un unique détecteur ;
- conserver la version exacte du modèle ;
- afficher le taux de faux positifs connu ;
- privilégier la provenance, les fichiers sources et les traces de génération déclarées ;
- distinguer illustration générée, image expérimentale synthétique et figure mise en forme avec assistance IA ;
- ne pas mélanger ce score avec le score de duplication.

---

## 25. Comparaison aux données sources

### 25.1 Alignement source-publication

- retrouver la source correspondante ;
- estimer crop, rotation, échelle, gamma et contraste ;
- superposer ;
- calculer une carte de différence ;
- identifier les régions ajoutées, retirées ou altérées ;
- vérifier si la transformation est globale ou locale.

### 25.2 Provenance des sources

Chaque source doit avoir :

- propriétaire ;
- date de dépôt ;
- hash ;
- format ;
- chaîne de transformation déclarée ;
- liens vers figure/panneau ;
- statut d’authentification ;
- commentaire de l’auteur ou de l’éditeur.

### 25.3 Cas de source absente

Le rapport doit préciser :

- « données sources non fournies » ;
- « analyse limitée aux pixels de publication » ;
- « impossibilité de conclure sur la nature de l’anomalie ».

---

## 26. Modèle de résultat d’un détecteur

```json
{
  "finding_id": "fnd_01J...",
  "analysis_run_id": "run_01J...",
  "document_id": "doc_01J...",
  "figure_id": "fig_01J...",
  "panel_id": "pnl_01J...",
  "detector": {
    "name": "copy_move_dense",
    "version": "1.3.0",
    "model_digest": "sha256:...",
    "config_digest": "sha256:..."
  },
  "anomaly_type": "internal_duplication",
  "evidence_level": "E3",
  "triage_level": "T2",
  "raw_score": 0.914,
  "calibrated_score": 0.842,
  "score_meaning": "probability that the detector output corresponds to a valid localized duplication under the validation distribution; not probability of fraud",
  "source_region": {
    "x": 143,
    "y": 88,
    "width": 122,
    "height": 94
  },
  "target_region": {
    "x": 402,
    "y": 105,
    "width": 119,
    "height": 91
  },
  "estimated_transform": {
    "type": "affine",
    "rotation_deg": 179.2,
    "scale": 1.01,
    "mirror": false
  },
  "supporting_artifacts": [
    "overlay.png",
    "match_lines.png",
    "source_crop.png",
    "target_crop.png",
    "mask.png"
  ],
  "limitations": [
    "repetitive biological texture",
    "source image compressed in PDF"
  ],
  "created_at": "2026-07-11T15:00:00Z"
}
```

---

## 27. Niveaux de preuve techniques

Ces niveaux sont internes au produit et ne remplacent pas la classification STM.

### E0 — non exploitable

- analyse impossible ;
- image trop petite ;
- format corrompu ;
- aucune conclusion.

### E1 — indice faible

- anomalie statistique non localisée ;
- histogramme atypique ;
- incohérence de compression isolée ;
- similarité faible.

### E2 — indice reproductible

- similarité localisée ;
- correspondances géométriques partielles ;
- raccord possible ;
- résultat stable à plusieurs paramètres.

### E3 — indice fort

- régions source/cible localisées ;
- transformation cohérente ;
- confirmation par plusieurs détecteurs ;
- faible probabilité de répétition naturelle.

### E4 — indice corroboré

- E3 plus comparaison aux données sources ;
- ou duplication exacte contextualisée comme données différentes ;
- ou plusieurs éléments indépendants convergents.

Même E4 n’est pas une conclusion de fraude. Il justifie une revue d’intégrité prioritaire.

---

## 28. Niveaux de triage opérationnels

### T0 — aucun signal prioritaire

Résultat conservé, pas de revue requise.

### T1 — revue facultative

Indice faible ou contexte probablement bénin.

### T2 — revue recommandée

Anomalie localisée, duplication ou raccord plausible.

### T3 — revue prioritaire

Plusieurs indices forts, données sources incohérentes ou réutilisation importante susceptible d’affecter l’interprétation.

La classification finale STM I/II/III, lorsqu’elle est utilisée par une organisation, doit être saisie par un expert après analyse du contexte, des explications et de l’impact sur les conclusions [R1].

---

## 29. Fusion des indices

### 29.1 Interdiction d’un « fraud score »

Le système peut produire :

- un score de similarité ;
- un score de qualité de localisation ;
- un score de confiance du détecteur ;
- un niveau de corroboration ;
- une priorité de revue.

Il ne doit pas produire un score nommé « probabilité de fraude ».

### 29.2 Fusion recommandée

Utiliser une combinaison explicable :

```text
priorité =
  qualité_localisation
  × robustesse_transformation
  × calibration_modalité
  × corroboration_inter-détecteurs
  × importance_contextuelle
  × disponibilité_des_sources
```

Chaque composant est visible. Le produit peut utiliser un modèle de ranking, mais celui-ci doit être monotone, auditable et calibré.

### 29.3 Contexte

L’importance contextuelle peut prendre en compte :

- panneau central ou secondaire ;
- rôle de contrôle ;
- mention dans le titre/abstract ;
- conclusion appuyée par la figure ;
- existence d’autres preuves indépendantes ;
- déclaration de réutilisation ;
- licence et citation.

L’extraction de ce contexte par LLM est autorisée uniquement comme aide, avec citations textuelles, et doit être validée par un humain.

---

## 30. Interface de revue

### 30.1 Écran document

- miniature des pages ;
- liste des figures ;
- état d’extraction ;
- alertes par niveau ;
- métadonnées ;
- liens vers le texte et la légende.

### 30.2 Écran comparaison

- images côte à côte ;
- zoom synchronisé ;
- miroir/rotation interactifs ;
- superposition avec opacité ;
- différence absolue ;
- lignes de correspondance ;
- masque de régions ;
- histogrammes ;
- données sources ;
- paramètres du détecteur.

### 30.3 Décision humaine

Actions :

- confirmer comme anomalie technique ;
- rejeter comme faux positif ;
- classer comme acceptable/déclaré ;
- demander des données sources ;
- marquer « indéterminé » ;
- demander expertise disciplinaire ;
- associer à un autre finding ;
- ajouter commentaire et pièce jointe.

La décision doit comporter :

- auteur ;
- date ;
- justification ;
- niveau de confiance ;
- impact potentiel ;
- références consultées ;
- historique des modifications.

---

## 31. Rapport d’analyse

### 31.1 Contenu

- identification du document ;
- origine et hash ;
- version du pipeline ;
- couverture de l’analyse ;
- échecs et limites ;
- figures extraites ;
- findings triés ;
- visualisations ;
- sorties de chaque détecteur ;
- décisions humaines ;
- données sources reçues ;
- conclusion descriptive ;
- annexes techniques ;
- manifeste de preuve.

### 31.2 Formulation

Le rapport doit éviter les formulations accusatoires. Exemple :

> « Les panneaux 3B et 5D présentent une similarité localisée compatible avec une réutilisation après rotation de 180°. L’algorithme identifie 42 correspondances géométriquement cohérentes couvrant 31 % du panneau. Les légendes décrivent des conditions expérimentales différentes. La vérification des données sources est recommandée. »

### 31.3 Export

- PDF ;
- HTML autonome ;
- JSON ;
- ZIP de preuve ;
- CSV des findings ;
- graphe de provenance GraphML/JSON.

---

## 32. API applicative

### 32.1 Endpoints principaux

```text
POST   /v1/documents
GET    /v1/documents/{id}
POST   /v1/documents/{id}/sources
POST   /v1/analyses
GET    /v1/analyses/{id}
POST   /v1/analyses/{id}/cancel
GET    /v1/analyses/{id}/findings
GET    /v1/findings/{id}
POST   /v1/findings/{id}/reviews
POST   /v1/corpus/search
GET    /v1/provenance/{cluster_id}
POST   /v1/reports
GET    /v1/reports/{id}
GET    /v1/models
POST   /v1/models/{id}/activate
```

### 32.2 Création d’analyse

```json
{
  "document_ids": ["doc_01J..."],
  "profile": "biomedical_full",
  "detectors": [
    "exact_duplicate",
    "near_duplicate",
    "copy_move_dense",
    "copy_move_keypoint",
    "splice",
    "contrast",
    "blot"
  ],
  "corpus_scope": "organization",
  "priority": "normal",
  "deterministic": true
}
```

### 32.3 Webhooks

Événements :

- `analysis.started`;
- `analysis.progress`;
- `analysis.completed`;
- `analysis.failed`;
- `finding.created`;
- `review.requested`;
- `report.ready`.

Tous les webhooks doivent être signés.

---

## 33. Modèle de données

### Document

- id ;
- DOI ;
- titre ;
- auteurs ;
- date ;
- revue ;
- source ;
- droits ;
- hash ;
- statut.

### Asset

- id ;
- document_id ;
- type ;
- original_path ;
- derived_path ;
- MIME ;
- dimensions ;
- hash ;
- provenance.

### Figure

- id ;
- document_id ;
- page ;
- label ;
- caption ;
- bounding_box ;
- extraction_method ;
- confidence.

### Panel

- id ;
- figure_id ;
- label ;
- bounding_box ;
- modality ;
- modality_scores ;
- text_mask.

### AnalysisRun

- id ;
- profile ;
- status ;
- code_version ;
- config_digest ;
- started_at ;
- ended_at ;
- hardware ;
- seed.

### DetectorRun

- id ;
- analysis_run_id ;
- detector ;
- version ;
- parameters ;
- metrics ;
- logs.

### Finding

- id ;
- detector_run_id ;
- type ;
- severity ;
- evidence_level ;
- geometry ;
- score ;
- artifacts ;
- limitations.

### ReviewDecision

- id ;
- finding_id ;
- reviewer ;
- status ;
- rationale ;
- impact ;
- created_at ;
- supersedes_id.

### ProvenanceEdge

- source_panel_id ;
- target_panel_id ;
- transformation ;
- score ;
- date_constraint ;
- reviewer_status.

---

## 34. Architecture technique recommandée

### 34.1 Composants

**Application métier**

- Symfony 7 ou application web équivalente ;
- authentification, organisations, dossiers, workflow, rapports ;
- interface de revue.

**API de calcul**

- Python ;
- FastAPI ;
- Pydantic ;
- API interne versionnée.

**Workers**

- Celery, Dramatiq ou RQ ;
- files séparées CPU, GPU, OCR et export ;
- annulation coopérative ;
- reprise après échec.

**Stockage**

- PostgreSQL ;
- pgvector ou moteur vectoriel dédié ;
- Redis pour files/cache ;
- stockage objet S3/MinIO ;
- index FAISS local pour prototypes.

**Calcul**

- OpenCV ;
- scikit-image ;
- NumPy/SciPy ;
- PyTorch ;
- ONNX Runtime pour inférence portable ;
- OCR spécialisé ;
- outils PDF isolés.

**Observabilité**

- OpenTelemetry ;
- métriques Prometheus ;
- traces ;
- logs structurés ;
- corrélation par `analysis_run_id`.

### 34.2 Déploiement

**Développement**

- Docker Compose ;
- CPU par défaut ;
- profil GPU facultatif ;
- jeux de tests réduits.

**Production institutionnelle**

- Kubernetes ou orchestration équivalente ;
- nœuds CPU/GPU séparés ;
- autoscaling par file ;
- stockage chiffré ;
- sauvegardes ;
- politiques de rétention.

### 34.3 Isolement

Chaque analyse s’exécute dans un environnement :

- sans accès réseau sauf connecteurs autorisés ;
- en lecture seule sur l’original ;
- avec limites CPU, RAM, GPU et temps ;
- avec filesystem temporaire ;
- détruit après traitement.

---

## 35. Stratégie logiciel libre et réutilisation

### 35.1 Composants académiques à examiner

- SILA/RIVIEW : pipeline de recherche, modules d’extraction, ranking, segmentation, copy-move et provenance [R5, R11] ;
- BioFors et MONet : données et modèle spécialisé [R6, R9, R12] ;
- RSIIL/RSIID : génération synthétique de duplications, nettoyage et retouche, avec figures simples et composites [R13, R14] ;
- PDFFigures 2.0 : extraction PDF [R7] ;
- ELIS : intégration open source plus récente de plusieurs modules de forensic scientifique [R15].

### 35.2 Règle de réutilisation

Avant intégration :

- audit de licence ;
- audit de sécurité ;
- audit de maintenance ;
- test sur le corpus réel ;
- vérification des dépendances ;
- encapsulation dans une interface interne ;
- possibilité de remplacement.

ELIS est publié sous AGPLv3 et indique que ses modules peuvent avoir des licences différentes ou des restrictions propres ; une analyse juridique est donc nécessaire avant réutilisation dans un service commercial [R15].

### 35.3 Positionnement recommandé

Utiliser les projets académiques comme :

- références algorithmiques ;
- baselines ;
- sources de jeux de données ;
- prototypes de comparaison.

Ne pas les considérer automatiquement comme des briques de production.

---

## 36. Entraînement et jeux de données

### 36.1 Jeux de données principaux

**BioFors**

- 47 805 images ;
- 1 031 articles ouverts ;
- catégories microscopie, blot/gel, FACS et macroscopie ;
- tâches de duplication externe, duplication interne et transition nette [R6].

**RSIID**

- figures scientifiques simples et composites ;
- duplication, splicing, overlap, nettoyage, inpainting, floutage et contraste ;
- 39 423 figures synthétiquement altérées à partir de 2 923 images sources [R13].

**SILA/SP Dataset**

- articles et annotations issues de cas documentés ;
- masques de copier-déplacer ;
- graphes de provenance ;
- utile pour l’évaluation réaliste et les cas de faible résolution [R5].

### 36.2 Jeu interne

Créer un corpus interne avec :

- vrais négatifs issus de plusieurs disciplines ;
- altérations synthétiques contrôlées ;
- cas bénins déclarés ;
- cas confirmés publiquement ;
- images de faible et haute résolution ;
- conversions par plusieurs éditeurs ;
- figures composites ;
- données sources.

### 36.3 Séparation des données

La séparation train/validation/test doit se faire :

- par article ;
- par groupe d’auteurs ;
- par image source ;
- par revue si possible ;
- par famille de transformations.

Aucun panneau dérivé d’une même image source ne doit apparaître dans train et test.

### 36.4 Augmentations

- JPEG ;
- redimensionnement ;
- gamma ;
- bruit ;
- flou ;
- rotation ;
- miroir ;
- recadrage ;
- ajout de texte ;
- changement de palette ;
- capture d’écran ;
- export PDF ;
- recompression multiple.

Les augmentations doivent reproduire les transformations éditoriales et les manipulations visées, sans rendre le jeu artificiellement facile.

---

## 37. Mesures de performance

### 37.1 Détection image

- précision ;
- rappel ;
- F1 ;
- MCC ;
- AUROC ;
- AUPRC ;
- taux de faux positifs par image ;
- calibration ECE/Brier.

### 37.2 Localisation

- IoU ;
- Dice/F1 pixel ;
- précision/rappel pixel ;
- distance de contour ;
- couverture source/cible.

### 37.3 Recherche de doublons

- Recall@K ;
- Precision@K ;
- mAP ;
- taux de cluster correct ;
- latence ;
- nombre de candidats par requête.

### 37.4 Extraction

- rappel des figures ;
- précision des légendes ;
- IoU des panneaux ;
- taux d’échec documentaire ;
- taux de correction manuelle.

### 37.5 Opérationnel

- findings T2/T3 par 100 figures ;
- temps humain par article ;
- proportion de faux positifs confirmés ;
- proportion de findings non examinables ;
- temps de traitement ;
- coût CPU/GPU ;
- stabilité inter-version.

### 37.6 Validation humaine

- accord inter-évaluateurs ;
- temps moyen de décision ;
- taux d’escalade ;
- impact du système sur la détection ;
- analyse des biais par discipline, revue, résolution et provenance.

---

## 38. Seuils de lancement recommandés

Ces seuils doivent être validés sur le corpus cible.

### Doublon exact/quasi-exact

- précision ≥ 99 % pour T2 ;
- Recall@10 ≥ 95 % sur transformations prises en charge.

### Réutilisation partielle

- précision ≥ 90 % pour T2 ;
- correspondance géométrique localisée ;
- surface minimale paramétrable ;
- faux positifs ≤ 2 par 100 panneaux au seuil prioritaire.

### Copier-déplacer

- précision image ≥ 85 % au seuil T2 ;
- localisation exploitable sur ≥ 80 % des vrais positifs ;
- performances publiées séparément par modalité.

### Splicing/inpainting

- aucune alerte T3 basée sur un détecteur unique ;
- au moins deux indices indépendants ou une confirmation par source.

### Charge de revue

- ≤ 10 findings T1/T2 par article médian ;
- ≤ 3 findings T2/T3 par article médian ;
- possibilité d’un profil exploratoire plus bavard.

---

## 39. Plan de tests

### 39.1 Tests unitaires

- hashes ;
- transformations ;
- coordonnées ;
- masques ;
- conversions colorimétriques ;
- sérialisation ;
- calculs de score ;
- reproductibilité.

### 39.2 Tests d’intégration

- PDF → figure → panneau → détecteurs → rapport ;
- échec d’un moteur avec fallback ;
- reprise de job ;
- annulation ;
- corpus vectoriel ;
- stockage ;
- webhooks.

### 39.3 Tests de robustesse

Pour chaque manipulation :

- JPEG 50–100 ;
- redimensionnement 0,5× à 2× ;
- rotation ±180° ;
- miroir ;
- bruit ;
- gamma ;
- flou ;
- annotations ;
- recadrage ;
- recompression PDF ;
- faible résolution.

### 39.4 Tests négatifs

- tissus répétitifs ;
- cellules similaires ;
- bandes biologiquement semblables ;
- graphiques à style identique ;
- contrôles légitimement réutilisés ;
- logos et templates ;
- axes ;
- barres d’échelle ;
- images synthétiques déclarées.

### 39.5 Tests de sécurité

- PDF malformé ;
- zip bomb ;
- path traversal ;
- image décompressée gigantesque ;
- code embarqué ;
- timeout ;
- épuisement mémoire ;
- accès croisé entre organisations ;
- falsification de webhook ;
- export contenant des données non autorisées.

### 39.6 Tests de non-régression ML

Chaque nouvelle version doit :

- être évaluée sur le test gelé ;
- être comparée à la version active ;
- publier un rapport par modalité ;
- documenter les régressions ;
- passer un seuil de charge de revue ;
- être déployable en shadow mode.

---

## 40. Gouvernance des modèles

### 40.1 Fiche modèle

- nom et version ;
- architecture ;
- tâche ;
- licence ;
- données d’entraînement ;
- exclusions ;
- métriques ;
- seuils ;
- biais connus ;
- matériel ;
- checksum ;
- propriétaire ;
- date d’activation.

### 40.2 Cycle de vie

- expérimental ;
- candidat ;
- validé ;
- actif ;
- déprécié ;
- retiré.

### 40.3 Shadow mode

Une nouvelle version analyse les mêmes documents sans influencer le triage. Ses résultats sont comparés pendant une période définie.

### 40.4 Dérive

Surveiller :

- changement de résolution ;
- nouveaux formats éditoriaux ;
- nouvelles modalités ;
- baisse de précision ;
- hausse des faux positifs ;
- dérive des embeddings ;
- changement du corpus.

---

## 41. Utilisation d’un LLM

### 41.1 Autorisé

- résumer une légende ;
- relier une figure à un passage ;
- extraire les conditions expérimentales ;
- préparer un rapport à partir de findings structurés ;
- proposer des questions à poser aux auteurs ;
- traduire un rapport.

### 41.2 Interdit

- inventer une anomalie ;
- produire des coordonnées ;
- remplacer les détecteurs d’image ;
- déclarer une fraude ;
- interpréter une figure sans citer les éléments ;
- masquer l’incertitude ;
- fusionner silencieusement des findings.

### 41.3 Contrat de sortie

Toute phrase générée doit être reliée à :

- un finding ;
- une légende ;
- une méthode ;
- une source ;
- ou une décision humaine.

---

## 42. Sécurité, confidentialité et droits

### 42.1 Contrôle d’accès

- RBAC ;
- séparation par organisation ;
- MFA facultatif/obligatoire selon politique ;
- jetons à durée courte ;
- journaux d’accès ;
- partage explicite.

### 42.2 Chiffrement

- TLS ;
- chiffrement au repos ;
- clés séparées par environnement ;
- secrets dans un coffre ;
- rotation.

### 42.3 Conservation

- durée configurable ;
- suppression vérifiable ;
- gel légal ;
- anonymisation ;
- purge des dérivés avec l’original ;
- conservation séparée des rapports.

### 42.4 Droits sur les figures

Le corpus doit mémoriser :

- source ;
- licence ;
- droit d’usage ;
- restrictions ;
- finalité ;
- date d’acquisition.

Les images payantes peuvent être analysées dans le cadre des droits légitimes de l’utilisateur ou de l’institution, mais ne doivent pas être redistribuées par le système.

### 42.5 Risque diffamatoire

- aucune page publique par défaut ;
- vocabulaire neutre ;
- validation humaine ;
- accès restreint ;
- traçabilité ;
- procédure de contestation ;
- avis juridique avant publication d’un rapport nominatif.

---

## 43. Performance et dimensionnement

### 43.1 Profils

**Rapide**

- extraction ;
- doublons exacts ;
- pHash ;
- embeddings ;
- analyse de contraste.

**Standard**

- rapide + vérification géométrique ;
- segmentation ;
- copier-déplacer clépoints ;
- analyse spécialisée.

**Complet**

- dense copy-move ;
- splicing ;
- inpainting ;
- provenance corpus ;
- données sources ;
- plusieurs modèles.

### 43.2 Cibles indicatives

Sur une machine GPU milieu/haut de gamme :

- extraction : quelques secondes à quelques minutes par article ;
- profil standard : objectif inférieur à 5 minutes pour un article courant ;
- profil complet : objectif inférieur à 20 minutes, hors recherche sur corpus massif ;
- recherche ANN : sous-seconde à quelques secondes selon corpus ;
- rapport : inférieur à une minute.

Ces objectifs doivent être mesurés sur le matériel retenu et ne constituent pas une garantie.

### 43.3 Mise à l’échelle

- batch d’embeddings ;
- index partitionné ;
- cache par hash ;
- déduplication des jobs ;
- calcul incrémental ;
- priorités ;
- nœuds GPU partagés ;
- pré-calcul du corpus.

---

## 44. Feuille de route

### Phase 0 — preuve de concept

- ingestion PDF/images ;
- extraction ;
- segmentation manuelle assistée ;
- hash/pHash ;
- SIFT + RANSAC ;
- détection de copier-déplacer simple ;
- interface de comparaison ;
- rapport JSON/HTML ;
- tests sur BioFors/RSIID.

### Phase 1 — MVP

- multi-utilisateurs ;
- stockage et audit ;
- panel segmentation automatique ;
- classifieur de modalité ;
- index corpus ;
- pipeline blot/gel ;
- scoring et triage ;
- validation humaine ;
- rapport PDF ;
- API.

### Phase 2 — production

- données sources ;
- splicing/inpainting ;
- provenance ;
- calibration ;
- gouvernance ML ;
- sécurité renforcée ;
- déploiement institutionnel ;
- connecteurs OpenAlex/Crossref/Unpaywall ;
- observabilité.

### Phase 3 — recherche avancée

- FCS ;
- DICOM ;
- formats microscopie ;
- graphs/data consistency ;
- IA générative ;
- apprentissage actif ;
- benchmark communautaire.

---

## 45. Backlog fonctionnel priorisé

### P0

- préservation de l’original ;
- extraction fiable ;
- panneaux ;
- masquage texte ;
- doublons exacts/quasi-exacts ;
- comparaison géométrique ;
- copy-move ;
- revue humaine ;
- rapport ;
- audit.

### P1

- blot/gel ;
- index corpus ;
- provenance ;
- données sources ;
- calibration ;
- API complète ;
- sécurité multi-tenant.

### P2

- splicing avancé ;
- inpainting ;
- microscopie multi-canaux ;
- FACS brut ;
- graphiques ;
- DICOM ;
- AI-generated detection.

---

## 46. Risques et mesures de réduction

### Faux positifs élevés

- spécialiser par modalité ;
- masquer textes et axes ;
- validation géométrique ;
- calibration ;
- revue humaine ;
- filtres de répétition naturelle.

### Faux négatifs

- combiner plusieurs détecteurs ;
- traiter plusieurs résolutions ;
- exploiter les données sources ;
- profil complet ;
- signaler les limites.

### Faible qualité des PDF

- extraction multi-moteur ;
- rendu haute résolution ;
- accès aux figures originales ;
- conservation des erreurs.

### Biais de jeu de données

- corpus multi-disciplinaire ;
- split par source/auteur ;
- vrais négatifs difficiles ;
- tests externes ;
- publication des performances par modalité.

### Risque juridique

- neutralité ;
- accès restreint ;
- workflow de validation ;
- pas d’accusation automatique ;
- conseil juridique.

### Dette de dépendances académiques

- interfaces internes ;
- conteneurisation ;
- audit de licence ;
- remplacement possible ;
- tests de non-régression.

---

## 47. Critères de recette globale

Le produit est recevable lorsque :

1. chaque document est conservé avec hash et provenance ;
2. toute figure extraite est reliée à une page et une légende ;
3. les panneaux sont éditables et traçables ;
4. chaque finding est localisé et reproductible ;
5. aucune alerte n’utilise le mot « fraude » automatiquement ;
6. les sorties individuelles des détecteurs sont accessibles ;
7. les échecs et limites sont visibles ;
8. le rapport contient versions, paramètres et preuves ;
9. l’analyste peut confirmer, rejeter ou demander des sources ;
10. l’accès est cloisonné par organisation ;
11. les modèles ont une fiche et un checksum ;
12. les métriques sont publiées par modalité ;
13. un test de non-régression bloque une version dégradée ;
14. le dossier de preuve peut être exporté et vérifié ;
15. le système ne redistribue pas les contenus sous droits.

---

## 48. Décisions d’architecture recommandées

1. **Séparer le workflow métier du calcul forensique.**  
   Symfony peut gérer dossiers, utilisateurs, décisions et rapports ; Python gère les traitements d’image et ML.

2. **Commencer par les doublons et le copier-déplacer.**  
   Ce sont les anomalies les mieux définies, les plus localisables et les plus testables.

3. **Prioriser blot/gel et microscopie.**  
   Ces modalités ont une forte valeur d’usage et disposent de jeux de données spécialisés.

4. **Ne pas lancer immédiatement un modèle unique end-to-end.**  
   Une architecture modulaire facilite l’audit, la calibration, la substitution et l’explication.

5. **Créer dès le départ un jeu interne de vrais négatifs difficiles.**  
   Sans lui, les performances en laboratoire seront trompeuses.

6. **Conserver un mode exploratoire distinct du mode probant.**  
   Le premier favorise le rappel ; le second exige une précision élevée et des preuves stables.

7. **Exiger une validation humaine avant tout classement éditorial.**  
   Les recommandations STM et COPE traitent les anomalies dans le contexte des sources, explications et conclusions, pas comme des verdicts automatiques [R1, R3].

---

## 49. Références principales

**[R1]** STM Working Group on Image Alteration and Duplication Detection. *Recommendations for handling image integrity issues*, version 1.0, 2021.  
https://stm-assoc.org/document/recommendations-for-handling-image-integrity-issues-v10/

**[R2]** Office of Research Integrity. *Forensic Tools* et *Image Processing Useful in Research Misconduct Cases*.  
https://ori.hhs.gov/forensic-tools  
https://ori.hhs.gov/image-processing-useful-research-misconduct-cases

**[R3]** Committee on Publication Ethics. *Inappropriate image manipulation in a published article*, 2024.  
https://publicationethics.org/guidance/flowchart/inappropriate-image-manipulation-published-article

**[R4]** Bik EM, Casadevall A, Fang FC. *The Prevalence of Inappropriate Image Duplication in Biomedical Research Publications*. mBio, 2016. DOI: 10.1128/mbio.00809-16.

**[R5]** Moreira D, et al. *SILA: a system for scientific image analysis*. Scientific Reports, 2022. DOI: 10.1038/s41598-022-21535-3.

**[R6]** Sabir E, Nandi S, AbdAlmageed W, Natarajan P. *BioFors: A Large Biomedical Image Forensics Dataset*. ICCV, 2021.  
https://openaccess.thecvf.com/content/ICCV2021/html/Sabir_BioFors_A_Large_Biomedical_Image_Forensics_Dataset_ICCV_2021_paper.html

**[R7]** Clark C, Divvala S. *PDFFigures 2.0: Mining Figures from Research Papers*. JCDL, 2016.  
https://github.com/allenai/pdffigures2

**[R8]** Siegel N, et al. *Extracting Scientific Figures with Distantly Supervised Neural Networks*. JCDL, 2018.  
https://github.com/allenai/deepfigures-open

**[R9]** Sabir E, et al. *MONet: Multi-Scale Overlap Network for Duplication Detection in Biomedical Images*. ICIP, 2022.  
https://arxiv.org/abs/2207.09107

**[R10]** Nature Portfolio. *Image Integrity* et *Research Figure Guide*.  
https://research-figure-guide.nature.com/figures/image-integrity/

**[R11]** RIVIEW/SILA source code and experimental modules.  
https://github.com/danielmoreira/sciint

**[R12]** BioFors dataset and MONet code.  
https://github.com/vimal-isi-edu/BioFors

**[R13]** Cardenuto JP, Rocha A. *Benchmarking Scientific Image Forgery Detectors*. Science and Engineering Ethics, 2022. DOI: 10.1007/s11948-022-00391-4.

**[R14]** Recod.ai Scientific Image Integrity Library and Dataset.  
https://github.com/phillipecardenuto/rsiil  
https://zenodo.org/records/15095089

**[R15]** ELIS — Scientific Integrity System.  
https://github.com/researchintegrity/elis

---

## 50. Conclusion de conception

Le produit doit être conçu comme un **poste de travail d’investigation assistée**, et non comme un classifieur accusatoire. Sa valeur repose sur quatre qualités :

- détection multi-méthodes ;
- localisation visuelle claire ;
- traçabilité intégrale ;
- interprétation humaine contextualisée.

La première version réellement utile peut être construite autour de l’extraction PDF, de la segmentation, des doublons, du copier-déplacer, de l’index de similarité et d’un module blot/gel. Les analyses de splicing, de bruit, d’inpainting, de données sources et de provenance viennent ensuite renforcer la preuve.

Le résultat attendu n’est pas « cette étude est frauduleuse », mais :

> « voici les régions, transformations, comparaisons, limites et données manquantes qui justifient — ou non — une revue d’intégrité scientifique ».
