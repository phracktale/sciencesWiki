<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Harvester\Message\GenerateNodeArticle;
use App\Repository\TreeNodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * (Re)génération à la demande de l'article wiki d'une rubrique (ROLE_ADMIN),
 * déclenchée depuis le bouton admin du wiki PUBLIC. Asynchrone (file Messenger) :
 * la rédaction IA est longue, on ne bloque ni la requête ni le proxy. On pose
 * tout de suite article_generating_at pour que le wiki public affiche un loader
 * persistant (visible par tous, survit au reload), même si le worker est occupé.
 */
final class AdminNodeArticleController
{
    public function __construct(
        private readonly TreeNodeRepository $nodes,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/api/admin/nodes/{id}/regenerate-article', name: 'admin_node_regenerate_article', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(int $id): JsonResponse
    {
        $node = $this->nodes->find($id);
        if (null === $node) {
            return new JsonResponse(['error' => 'Rubrique introuvable.'], 404);
        }

        // Idempotent : un seul job à la fois par nœud (le bouton se transforme en
        // loader, mais on protège aussi côté serveur contre un double-clic / appel).
        if (null !== $node->getArticleGeneratingAt()) {
            return new JsonResponse([
                'ok' => true,
                'generating' => true,
                'message' => 'Génération déjà en cours.',
            ]);
        }

        $node->setArticleGeneratingAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->bus->dispatch(new GenerateNodeArticle($id));

        return new JsonResponse([
            'ok' => true,
            'generating' => true,
            'message' => 'Génération lancée. L\'article apparaîtra dans quelques minutes.',
        ], 202);
    }
}
