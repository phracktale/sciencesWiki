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

        $embedder = $this->embeddings->create();
        $done = 0;
        foreach ($targets as $node) {
            $io->writeln(\sprintf('• %s (niveau %d)…', $node->getLabel(), $node->getLevel()));
            try {
                [$sourcePubs, $sourceEmbedding] = $this->sourcePublications($node, $embedder);
                $sources = $this->formatSources($sourcePubs);
                $related = $this->relatedNodes($node);
                // Streaming : la rédaction longue dépasse le timeout idle d'un appel
                // non-streamé ; le flux remet le compteur à zéro à chaque jeton.
                $md = '';
                foreach ($this->llm->stream(
                    $this->buildMessages($node, $sources, $this->formatRelated($related)),
                    ['temperature' => 0.3, 'max_tokens' => 12000, 'model' => $model],
                ) as $delta) {
                    $md .= $delta;
                }
                // Retire d'éventuelles traces de raisonnement (modèles « thinking » type Qwen3)
                // + un éventuel bloc de code englobant ```markdown … ```.
                $md = preg_replace('#<think>.*?</think>#is', '', $md) ?? $md;
                $md = trim($md);
                $md = preg_replace('#^```(?:markdown|md)?\s*\n(.*)\n```$#is', '$1', $md) ?? $md;
                $md = trim($md);
                // Liens internes garantis (le modèle local les omet souvent) : on lie
                // la 1re occurrence de chaque domaine voisin dans le corps de l'article.
                $md = $this->autolink($md, $related, $node->getSlug());
                // Anti-hallucination (cran 2) : marque les affirmations non soutenues
                // par les PASSAGES plein texte des sources, puis promeut les marqueurs
                // en liens Wikipédia réels. Gardé par le réglage RAG_VERIFY.
                $md = $this->wikipedia->resolve($this->faithfulness->annotate($md, $sourcePubs, $sourceEmbedding));
                if (mb_strlen($md) < 800) {
                    $io->warning(\sprintf('  réponse trop courte (%d car.), ignorée.', mb_strlen($md)));
                    continue;
                }
                $node->setArticleMd($md)
                    ->setArticleModel($model)
                    ->setArticleStatus('non_relu')
                    ->setArticleGeneratedAt(new \DateTimeImmutable());
                // Révision (historique + diff en back-office) — paternité IA.
                $this->em->persist(
                    (new \App\Entity\ArticleRevision($node, $md, 'ia'))
                        ->setAuthorLabel($model)
                        ->setChangeSummary('Génération IA')
                );
                $this->em->flush();
                ++$done;
                $io->writeln(\sprintf('  ✓ %d signes', mb_strlen($md)));
            } catch (\Throwable $e) {
                $io->warning(\sprintf('  échec : %s', $e->getMessage()));
            }
        }

        $io->success(\sprintf('%d article(s) rédigé(s).', $done));

        return Command::SUCCESS;
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
    private function buildMessages(TreeNode $node, string $sources, string $related): array
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
            - AVANT-DERNIÈRE section « ## Chercheurs essentiels » : une liste de 10 chercheurs marquants du domaine,
              une puce chacun, au format :
              « - **Prénom Nom** (année de naissance–année de mort, ou « né en … » si vivant) — connu·e pour … — prix majeur(s) le cas échéant ».
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

            SORTIE
            - Réponds UNIQUEMENT par l'article en Markdown (de l'introduction à « ## Références »),
              sans préambule, sans bloc de code englobant, sans commentaire.
            SYS;

        $breadcrumb = implode(' › ', array_map(static fn (array $b): string => $b['label'] ?? '', $node->getBreadcrumb()));

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

            Sois EXHAUSTIF : couvre l'ensemble des sujets essentiels du domaine (objet, histoire, concepts et
            théories, formalismes, méthodes, sous-disciplines, découvertes majeures, débats et limites, liens
            avec les domaines voisins). Termine impérativement par « ## Chercheurs essentiels » (liste de 10,
            avec dates naissance–mort et prix quand tu en es sûr), puis « ## Applications essentielles »
            (liste des applications concrètes majeures), puis « ## Références ». Vise au moins 20 000 signes.
            TXT;

        return [LlmMessage::system($system."\n\n".\App\Service\SettingsService::GEO_SCOPE_GUARD), LlmMessage::user($user)];
    }
}
