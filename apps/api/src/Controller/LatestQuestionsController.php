<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Answer;
use App\Repository\AnswerRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dernières Q/R publiques (accueil) : titre court, auteur (demandeur), rubrique,
 * date/heure et identifiants pour construire le lien direct.
 */
final class LatestQuestionsController
{
    public function __construct(private readonly AnswerRepository $answers)
    {
    }

    #[Route('/api/questions/latest', name: 'api_questions_latest', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $perPage = min(20, max(1, (int) $request->query->get('limit', '10')));
        $page = max(1, (int) $request->query->get('page', '1'));
        $offset = ($page - 1) * $perPage;

        // On demande un élément de plus pour savoir s'il existe une page suivante.
        $rows = $this->answers->findLatestPublic($perPage + 1, $offset);
        $hasMore = \count($rows) > $perPage;
        $rows = \array_slice($rows, 0, $perPage);

        $items = array_map(static function (Answer $a): array {
            $question = $a->getQuestion();

            return [
                'answerId' => $a->getId(),
                'title' => $question->getTitle() ?? $a->getQuestionText(),
                'question' => $a->getQuestionText(),
                'author' => $question->getAskerName() ?? 'SciencesWiki',
                'node' => $a->getNode(),
                'status' => $a->getValidationStatus()->value,
                'createdAt' => $a->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }, $rows);

        return new JsonResponse(['items' => $items, 'page' => $page, 'hasMore' => $hasMore]);
    }
}
