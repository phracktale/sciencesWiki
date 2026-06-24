# Choix techniques (et pourquoi)

> Ce document explique le *raisonnement* derrière les décisions structurantes.
> Format : **Décision → Pourquoi → Alternatives écartées**. Il sert de mémoire
> partagée : avant de remettre en cause un choix, lis le contexte qui l'a motivé.

## 1. Monorepo

**Décision.** Un seul dépôt contient `api`, `web`, `mobile`, `ml`, `infra` et `docs`.

**Pourquoi.** Les composants évoluent ensemble (un changement de contrat d'API touche
souvent le web *et* le mobile). Un monorepo garde les modifications atomiques,
versionne l'infra et la doc avec le code, et simplifie la mise en route d'un nouveau
contributeur (un `clone` suffit).

**Écarté.** Multi-dépôts : surcoût de synchronisation des versions et des contrats
pour un projet encore piloté par une petite équipe.

## 2. Symfony 8 + API Platform (cœur API)

**Décision.** Backend en **PHP 8.4 / Symfony 8.1**, API REST exposée par **API
Platform 4.3** (JSON + JSON-LD/Hydra).

**Pourquoi.** Écosystème mature pour une appli pilotée par les données : Doctrine
(ORM + migrations), Messenger (asynchrone), Security/JWT, Mercure, Validator — le
tout cohérent et maintenu. API Platform génère le CRUD, la pagination, les filtres et
la **doc OpenAPI** (`/api/docs`) à partir d'attributs sur les entités : on écrit la
logique, pas la plomberie REST.

**Écarté.** Un backend Node/Python *from scratch* aurait demandé de réimplémenter
beaucoup de cette plomberie. Le choix PHP tient aussi à l'expertise de l'équipe.

## 3. FrankenPHP comme runtime

**Décision.** Serveur applicatif **FrankenPHP** (Caddy + PHP embarqué) plutôt que
PHP-FPM + nginx séparés.

**Pourquoi.** Une seule image, **mode worker** (l'app reste en mémoire entre les
requêtes → latence réduite), **HTTP/2-3** et **hub Mercure intégré** sans service
additionnel. La *même image* sert le serveur HTTP **et** les workers Messenger.

**Écarté.** PHP-FPM + nginx : deux services à orchestrer, pas de mode worker natif,
Mercure à héberger à part.

## 4. PostgreSQL + pgvector (et pas une base vectorielle dédiée)

**Décision.** Les vecteurs (384d) vivent **dans PostgreSQL** via l'extension
**pgvector**, à côté des métadonnées relationnelles.

**Pourquoi.** **Une seule base** à opérer, à sauvegarder, à requêter. On joint
nativement un résultat sémantique (`embedding <-> :q`) à ses métadonnées (auteurs,
journal, statut de rétractation) **dans la même requête SQL**. À notre échelle
(jusqu'à ~1–2 M d'articles plein texte), pgvector + index **HNSW** suffit largement.
Optimisation prévue : **`halfvec(384)`** (float16) → ÷2 disque **et** RAM d'index,
pour une perte de qualité quasi nulle.

