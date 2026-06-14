# SciencesWiki — front web (Symfony/Twig)

Front public **rendu serveur** (SEO) de l'encyclopédie. Il **consomme l'API**
(`apps/api`) et ne touche jamais la base (cf. spec §5).

## Installation

```bash
composer install
# .env.local :
#   API_BASE_URL=http://127.0.0.1:8000
php -S 127.0.0.1:8080 -t public public/index.php
```

(L'API `apps/api` doit tourner et être accessible via `API_BASE_URL`.)

## Pages

- `/` — grands domaines de l'arbre des connaissances.
- `/n/{slug}` — une notion : fil d'Ariane, sous-domaines, et ses **Q/R publiques**
  (bloc vulgarisation + bloc académique sourcé, badge de validation, sources DOI).
