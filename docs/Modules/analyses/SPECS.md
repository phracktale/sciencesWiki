# Spécification fonctionnelle et technique
## Module de routage et d’analyse des publications scientifiques

**Nom de travail :** Scientific Analysis Router  
**Version :** 0.1.0  
**Statut :** Spécification initiale  
**Type :** Module générique intégrable dans une application scientifique  
**Langue de référence :** Français  

---

## 1. Objet

Ce document définit les spécifications fonctionnelles et techniques d’un module chargé de :

1. identifier la nature d’une publication scientifique ;
2. déterminer le ou les plans d’étude présents ;
3. identifier le ou les champs scientifiques concernés ;
4. sélectionner les référentiels, outils et modules d’analyse applicables ;
5. construire un plan d’analyse composite ;
6. exécuter ou orchestrer les analyses ;
7. produire un résultat scientifique canonique ;
8. adapter la restitution aux rôles cibles de l’application hôte.

Le module est conçu pour être intégré dans une application scientifique plus large. Il ne dépend pas d’un domaine unique, d’un type d’étude unique ni d’un référentiel unique.

---

## 2. Principes directeurs

### 2.1 Séparation du fond scientifique et de la restitution

Le résultat scientifique produit par le module doit être indépendant du rôle utilisateur.

Le rôle utilisateur peut modifier :

- le niveau de détail affiché ;
- le vocabulaire ;
- les alertes mises en avant ;
- les possibilités de validation ou de correction ;
- les permissions d’accès ;
- les formats d’export.

Le rôle utilisateur ne doit jamais modifier :

- le type d’étude détecté ;
- les preuves extraites ;
- les réponses aux critères ;
- le niveau de risque de biais ;
- les conclusions méthodologiques ;
- le niveau de confiance scientifique.

### 2.2 Routage composite

Le routeur ne doit pas sélectionner une seule grille.

Il doit produire un plan d’analyse composé de plusieurs éléments :

- référentiel méthodologique principal ;
- référentiel de reporting ;
- outil de risque de biais ;
- contrôles statistiques ;
- contrôles propres au domaine scientifique ;
- contrôles propres à la modalité des données ;
- contrôles de reproductibilité ;
- contrôles d’intégrité scientifique ;
- contrôles de cohérence entre résultats et conclusions.

### 2.3 Traçabilité

Toute décision de routage doit être :

- explicable ;
- versionnée ;
- accompagnée d’un niveau de confiance ;
- reliée à des preuves documentaires ;
- modifiable par un utilisateur autorisé ;
- historisée en cas de correction manuelle.

### 2.4 Gestion explicite de l’incertitude

Le système ne doit pas transformer une absence d’information en réponse négative.

Les statuts minimaux sont :

- `yes`
- `partial`
- `no`
- `unclear`
- `not_applicable`

Une absence dans le texte disponible doit produire `unclear`, sauf si l’absence a été vérifiée sur l’intégralité des sources attendues.

---

## 3. Périmètre fonctionnel

### 3.1 Inclus dans le module

Le module couvre :

- ingestion de métadonnées scientifiques ;
- extraction ou réception du contenu structuré d’un document ;
- classification de la publication ;
- classification du plan d’étude ;
- classification du domaine scientifique ;
- identification de la finalité scientifique ;
- identification des modalités de données ;
- application des règles de routage ;
- production d’un plan d’analyse ;
- orchestration des analyseurs ;
- centralisation des preuves ;
- génération d’un résultat canonique ;
- projection du résultat selon les rôles ;
- gestion des validations humaines ;
- gestion des versions des référentiels et règles.

### 3.2 Hors périmètre initial

Sont hors périmètre de cette version :

- gestion complète des comptes utilisateurs ;
- gestion commerciale ou des abonnements ;
- hébergement documentaire global ;
- édition collaborative du texte scientifique ;
- soumission à une revue ;
- décision automatique d’acceptation ou de rejet d’un article ;
- diagnostic médical ou recommandation clinique automatique ;
- détection exhaustive de fraude scientifique ;
- décision juridique ou réglementaire.

Ces fonctions peuvent être fournies par l’application hôte ou par des modules complémentaires.

---

## 4. Architecture générale

```text
Application scientifique hôte
│
├── Gestion des utilisateurs
├── Gestion des rôles et permissions
├── Gestion des projets
├── Gestion des documents
├── Interface utilisateur
│
└── Module Scientific Analysis Router
    │
    ├── Document Adapter
    ├── Study Fingerprinter
    ├── Ontology Registry
    ├── Framework Registry
    ├── Routing Rule Engine
    ├── Analysis Plan Resolver
    ├── Analysis Orchestrator
    ├── Evidence Engine
    ├── Canonical Assessment Builder
    ├── Role Projection Engine
    └── Audit and Versioning
```

---

## 5. Concepts métier

### 5.1 Publication

Objet documentaire soumis à l’analyse.

Exemples :

- article original ;
- protocole ;
- revue systématique ;
- méta-analyse ;
- preprint ;
- thèse ;
- rapport ;
- jeu de données ;
- article méthodologique ;
- réplication ;
- étude de validation.

### 5.2 Study Unit

Sous-ensemble scientifique analysable de manière autonome.

Un même article peut contenir :

- plusieurs expériences ;
- une étude principale et une étude secondaire ;
- une phase qualitative et une phase quantitative ;
- plusieurs cohortes ;
- plusieurs validations ;
- une étude pilote et une étude confirmatoire.

Le routeur doit pouvoir créer plusieurs `StudyUnit`.

### 5.3 Study Fingerprint

Empreinte structurée de l’étude, construite avant le routage.

Elle contient au minimum :