**Écarté (pour l'instant).** Qdrant / Weaviate / Milvus : une *deuxième* base à
synchroniser avec Postgres pour les métadonnées. On ne bascule (vers Qdrant) qu'au-delà
de ~1–2 M d'articles plein texte, quand la quantization (PQ/binaire, ÷8 à ÷32 RAM)
devient nécessaire. **YAGNI** jusque-là.

## 5. Front web en BFF Symfony/Twig (et pas une SPA)

**Décision.** Le site public est un **Backend-For-Frontend** rendu côté serveur
(Twig + Hotwire Turbo), client *server-side* de l'API.

**Pourquoi.** Une encyclopédie vit du **SEO** et de la **rapidité de premier rendu** :
le HTML serveur est indexable et instantané. Turbo apporte la fluidité d'une SPA sans
le poids d'un framework JS. Le BFF **cache la complexité** de l'API au navigateur et
garde le JWT **côté serveur** (en session) — jamais exposé au client.

**Écarté.** SPA React/Vue : moins bon SEO « out of the box », gestion du token dans le
navigateur (surface d'attaque XSS), duplication de la logique d'accès.

## 6. Messenger sur transport Doctrine (et pas Redis/RabbitMQ)

**Décision.** Files asynchrones stockées **dans PostgreSQL** (transport Doctrine).

**Pourquoi.** **Zéro service supplémentaire** : la file est transactionnelle avec les
données. Les débits visés (moisson bornée par la *politesse* envers les éditeurs, pas
par la file) ne justifient pas un broker dédié. Les charges sont **isolées par file**
(`harvester`, `fulltext`, `analysis`) pour éviter qu'un long run d'analyse n'affame la
moisson.

**Écarté.** Redis/RabbitMQ : plus de débit, mais un composant de plus à exploiter et
sauvegarder, inutile à notre échelle.

## 7. IA auto-hébergée (et pas des API propriétaires)

**Décision.** Embeddings (`sentence-transformers`), LLM (**Ollama** / compatible
OpenAI) et **GROBID** sont **auto-hébergés**.

**Pourquoi.** Trois raisons : **coût** (vectoriser des millions d'articles via une API
payante serait prohibitif ; en local, c'est borné par le CPU/GPU), **souveraineté**
(données et corpus restent chez nous, projet francophone et ouvert), et
**indépendance** (pas de dérive de prix ou de dépréciation de modèle subie). L'API ne
connaît ces services que par des **URL HTTP** et des **interfaces** : on peut changer
de modèle sans toucher au métier.

**Écarté.** OpenAI/Anthropic en direct : excellents, mais coût à l'échelle + dépendance
externe pour un service public à but non lucratif.

## 8. Drivers derrière une interface + fabrique

**Décision.** `EmbeddingClient` et `LlmClient` sont des **interfaces** ; l'implémentation
est choisie à l'exécution par une **fabrique** lisant une variable d'environnement
(`EMBEDDING_DRIVER`, `LLM_DRIVER`).

**Pourquoi.** En dev/CI, on bascule sur un driver **déterministe** (`hashing` pour les
embeddings, `stub` pour le LLM) → **tests rapides et sans GPU**. En prod, le driver HTTP
pointe sur les services réels. Le métier ne dépend jamais d'un fournisseur concret.

## 9. RAG **sourcé par construction** + anti-hallucination

**Décision.** Aucune génération « à blanc » : le LLM ne fait que **reformuler des
passages réellement récupérés**, et chaque affirmation porte un marqueur de source
`[n]` → DOI. Une **seconde passe LLM** (`FaithfulnessChecker`) marque d'un
`[réf. nécessaire]` toute affirmation non étayée par les sources fournies.

**Pourquoi.** Une plateforme de vulgarisation **scientifique** ne peut pas tolérer une
IA qui invente. Le *grounding* rend chaque réponse vérifiable ; le marqueur
`[réf. nécessaire]` (clin d'œil à Wikipédia) signale visuellement le doute et **guide la
relecture humaine** (comité). C'est la garantie de confiance du produit.

**Écarté.** LLM seul, sans récupération : rapide mais non vérifiable — inacceptable ici.

## 10. OpenAlex comme source, et « jeter le PDF / garder l'URL »

**Décision.** Source primaire = **OpenAlex** (métadonnées + résumés, **gratuit**,
massif). Le texte intégral est récupéré via le **PDF OA de l'éditeur → GROBID**, puis
le fichier brut est **détruit** ; on ne conserve que l'URL, les fragments et les
vecteurs.

**Pourquoi.** Découpler **la taille de la source** (20–250 To) de **la taille de
l'index** (quelques dizaines de Go). On reste dans le gratuit et le légal (on ne
redistribue pas les PDF), et l'index tient sur du matériel modeste. La pagination se
fait **par curseur** (reprise sur incident) et **jamais** par `from_updated_date`
(réservé au plan payant — un appel large renvoie un 429 « Plan upgrade required »).

**Écarté.** Archive R2 complète d'OpenAlex (coût entreprise, 20 To à stocker inutilement)
et `pdftotext` (qualité d'extraction très inférieure à GROBID/TEI).

## 11. Authentification JWT *stateless* côté API, session côté BFF

**Décision.** L'API est **sans état** (JWT en `Authorization: Bearer`). Le BFF web,
lui, garde le JWT en **session serveur** et le réémet pour le compte de l'utilisateur.

**Pourquoi.** Le *stateless* simplifie l'API (scalable, pas de session partagée) et sert
aussi le mobile et les agents tiers. Le **navigateur** ne manipule jamais le token (il
ne voit qu'un cookie de session opaque) → pas de token volable par XSS.

## 12. Mobile en Flutter

**Décision.** Application mobile en **Flutter/Dart**, consommant l'API publique.

**Pourquoi.** Une seule base de code pour iOS et Android, démarrage rapide, et l'app de
consultation reste simple (pas d'auth) : elle réutilise les mêmes endpoints publics que
le web et le futur serveur MCP.

## 13. Réglages éditables en base (`SettingsService`)

**Décision.** Les paramètres « produit » (prompt système, température, nombre de
voisins kNN, cadence de moisson, modèle léger de vérification…) sont **en base** et
éditables depuis le back-office, pas codés en dur.

**Pourquoi.** Le comité éditorial peut **régler le comportement de l'IA sans
redéploiement**. Les valeurs de génération sont **gelées** sur chaque `Answer`
(`generationModel`) pour la traçabilité.

## 14. Thème « CRT » rétro

**Décision.** Un thème optionnel imitant un terminal/écran cathodique
(`crt-theme.css`, `crt.js`), activable par réglage.

**Pourquoi.** Identité visuelle différenciante pour les pages de présentation, sans
impacter la lisibilité du contenu encyclopédique (le thème « legacy » reste le défaut
pour la lecture). C'est un choix de marque assumé, isolé du reste du front.

---

### En résumé : la philosophie

1. **Le moins de pièces mobiles possible** — Postgres fait base relationnelle *et*
   vectorielle *et* file de messages. On ajoute un composant seulement quand l'échelle
   l'impose (YAGNI).
2. **Tout est remplaçable derrière une interface** — drivers IA, sources de moisson.
3. **La confiance d'abord** — sourçage obligatoire, anti-hallucination, traçabilité.
4. **Gratuit et souverain** — données ouvertes (OpenAlex), IA auto-hébergée, code ouvert.
