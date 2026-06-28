<?php

declare(strict_types=1);

namespace App\Harvester\Command;

use App\Ai\Llm\LlmClient;
use App\Ai\Llm\LlmMessage;
use App\Entity\TreeNode;
use App\Harvester\Ai\EmbeddingClientFactory;
use App\Repository\PublicationRepository;
use App\Repository\TreeNodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Pgvector\Vector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rédige, pour chaque nœud de l'arbre, un article encyclopédique long (~20 000
 * signes, ~10 intertitres) décrivant le domaine scientifique, de qualité type
 * Wikipédia, ANCRÉ sur le corpus (sources réelles récupérées par recherche
 * sémantique) et truffé de LIENS INTERNES vers les domaines liés.
 *
 * Paternité : article_status='non_relu' (IA seule) + article_model (modèle).
 * Idempotent et borné ; reprend les nœuds sans article (ou --force).
 *
 *   bin/console app:wiki:generate --limit=3
 */
#[AsCommand(name: 'app:wiki:generate', description: 'Rédige les articles encyclopédiques des domaines (IA, ancrés corpus, liens internes).')]
final class GenerateWikiArticlesCommand extends Command
{
    public function __construct(
        private readonly TreeNodeRepository $nodes,
        private readonly PublicationRepository $publications,
        private readonly EmbeddingClientFactory $embeddings,
        private readonly LlmClient $llm,
        private readonly EntityManagerInterface $em,
        private readonly \App\Service\SettingsService $settings,
        private readonly \App\Rag\FaithfulnessChecker $faithfulness,
        private readonly \App\Rag\WikipediaLinker $wikipedia,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre d’articles à générer', '3');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Régénère même les nœuds ayant déjà un article');
        $this->addOption('slug', null, InputOption::VALUE_REQUIRED, 'Cible un nœud précis (par slug)');
        $this->addOption('max-level', null, InputOption::VALUE_REQUIRED, 'Limite aux nœuds de niveau ≤ N (0=domaines…)');
        $this->addOption('model', null, InputOption::VALUE_REQUIRED, 'Modèle LLM rédacteur (défaut : réglage BO « Modèle Articles WIKI »)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @set_time_limit(0);
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        // Modèle : --model explicite, sinon réglage back-office « Modèle Articles WIKI ».
        $model = trim((string) ($input->getOption('model') ?? '')) ?: $this->settings->wikiModel();

        if (null !== ($slug = $input->getOption('slug'))) {
            $node = $this->nodes->findOneBy(['slug' => $slug]);
            $targets = null !== $node ? [$node] : [];
        } else {
            // Domaines d'abord (niveau bas), puis on descend.
            $qb = $this->nodes->createQueryBuilder('n')
                ->orderBy('n.level', 'ASC')->addOrderBy('n.id', 'ASC')
                ->setMaxResults($limit);
            if (!$input->getOption('force')) {
                $qb->andWhere('n.articleMd IS NULL');
            }
            if (null !== ($ml = $input->getOption('max-level'))) {
                $qb->andWhere('n.level <= :ml')->setParameter('ml', (int) $ml);
            }
            $targets = $qb->getQuery()->getResult();
        }

        if ([] === $targets) {
            $io->success('Aucun nœud à rédiger.');

            return Command::SUCCESS;
        }

        $done = 0;
        foreach ($targets as $node) {
            $io->writeln(\sprintf('• %s (niveau %d)…', $node->getLabel(), $node->getLevel()));
            try {
                $chars = $this->generateOne($node, $model);
                if ($chars > 0) {
                    ++$done;
                    $io->writeln(\sprintf('  ✓ %d signes', $chars));
                } else {
                    $io->warning('  réponse trop courte, ignorée.');
                }
            } catch (\Throwable $e) {
                $io->warning(\sprintf('  échec : %s', $e->getMessage()));
            }
        }

        $io->success(\sprintf('%d article(s) rédigé(s).', $done));

        return Command::SUCCESS;
    }