- type de publication ;
- candidats de plan d’étude ;
- domaine scientifique ;
- sous-domaines ;
- finalité scientifique ;
- modalités de données ;
- population ou unité d’observation ;
- présence d’intervention ;
- temporalité ;
- présence de groupe comparateur ;
- méthode d’échantillonnage ;
- type de résultat principal ;
- ambiguïtés ;
- preuves utilisées pour la classification.

### 5.4 Analysis Plan

Plan composite généré par le routeur.

Il contient :

- référentiels principaux ;
- référentiels secondaires ;
- outils de risque de biais ;
- modules statistiques ;
- modules de domaine ;
- modules de modalité ;
- modules d’intégrité ;
- modules de reproductibilité ;
- ordre d’exécution ;
- dépendances ;
- exclusions ;
- avertissements ;
- niveau de confiance ;
- besoin de validation humaine.

### 5.5 Canonical Assessment

Résultat scientifique de référence, indépendant du rôle utilisateur.

### 5.6 Role Projection

Vue dérivée du résultat canonique, adaptée à un rôle métier.

---

## 6. Axes de classification

## 6.1 Type de publication

Valeurs minimales :

```yaml
publication_types:
  - original_research
  - protocol
  - systematic_review
  - meta_analysis
  - umbrella_review
  - scoping_review
  - narrative_review
  - replication_study
  - methods_paper
  - dataset_paper
  - preprint
  - thesis
  - institutional_report
  - editorial
  - commentary
  - letter
  - case_report
  - case_series
```

## 6.2 Type d’étude

Valeurs minimales :

```yaml
study_designs:
  - randomized_controlled_trial
  - cluster_randomized_trial
  - crossover_trial
  - non_randomized_intervention
  - cohort_prospective
  - cohort_retrospective
  - case_control
  - cross_sectional
  - ecological
  - diagnostic_accuracy
  - prognostic_factor
  - prediction_model_development
  - prediction_model_validation
  - qualitative
  - mixed_methods
  - case_report
  - case_series
  - animal_study
  - in_vitro
  - laboratory_experiment
  - simulation_study
  - computational_experiment
  - algorithm_benchmark
  - economic_evaluation
  - systematic_review
  - meta_analysis
  - umbrella_review
  - scoping_review
  - bibliometric_study
  - replication_study
  - methods_validation
  - unknown
```

## 6.3 Finalité scientifique

```yaml
objectives:
  - efficacy
  - effectiveness
  - safety
  - causality
  - association
  - prevalence
  - incidence
  - diagnosis
  - prognosis
  - prediction
  - screening
  - method_validation
  - model_validation
  - comparison
  - exploration
  - description
  - mechanism
  - replication
  - economic_estimation
  - qualitative_understanding
```

## 6.4 Domaines scientifiques

L’ontologie doit être hiérarchique et extensible.

```yaml
scientific_domains:
  life_sciences:
    - medicine
    - biology
    - neuroscience
    - pharmacology
    - epidemiology
    - public_health
    - genetics
    - microbiology
  social_sciences:
    - psychology
    - sociology
    - education
    - economics
    - political_science
    - anthropology
  physical_sciences:
    - physics
    - chemistry
    - astronomy
    - materials_science
  environmental_sciences:
    - ecology
    - climatology
    - geosciences
    - environmental_health
    - environmental_toxicology
  formal_and_engineering:
    - computer_science
    - artificial_intelligence
    - mathematics
    - statistics
    - robotics
    - engineering
```

Une étude peut appartenir à plusieurs domaines.

## 6.5 Modalités de données

```yaml
data_modalities:
  - tabular
  - survey
  - questionnaire
  - interview
  - focus_group
  - scientific_image
  - medical_imaging
  - microscopy
  - western_blot
  - genomic_sequence
  - omics
  - time_series
  - physiological_signal
  - geospatial
  - text_corpus
  - source_code
  - simulation_output
  - sensor_data
  - synthetic_data
  - administrative_database
  - electronic_health_record
```

---

## 7. Composants

## 7.1 Document Adapter

Responsabilités :

- recevoir un document ou une référence documentaire ;
- récupérer le texte structuré ;
- préserver les sections ;
- préserver les numéros de page ;
- préserver les tableaux et figures ;
- référencer les annexes ;
- signaler les sources manquantes ;
- produire un format canonique.

### Format minimal

```json
{
  "document_id": "doc_123",
  "title": "Example article",
  "sections": [
    {
      "section_id": "methods",
      "title": "Methods",
      "content": "..."
    }
  ],
  "tables": [],
  "figures": [],
  "supplements": [],
  "metadata": {},
  "availability": {
    "full_text": true,
    "tables_available": true,
    "figures_available": true,
    "supplements_available": false
  }
}
```

---

## 7.2 Study Fingerprinter

Responsabilités :

- détecter le type de publication ;
- détecter le ou les plans d’étude ;
- détecter les finalités ;
- détecter les domaines ;
- détecter les modalités de données ;
- produire des candidats concurrents ;
- fournir des preuves ;
- fournir un score de confiance ;
- signaler les contradictions.

### Exemple

```json
{
  "study_unit_id": "study_01",
  "publication_type": {
    "code": "original_research",
    "confidence": 0.98
  },
  "study_design_candidates": [
    {
      "code": "cross_sectional",
      "confidence": 0.86,
      "evidence_ids": ["ev_001", "ev_002"]
    },
    {
      "code": "cohort_retrospective",
      "confidence": 0.31,
      "evidence_ids": ["ev_003"]
    }
  ],
  "scientific_domains": [
    {
      "code": "social_sciences.psychology",
      "confidence": 0.91
    }
  ],
  "objectives": [
    {
      "code": "prevalence",
      "confidence": 0.88
    },
    {
      "code": "association",
      "confidence": 0.79
    }
  ],
  "data_modalities": [
    "survey",
    "tabular"
  ],
  "ambiguities": [
    {
      "code": "possible_longitudinal_component",
      "severity": "medium"
    }
  ]
}
```

