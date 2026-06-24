# Contribuer & conventions de Pull Request

> Comment proposer une modification à SciencesWiki. Lis d'abord les
> **[Conventions de code](04-conventions-de-code.md)**.

## 1. Avant de commencer

- **Ouvre (ou prends) une *issue*** décrivant le besoin avant un gros changement,
  pour aligner sur l'approche et éviter le travail en double.
- Garde le **périmètre d'une PR petit et cohérent** : une intention par PR. Une
  grosse fonctionnalité se découpe en plusieurs PR enchaînées.
- Le projet est francophone : **échanges, issues et PR en français**.

## 2. Branches

Travaille sur une branche dédiée, jamais directement sur la branche par défaut.
Nommage aligné sur les types de commit :

```
feat/<scope>-<court-resume>      ex. feat/rag-citations-multiples
fix/<scope>-<court-resume>       ex. fix/csp-nonce-turbo
chore/<scope>-<court-resume>     ex. chore/front-maj-theme-crt
```

## 3. Convention de commit

Le projet suit un format **type(scope): description**, en **français**, inspiré des
*Conventional Commits* (appliqué de façon pragmatique) :

```
feat(rag): verification de fidelite anti-hallucination (tags [ref. necessaire])
fix(openwebui): corrige l URL Ollama figee en base
chore(front): maj theme CRT (crt-theme.css)
```

Règles :
- **Types** courants : `feat` (fonctionnalité), `fix` (correctif), `chore`
  (maintenance/outillage). On peut aussi utiliser `docs`, `refactor`, `test`.
- **Scope** = zone touchée : `rag`, `rag-chat`, `harvester`/`moisson`, `analysis`,
  `search`, `crt`/`theme`, `web`, `node`, `csp`, `openwebui`, `refs`, etc.
- **Description** à l'impératif, concise, en minuscules, **sans point final**.
- **Commits atomiques** : un commit = un changement cohérent qui compile et passe les
  tests. On évite les commits « wip » dans l'historique final.

## 4. Checklist avant d'ouvrir la PR

- [ ] `php bin/phpunit` **passe** (api) — rappel : il échoue sur toute *deprecation*.
- [ ] Le diff respecte les **[conventions de code](04-conventions-de-code.md)**
      (EditorConfig, `strict_types`, classes `final`, suffixes de nommage…).
- [ ] **Migrations additives** et **relues** (pas de modification d'une migration
      passée ; pas de SQL auto-généré non vérifié).
- [ ] **Aucun secret** ni `.pem` ni fichier d'environnement committé.
- [ ] Si une ressource API a changé : vérifié dans **`/api/docs`**.
- [ ] **Doc à jour** : si le changement modifie l'architecture, un flux ou une
      commande, mettre à jour `docs/developpeur/`.
- [ ] **Bump de `app_version`** (dans `apps/web/config/packages/twig.yaml`) à chaque
      livraison destinée à la production — la version est affichée en pied de page et
      sert de repère de déploiement.

## 5. Gabarit de description de PR

```markdown
## Contexte
Pourquoi ce changement ? (lier l'issue : #123)

## Changements
- Ce qui a été modifié, du point de vue fonctionnel et technique.

## Comment tester
Étapes pour reproduire/valider (commandes, route, scénario).

## Impacts
- [ ] Migration de base (additive ?)
- [ ] Changement de contrat d'API (web/mobile à adapter ?)
- [ ] Nouvelle variable d'environnement / secret
- [ ] Mise à jour de doc nécessaire

## Captures (si UI)
```

## 6. Revue de code

Critères regardés en revue :
- **Correction** : le code fait ce qu'il annonce ; cas limites traités.
- **Cohérence** : suit les patterns existants (handlers idempotents, mappers purs,
  drivers via interface, contrôleurs minces).
- **Sourçage & sécurité** : RAG sourcé préservé ; pas de contournement anti-SSRF ;
  pas de secret exposé.
- **Tests** : présence de tests sur la logique nouvelle/corrigée.
- **Lisibilité** : nommage clair, pas de complexité inutile (YAGNI).
- **Migrations** : additives et sûres pour la prod.

Tant qu'il n'y a pas de CI, **le relecteur exécute les tests** et relit le diff
manuellement. Une PR n'est fusionnée qu'après **au moins une approbation** d'un
mainteneur et résolution des commentaires.

## 7. Définition de « terminé » (Definition of Done)

Une contribution est terminée quand :
1. Le code respecte les conventions et passe les tests.
2. La documentation impactée est à jour.
3. Les migrations sont additives et vérifiées.
4. La PR est relue, approuvée, et son périmètre est clos (pas de « TODO » orphelin).

## 8. Licence & gouvernance de l'open source

- Le **contenu** de SciencesWiki est publié sous **CC BY-SA 4.0** (cf. pied de page).
- La **licence du code** est précisée dans le fichier `LICENSE` à la racine du dépôt.
  En contribuant, tu acceptes que ta contribution soit distribuée sous cette licence.
- Selon la gouvernance retenue par les mainteneurs, une PR peut requérir un accord de
  contribution (**DCO** `Signed-off-by:` ou **CLA**). Le cas échéant, la procédure
  est indiquée dans `CONTRIBUTING.md` / le gabarit de PR.

> **À formaliser par les mainteneurs avant l'ouverture publique** : fichier
> `LICENSE` (code), `CODE_OF_CONDUCT.md`, `CONTRIBUTING.md` à la racine, et
> éventuellement les *templates* d'issue/PR GitHub. Ce document décrit le *process* ;
> ces fichiers en sont la forme canonique.

## 9. Sécurité — divulgation responsable

Une faille de sécurité ne se signale **pas** dans une issue publique. Contacter
directement les mainteneurs (adresse de contact du projet) pour une divulgation
responsable, avant toute publication.