    /**
     * Génère (et persiste) l'article d'UNE rubrique — réutilisable hors CLI (handler
     * async du bouton admin). Renvoie le nombre de signes écrits, 0 si trop court.
     * Pipeline complet : sources corpus → rédaction LLM → liens internes → cran 2
     * (faithfulness + liens Wikipédia) → ArticleRevision.
     */
    public function generateOne(TreeNode $node, ?string $model = null): int
    {
        $model = ('' !== (string) $model) ? (string) $model : $this->settings->wikiModel();
        $embedder = $this->embeddings->create();

        [$sourcePubs, $sourceEmbedding] = $this->sourcePublications($node, $embedder);
        $related = $this->relatedNodes($node);
        $sources = $this->formatSources($sourcePubs);
        $relatedFmt = $this->formatRelated($related);
        // Vrais contributeurs du domaine (données corpus, anti star-system) — calculé
        // une fois, identique pour les 3 versions.
        $contributors = $this->topContributors($sourceEmbedding);

        $total = 0;
        // Trois registres : « adulte » = version CANONIQUE (article_md, éditable /
        // validable / historisée) ; « ado » et « chercheur » = variantes IA en
        // lecture seule, déclinées du même fond sourcé.
        foreach (['adulte', 'ado', 'chercheur'] as $audience) {
            $md = $this->renderVersion($node, $sources, $relatedFmt, $related, $sourcePubs, $sourceEmbedding, $model, $audience, $contributors);
            if (mb_strlen($md) < 500) {
                continue; // version trop courte (échec/troncature) : on n'écrase pas
            }
            if ('adulte' === $audience) {
                $node->setArticleMd($md)
                    ->setArticleModel($model)
                    ->setArticleStatus('non_relu')
                    ->setArticleGeneratedAt(new \DateTimeImmutable());
                $this->em->persist(
                    (new \App\Entity\ArticleRevision($node, $md, 'ia'))
                        ->setAuthorLabel($model)
                        ->setChangeSummary('Génération IA')
                );
            } elseif ('ado' === $audience) {
                $node->setArticleMdAdo($md);
            } else {
                $node->setArticleMdChercheur($md);
            }
            $total += mb_strlen($md);
        }
        $this->em->flush();

        return $total;
    }

    /**
     * Génère UNE version de l'article pour une cible de lectorat (stream LLM →
     * nettoyage → liens internes garantis → vérif de fidélité cran 2 → Wikipédia).
     *
     * @param list<\App\Entity\Publication>          $sourcePubs
     * @param list<float>                            $sourceEmbedding
     * @param list<array{label:string,slug:string}>  $related
     */
    private function renderVersion(TreeNode $node, string $sources, string $relatedFmt, array $related, array $sourcePubs, array $sourceEmbedding, string $model, string $audience, string $contributors = ''): string
    {
        // Streaming interne : la rédaction longue dépasse le timeout idle non-streamé.
        $md = '';
        foreach ($this->llm->stream(
            $this->buildMessages($node, $sources, $relatedFmt, $audience, $contributors),
            ['temperature' => 0.3, 'max_tokens' => 12000, 'model' => $model],
        ) as $delta) {
            $md .= $delta;
        }
        // Nettoie traces de raisonnement (modèles « thinking ») + bloc de code englobant.
        $md = trim((string) preg_replace('#<think>.*?</think>#is', '', $md));
        $md = trim((string) preg_replace('#^```(?:markdown|md)?\s*\n(.*)\n```$#is', '$1', $md));
        // Liens internes garantis + anti-hallucination (cran 2, gardé par RAG_VERIFY).
        $md = $this->autolink($md, $related, $node->getSlug());

        return $this->wikipedia->resolve($this->faithfulness->annotate($md, $sourcePubs, $sourceEmbedding));
    }