---

## 7.3 Ontology Registry

Le registre doit gérer :

- types d’étude ;
- types de publication ;
- domaines scientifiques ;
- modalités de données ;
- finalités ;
- types de biais ;
- dimensions d’analyse ;
- relations de compatibilité ;
- synonymes ;
- traductions ;
- versions.

Le registre doit permettre :

- l’ajout d’un domaine sans modification du noyau ;
- l’ajout d’un type d’étude ;
- la dépréciation d’un terme ;
- la correspondance entre taxonomies externes ;
- le versionnement sémantique.

---

## 7.4 Framework Registry

Chaque référentiel est déclaré sous forme de plugin.

### Métadonnées minimales

```json
{
  "framework_id": "axis",
  "name": "AXIS",
  "version": "1.0",
  "framework_type": "critical_appraisal",
  "supported_designs": ["cross_sectional"],
  "supported_domains": ["*"],
  "required_inputs": ["full_text"],
  "dimensions": [
    "methodological_quality",
    "reporting_quality",
    "risk_of_bias"
  ],
  "incompatibilities": [
    "randomized_controlled_trial"
  ],
  "implementation": "axis_analyzer_v1"
}
```

### Types de plugins

- `critical_appraisal`
- `reporting_guideline`
- `risk_of_bias`
- `statistical_review`
- `domain_overlay`
- `data_modality_overlay`
- `integrity_check`
- `reproducibility_check`
- `claim_consistency_check`

---

## 7.5 Routing Rule Engine

Le moteur doit appliquer des règles déclaratives.

### Exemple

```json
{
  "rule_id": "route_cross_sectional",
  "version": "1.0.0",
  "priority": 100,
  "conditions": {
    "study_design_in": ["cross_sectional"]
  },
  "actions": {
    "add_primary_frameworks": ["axis"],
    "add_reporting_frameworks": ["strobe_cross_sectional"],
    "add_modules": [
      "observational_statistics",
      "sampling_bias",
      "confounding_analysis"
    ]
  }
}
```

### Propriétés obligatoires d’une règle

- identifiant ;
- version ;
- priorité ;
- conditions ;
- actions ;
- justification ;
- statut ;
- date d’effet ;
- auteur ou source ;
- niveau de criticité.

### Types de conditions

- type d’étude ;
- type de publication ;
- domaine ;
- modalité ;
- finalité ;
- intervention présente ;
- randomisation présente ;
- temporalité ;
- population humaine ou non humaine ;
- données individuelles ou agrégées ;
- disponibilité du texte intégral ;
- présence de code ou données ;
- contexte réglementaire.

---

## 8. Matrice principale de routage par type d’étude

La matrice ci-dessous définit le socle par défaut. Elle est extensible et versionnée.

| Type d’étude | Référentiel principal | Reporting | Risque de biais | Modules statistiques | Modules complémentaires |
|---|---|---|---|---|---|
| Essai randomisé contrôlé | Outil critique ECR | CONSORT | RoB 2 | taille d’effet, puissance, ITT, données manquantes | randomisation, allocation, aveugle, sécurité |
| Essai randomisé en grappes | Outil critique ECR cluster | CONSORT Cluster | RoB 2 Cluster | effet de grappe, ICC, puissance ajustée | contamination intergroupes |
| Essai croisé | Outil critique crossover | CONSORT Crossover | RoB 2 adapté | effet de période, carry-over | washout, ordre des séquences |
| Intervention non randomisée | Outil critique interventionnelle | TREND ou équivalent | ROBINS-I | ajustement, confusion, comparabilité | biais de sélection |
| Cohorte prospective | Outil critique cohorte | STROBE Cohort | ROBINS-I ou outil cohorte | survie, attrition, confusion | temporalité, pertes de suivi |
| Cohorte rétrospective | Outil critique cohorte | STROBE Cohort | ROBINS-I | données manquantes, confusion | qualité des bases secondaires |
| Cas-témoins | Outil critique cas-témoins | STROBE Case-Control | ROBINS-I ou outil dédié | odds ratio, appariement | biais de rappel, sélection témoins |
| Étude transversale | AXIS ou équivalent | STROBE Cross-Sectional | Outil adapté | prévalence, pondération, confusion | représentativité, causalité interdite |
| Étude écologique | Outil critique écologique | STROBE adapté | Outil spécifique | corrélations agrégées | erreur écologique |
| Diagnostic | Outil critique diagnostic | STARD | QUADAS-2 | sensibilité, spécificité, AUC | seuils, standard de référence |
| Pronostic | Outil critique pronostique | REMARK ou équivalent | QUIPS | calibration, discrimination | suivi, facteurs concurrents |
| Développement modèle prédictif | Outil modèle prédictif | TRIPOD | PROBAST | validation interne, surapprentissage | sélection de variables |
| Validation modèle prédictif | Outil validation externe | TRIPOD | PROBAST | calibration externe, transportabilité | dérive de population |
| Étude qualitative | CASP Qualitative ou équivalent | COREQ ou SRQR | Outil qualitatif | non applicable au sens classique | saturation, réflexivité, triangulation |
| Méthodes mixtes | MMAT | Reporting mixte | MMAT | quantitatif + qualitatif | cohérence d’intégration |
| Cas clinique | Outil critique cas clinique | CARE | non standard | descriptif | causalité très limitée |
| Série de cas | Outil série de cas | PROCESS adapté | Outil dédié | descriptif | sélection et exhaustivité |
| Étude animale | Outil préclinique animal | ARRIVE | SYRCLE RoB | puissance, randomisation | bien-être animal, transposabilité |
| Étude in vitro | Outil in vitro | Référentiel domaine | Outil interne | réplications, normalisation | contamination, lignée cellulaire |
| Expérience de laboratoire | Outil expérimental | Référentiel domaine | Outil interne | réplication, incertitude | calibration, contrôle expérimental |
| Simulation | Outil simulation | Guide de reporting simulation | Outil interne | sensibilité, stabilité | hypothèses, validation du modèle |
| Expérience computationnelle | Outil computationnel | Référentiel informatique | Outil interne | variance, répétitions | matériel, dépendances, seed |
| Benchmark algorithmique | Outil benchmark | Référentiel IA/ML | Outil interne | comparaison multiple, variance | fuite de données, sélection benchmark |
| Évaluation économique | Outil économique | CHEERS | Outil économique | ICER, sensibilité | perspective, horizon temporel |
| Revue systématique | AMSTAR 2 ou équivalent | PRISMA | ROBIS | synthèse, hétérogénéité | recherche, sélection, publication |
| Méta-analyse | AMSTAR 2 | PRISMA | ROBIS | effets fixes/aléatoires, I² | biais de publication |
| Umbrella review | Outil umbrella review | PRIOR ou équivalent | ROBIS adapté | chevauchement, hétérogénéité | duplication des études |
| Scoping review | JBI Scoping Review | PRISMA-ScR | généralement limité | descriptif | couverture du champ |
| Bibliométrie | Outil bibliométrique | Reporting bibliométrique | Outil interne | réseau, normalisation | biais de base de données |
| Réplication | Outil selon étude source | Référentiel original + réplication | Outil adapté | équivalence, précision | fidélité au protocole |
| Validation de méthode | Outil validation de méthode | Référentiel domaine | Outil interne | répétabilité, reproductibilité | étalonnage, robustesse |

