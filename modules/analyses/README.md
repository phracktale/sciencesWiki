# Module `analyses` (préfixe `ANALYS`)

Routeur et moteur d'**analyse composite** des publications scientifiques.
Standalone, sur la base SciencesWiki partagée. **Ne modifie pas** l'analyse legacy.

- Manifeste : [`module.yaml`](module.yaml)
- Spécification fonctionnelle et technique : [`docs/Modules/analyses/SPECS.md`](../../docs/Modules/analyses/SPECS.md)
- Framework d'intégration : [`docs/Modules/SPECS.md`](../../docs/Modules/SPECS.md)

## Périmètre

Détecte le type d'étude, construit un **plan d'analyse composite** (référentiel principal
+ reporting + risque de biais + contrôles domaine/modalité/intégrité), l'exécute et produit
un **résultat canonique** projeté par rôle. Référentiels prévus : AXIS, RoB 2, AMSTAR 2,
MMAT, STROBE, PRISMA… déclarés comme **plugins** (écrits from scratch, pas repris du legacy).

## Tables (base SW partagée) — préfixe `analys_`

`analys_scientific_document`, `analys_study_unit`, `analys_study_fingerprint`,
`analys_analysis_plan`, `analys_assessment`, `analys_assessment_criterion`,
`analys_evidence`, `analys_human_review`, `analys_route_override`, `analys_framework_version`…
(cf. SPECS analyses §25). Migrations dans [`migrations/`](migrations/).

## Statut

Scaffolding initial (manifeste + structure). Implémentation à venir — feuille de route
SPECS §15, Phase 3.
