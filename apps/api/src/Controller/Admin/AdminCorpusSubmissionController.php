<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CorpusSubmission;
use App\Entity\User;
use App\Enum\SubmissionStatus;
use App\Harvester\Ai\PublicationEmbedder;
use App\Repository\CorpusSubmissionRepository;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Back-office « Études proposées » (ROLE_ADMIN) : file des dépôts utilisateur en
 * attente d'intégration au corpus + décision comité. À l'ACCEPTATION, l'étude devient
 * publique (listed_in_corpus=true) et est vectorisée (embed immédiat + placement par
 * le drain) ; au REFUS, elle reste privée à son uploadeur.
 */
final class AdminCorpusSubmissionController
{
    public function __construct(
        private readonly CorpusSubmissionRepository $submissions,
        private readonly PublicationEmbedder $embedder,
        private readonly EntityManagerInterface $em,
        private readonly ActivityLogger $activity,
        private readonly Security $security,
    ) {
    }

    #[Route('/api/admin/corpus-submissions', name: 'admin_corpus_submissions', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $items = array_map(function (CorpusSubmission $s): array {
            $p = $s->getPublication();

            return [
                'id' => $s->getId(),
                'submittedAt' => $s->getSubmittedAt()->format(\DateTimeInterface::ATOM),
                'note' => $s->getNote(),
                'submittedBy' => $s->getSubmittedBy()?->getDisplayName(),
                'publication' => [
                    'id' => $p->getId(),
                    'title' => $p->getTitle(),
                    'year' => $p->getPublicationDate()?->format('Y'),
                    'doi' => $p->getDoi(),
                    'venue' => $p->getVenue(),
                    'abstract' => mb_substr((string) $p->getAbstract(), 0, 400),
                ],
            ];
        }, $this->submissions->findPending(100));

        return new JsonResponse(['items' => $items, 'pending' => $this->submissions->countPending()]);
    }

    #[Route('/api/admin/corpus-submissions/{id}/review', name: 'admin_corpus_submission_review', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function review(int $id, Request $request): JsonResponse
    {
        $submission = $this->submissions->find($id);
        if (null === $submission) {
            return new JsonResponse(['error' => 'Proposition introuvable.'], 404);
        }
        /** @var array<string,mixed> $body */
        $body = json_decode($request->getContent() ?: '[]', true) ?? [];
        $decision = (string) ($body['decision'] ?? '');
        $status = match ($decision) {
            'approve' => SubmissionStatus::Approved,
            'reject' => SubmissionStatus::Rejected,
            default => null,
        };
        if (null === $status) {
            return new JsonResponse(['error' => 'Décision invalide (approve|reject).'], 422);
        }

        $reviewer = $this->security->getUser();
        $submission->review($status, $reviewer instanceof User ? $reviewer : null);
        $publication = $submission->getPublication();

        $embedded = false;
        if (SubmissionStatus::Approved === $status) {
            // Intègre l'étude au corpus public : désormais recherchable + plaçable.
            $publication->setListedInCorpus(true);
            // Embed immédiat (recherche sémantique tout de suite) ; le placement dans
            // l'arbre suit via le drain habituel. Non bloquant si le service ML est down.
            try {
                $this->embedder->embed($publication);
                $embedded = true;
            } catch (\Throwable) {
                // Repli : le drain d'embedding rattrapera (listed_in_corpus=true).
            }
        }
        $this->em->flush();

        $this->activity->log(
            'moderation',
            'corpus_submission_'.$decision,
            $this->security->getUser()?->getUserIdentifier() ?? 'admin',
            \sprintf('%s : « %s »', $status->label(), $publication->getTitle()),
            ['publicationId' => $publication->getId(), 'submissionId' => $id],
            $request->getClientIp(),
        );

        return new JsonResponse(['ok' => true, 'status' => $status->value, 'embedded' => $embedded]);
    }
}
