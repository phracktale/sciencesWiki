<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Question;
use App\Enum\QuestionOrigin;
use App\Repository\QuestionRepository;
use App\Repository\TreeNodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pose d'une question libre par un visiteur (cf. spec §8.2). Anonyme côté compte,
 * mais nom/pseudo obligatoire et IP captée (audit + anti-abus). La rédaction
 * n'est PAS faite ici : le front ouvre ensuite le flux /api/questions/{id}/stream.
 */
final class AskQuestionController
{
    private const RATE_LIMIT_PER_HOUR = 5;

    public function __construct(
        private readonly TreeNodeRepository $nodes,
        private readonly QuestionRepository $questions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/questions/ask', name: 'api_question_ask', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array<string,mixed> $data */
        $data = json_decode($request->getContent() ?: '[]', true) ?? [];
        $name = trim((string) ($data['name'] ?? ''));
        $text = trim((string) ($data['question'] ?? ''));
        $slug = trim((string) ($data['node'] ?? ''));

        if ('' === $name) {
            return $this->error('Un nom ou pseudonyme est obligatoire pour poser une question.', 422);
        }
        if (mb_strlen($text) < 8) {
            return $this->error('Votre question est trop courte (8 caractères minimum).', 422);
        }

        $node = '' !== $slug ? $this->nodes->findOneBy(['slug' => $slug]) : null;
        if (null === $node) {
            return $this->error('Rubrique introuvable.', 404);
        }

        $ip = $request->getClientIp() ?? '0.0.0.0';
        if ($this->questions->countRecentByIp($ip, new \DateTimeImmutable('-1 hour')) >= self::RATE_LIMIT_PER_HOUR) {
            return $this->error('Trop de questions posées récemment. Merci de réessayer dans un moment.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Déduplication : réutilise une question identique déjà posée sur ce nœud.
        $question = $this->questions->findOneByNodeAndText($node, $text);
        if (null === $question) {
            $question = new Question($node, $text, QuestionOrigin::FreeUser);
        } else {
            $question->incrementAskCount();
        }
        $question->setAskerName($name)->setAskerIp($ip);

        $this->em->persist($question);
        $this->em->flush();

        return new JsonResponse([
            'id' => $question->getId(),
            'node' => $node->getSlug(),
            'stream' => '/api/questions/'.$question->getId().'/stream',
        ], Response::HTTP_CREATED);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