### Règles d’interprétation

1. La colonne « référentiel principal » détermine le socle méthodologique.
2. La colonne « reporting » contrôle la qualité de description, pas nécessairement la validité.
3. La colonne « risque de biais » doit rester distincte du reporting.
4. Les modules statistiques sont activés selon les analyses effectivement présentes.
5. Les modules complémentaires sont ajoutés selon le domaine, la modalité et la finalité.
6. Une cellule vide ne signifie pas absence de contrôle, mais absence de référentiel générique retenu à ce stade.

---

## 9. Matrice de surcouches par domaine scientifique

Les surcouches complètent le plan principal.

| Domaine | Modules ajoutés | Risques spécifiques | Contrôles minimaux |
|---|---|---|---|
| Médecine clinique | applicabilité clinique, sécurité, pertinence patient | substitution de critères, populations non représentatives | critères cliniques, événements indésirables, pertinence absolue |
| Épidémiologie | confusion, sélection, temporalité | causalité abusive, biais de surveillance | définition exposition, population source, variables d’ajustement |
| Santé publique | généralisation, équité, contexte | effets de système, déterminants sociaux ignorés | comparabilité territoriale, politiques concomitantes |
| Psychologie | psychométrie, biais de méthode commune | auto-questionnaires, faible puissance, flexibilité analytique | validité des échelles, fidélité, pré-enregistrement |
| Neurosciences | multiplicité, traitement du signal | circular analysis, petit effectif | correction multiple, pipeline, réplication |
| Pharmacologie | dose-réponse, pharmacocinétique | sélection des doses, conflits | exposition, métabolites, sécurité |
| Génétique et omiques | qualité séquençage, correction multiple | inflation statistique, population stratification | contrôle qualité, réplication, validation externe |
| Biologie cellulaire | identité des lignées, réplication | contamination, duplication d’images | authentification, nombre de réplications, contrôles |
| Microbiologie | contamination, conditions de culture | généralisation abusive | contrôles positifs/négatifs, souches, environnement |
| Écologie | structure spatiale, saisonnalité | pseudo-réplication, dépendance spatiale | unité expérimentale, autocorrélation |
| Climatologie | séries temporelles, modèles | choix de période, scénarios | sensibilité, incertitude, validation |
| Sciences environnementales | exposition, géographie | confusion territoriale, mesure indirecte | géocodage, modèle d’exposition, résolution |
| Économie | identification causale, robustesse | p-hacking, choix de spécification | hypothèses, tests placebo, sensibilité |
| Sociologie | échantillonnage, contexte | surinterprétation, biais déclaratif | représentativité, réflexivité |
| Sciences de l’éducation | clustering, effet enseignant | contamination, attrition | hiérarchie des données, contexte scolaire |
| Physique | incertitude instrumentale, calibration | sélection d’événements | propagation d’incertitude, calibration |
| Chimie | pureté, répétabilité, caractérisation | résultats non reproductibles | protocoles, spectres, contrôles |
| Matériaux | préparation, caractérisation | variabilité d’échantillons | lots, conditions, mesures répétées |
| Informatique | reproductibilité logicielle | benchmarks opportunistes | code, versions, environnement |
| Intelligence artificielle | fuite de données, dérive, biais | surapprentissage, sélection du test | split, validation externe, seeds, métriques |
| Robotique | environnement réel, robustesse | démonstration non généralisable | répétitions, scénarios, pannes |
| Mathématiques | validité formelle | preuve incomplète | hypothèses, démonstration, vérification |
| Ingénierie | tolérance, sécurité, contraintes | prototype unique | essais, marges, conditions limites |

---

## 10. Matrice de surcouches par modalité de données

