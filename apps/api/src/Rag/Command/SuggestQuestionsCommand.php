<?php

declare(strict_types=1);

namespace App\Rag\Command;

use App\Rag\QuestionSuggester;
use App\Repository\TreeNodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Propose des questions de vulgarisation pour un nœud (cf. spec §8.2).
 *
 *   bin/console wiki:suggest-questions --node=computer-science --count=5
 */
#[AsCommand(name: 'wiki:suggest-questions', description: 'Propose (via LLM) des questions de vulgarisation pour un nœud.')]
final class SuggestQuestionsCommand extends Command
{
    public function __construct(
        private readonly TreeNodeRepository $nodes,
        private readonly QuestionSuggester $suggester,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('node', null, InputOption::VALUE_REQUIRED, 'Slug du nœud')
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'Nombre de questions', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $node = $this->nodes->findOneBy(['slug' => (string) $input->getOption('node')]);
        if (null === $node) {
            $io->error('Nœud introuvable.');

            return Command::FAILURE;
        }

        $created = $this->suggester->suggest($node, max(1, (int) $input->getOption('count')));
        $this->em->flush();

        $io->title(\sprintf('Questions suggérées — %s', $node->getLabel()));
        foreach ($created as $question) {
            $io->writeln('  • '.$question->getText());
        }
        $io->success(\sprintf('%d nouvelle(s) question(s) créée(s).', \count($created)));

        return Command::SUCCESS;
    }
}
