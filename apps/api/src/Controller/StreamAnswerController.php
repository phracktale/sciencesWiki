<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\Llm\LlmClientFactory;
use App\Enum\AnswerType;
use App\Rag\AnswerDrafter;
use App\Repository\AnswerRepository;
use App\Repository\QuestionRepository;
use App\Repository\TreeNodeRepository;
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
    /** Distance cosinus max pour qu'une publication soit jugée pertinente (garde-fou). */
    private const MAX_SOURCE_DISTANCE = 0.62;

    public function __construct(
        private readonly QuestionRepository $questions,
        private readonly AnswerRepository $answers,
        private readonly AnswerDrafter $drafter,
        private readonly LlmClientFactory $llmFactory,
        private readonly TreeNodeRepository $nodes,
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
            // La rédaction se termine et se persiste même si le visiteur ferme l'onglet.
            ignore_user_abort(true);
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

                // Garde-fou amont : aucune source pertinente => on ne génère pas
                // et on ne publie pas (cf. exigence : pas de réponse creuse).
                $sources = $this->drafter->retrieveSources($question, 6, self::MAX_SOURCE_DISTANCE);

                // Réorientation (spec §8.2) : rattacher la question au nœud le plus
                // proche sémantiquement, quel que soit le nœud d'origine.
                $embedding = $question->getEmbedding();
                if (null !== $embedding) {
                    $best = $this->nodes->nearestTo($embedding->toArray(), 1);
                    if ([] !== $best) {
                        $question->setTreeNode($best[0]['node']);
                    }
                }

                if ([] === $sources) {
                    $send(['nosource' => true, 'message' => "Nous n'avons pas encore de source scientifique fiable pour répondre à cette question. Elle est enregistrée et sera traitée dès que des publications pertinentes seront disponibles dans le corpus."]);

                    return;
                }

                $messages = $this->drafter->buildMessages($question, $sources);

                $full = '';
                foreach ($this->llmFactory->create()->stream($messages, ['temperature' => 0.2, 'max_tokens' => 1200]) as $chunk) {
                    $full .= $chunk;
                    $send(['delta' => $chunk]);
                }

                // Garde-fou aval : si la rédaction ne cite aucune source, elle est
                // jugée non ancrée => non publiée.
                $parsed = $this->drafter->analyze($full, $sources);
                if ([] === $parsed['footnotes']) {
                    $send(['nosource' => true, 'message' => "La rédaction n'a pas pu s'appuyer sur les sources disponibles : rien n'est publié pour éviter une réponse non sourcée."]);

                    return;
                }

                $answer = $this->drafter->persistFromText($question, AnswerType::Free, $sources, $full);
                $this->em->flush();

                $send([
                    'done' => true,
                    'answerId' => $answer->getId(),
                    'node' => $question->getTreeNode()->getSlug(),
                    'title' => $question->getTitle(),
                ]);
            } catch (\Throwable) {
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
