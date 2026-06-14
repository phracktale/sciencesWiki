# SciencesWiki — application mobile (Flutter)

Client **iOS / Android** de l'encyclopédie. Il **consomme l'API** (`apps/api`) et
ne touche jamais la base (cf. spec §5). Les apps mobiles natives ne sont pas
soumises au CORS.

## Prérequis

- Flutter SDK ≥ 3.22 (Dart ≥ 3.4)

## Lancer

```bash
flutter pub get

# Android (émulateur) : 10.0.2.2 = hôte → API locale (valeur par défaut)
flutter run

# iOS simulateur / web : pointer vers 127.0.0.1
flutter run --dart-define=API_BASE_URL=http://127.0.0.1:8000

# Production : domaine de l'API
flutter run --dart-define=API_BASE_URL=https://api.scienceswiki.org
```

(L'API `apps/api` doit tourner et être accessible.)

## Écrans

- **Accueil** : grands domaines de l'arbre des connaissances.
- **Notion** (`NodeScreen`) : fil d'Ariane, sous-domaines, et **Q/R publiques**
  (bloc vulgarisation + bloc académique, badge ✅/⚠️, sources DOI, signature).

## Structure

```
lib/
├── main.dart            Application + thème
├── api_client.dart      Client de l'API (HTTP), API_BASE_URL configurable
├── models.dart          Modèles (TreeNode, Answer, sources…)
└── screens/
    ├── home_screen.dart  Domaines
    └── node_screen.dart  Notion + Q/R
```

> Tests : `flutter test`. Lint : `flutter analyze`.
