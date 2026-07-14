<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Answer;
use App\Entity\TreeNode;
use App\Repository\AnswerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * File de relecture du COMITÉ scientifique (accès ROLE_COMITE / ROLE_MODERATEUR, cf.
 * security.yaml). Liste les contenus en attente de validation — réponses (Q/R) et articles
 * de rubrique — pour que le comité les traite depuis un tableau de bord unique. La validation
 * elle-même passe par les endpoints existants (POST /api/answers/{id}/validate,
 * POST /api/nodes/{id}/article/validate), soumis aux voters de compétence par domaine.
 */
final class CommitteeController
{
    public function __construct(
        private readonly AnswerRepository $answers,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/committee/queue', name: 'api_committee_queue', methods: ['GET'])]
    public function queue(): JsonResponse
    {
        $answers = array_map(
            static fn (Answer $a): array => [
                'id' => $a->getId(),
                'title' => $a->getTitle(),
                'questionId' => $a->getQuestionId(),
                'node' => $a->getNode(),
                'status' => $a->getValidationStatus()->value,
                'needsRevalidation' => $a->needsRevalidation(),
                'createdAt' => $a->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
            $this->answers->findForCommittee(),
        );

        /** @var list<TreeNode> $nodes */
        $nodes = $this->em->createQuery(
            'SELECT n FROM App\Entity\TreeNode n
             WHERE n.articleStatus = :s AND n.articleMd IS NOT NULL
             ORDER BY n.articleGeneratedAt DESC',
        )->setParameter('s', 'non_relu')->setMaxResults(100)->getResult();

        $articles = array_map(
            static fn (TreeNode $n): array => [
                'id' => $n->getId(),
                'slug' => $n->getSlug(),
                'label' => $n->getLabel(),
                'generatedAt' => $n->getArticleGeneratedAt()?->format(\DateTimeInterface::ATOM),
            ],
            $nodes,
        );

        return new JsonResponse([
            'answers' => $answers,
            'articles' => $articles,
            'counts' => ['answers' => \count($answers), 'articles' => \count($articles)],
        ]);
    }
}
