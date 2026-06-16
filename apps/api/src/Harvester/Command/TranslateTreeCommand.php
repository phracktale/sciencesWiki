<?php

declare(strict_types=1);

namespace App\Harvester\Command;

use App\Ai\Llm\LlmClientFactory;
use App\Ai\Llm\LlmMessage;
use App\Repository\TreeNodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Traduit en français les intitulés des nœuds de l'arbre (amorcés en anglais
 * depuis OpenAlex). Les slugs (URLs) et les embeddings (dérivés de l'anglais,
 * utiles au placement kNN sur des résumés anglais) restent inchangés.
 *
 *   bin/console app:translate-tree --batch=25
 */
#[AsCommand(name: 'app:translate-tree', description: 'Traduit en français les intitulés des nœuds de l\'arbre (via LLM).')]
final class TranslateTreeCommand extends Command
{
    public function __construct(
        private readonly LlmClientFactory $llmFactory,
        private readonly TreeNodeRepository $nodes,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Nombre d\'intitulés par appel LLM', '25');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $llm = $this->llmFactory->create();
        $batchSize = max(1, (int) $input->getOption('batch'));

        /** @var list<\App\Entity\TreeNode> $all */
        $all = $this->nodes->findAll();
        $io->title(\sprintf('Traduction FR de %d intitulés (modèle %s, lots de %d)', \count($all), $llm->model(), $batchSize));
        $io->progressStart(\count($all));

        $translated = 0;
        foreach (array_chunk($all, $batchSize) as $chunk) {
            $labels = array_map(static fn (\App\Entity\TreeNode $n): string => $n->getLabel(), $chunk);
            $map = $this->translateBatch($llm, $labels);

            foreach ($chunk as $i => $node) {
                $fr = $map[$i + 1] ?? null;
                if (null !== $fr && '' !== $fr && $fr !== $node->getLabel()) {
                    $node->setLabel(mb_substr($fr, 0, 512));
                    ++$translated;
                }
                $io->progressAdvance();
            }
            $this->em->flush();
        }

        $io->progressFinish();
        $io->success(\sprintf('%d intitulé(s) traduit(s).', $translated));

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $labels
     *
     * @return array<int,string> numéro de ligne (1-based) => traduction
     */
    private function translateBatch(\App\Ai\Llm\LlmClient $llm, array $labels): array
    {
        $numbered = '';
        foreach ($labels as $i => $label) {
            $numbered .= ($i + 1).'. '.$label."\n";
        }

        $completion = $llm->complete([
            LlmMessage::system(
                'Tu es traducteur scientifique anglais→français. On te donne une liste numérotée '
                ."d'intitulés de disciplines/notions scientifiques. Traduis chacun en français "
                .'(terminologie académique usuelle). Réponds UNIQUEMENT par la liste numérotée traduite, '
                .'une ligne par entrée, au format "N. traduction", sans commentaire ni texte additionnel. '
                .'Conserve les noms propres et acronymes établis.'
            ),
            LlmMessage::user($numbered),
        ], ['temperature' => 0.1]);

        $map = [];
        foreach (preg_split('/\r?\n/', $completion->content) ?: [] as $line) {
            if (preg_match('/^\s*(\d+)[.):]\s*(.+?)\s*$/u', $line, $m)) {
                $map[(int) $m[1]] = $m[2];
            }
        }

        return $map;
    }
}