    /**
     * Publications réelles du corpus pour ancrer l'article (recherche sémantique).
     * Renvoie aussi l'embedding de contexte (sert à la vérification de fidélité
     * cran 2 : passages plein texte les plus pertinents par source).
     *
     * @return array{0:list<\App\Entity\Publication>,1:list<float>}
     */
    private function sourcePublications(TreeNode $node, $embedder): array
    {
        $query = $node->getLabel().'. '.(string) $node->getDescription();
        $embedding = $embedder->embed($query);
        $pubs = array_map(
            static fn (array $hit): \App\Entity\Publication => $hit['publication'],
            $this->publications->nearestTo($embedding, 12),
        );

        return [$pubs, $embedding];
    }

    /**
     * Bloc de sources formaté pour le prompt rédacteur.
     *
     * @param list<\App\Entity\Publication> $pubs
     */
    private function formatSources(array $pubs): string
    {
        $lines = [];
        foreach ($pubs as $i => $p) {
            $year = $p->getPublicationDate()?->format('Y') ?? 's.d.';
            $doi = $p->getDoi() ? 'https://doi.org/'.$p->getDoi() : '';
            $lines[] = \sprintf('[%d] %s (%s)%s', $i + 1, $p->getTitle(), $year, '' !== $doi ? ' — '.$doi : '');
        }

        return [] === $lines ? '(aucune source trouvée dans le corpus)' : implode("\n", $lines);
    }

    /**
     * Domaines liés (parents, enfants, frères) pour les liens internes.
     *
     * @return list<array{label:string,slug:string}>
     */
    private function relatedNodes(TreeNode $node): array
    {
        $seen = [];
        $out = [];
        $add = function (array $n) use (&$seen, &$out): void {
            $slug = $n['slug'] ?? null;
            $label = $n['label'] ?? null;
            if (null === $slug || null === $label || isset($seen[$slug])) {
                return;
            }
            $seen[$slug] = true;
            $out[] = ['label' => (string) $label, 'slug' => (string) $slug];
        };
        foreach ($node->getParents() as $n) {
            $add($n);
        }
        foreach (\array_slice($node->getChildren(), 0, 20) as $n) {
            $add($n);
        }
        $parents = $node->getParents();
        if (isset($parents[0]['slug']) && null !== ($parent = $this->nodes->findOneBy(['slug' => $parents[0]['slug']]))) {
            foreach (\array_slice($parent->getChildren(), 0, 12) as $n) {
                $add($n);
            }
        }

        return \array_slice($out, 0, 30);
    }

    /** @param list<array{label:string,slug:string}> $related */
    private function formatRelated(array $related): string
    {
        if ([] === $related) {
            return '(aucun)';
        }

        return implode("\n", array_map(static fn (array $r): string => \sprintf('- %s → /fr/%s', $r['label'], $r['slug']), $related));
    }

    /**
     * Lie la 1re occurrence de chaque domaine voisin dans le corps de l'article
     * (hors liens existants), pour garantir des liens internes même si le modèle
     * les a omis. Trie par libellé le plus long d'abord (évite les sous-chaînes).
     *
     * @param list<array{label:string,slug:string}> $related
     */
    private function autolink(string $md, array $related, string $selfSlug): string
    {
        usort($related, static fn (array $a, array $b): int => mb_strlen($b['label']) <=> mb_strlen($a['label']));
        foreach ($related as $r) {
            if ($r['slug'] === $selfSlug || mb_strlen($r['label']) < 4) {
                continue;
            }
            $quoted = preg_quote($r['label'], '/');
            // Pas précédé de [ / \w ; pas suivi de ] ou mot ; ignore casse + accents.
            $pattern = '/(?<![\[\/\w\p{L}])('.$quoted.')(?![\w\p{L}\]])/iu';
            $md = preg_replace($pattern, '['.'$1'.'](/fr/'.$r['slug'].')', $md, 1) ?? $md;
        }

        return $md;
    }

