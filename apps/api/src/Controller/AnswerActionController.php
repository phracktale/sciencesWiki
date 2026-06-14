<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AnswerRevision;
use App\Entity\Footnote;
use App\Entity\User;
use App\Enum\RevisionAuthorType;
use App\Rag\AnswerValidator;
use App\Repository\AnswerRepository;
use App\Security\Voter\AnswerVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Actions de gouvernance sur les réponses, réservées aux rôles compétents
 * (cf. spec §8.4/§8.6). Authentification JWT + AnswerVoter.
 */
final class AnswerActionController
{
    public function __construct(
        private readonly AnswerRepository $answers,
        private readonly AnswerValidator $validator,
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Validation par un membre du comité compétent sur le domaine. */
    #[Route('/api/answers/{id}/validate', name: 'api_answer_validate', methods: ['POST'])]
    public function validate(int $id): JsonResponse
    {
        $answer = $this->answers->find($id) ?? throw new NotFoundHttpException('Réponse introuvable.');

        if (!$this->security->isGranted(AnswerVoter::VALIDATE, $answer)) {
            throw new AccessDeniedHttpException('Validation réservée au comité compétent sur ce domaine.');
        }

        $reviewer = $this->security->getUser();
        $this->validator->validate($answer, $reviewer instanceof User ? $reviewer : null);
        $this->em->flush();

        return new JsonResponse([
            'id' => $answer->getId(),
            'validationStatus' => $answer->getValidationStatus()->value,
            'validatedBy' => $reviewer instanceof User ? $reviewer->getDisplayName() : null,
        ]);
    }

    /** Édition par un rédacteur : nouvelle révision signée par l'utilisateur. */
    #[Route('/api/answers/{id}/revise', name: 'api_answer_revise', methods: ['POST'])]
    public function revise(int $id, Request $request): JsonResponse
    {
        $answer = $this->answers->find($id) ?? throw new NotFoundHttpException('Réponse introuvable.');

        if (!$this->security->isGranted(AnswerVoter::EDIT, $answer)) {
            throw new AccessDeniedHttpException('Édition réservée aux rédacteurs.');
        }

        /** @var User $user */
        $user = $this->security->getUser();
        $payload = json_decode($request->getContent() ?: '{}', true);
        $previous = $answer->getLatestRevision();

        $authorType = $this->security->isGranted('ROLE_COMITE')
            ? RevisionAuthorType::Committee
            : RevisionAuthorType::Contributor;

        $revision = (new AnswerRevision($authorType))
            ->setAuthor($user)
            ->setAcademicContent((string) ($payload['academic'] ?? $previous?->getAcademicContent() ?? ''))
            ->setVulgarizationContent((string) ($payload['vulgarization'] ?? $previous?->getVulgarizationContent() ?? ''))
            ->setChangeSummary((string) ($payload['summary'] ?? 'Révision'))
            ->setParentRevision($previous);

        // Conserve les sources de la révision précédente.
        if (null !== $previous) {
            foreach ($previous->getFootnotes() as $footnote) {
                $revision->addFootnote(new Footnote($footnote->getPublication(), $footnote->getMarker()));
            }
        }

        $answer->addRevision($revision);
        $this->em->persist($revision);
        $this->em->flush();

        return new JsonResponse([
            'id' => $answer->getId(),
            'revisionId' => $revision->getId(),
            'signature' => $answer->getSignature(),
        ], 201);
    }
}