| Modalité | Modules ajoutés | Contrôles principaux |
|---|---|---|
| Données tabulaires | qualité des données, valeurs manquantes | dictionnaire, unités, doublons |
| Questionnaire | psychométrie, biais déclaratif | validité, fidélité, formulation |
| Entretiens | analyse qualitative | guide, codage, saturation |
| Images scientifiques | intégrité d’image | duplication, recadrage, contraste |
| Imagerie médicale | lecture, segmentation, standardisation | aveugle, accord inter-juges, protocole |
| Microscopie | acquisition et quantification | champs sélectionnés, normalisation |
| Western blot | intégrité de bandes | duplication, exposition, contrôles |
| Génomique | pipeline bioinformatique | QC, alignement, correction multiple |
| Séries temporelles | dépendance temporelle | stationnarité, autocorrélation |
| Signaux physiologiques | filtrage et artefacts | fréquence, prétraitement, exclusions |
| Géospatial | dépendance spatiale | résolution, MAUP, autocorrélation |
| Corpus textuel | annotation, représentativité | provenance, accord annotateurs |
| Code source | reproductibilité logicielle | dépendances, versions, licence |
| Sorties de simulation | robustesse numérique | paramètres, seeds, convergence |
| Données de capteurs | calibration et dérive | précision, pertes, synchronisation |
| Données synthétiques | fidélité et confidentialité | distribution, biais, fuite |
| Dossiers de santé | codage et exhaustivité | définitions, biais de capture |
| Bases administratives | qualité secondaire | finalité initiale, variables absentes |

---

## 11. Matrice de routage par finalité scientifique

| Finalité | Modules obligatoires |
|---|---|
| Efficacité | effet causal, comparateur, adhérence, analyse ITT |
| Sécurité | événements indésirables, durée de suivi, puissance sécurité |
| Causalité | temporalité, confusion, biais de sélection, hypothèses |
| Association | ajustement, multiplicité, interdiction d’inférence causale |
| Prévalence | représentativité, pondération, période, définition des cas |
| Incidence | population à risque, suivi, pertes de vue |
| Diagnostic | standard de référence, aveugle, seuils |
| Pronostic | suivi, calibration, événements concurrents |
| Prédiction | validation, surapprentissage, performance, utilité |
| Validation de méthode | répétabilité, reproductibilité, exactitude |
| Comparaison | équivalence des conditions, multiplicité |
| Exploration | caractère exploratoire, hypothèses post hoc |
| Réplication | fidélité au protocole, puissance, compatibilité |
| Compréhension qualitative | saturation, réflexivité, triangulation |
| Évaluation économique | perspective, horizon, actualisation, sensibilité |

---

## 12. Algorithme de routage

### 12.1 Ordre de résolution

Le moteur applique l’ordre suivant :

1. identifier les `StudyUnit` ;
2. identifier le type de publication ;
3. identifier les candidats de plan d’étude ;
4. identifier la finalité principale ;
5. identifier les modalités de données ;
6. identifier les domaines scientifiques ;
7. sélectionner le socle méthodologique ;
8. ajouter les référentiels de reporting ;
9. ajouter les outils de risque de biais ;
10. ajouter les modules statistiques ;
11. ajouter les surcouches de domaine ;
12. ajouter les surcouches de modalité ;
13. ajouter les contrôles d’intégrité ;
14. ajouter les contrôles de reproductibilité ;
15. résoudre les conflits ;
16. calculer la couverture ;
17. déterminer si une validation humaine est requise.

### 12.2 Pseudo-code

```text
for each study_unit in document:
    fingerprint = fingerprint_study(study_unit)

    candidate_routes = []

    for design_candidate in fingerprint.study_design_candidates:
        base_route = select_base_route(design_candidate)
        base_route += select_reporting_guidelines(design_candidate)
        base_route += select_risk_of_bias_tools(design_candidate)

        for objective in fingerprint.objectives:
            base_route += objective_overlays(objective)

        for domain in fingerprint.scientific_domains:
            base_route += domain_overlays(domain)

        for modality in fingerprint.data_modalities:
            base_route += modality_overlays(modality)

        base_route += integrity_core()
        base_route += reproducibility_core()
        base_route += claim_consistency_core()

        resolved_route = resolve_conflicts(base_route)
        candidate_routes.append(resolved_route)

    selected_route = rank_routes(candidate_routes)

    if selected_route.confidence < threshold:
        selected_route.human_review_required = true
```

---

## 13. Résolution des conflits

Le résolveur doit détecter :

- deux référentiels incompatibles ;
- une grille non applicable ;
- une redondance forte ;
- un module sans données requises ;
- plusieurs plans d’étude concurrents ;
- une divergence entre résumé et méthodes ;
- une divergence entre publication déclarée et étude réelle.

### Exemples

- Un article se présente comme une cohorte mais décrit une mesure unique : signaler un conflit `cohort_vs_cross_sectional`.
- Une étude dite randomisée sans méthode de randomisation identifiable : conserver le candidat ECR, mais exiger une validation.
- Une revue narrative contenant une méta-analyse partielle : créer plusieurs unités d’analyse ou une route composite.
- Une étude IA diagnostique : combiner `diagnostic_accuracy`, `artificial_intelligence` et `medical_imaging`.

---

## 14. Seuils de validation humaine

Une validation humaine est requise si au moins une condition est vraie :

- confiance du plan principal inférieure à `0.75` ;
- écart entre les deux meilleurs candidats inférieur à `0.15` ;
- plusieurs sous-études non séparables automatiquement ;
- texte intégral indisponible ;
- annexes indispensables indisponibles ;
- contradictions majeures dans le document ;
- route composite contenant des modules incompatibles ;
- changement de route susceptible de modifier fortement la conclusion ;
- domaine scientifique non couvert par une règle validée ;
- référentiel expérimental ou non validé.

