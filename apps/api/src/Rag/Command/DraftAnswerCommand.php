<?php

declare(strict_types=1);

namespace App\Rag\Command;

use App\Entity\Question;
use App\Enum\AnswerType;
use App\Enum\QuestionOrigin;
use App\Rag\AnswerDrafter;
use App\Repository\QuestionRepository;
use App\Repository\TreeNodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Génère un brouillon de réponse (Q/R) ancré RAG pour une question rattachée à
 * un nœud (cf. spec §8.2). Le brouillon part en relecture — il n'est pas publié.
 *
 *   bin/console wiki:draft-answer --node=computer-science --question="Qu'est-ce que l'apprentissage automatique ?"
 */
#[AsCommand(name: 'wiki:draft-answer', description: 'Génère un brouillon de réponse vulgarisée (RAG) pour une question.')]
final class DraftAnswerCommand extends Command
{
    public function __construct(
        private readonly TreeNodeRepository $nodes,
        private readonly QuestionRepository $questions,
        private readonly AnswerDrafter $drafter,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('node', null, InputOption::VALUE_REQUIRED, 'Slug du nœud de rattachement')
            ->addOption('question', null, InputOption::VALUE_REQUIRED, 'Texte de la question')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Type de Q/R : canonique | libre', 'canonique')
            ->addOption('neighbors', 'k', InputOption::VALUE_REQUIRED, 'Nombre de sources à récupérer', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $slug = (string) $input->getOption('node');
        $text = trim((string) $input->getOption('question'));
        if ('' === $slug || '' === $text) {
            $io->error('--node et --question sont requis.');

            return Command::FAILURE;
        }

        $node = $this->nodes->findOneBy(['slug' => $slug]);
        if (null === $node) {
            $io->error(\sprintf('Nœud introuvable : « %s ».', $slug));

            return Command::FAILURE;
        }

        $type = 'libre' === strtolower((string) $input->getOption('type')) ? AnswerType::Free : AnswerType::Canonical;
        $k = max(1, (int) $input->getOption('neighbors'));

        // Déduplication : réutilise la question si elle existe déjà sur ce nœud.
        $question = $this->questions->findOneByNodeAndText($node, $text);
        if (null === $question) {
            $origin = AnswerType::Free === $type ? QuestionOrigin::FreeUser : QuestionOrigin::SuggeredByAi;
            $question = new Question($node, $text, $origin);
        } else {
            $question->incrementAskCount();
        }

        $io->title(\sprintf('Rédaction RAG — « %s »', $node->getLabel()));

        $answer = $this->drafter->draft($question, $type, $k);
        $this->em->flush();

        $revision = $answer->getLatestRevision();
        $io->definitionList(
            ['Question' => $text],
            ['Type' => $answer->getType()->value],
            ['Statut' => $answer->getValidationStatus()->value],
            ['Sources citées' => (string) ($revision?->getFootnotes()->count() ?? 0)],
        );
        $io->section('Bloc vulgarisation (extrait)');
        $io->writeln(mb_substr($revision?->getVulgarizationContent() ?? '', 0, 400));

        $io->success('Brouillon généré et placé en relecture (non publié).');

        return Command::SUCCESS;
    }
}