    /** @return list<LlmMessage> */
    private function buildMessages(TreeNode $node, string $sources, string $related, string $audience = 'adulte', string $contributors = ''): array
    {
        $system = <<<'SYS'
            Tu es un rédacteur encyclopédique scientifique francophone, du niveau de Wikipédia.
            Tu rédiges un article de RÉFÉRENCE, LONG, EXHAUSTIF, précis, neutre et sourcé, en Markdown.

            OBJECTIF
            - Synthétiser TOUS les sujets essentiels du domaine : un lecteur doit en ressortir avec une
              vue complète et structurée, sans angle mort majeur.
            - Longueur cible : 20 000 signes minimum (article fouillé ; n'abrège pas, développe chaque section).

            STYLE
            - Rigoureux, factuel, dense mais clair ; vulgarisation exacte (définis les termes techniques).
            - Ton neutre et encyclopédique. Jamais de « je », pas d'adresse au lecteur, pas de méta-commentaire
              (n'écris pas « dans cet article… »), pas de conclusion bavarde.
            - Français soigné. Unités SI. Pas de listes à puces pour le corps du texte : rédige des paragraphes
              (les puces sont réservées aux sections finales Chercheurs et Applications).

            STRUCTURE (Markdown)
            - PAS de titre de niveau 1 (#). Commence par 2–3 paragraphes d'introduction (définition, périmètre, enjeux).
            - Puis ~10 intertitres de niveau 2 (## …), avec des ### pour les sous-parties si utile, couvrant :
              objet et périmètre d'étude ; histoire et grandes étapes ; concepts et théories fondamentales ;
              formalismes/lois clés ; méthodes et instruments ; principales sous-disciplines ; résultats et
              découvertes majeurs ; débats, limites et questions ouvertes ; liens avec les domaines voisins.
            - AVANT-DERNIÈRE section « ## Chercheurs essentiels » : une liste de ~10 chercheurs, une puce chacun.
              PRINCIPE ANTI STAR-SYSTEM : la science est COLLECTIVE. Quand des « CONTRIBUTEURS RÉELS DU CORPUS »
              te sont fournis (premiers-auteurs, ceux qui ont fait le travail), PRIVILÉGIE-les ; pour eux,
              décris leur rôle de façon FACTUELLE sans rien inventer (« premier·e auteur·e de N publications très
              citées du corpus sur [sous-thème] »), n'invente NI dates NI prix.
              Tu peux compléter avec quelques figures historiques incontournables (format « - **Prénom Nom**
              (naissance–mort) — connu·e pour … — prix le cas échéant »), MAIS : attribue honnêtement (un nom-totem
              cache une équipe : écris « l'équipe de …, dont X et Y » plutôt qu'un seul homme), n'inscris pas une
              figure dont l'apport majeur est pseudoscientifique (cf. garde-fou démarcation), et un prix prestigieux
              ne vaut PAS caution sur ses autres prises de position.
            - DERNIÈRE section « ## Applications essentielles » : une liste à puces des applications concrètes
              majeures du domaine (technologies, usages, retombées), chacune en une phrase explicative.
            - Termine par « ## Références » listant les sources fournies (numéro, titre, année, lien DOI s'il existe).

            SOURCES & LIENS
            - Appuie les affirmations importantes sur les sources fournies, citées en [n] dans le texte.
            - Insère des LIENS INTERNES Markdown vers les domaines liés fournis, là où c'est pertinent :
              [libellé](/fr/slug). N'invente JAMAIS d'autre lien interne que ceux fournis.

            GARDE-FOUS (anti-hallucination — IMPÉRATIF)
            - N'invente AUCUN fait, chiffre, citation ou référence. Reste fidèle au sujet et aux sources.
            - Pour les chercheurs : n'inscris une date de naissance/mort ou un prix QUE si tu en es sûr ;
              en cas de doute, OMETS le détail incertain plutôt que de l'inventer (mieux vaut « — prix : (n.d.) »
              qu'une information fausse). Choisis des chercheurs réellement emblématiques du domaine.
            - Ne fabrique pas de DOI ; n'ajoute pas de sources qui ne te sont pas fournies.

            INTÉGRITÉ SCIENTIFIQUE (démarcation — IMPÉRATIF)
            - SciencesWiki est une encyclopédie de SCIENCE. Ne présente JAMAIS comme un savoir établi
              les théories rejetées par le consensus scientifique ou relevant de la pseudoscience
              (par ex. psychanalyse, homéopathie, astrologie, mémoire de l'eau, créationnisme).
            - Si une telle approche est historiquement rattachée au domaine, mentionne-la UNIQUEMENT pour
              expliciter son statut NON scientifique (absence de validation empirique, non-réfutabilité,
              non-reproductibilité) — jamais comme un acquis ni sur un pied d'égalité avec la science.
            - Dans « ## Chercheurs essentiels », ne liste QUE des scientifiques dont l'apport est validé par
              la méthode scientifique. N'y inscris JAMAIS une figure dont la contribution majeure est
              pseudoscientifique (par ex. Sigmund Freud pour la psychanalyse). Si une telle figure est
              incontournable historiquement, évoque-la dans le corps du texte AVEC le recul critique requis,
              pas dans la liste des chercheurs de référence.

            SORTIE
            - Réponds UNIQUEMENT par l'article en Markdown (de l'introduction à « ## Références »),
              sans préambule, sans bloc de code englobant, sans commentaire.
            SYS;

        // Cible de lectorat : « adulte » = prompt encyclopédique de référence (inchangé) ;
        // « ado »/« chercheur » = une directive PRIORITAIRE en tête redéfinit ton, longueur
        // et profondeur, sans toucher aux sources ni aux garde-fous anti-hallucination.
        if ('adulte' !== $audience) {
            $system = $this->audienceDirective($audience)."\n\n".$system;
        }

        $breadcrumb = implode(' › ', array_map(static fn (array $b): string => $b['label'] ?? '', $node->getBreadcrumb()));

        $contributorsBlock = '' !== trim($contributors)
            ? "CONTRIBUTEURS RÉELS DU CORPUS (premiers-auteurs des publications les plus citées du domaine — à PRIVILÉGIER dans « ## Chercheurs essentiels », rôle décrit FACTUELLEMENT, sans inventer dates ni prix) :\n".$contributors."\n"
            : '';

        $user = <<<TXT
            Rédige l'article encyclopédique de RÉFÉRENCE du domaine scientifique suivant, en respectant
            scrupuleusement la structure et les garde-fous.

            DOMAINE : {$node->getLabel()}
            POSITION DANS L'ARBRE : {$breadcrumb}
            DESCRIPTION EXISTANTE : {$node->getDescription()}

            SOURCES DU CORPUS (à citer en [n], à reprendre dans « ## Références ») :
            {$sources}

            LIENS INTERNES AUTORISÉS (à placer naturellement dans le texte) :
            {$related}

            {$contributorsBlock}
            Sois EXHAUSTIF : couvre l'ensemble des sujets essentiels du domaine (objet, histoire, concepts et
            théories, formalismes, méthodes, sous-disciplines, découvertes majeures, débats et limites, liens
            avec les domaines voisins). Termine impérativement par « ## Chercheurs essentiels » (liste de 10,
            avec dates naissance–mort et prix quand tu en es sûr), puis « ## Applications essentielles »
            (liste des applications concrètes majeures), puis « ## Références ». Vise au moins 20 000 signes.
            TXT;

        return [LlmMessage::system($system."\n\n".\App\Service\SettingsService::GEO_SCOPE_GUARD), LlmMessage::user($user)];
    }

    /**
     * Vrais contributeurs du domaine : PREMIERS-auteurs (souvent ceux qui ont fait
     * le travail, vs le dernier auteur = directeur/PI) des publications du corpus
     * les plus proches sémantiquement du nœud, classés par citations cumulées.
     * Ancrage DONNÉES contre le « star-system » : surface des chercheurs réels du
     * corpus (sans hallucination), pas seulement les célébrités connues du modèle.
     * NB : corpus depuis 2015 → contributeurs ACTIFS récents (les fondateurs
     * historiques restent du ressort de la connaissance du modèle).
     *
     * @param list<float> $embedding
     */
    private function topContributors(array $embedding): string
    {
        if ([] === $embedding) {
            return '';
        }
        try {
            $conn = $this->em->getConnection();
            $conn->executeStatement('SET hnsw.ef_search = 300');
            $rows = $conn->fetchAllAssociative(
                "WITH near AS (
                     SELECT id, cited_by_count
                     FROM publication
                     WHERE embedding IS NOT NULL
                     ORDER BY embedding <=> CAST(:vec AS vector)
                     LIMIT 300
                 )
                 SELECT a.name AS name, count(*) AS papers, COALESCE(sum(n.cited_by_count), 0) AS cits
                 FROM near n
                 JOIN authorship au ON au.publication_id = n.id AND au.position = 1
                 JOIN author a ON a.id = au.author_id
                 GROUP BY a.id, a.name
                 ORDER BY cits DESC, papers DESC
                 LIMIT 12",
                ['vec' => (string) new Vector($embedding)],
            );
        } catch (\Throwable) {
            return ''; // pas de contributeurs ≠ échec de génération
        }

        $lines = [];
        foreach ($rows as $r) {
            $papers = (int) $r['papers'];
            $lines[] = \sprintf(
                '- %s — premier·e auteur·e de %d publication%s du corpus (%s citations cumulées)',
                (string) $r['name'], $papers, $papers > 1 ? 's' : '', number_format((int) $r['cits'], 0, ',', ' '),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Consigne PRIORITAIRE de cible de lectorat, placée en tête du prompt système
     * pour décliner la même matière sourcée en « ado » ou « chercheur ».
     */
    private function audienceDirective(string $audience): string
    {
        if ('ado' === $audience) {
            return <<<'ADO'
                ⚑ CIBLE DE LECTORAT : ADOLESCENT (≈ 13-17 ans). CETTE CONSIGNE PRIME sur le style générique ci-dessous.
                - Vise une vulgarisation ACCESSIBLE mais EXACTE : explique CHAQUE terme technique par une analogie ou un
                  exemple concret du quotidien. Phrases courtes, ton vivant et clair, sans être enfantin ni condescendant.
                - PLUS COURT : 6 000 à 9 000 signes. Seulement ~5-6 intertitres ## (l'essentiel : c'est quoi, à quoi ça
                  sert, comment ça marche, histoire en bref, débats/limites). Pas de sous-sections ### superflues.
                - Évite le formalisme mathématique lourd ; si une formule est vraiment centrale, explique-la avec des mots.
                - Garde tout de même « ## Chercheurs essentiels » (5 suffisent), « ## Applications essentielles » et
                  « ## Références ». MÊMES garde-fous anti-hallucination : aucun fait, chiffre, date ou source inventé.
                ADO;
        }

        return <<<'CHE'
            ⚑ CIBLE DE LECTORAT : CHERCHEUR / NIVEAU AVANCÉ. CETTE CONSIGNE PRIME sur le style générique ci-dessous.
            - Registre TECHNIQUE et académique : le lecteur maîtrise les bases, n'explique pas les notions élémentaires.
            - Insiste sur les formalismes et lois clés, les méthodes et protocoles, les résultats de pointe, les
              CONTROVERSES, les LIMITES et les QUESTIONS OUVERTES, et l'état de l'art. Terminologie précise ; dense est permis.
            - LONG : 15 000 à 22 000 signes, avec davantage de sous-sections ### techniques.
            - Garde « ## Chercheurs essentiels » (10), « ## Applications essentielles » (orientées recherche/transfert) et
              « ## Références ». MÊMES garde-fous anti-hallucination : aucun DOI ni source inventé.
            CHE;
    }
}
