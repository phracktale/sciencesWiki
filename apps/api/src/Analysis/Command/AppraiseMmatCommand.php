<?php

declare(strict_types=1);

namespace App\Analysis\Command;

use App\Analysis\Mmat\MmatAppraiser;
use App\Analysis\Mmat\MmatSerializer;
use App\Repository\PublicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Évalue par la grille MMAT UNE publication (par id), en synchrone. Outil de
 * debug/backfill : en exploitation, c'est le déclenchement à la demande (worker
 * « analysis ») qui applique cette évaluation.
 *
 *   bin/console analysis:appraise-mmat --id=12345 --reappraise
 */
#[AsCommand(name: 'analysis:appraise-mmat', description: 'Évalue (MMAT) une étude empirique par id (LLM).')]
final class AppraiseMmatCommand extends Command
{
    public function __construct(
        private readonly MmatAppraiser $appraiser,
        private readonly MmatSerializer $serializer,
        private readonly PublicationRepository $publications,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Identifiant de la publication à évaluer')
            ->addOption('reappraise', null, InputOption::VALUE_NONE, 'Ré-évalue même si déjà traitée');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $id = (int) $input->getOption('id');
        if ($id <= 0) {
            $io->error('L\'option --id=<n> est requise.');

            return Command::FAILURE;
        }
        $publication = $this->publications->find($id);
        if (null === $publication) {
            $io->error(\sprintf('Publication #%d introuvable.', $id));

            return Command::FAILURE;
        }

        $io->section(\sprintf('#%d — %s', $id, (string) $publication->getTitle()));

        $appraisal = $this->appraiser->appraiseForPublication($publication, null, (bool) $input->getOption('reappraise'));
        if (null === $appraisal) {
            $io->warning('Aucune évaluation produite (texte source vide ou sortie LLM indécodable).');

            return Command::FAILURE;
        }
        $this->em->flush();

        $data = $this->serializer->serialize($appraisal);
        $io->definitionList(
            ['Applicabilité' => $data['applicabilityLabel']],
            ['Catégorie' => $data['categoryLabel']],
            ['Design détecté' => (string) ($data['studyDesign'] ?? '—')],
            ['Filtrage' => $data['screeningPassed'] ? 'satisfait' : 'non satisfait'],
            ['Critères remplis' => $data['metCount'].'/5'],
            ['Qualité indicative' => $data['overallLabel']],
            ['Source' => $data['sourceScope']],
        );
        if (\is_array($data['items'] ?? null) && [] !== $data['items']) {
            $io->text('Critères :');
            foreach ($data['items'] as $it) {
                $io->text(\sprintf('  [%s] %s', $it['answerLabel'], $it['question']));
            }
        }
        if (null !== ($data['summary'] ?? null)) {
            $io->newLine();
            $io->text('Synthèse : '.$data['summary']);
        }

        $io->success('Évaluation MMAT enregistrée.');

        return Command::SUCCESS;
    }
}
