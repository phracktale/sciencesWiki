# Modules SciencesWiki

Modules **standalone** intégrés à SciencesWiki via le framework décrit dans
[`docs/Modules/SPECS.md`](../docs/Modules/SPECS.md).

## Principes (rappel)

- **Standalone + base partagée** : chaque module est déployé séparément mais utilise la
  base PostgreSQL de SciencesWiki. Il possède ses **tables préfixées** (`xxxxxx_*`) et lit
  le corpus du cœur **via les ports du SDK** (jamais d'écriture ni de migration du cœur).
- **Isolation par préfixe (6 ALPHA)** : tables, réglages, routes, événements, files, assets.
- **Accès par rôle** : sections gardées ; la section `admin` est réservée à `ROLE_ADMIN`.
- **Aucune régression** : l'analyse legacy de SciencesWiki (AXIS/RoB 2/AMSTAR 2/MMAT)
  **n'est pas modifiée**. Les modules coexistent avec elle. La bascule n'aura lieu
  qu'après validation (cf. SPECS §3.8).

## Modules

| Slug | Préfixe | Type | Rôle d'usage | Spéc |
|---|---|---|---|---|
| [`analyses`](analyses/) | `ANALYS` | standalone PHP | `ROLE_RESEARCHER`, `ROLE_COMITE` | [SPECS](../docs/Modules/analyses/SPECS.md) |
| [`figtrack`](figtrack/) | `FIGTRK` | standalone Python/ML | `ROLE_RESEARCHER` | [SPECS](../docs/Modules/figTrack/SPECS.md) |

## Structure d'un module

```text
modules/<slug>/
├── module.yaml        # manifeste (identité, version, accès, capacités, hooks) — SPECS §6
├── README.md
├── migrations/        # migrations des tables xxxxxx_* (dans la base SW)
└── src/ | app/        # code du module (PHP standalone, ou service Python/ML)
```

## Enregistrement

Un module est déclaré à l'hôte (table cœur `module_registry`) puis activé par un
administrateur, qui accorde les **capacités** demandées. La compatibilité de version est
vérifiée au chargement ; un module incompatible est isolé, jamais monté (pas de 500).

> Le noyau du framework (Module Kernel, registre, SDK, page hub) est en cours
> d'implémentation — cf. feuille de route SPECS §15.
