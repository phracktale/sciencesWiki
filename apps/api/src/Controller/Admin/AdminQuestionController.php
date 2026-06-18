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
