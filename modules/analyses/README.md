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

## Stack & intégration

- **App Symfony 8.1** autonome (FrankenPHP), PSR-4 `Analyses\`.
- **Base partagée** : `DATABASE_URL` pointe sur la base SciencesWiki. Le module ne
  crée/migre **que** les tables `analys_*` ; il lit le corpus du cœur en lecture.
- **Sécurité** : validation **JWT locale** avec la **clé publique** de SW
  (`JWT_PUBLIC_KEY`, montée en lecture seule). Le module ne délivre aucun token ;
  l'utilisateur est reconstruit depuis les claims (`username` + `roles`). Aucune
  modification du cœur.

## Démarrage (dev)

```bash
cd modules/analyses
composer install                      # génère composer.lock + vendor/
cp .env .env.local                    # renseigner DATABASE_URL + JWT_PUBLIC_KEY
php bin/console doctrine:migrations:migrate   # crée les tables analys_*
symfony serve                         # ou: frankenphp / php -S
```

## Build & run (conteneur)

```bash
docker build -t scienceswiki/module-analyses modules/analyses
docker run --rm -p 8081:80 \
  -e DATABASE_URL="postgresql://sciences:***@marvin:5432/sciences?serverVersion=16" \
  -e JWT_PUBLIC_KEY=/app/config/jwt/public.pem \
  -e RUN_INIT=1 \
  -v /path/to/scienceswiki/config/jwt/public.pem:/app/config/jwt/public.pem:ro \
  scienceswiki/module-analyses
```

## Vérification

```bash
curl localhost:8081/health          # {"module":"analyses","status":"ok",...}
curl -H "Authorization: Bearer <JWT SW>" localhost:8081/   # identité + rôles
```

`/` exige `ROLE_RESEARCHER` ou `ROLE_COMITE` ; `/admin/*` exige `ROLE_ADMIN`
(sections du manifeste).

## Structure

```text
src/Controller/   src/Entity/   src/Repository/   config/   migrations/   frankenphp/
```

À venir (SPECS analyses) : `Router/` (moteur de routage + matrices), `Frameworks/`
(plugins AXIS, RoB 2, AMSTAR 2, MMAT…), `Overlays/`, ports SDK (corpus, LLM, PDF).

## Statut

Squelette **bootable** : health + endpoint authentifié JWT + 1 entité + migration.
Moteur d'analyse (routeur composite, référentiels) à implémenter — SPECS §15, Phase 3.
