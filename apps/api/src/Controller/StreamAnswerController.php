<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\Llm\LlmClientFactory;
use App\Enum\AnswerType;
use App\Rag\AnswerDrafter;
use App\Repository\AnswerRepository;
use App\Repository\QuestionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Rédaction RAG d'une question libre, diffusée en flux (SSE) pour un affichage
 * « lettre par lettre ». À la fin, la réponse est persistée (non relue, publique
 * avec bandeau ⚠️ — cf. spec §8.4). Si la réponse existe déjà, on la renvoie d'un coup.
 *
 * Événements SSE : {"delta":"…"} au fil de l'eau, puis {"done":true,"answerId":N,"node":"slug"}.
 */
final class StreamAnswerController
{
    public function __construct(
        private readonly QuestionRepository $questions,
        private readonly AnswerRepository $answers,
        private readonly AnswerDrafter $drafter,
        private readonly LlmClientFactory $llmFactory,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/questions/{id}/stream', name: 'api_question_stream', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function __invoke(int $id): Response
    {
        $question = $this->questions->find($id);
        if (null === $question) {
            return new JsonResponse(['error' => 'Question introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $response = new StreamedResponse(function () use ($question): void {
            @set_time_limit(0);
            $send = static function (array $payload): void {
                echo 'data: '.json_encode($payload, \JSON_UNESCAPED_UNICODE)."\n\n";
                @ob_flush();
                flush();
            };

            try {
                // Déjà rédigée : on renvoie le contenu d'un coup.
                $existing = $this->answers->findOnePublicByQuestion($question);
                if (null !== $existing) {
                    $send(['delta' => $existing->getVulgarizationContent()]);
                    $send(['done' => true, 'answerId' => $existing->getId(), 'node' => $question->getTreeNode()->getSlug()]);

                    return;
                }

                $sources = $this->drafter->retrieveSources($question, 5);
                $messages = $this->drafter->buildMessages($question, $sources);

                $full = '';
                foreach ($this->llmFactory->create()->stream($messages, ['temperature' => 0.2, 'max_tokens' => 1200]) as $chunk) {
                    $full .= $chunk;
                    $send(['delta' => $chunk]);
                }

                $answer = $this->drafter->persistFromText($question, AnswerType::Free, $sources, $full);
                $this->em->flush();

                $send([
                    'done' => true,
                    'answerId' => $answer->getId(),
                    'node' => $question->getTreeNode()->getSlug(),
                    'title' => $question->getTitle(),
                ]);
            } catch (\Throwable $e) {
                $send(['error' => 'La rédaction a échoué. Réessayez plus tard.']);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        // Désactive le buffering nginx (Heimdall) pour un vrai flux temps réel.
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }
}
