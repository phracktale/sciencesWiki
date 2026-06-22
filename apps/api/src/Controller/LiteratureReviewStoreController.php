<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\LiteratureReview;
use App\Entity\User;
use App\Repository\LiteratureReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bibliothèque des revues de littérature sauvegardées (espace chercheur).
 * Accès réservé à ROLE_RESEARCHER (cf. security.yaml) ; chaque opération est
 * cantonnée aux revues de l'utilisateur connecté.
 */
final class LiteratureReviewStoreController
{
    private const MAX_CONTENT = 200_000; // garde-fou taille (~200 Ko)

    public function __construct(
        private readonly LiteratureReviewRepository $repository,
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/literature-reviews', name: 'api_litreview_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        /** @var array<string,mixed> $data */
        $data = json_decode($request->getContent() ?: '[]', true) ?? [];

        $topic = trim((string) ($data['topic'] ?? ''));
        $markdown = (string) ($data['markdown'] ?? '');
        if ('' === $topic || '' === trim($markdown)) {
            return new JsonResponse(['error' => 'Sujet et contenu requis.'], 400);
        }
        if (\strlen($markdown) > self::MAX_CONTENT) {
            return new JsonResponse(['error' => 'Revue trop volumineuse.'], 413);
        }
        $sources = \is_array($data['sources'] ?? null) ? $data['sources'] : [];
        $rubric = '' !== trim((string) ($data['rubric'] ?? '')) ? (string) $data['rubric'] : null;

        $review = new LiteratureReview($user, $topic, $markdown, $sources, $rubric);
        $this->em->persist($review);
        $this->em->flush();

        return new JsonResponse(['id' => $review->getId()], 201);
    }

    #[Route('/api/literature-reviews', name: 'api_litreview_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $items = array_map(static fn (LiteratureReview $r): array => [
            'id' => $r->getId(),
            'topic' => $r->getTopic(),
            'rubric' => $r->getRubric(),
            'createdAt' => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'sourceCount' => \count($r->getSources()),
        ], $this->repository->findByUser($this->currentUser()));

        return new JsonResponse(['items' => $items]);
    }

    #[Route('/api/literature-reviews/{id}', name: 'api_litreview_get', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        $review = $this->owned($id);

        return new JsonResponse([
            'id' => $review->getId(),
            'topic' => $review->getTopic(),
            'rubric' => $review->getRubric(),
            'markdown' => $review->getContentMd(),
            'sources' => $review->getSources(),
            'createdAt' => $review->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/api/literature-reviews/{id}', name: 'api_litreview_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $this->em->remove($this->owned($id));
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    private function currentUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentification requise.');
        }

        return $user;
    }

    private function owned(int $id): LiteratureReview
    {
        $review = $this->repository->find($id) ?? throw new NotFoundHttpException('Revue introuvable.');
        if ($review->getUser()->getId() !== $this->currentUser()->getId() && !$this->security->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedHttpException('Accès refusé.');
        }

        return $review;
    }
}
