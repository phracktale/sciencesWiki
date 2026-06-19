<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\AnswerRepository;
use App\Repository\QuestionRepository;
use App\Repository\TreeNodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Actions admin sur les questions (ROLE_ADMIN). Déplacer manuellement une
 * question (et ses réponses) vers une autre rubrique.
 */
final class AdminQuestionController
{
    public function __construct(
        private readonly QuestionRepository $questions,
        private readonly AnswerRepository $answers,
        private readonly TreeNodeRepository $nodes,
        private readonly EntityManagerInterface $em,
        private readonly \App\Service\ActivityLogger $activity,
        private readonly \Symfony\Bundle\SecurityBundle\Security $security,
        private readonly \App\Rag\AnswerDrafter $drafter,
    ) {
    }

    private function actor(): string
    {
        return $this->security->getUser()?->getUserIdentifier() ?? 'admin';
    }

    #[Route('/api/admin/questions/{id}/move', name: 'admin_question_move', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function move(int $id, Request $request): JsonResponse
    {
        $question = $this->questions->find($id);
        if (null === $question) {
            return new JsonResponse(['error' => 'Question introuvable.'], Response::HTTP_NOT_FOUND);
        }
        /** @var array<string,mixed> $data */
        $data = json_decode($request->getContent() ?: '[]', true) ?? [];
        $node = !empty($data['nodeId']) ? $this->nodes->find((int) $data['nodeId']) : null;
        if (null === $node) {
            return new JsonResponse(['error' => 'Rubrique cible introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $question->setTreeNode($node);
        // Les réponses suivent la question.
        foreach ($this->answers->findBy(['question' => $question]) as $answer) {
            $answer->setTreeNode($node);
        }
        $this->em->flush();

        $this->activity->log('question', 'move', $this->actor(), \sprintf('Question #%d déplacée vers « %s »', $id, $node->getLabel()), ['questionId' => $id, 'node' => $node->getSlug()]);

        return new JsonResponse(['id' => $id, 'node' => ['slug' => $node->getSlug(), 'label' => $node->getLabel()]]);
    }

    /** Édite l'intitulé/titre d'une question (réinitialise l'embedding pour le RAG). */
    #[Route('/api/admin/questions/{id}', name: 'admin_question_edit', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): JsonResponse
    {
        $question = $this->questions->find($id);
        if (null === $question) {
            return new JsonResponse(['error' => 'Question introuvable.'], Response::HTTP_NOT_FOUND);
        }
        /** @var array<string,mixed> $data */
        $data = json_decode($request->getContent() ?: '[]', true) ?? [];

        if (\array_key_exists('text', $data)) {
            $text = trim((string) $data['text']);
            if ('' === $text) {
                return new JsonResponse(['error' => 'L\'intitulé ne peut être vide.'], 422);
            }
            if ($text !== $question->getText()) {
                $question->setText($text);
                $question->setEmbedding(null); // recalculé à la prochaine génération
            }
        }
        if (\array_key_exists('title', $data)) {
            $question->setTitle(null !== $data['title'] ? (string) $data['title'] : null);
        }
        $this->em->flush();

        $this->activity->log('question', 'edit', $this->actor(), \sprintf('Question #%d éditée', $id), ['questionId' => $id]);

        return new JsonResponse(['id' => $id, 'text' => $question->getText(), 'title' => $question->getTitle()]);
    }

    /** Régénère la réponse (RAG) : supprime les réponses existantes et en rédige une neuve. */
    #[Route('/api/admin/questions/{id}/regenerate', name: 'admin_question_regenerate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function regenerate(int $id): JsonResponse
    {
        $question = $this->questions->find($id);
        if (null === $question) {
            return new JsonResponse(['error' => 'Question introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // La génération LLM peut dépasser la limite d'exécution PHP par défaut (30 s).
        set_time_limit(0);

        $existing = $this->answers->findBy(['question' => $question]);
        $type = $existing[0]?->getType() ?? \App\Enum\AnswerType::Canonical;

        // On rédige D'ABORD la nouvelle réponse ; on ne supprime les anciennes
        // qu'en cas de succès (si le draft échoue, l'ancienne réponse est conservée).
        try {
            $answer = $this->drafter->draft($question, $type);
            $this->em->flush();
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Échec de la génération : '.$e->getMessage()], 502);
        }

        foreach ($existing as $old) {
            $this->em->remove($old);
        }
        $this->em->flush();

        $this->activity->log('question', 'regenerate', $this->actor(), \sprintf('Réponse régénérée pour la question #%d', $id), ['questionId' => $id, 'answerId' => $answer->getId()]);

        return new JsonResponse(['id' => $id, 'answerId' => $answer->getId(), 'message' => 'Réponse régénérée.']);
    }

    #[Route('/api/admin/questions/{id}', name: 'admin_question_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $question = $this->questions->find($id);
        if (null === $question) {
            return new JsonResponse(['error' => 'Question introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $text = mb_substr($question->getText(), 0, 120);

        // Suppression des réponses (cascade : révisions via orphanRemoval, notes de
        // bas de page en CASCADE base), puis de la question elle-même.
        foreach ($this->answers->findBy(['question' => $question]) as $answer) {
            $this->em->remove($answer);
        }
        $this->em->remove($question);
        $this->em->flush();

        $this->activity->log('question', 'delete', $this->actor(), \sprintf('Question #%d supprimée : %s', $id, $text), ['questionId' => $id]);

        return new JsonResponse(['id' => $id, 'message' => 'Question et réponses supprimées.']);
    }
}
