# Module `figtrack` (préfixe `FIGTRK`)

Plateforme de **forensique d'images scientifiques** (détection assistée d'anomalies).
Standalone (service Python/ML) sur la base SciencesWiki partagée.
**Outil d'investigation** : ne conclut jamais à une fraude — human-in-the-loop.

- Manifeste : [`module.yaml`](module.yaml)
- Spécification fonctionnelle et technique : [`docs/Modules/figTrack/SPECS.md`](../../docs/Modules/figTrack/SPECS.md)
- Framework d'intégration : [`docs/Modules/SPECS.md`](../../docs/Modules/SPECS.md)

## Périmètre

Extraction figures/panneaux, classification de modalité, détecteurs indépendants
(doublons, copier-déplacer, réutilisation inter-documents, splicing, inpainting,
contraste, blots/gels, microscopie…), comparaison aux données sources, revue humaine,
rapport de preuve reproductible.

## Architecture

- **Façade** : IHM de revue + proxy, intégrée à SciencesWiki (menu hub, réglages admin).
- **Service ML autonome** : Python / FastAPI + workers CPU/GPU, index vectoriel, stockage
  objet. Configuré par env (`FIGTRK_BASE_URL`, `FIGTRK_API_KEY`, `FIGTRK_WEBHOOK_SECRET`),
  webhooks signés. Isolation conteneur (SPECS figTrack §34.3).

## Tables (base SW partagée) — préfixe `figtrk_`

`figtrk_document`, `figtrk_asset`, `figtrk_figure`, `figtrk_panel`, `figtrk_analysis_run`,
`figtrk_detector_run`, `figtrk_finding`, `figtrk_review_decision`, `figtrk_provenance_edge`…
(cf. SPECS figTrack §33). Migrations dans [`migrations/`](migrations/).

## Statut

Scaffolding initial (manifeste + structure). Implémentation à venir — feuille de route
SPECS §15, Phase 4.
