<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Question;
use App\Enum\QuestionOrigin;
use App\Rag\Message\GenerateArticleMessage;
use App\Repository\QuestionRepository;
use App\Repository\TreeNodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Génération d'un article de vulgarisation À LA DEMANDE, en ASYNCHRONE (≠ pose de question
 * interactive + stream). Crée la question, met la rédaction en file (pipeline 2 appels) et
 * répond « en cours ». Le demandeur est averti par e-mail à la fin (si un e-mail est fourni).
 */
final class GenerateArticleController
{
    private const RATE_LIMIT_PER_HOUR = 5;

    public function __construct(
        private readonly TreeNodeRepository $nodes,
        private readonly QuestionRepository $questions,
        private readonly \App\Service\ActivityLogger $activity,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/api/articles/generate', name: 'api_article_generate', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array<string,mixed> $data */
        $data = json_decode($request->getContent() ?: '[]', true) ?? [];
        $name = trim((string) ($data['name'] ?? ''));
        $text = trim((string) ($data['topic'] ?? ($data['question'] ?? '')));
        $slug = trim((string) ($data['node'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));

        if (mb_strlen($text) < 8) {
            return new JsonResponse(['error' => 'Sujet trop court (8 caractères minimum).'], 422);
        }
        if ('' !== $email && false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'Adresse e-mail invalide.'], 422);
        }
        $node = '' !== $slug ? $this->nodes->findOneBy(['slug' => $slug]) : null;
        if (null === $node) {
            return new JsonResponse(['error' => 'Rubrique introuvable.'], 404);
        }

        $ip = $request->getClientIp() ?? '0.0.0.0';
        if ($this->questions->countRecentByIp($ip, new \DateTimeImmutable('-1 hour')) >= self::RATE_LIMIT_PER_HOUR) {
            return new JsonResponse(['error' => 'Trop de demandes récentes. Merci de réessayer plus tard.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $question = $this->questions->findOneByNodeAndText($node, $text) ?? new Question($node, $text, QuestionOrigin::FreeUser);
        $question->setAskerName('' !== $name ? $name : ('' !== $email ? $email : 'Anonyme'))->setAskerIp($ip);
        $this->em->persist($question);
        $this->em->flush();

        $this->bus->dispatch(new GenerateArticleMessage((int) $question->getId(), '' !== $email ? $email : null));
        $this->activity->log('article', 'generate', '' !== $name ? $name : ($email ?: 'anonyme'), \sprintf('Article demandé sous « %s » : %s', $node->getLabel(), $text), ['node' => $node->getSlug(), 'questionId' => $question->getId()], $ip);

        return new JsonResponse([
            'questionId' => $question->getId(),
            'message' => 'Rédaction lancée. Elle n\'est pas instantanée'.('' !== $email ? ' ; vous serez averti par e-mail à '.$email.' dès qu\'elle sera prête.' : '.'),
        ], Response::HTTP_ACCEPTED);
    }
}
