# SciencesWiki — 20 idées d'usage du corpus

> Le corpus = métadonnées + résumés de millions d'articles OpenAlex, texte intégral
> (TEI GROBID) du haut du panier, le tout vectorisé (RAG pgvector) + un LLM. On peut
> en tirer bien plus que le Q&R actuel. Idées classées par public (avec adaptations).

## 🔬 Chercheurs

1. **Recherche sémantique trans-domaine** — trouver les articles pertinents par le sens
   (pas seulement les mots-clés), y compris hors de sa discipline. *(déjà : `/api/search`)*
2. **Revue de littérature assistée** — synthèse RAG **sourcée** d'un sous-domaine
   (consensus, méthodes, lacunes) avec export Markdown/PDF + bibliographie.
3. **Consensus vs contradictions** — poser une question (« le jeûne intermittent
   réduit-il la mortalité ? ») → regrouper les conclusions concordantes/divergentes
   avec niveau de preuve (FWCI, citations, OA, rétractations).
4. **« Related work » d'un manuscrit** — coller un résumé → articles proches
   (kNN sur l'embedding) pour situer son travail / trouver les références manquantes.
5. **Veille personnalisée** — alertes e-mail : nouveaux articles top-cités d'un
   sous-domaine, ou citant un auteur/une revue suivie.
6. **Cartographie éditeurs / revues / auteurs** — qui publie quoi, où, avec qui
   (tables `publisher`/`journal`/`author` déjà construites) → graphes de collaboration.
7. **Détecteur de rétractations dans une biblio** — coller une liste de DOIs →
   signaler les études rétractées ou sous « expression of concern » (Retraction Watch).

## 📰 Journalistes

8. **Fact-checking sourcé** — une affirmation → articles qui la soutiennent/réfutent,
   avec fiabilité (citations, OA, statut rétractation) et formulation prudente.
9. **Briefing express** — sur un sujet d'actualité : synthèse vulgarisée + 5 sources
   clés + experts à contacter (auteurs correspondants).
10. **Hype-mètre** — coller un communiqué de presse/étude → évaluer la solidité
    (préprint vs revue, taille d'échantillon mentionnée, FWCI, n° de citations,
    indépendance des réplications) pour distinguer percée réelle et survente.
11. **Annuaire d'experts** — pour un thème, les auteurs les plus actifs/cités +
    affiliation + lien de sollicitation (e-mail poli), pour des interviews fiables.

## 👥 Grand public

12. **Q&R vulgarisé sourcé** *(actuel)* — réponses en langage clair, citant les papiers.
13. **Articles longs de vulgarisation** — épine dorsale encyclopédique par thème
    (cf. recommandation §Format ci-dessous), rédigés par RAG multi-sources + relus.
14. **« Décoder une étude »** — coller un DOI/lien → résumé clair + ce que l'étude
    montre **et ne montre pas** + limites + à qui ça s'applique.
15. **Bouclier anti-désinformation** — vérifier une affirmation virale (santé, climat,
    nutrition) contre le consensus scientifique, en une carte simple « ce qu'on sait ».
16. **Infolettre thématique automatique** — « les avancées du mois » d'un domaine,
    vulgarisées, avec les 3–5 études marquantes.

## 🎓 Élèves & enseignants

17. **Lycée** — fiches de révision sourcées, « explique-moi niveau Terminale »,
    **quiz auto-générés** depuis le corpus, sujets de Grand Oral avec sources fiables.
18. **Collège** — réécriture « niveau collège » (vocabulaire adapté, analogies,
    schémas), Q&R modéré, mini-dossiers thématiques.
19. **Élémentaire** — « explique comme à un enfant de 8 ans » (analogies très simples,
    images), questions d'enfants filtrées/modérées, format « Pourquoi… ? ».
20. **Boîte à outils enseignants** — générateur de séquences pédagogiques et de sujets
    d'exposé **par niveau**, adossés à des sources vérifiées (rétractations exclues),
    avec barème/quiz.

## 🔁 Briques transverses (démultiplient les 20)

- **Curseur de niveau de lecture** : un même contenu décliné en 4 niveaux
  (chercheur → grand public → collège → primaire) par simple reformulation LLM.
- **API / serveur MCP** : exposer la recherche du corpus comme outil pour des apps
  tierces et des agents (dont Claude) — le corpus comme « source de vérité » sourcée.
- **Jeux** : « Vrai ou Faux scientifique », « Devine l'étude », quiz par niveau.
- **Accessibilité** : synthèse vocale des articles, version « facile à lire ».

## 📐 Recommandation de format (cf. Q2)

Le Q&R est excellent comme **couche agile** (longue traîne de questions précises, SEO,
entrée à faible friction). Mais une **encyclopédie** gagne à avoir des **articles longs
de vulgarisation** comme **épine dorsale** (profondeur, autorité, valeur durable, par
sous-domaine). Modèle conseillé : **hybride** — articles longs canoniques par thème,
le Q&R traitant les questions spécifiques et **alimentant** la création d'articles
(questions fréquentes → article de synthèse). Chaque réponse Q&R renvoie vers l'article.