Les seuils doivent être configurables.

---

## 15. Plan d’analyse

### Schéma minimal

```json
{
  "analysis_plan_id": "plan_123",
  "document_id": "doc_123",
  "study_unit_id": "study_01",
  "route_version": "2026.1",
  "status": "human_review_required",
  "primary_design": {
    "code": "cross_sectional",
    "confidence": 0.86
  },
  "primary_frameworks": [
    "axis"
  ],
  "reporting_frameworks": [
    "strobe_cross_sectional"
  ],
  "risk_of_bias_tools": [
    "observational_bias_core"
  ],
  "analysis_modules": [
    "sampling_bias",
    "confounding_analysis",
    "observational_statistics",
    "psychometric_validity",
    "integrity_core",
    "reproducibility_core",
    "claim_consistency_core"
  ],
  "excluded_modules": [
    {
      "module_id": "consort",
      "reason": "not_randomized_trial"
    }
  ],
  "routing_warnings": [
    {
      "code": "possible_longitudinal_component",
      "severity": "medium"
    }
  ],
  "coverage": {
    "expected_dimensions": 12,
    "covered_dimensions": 10,
    "score": 0.83
  }
}
```

---

## 16. Interface commune des analyseurs

Chaque analyseur doit implémenter :

```text
supports(context): ApplicabilityResult
prepare(context): PreparedAnalysis
analyze(prepared_analysis): AnalysisResult
validate(result): ValidationResult
```

### ApplicabilityResult

```json
{
  "applicable": true,
  "confidence": 0.94,
  "reasons": [],
  "missing_requirements": []
}
```

### AnalysisResult

```json
{
  "module_id": "axis",
  "module_version": "1.0",
  "status": "completed",
  "criteria": [],
  "warnings": [],
  "coverage": 0.78
}
```

---

## 17. Moteur de preuves

Chaque preuve doit être stockée séparément.

### Schéma

```json
{
  "evidence_id": "ev_001",
  "document_id": "doc_123",
  "study_unit_id": "study_01",
  "source_type": "main_text",
  "section_id": "methods",
  "page": 4,
  "quote": "Participants completed a single online survey.",
  "normalized_fact": "Single time-point data collection",
  "evidence_type": "explicit_quote",
  "confidence": "high"
}
```

### Types de preuves

- `explicit_quote`
- `table_value`
- `figure_observation`
- `metadata`
- `calculated`
- `inference`
- `absence_verified`
- `absence_from_extracted_text_only`

### Règles

1. Une conclusion fondée sur une inférence doit être marquée `inference`.
2. Une inférence ne peut pas avoir une confiance `high` sans validation indépendante.
3. Une preuve doit être localisable.
4. Une citation doit conserver le contexte suffisant.
5. Une absence partielle ne peut pas être transformée en absence certaine.
6. Une preuve visuelle doit signaler le besoin éventuel de vérification humaine.

---

## 18. Format des critères d’analyse

```json
{
  "criterion_id": "axis.q05",
  "dimension": "sample_size",
  "question": "La taille de l’échantillon est-elle justifiée ?",
  "answer": "unclear",
  "verdict": "insufficient_evidence",
  "expected": "Justification de taille ou calcul de puissance",
  "analysis": "Aucune justification explicite n’a été trouvée.",
  "evidence_ids": [],
  "evidence_type": "absence_from_extracted_text_only",
  "confidence": "low",
  "limitations": [
    "Les suppléments ne sont pas disponibles."
  ],
  "requires_human_review": false
}
```

---

## 19. Résultat canonique

Le résultat canonique doit regrouper :

- classification de l’étude ;
- route sélectionnée ;
- route(s) alternatives ;
- résultats par référentiel ;
- résultats par dimension ;
- risques de biais ;
- qualité du reporting ;
- intégrité ;
- reproductibilité ;
- qualité statistique ;
- cohérence des conclusions ;
- limites ;
- couverture des preuves ;
- incertitudes ;
- besoins de validation humaine.

### Dimensions minimales

```yaml
assessment_dimensions:
  - study_identification
  - methodological_quality
  - reporting_quality
  - risk_of_bias
  - statistical_quality
  - data_quality
  - reproducibility
  - research_integrity
  - external_validity
  - claim_consistency
  - ethical_reporting
  - overall_evidence_confidence
```

---

## 20. Rôles cibles

Le module doit permettre des projections configurables.

### Chercheur

Affichage :

- preuves détaillées ;
- critères complets ;
- ambiguïtés ;
- statistiques ;
- possibilité de corriger la route.

### Méthodologiste

Affichage :

- toutes les routes candidates ;
- règles déclenchées ;
- conflits ;
- détails des référentiels ;
- validation des critères.

### Relecteur scientifique

Affichage :

- défauts de reporting ;
- risques de biais ;
- informations manquantes ;
- demandes de correction.

### Éditeur

Affichage :

- problèmes bloquants ;
- non-conformités ;
- alertes d’intégrité ;
- couverture du reporting.

### Clinicien ou professionnel métier

Affichage :

- validité interne ;
- applicabilité ;
- taille des effets ;
- sécurité ;
- limites pratiques.

### Journaliste ou vulgarisateur

Affichage :

- ce que l’étude montre ;
- ce qu’elle ne montre pas ;
- niveau de solidité ;
- causalité ou simple association ;
- limites majeures.

### Administrateur

Affichage :

- règles ;
- versions ;
- plugins ;
- seuils ;
- journaux ;
- erreurs.

---

