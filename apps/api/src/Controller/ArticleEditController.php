<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ArticleRevision;
use App\Entity\User;
use App\Repository\TreeNodeRepository;
use App\Security\Voter\ArticleVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Édition de l'article encyclopédique d'un nœud par les rôles habilités
 * (rédacteur : corriger ; modérateur/comité compétent/admin : valider).
 */
final class ArticleEditController
{
    public function __construct(
        private readonly TreeNodeRepository $nodes,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    #[Route('/api/nodes/{id}/article', name: 'api_node_article_edit', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): JsonResponse
    {
        $node = $this->nodes->find($id);
        if (null === $node) {
            return new JsonResponse(['error' => 'Rubrique introuvable.'], 404);
        }
        if (!$this->security->isGranted(ArticleVoter::EDIT, $node)) {
            throw new AccessDeniedHttpException('Édition réservée aux rédacteurs.');
        }

        $data = json_decode($request->getContent() ?: '{}', true) ?? [];
        $md = trim((string) ($data['articleMd'] ?? ''));
        if ('' === $md) {
            return new JsonResponse(['error' => 'Le contenu ne peut être vide.'], 422);
        }

        $node->setArticleMd($md)->setArticleReviewedAt(new \DateTimeImmutable());
        // Un comité/modérateur qui édite peut aussi valider d'un coup.
        if (true === ($data['validate'] ?? false) && $this->security->isGranted(ArticleVoter::VALIDATE, $node)) {
            $node->setArticleStatus('valide');
        }

        // Révision (historique + diff) : paternité humaine.
        $user = $this->security->getUser();
        $isCommittee = $this->security->isGranted('ROLE_COMITE') || $this->security->isGranted('ROLE_MODERATEUR') || $this->security->isGranted('ROLE_ADMIN');
        $revision = (new ArticleRevision($node, $md, $isCommittee ? 'comite' : 'contributeur'))
            ->setChangeSummary(trim((string) ($data['summary'] ?? '')) ?: 'Édition');
        if ($user instanceof User) {
            $revision->setAuthor($user)->setAuthorLabel($user->getPseudo() ?? $user->getRealName() ?? $user->getUserIdentifier());
        }
        $this->em->persist($revision);
        $this->em->flush();

        return new JsonResponse([
            'id' => $id,
            'status' => $node->getArticleStatus(),
            'length' => mb_strlen($md),
        ]);
    }

    #[Route('/api/nodes/{id}/article/validate', name: 'api_node_article_validate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function validate(int $id): JsonResponse
    {
        $node = $this->nodes->find($id);
        if (null === $node) {
            return new JsonResponse(['error' => 'Rubrique introuvable.'], 404);
        }
        if (!$this->security->isGranted(ArticleVoter::VALIDATE, $node)) {
            throw new AccessDeniedHttpException('Validation réservée au comité/modération.');
        }
        $node->setArticleStatus('valide')->setArticleReviewedAt(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse(['id' => $id, 'status' => 'valide']);
    }
}
