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
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        #[Autowire(env: 'LLM_MODEL')]
        private readonly string $llmModel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre d’articles à générer', '3');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Régénère même les nœuds ayant déjà un article');
        $this->addOption('slug', null, InputOption::VALUE_REQUIRED, 'Cible un nœud précis (par slug)');
        $this->addOption('max-level', null, InputOption::VALUE_REQUIRED, 'Limite aux nœuds de niveau ≤ N (0=domaines…)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @set_time_limit(0);
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));

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
                $sources = $this->sources($node, $embedder);
                $related = $this->relatedNodes($node);
                // Streaming : la rédaction longue dépasse le timeout idle d'un appel
                // non-streamé ; le flux remet le compteur à zéro à chaque jeton.
                $md = '';
                foreach ($this->llm->stream(
                    $this->buildMessages($node, $sources, $this->formatRelated($related)),
                    ['temperature' => 0.3, 'max_tokens' => 8000],
                ) as $delta) {
                    $md .= $delta;
                }
                $md = trim($md);
                // Liens internes garantis (le modèle local les omet souvent) : on lie
                // la 1re occurrence de chaque domaine voisin dans le corps de l'article.
                $md = $this->autolink($md, $related, $node->getSlug());
                if (mb_strlen($md) < 800) {
                    $io->warning(\sprintf('  réponse trop courte (%d car.), ignorée.', mb_strlen($md)));
                    continue;
                }
                $node->setArticleMd($md)
                    ->setArticleModel($this->llmModel)
                    ->setArticleStatus('non_relu')
                    ->setArticleGeneratedAt(new \DateTimeImmutable());
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

    /** Sources réelles du corpus pour ancrer l'article (recherche sémantique). */
    private function sources(TreeNode $node, $embedder): string
    {
        $query = $node->getLabel().'. '.(string) $node->getDescription();
        $embedding = $embedder->embed($query);
        $hits = $this->publications->nearestTo($embedding, 12);

        $lines = [];
        foreach ($hits as $i => $hit) {
            $p = $hit['publication'];
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
            Tu rédiges des articles LONGS, précis, neutres, structurés et sourcés, en Markdown.
            Règles STRICTES :
            - Longueur cible ≈ 20 000 signes (article complet et fouillé).
            - Environ 10 intertitres de niveau 2 (## …), éventuellement des ### pour les sous-parties.
            - Pas de titre de niveau 1 (#) : commence directement par un paragraphe d'introduction.
            - Style : rigoureux, factuel, accessible mais exact. Pas de « je », pas de méta-commentaire.
            - Cite les travaux fournis dans le texte sous la forme [n] et termine par une section « ## Références »
              listant ces sources (numéro, titre, année, lien DOI s'il existe).
            - Insère des LIENS INTERNES en Markdown vers les domaines liés fournis, là où c'est pertinent,
              au fil du texte : [libellé](/fr/slug). N'invente JAMAIS d'autre lien interne que ceux fournis.
            - N'invente pas de faits ni de chiffres : reste fidèle au sujet et aux sources.
            - Réponds UNIQUEMENT par l'article en Markdown, sans préambule ni conclusion hors-sujet.
            SYS;

        $breadcrumb = implode(' › ', array_map(static fn (array $b): string => $b['label'] ?? '', $node->getBreadcrumb()));

        $user = <<<TXT
            Rédige l'article encyclopédique du domaine scientifique suivant.

            DOMAINE : {$node->getLabel()}
            POSITION DANS L'ARBRE : {$breadcrumb}
            DESCRIPTION EXISTANTE : {$node->getDescription()}

            SOURCES DU CORPUS (à citer en [n], réutilise-les dans « ## Références ») :
            {$sources}

            LIENS INTERNES AUTORISÉS (à placer naturellement dans le texte) :
            {$related}

            Décris avec précision en quoi consiste ce domaine : objet d'étude, histoire et grandes
            étapes, concepts et théories fondamentales, méthodes, sous-disciplines, applications,
            débats/limites actuels, et liens avec les domaines voisins. Vise ≈ 20 000 signes.
            TXT;

        return [LlmMessage::system($system), LlmMessage::user($user)];
    }
}