## 21. Contrat d’intégration avec l’application hôte

### Entrée minimale

```json
{
  "document_id": "doc_123",
  "document_content": {},
  "metadata": {},
  "requested_analysis_depth": "full",
  "role_context": {
    "role_code": "researcher",
    "permissions": [
      "view_evidence",
      "override_route"
    ]
  },
  "locale": "fr-FR"
}
```

### Sortie minimale

```json
{
  "study_fingerprint": {},
  "analysis_plan": {},
  "canonical_assessment": {},
  "role_projection": {},
  "quality_control": {
    "routing_confidence": 0.86,
    "evidence_coverage": 0.73,
    "human_review_required": true,
    "warnings": []
  }
}
```

---

## 22. API proposée

### Identifier une étude

```http
POST /api/scientific-analysis/fingerprint
```

### Construire un plan

```http
POST /api/scientific-analysis/routes
```

### Valider ou corriger une route

```http
PATCH /api/scientific-analysis/routes/{routeId}
```

### Lancer une analyse

```http
POST /api/scientific-analysis/analyses
```

### Lire le résultat canonique

```http
GET /api/scientific-analysis/analyses/{analysisId}
```

### Lire une projection par rôle

```http
GET /api/scientific-analysis/analyses/{analysisId}/projection/{roleCode}
```

### Lire les preuves

```http
GET /api/scientific-analysis/analyses/{analysisId}/evidence
```

### Lire les règles déclenchées

```http
GET /api/scientific-analysis/routes/{routeId}/trace
```

---

## 23. Événements d’intégration

Le module peut publier :

```yaml
events:
  - scientific_document_received
  - study_fingerprint_completed
  - routing_completed
  - routing_requires_human_review
  - analysis_started
  - analysis_module_completed
  - analysis_completed
  - integrity_warning_detected
  - route_overridden
  - assessment_validated
```

---

## 24. Architecture logicielle recommandée

```text
scientific-analysis-router/
├── Domain/
│   ├── Publication/
│   ├── StudyUnit/
│   ├── StudyFingerprint/
│   ├── AnalysisPlan/
│   ├── Assessment/
│   ├── Evidence/
│   ├── Ontology/
│   └── RoutingRule/
├── Application/
│   ├── FingerprintStudy/
│   ├── BuildAnalysisPlan/
│   ├── ValidateRoute/
│   ├── RunAnalysis/
│   └── ProjectAssessment/
├── Router/
│   ├── RuleEngine/
│   ├── MatrixResolver/
│   ├── ConflictResolver/
│   ├── CoverageCalculator/
│   └── HumanReviewPolicy/
├── Frameworks/
│   ├── AXIS/
│   ├── CONSORT/
│   ├── STROBE/
│   ├── PRISMA/
│   ├── AMSTAR2/
│   ├── ROBINSI/
│   ├── ROB2/
│   ├── QUADAS2/
│   └── ...
├── Overlays/
│   ├── Domains/
│   ├── Modalities/
│   ├── Objectives/
│   ├── Integrity/
│   └── Reproducibility/
├── Infrastructure/
│   ├── LLM/
│   ├── DocumentExtraction/
│   ├── Persistence/
│   ├── Queue/
│   ├── Logging/
│   └── API/
└── Integration/
    ├── HostApplication/
    ├── Events/
    └── Exports/
```

Une architecture hexagonale ou ports/adapters est recommandée.

---

## 25. Modèle de persistance minimal

### Tables ou collections

- `scientific_document`
- `study_unit`
- `study_fingerprint`
- `study_design_candidate`
- `scientific_domain`
- `data_modality`
- `routing_rule`
- `routing_execution`
- `analysis_plan`
- `analysis_module_execution`
- `assessment`
- `assessment_criterion`
- `evidence`
- `human_review`
- `route_override`
- `framework_version`
- `ontology_version`

### Historisation

Toute modification manuelle doit conserver :

- ancienne valeur ;
- nouvelle valeur ;
- utilisateur ;
- date ;
- justification ;
- impact sur le plan ;
- modules relancés.

---

## 26. Versionnement

Doivent être versionnés indépendamment :

- ontologie ;
- matrice de routage ;
- règles ;
- référentiels ;
- prompts ou modèles d’analyse ;
- schémas JSON ;
- moteur de routage ;
- résultats.

Un résultat doit pouvoir être reproduit avec :

```json
{
  "engine_version": "0.1.0",
  "routing_matrix_version": "2026.1",
  "ontology_version": "1.0.0",
  "framework_versions": {
    "axis": "1.0",
    "strobe_cross_sectional": "2024.1"
  },
  "model_version": "provider/model/version"
}
```

---

## 27. Sécurité et conformité

Le module doit prévoir :

- isolation des documents ;
- contrôle d’accès par rôle ;
- journalisation des accès ;
- chiffrement en transit et au repos ;
- suppression contrôlée ;
- protection des données sensibles ;
- anonymisation optionnelle ;
- conservation configurable ;
- traçabilité des traitements automatisés ;
- signalement clair des traitements par IA.

Le module ne doit pas exposer des données personnelles inutiles aux analyseurs.

---

## 28. Exigences de qualité

### Exigences fonctionnelles

- Le système doit accepter plusieurs plans d’étude candidats.
- Le système doit gérer plusieurs domaines simultanément.
- Le système doit composer plusieurs modules.
- Le système doit justifier chaque route.
- Le système doit signaler les ambiguïtés.
- Le système doit permettre une correction manuelle.
- Le système doit produire un résultat indépendant du rôle.
- Le système doit distinguer reporting, méthodologie et risque de biais.

### Exigences techniques

