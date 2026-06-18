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
        private readonly \App\Rag\QuestionSuggester $suggester,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * L'IA imagine et pose une question sur la rubrique courante (cf. spec §8.2),
     * puis le front en streame la réponse. Rate-limité par IP comme la pose libre.
     */
    #[Route('/api/questions/suggest', name: 'api_question_suggest', methods: ['POST'])]
    public function suggest(Request $request): JsonResponse
    {
        /** @var array<string,mixed> $data */
        $data = json_decode($request->getContent() ?: '[]', true) ?? [];
        $node = '' !== trim((string) ($data['node'] ?? '')) ? $this->nodes->findOneBy(['slug' => trim((string) $data['node'])]) : null;
        if (null === $node) {
            return $this->error('Rubrique introuvable.', 404);
        }

        $ip = $request->getClientIp() ?? '0.0.0.0';
        if ($this->questions->countRecentByIp($ip, new \DateTimeImmutable('-1 hour')) >= self::RATE_LIMIT_PER_HOUR) {
            return $this->error('Trop de demandes récentes. Merci de réessayer dans un moment.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        // La génération de la question passe par le LLM local (gemma4, inférence CPU
        // sur Marvin) : on lève la limite de 30 s de PHP, sinon la requête échoue
        // avant la réponse du modèle (comme le fait le streaming des réponses).
        @set_time_limit(0);

        $created = $this->suggester->suggest($node, 1);
        if ([] === $created) {
            return $this->error('L\'IA n\'a pas pu proposer de question sur cette rubrique pour le moment.', 422);
        }
        $question = $created[0];
        $question->setAskerName('IA — SciencesWiki')->setAskerIp($ip);
        $this->em->flush();

        return new JsonResponse([
            'id' => $question->getId(),
            'node' => $node->getSlug(),
            'question' => $question->getText(),
            'stream' => '/api/questions/'.$question->getId().'/stream',
        ], Response::HTTP_CREATED);
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