- Les règles doivent être déclaratives.
- Les plugins doivent être indépendants du noyau.
- Les sorties doivent être structurées.
- Les versions doivent être conservées.
- Les opérations doivent être idempotentes lorsque possible.
- Le module doit être testable sans modèle génératif.
- Le moteur doit supporter l’exécution synchrone et asynchrone côté application hôte.
- Les erreurs partielles ne doivent pas invalider l’ensemble de l’analyse.

---

## 29. Critères d’acceptation

### Routage simple

Étant donné une étude transversale en psychologie :

- le système sélectionne AXIS ou le référentiel configuré ;
- le système ajoute STROBE Cross-Sectional ;
- le système ajoute psychométrie ;
- le système ajoute biais d’auto-questionnaire ;
- le système n’ajoute pas CONSORT ;
- le système produit une trace explicable.

### Routage composite

Étant donné une étude diagnostique utilisant un modèle d’IA sur imagerie médicale :

- le système sélectionne le socle diagnostic ;
- le système ajoute STARD ;
- le système ajoute QUADAS-2 ;
- le système ajoute les contrôles IA ;
- le système ajoute les contrôles d’imagerie ;
- le système ajoute validation externe et fuite de données ;
- le système n’écrase aucun module par un autre.

### Incertitude

Étant donné une publication ambiguë entre cohorte et transversal :

- le système conserve les deux candidats ;
- le système calcule leurs confiances ;
- le système signale l’ambiguïté ;
- le système demande une validation humaine si les seuils sont atteints.

### Absence de données

Étant donné un article sans supplément accessible :

- le système ne conclut pas automatiquement à l’absence d’information ;
- le système utilise `unclear` ;
- le système indique la source manquante.

### Rôles

Étant donné un même résultat :

- le chercheur voit les preuves détaillées ;
- le journaliste voit une synthèse vulgarisée ;
- les conclusions scientifiques restent identiques.

---

## 30. Cas d’usage de référence

### Cas 1 — Étude transversale en psychologie

```text
Type : cross_sectional
Domaine : psychology
Modalité : questionnaire
Finalité : prevalence + association
```

Route :

```yaml
primary:
  - axis
reporting:
  - strobe_cross_sectional
modules:
  - observational_statistics
  - sampling_bias
  - confounding_analysis
  - psychometric_validity
  - self_report_bias
  - common_method_bias
  - integrity_core
  - reproducibility_core
  - claim_consistency_core
```

### Cas 2 — IA diagnostique en radiologie

```text
Type : diagnostic_accuracy
Domaine : medicine + artificial_intelligence
Modalité : medical_imaging
Finalité : diagnosis
```

Route :

```yaml
primary:
  - diagnostic_appraisal
reporting:
  - stard
risk_of_bias:
  - quadas_2
modules:
  - diagnostic_statistics
  - ai_data_leakage
  - model_validation
  - external_validation
  - imaging_protocol
  - segmentation_reliability
  - dataset_shift
  - integrity_core
  - reproducibility_core
```

### Cas 3 — Étude animale pharmacologique

```text
Type : animal_study
Domaine : pharmacology
Modalité : tabular + microscopy
Finalité : efficacy + mechanism
```

Route :

```yaml
primary:
  - animal_appraisal
reporting:
  - arrive
risk_of_bias:
  - syrcle_rob
modules:
  - randomization
  - blinding
  - sample_size
  - dose_response
  - microscopy_integrity
  - translational_validity
```

### Cas 4 — Benchmark de modèle de langage

```text
Type : algorithm_benchmark
Domaine : artificial_intelligence
Modalité : text_corpus + source_code
Finalité : comparison
```

Route :

```yaml
primary:
  - ai_benchmark_appraisal
modules:
  - benchmark_selection
  - train_test_leakage
  - prompt_variance
  - seed_variance
  - multiple_comparisons
  - code_reproducibility
  - dataset_documentation
  - contamination_risk
```

---

## 31. Roadmap recommandée

### Phase 1 — Noyau

- ontologie minimale ;
- routeur déclaratif ;
- matrice principale ;
- registre des plugins ;
- format canonique ;
- preuves ;
- validation humaine.

### Phase 2 — Référentiels prioritaires

- AXIS ;
- STROBE ;
- CONSORT ;
- PRISMA ;
- AMSTAR 2 ;
- RoB 2 ;
- ROBINS-I ;
- QUADAS-2 ;
- TRIPOD ;
- PROBAST ;
- COREQ ;
- ARRIVE.

### Phase 3 — Surcouches métiers

- médecine ;
- psychologie ;
- biologie ;
- intelligence artificielle ;
- environnement ;
- sciences sociales.

### Phase 4 — Intégrité et reproductibilité

- images ;
- données ;
- code ;
- statistiques ;
- cohérence résultats-conclusions ;
- duplication et réutilisation suspecte.

### Phase 5 — Projection par rôle

- chercheur ;
- méthodologiste ;
- relecteur ;
- clinicien ;
- journaliste ;
- administrateur.

---

## 32. Décision d’architecture structurante

Le routeur doit suivre cette règle :

> Le type d’étude sélectionne le socle méthodologique.  
> La finalité scientifique détermine les contrôles d’interprétation.  
> Le domaine scientifique ajoute des contrôles spécialisés.  
> La modalité des données ajoute des contrôles techniques.  
> Le rôle utilisateur adapte la restitution, jamais le résultat scientifique.

Cette architecture évite de confondre :

- qualité de reporting ;
- qualité méthodologique ;
- risque de biais ;
- intégrité ;
- reproductibilité ;
- applicabilité ;
- niveau de certitude.

Elle permet également d’ajouter de nouveaux domaines, référentiels et modules sans modifier le cœur du système.
