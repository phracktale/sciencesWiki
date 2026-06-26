<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Harvester\Message\GenerateNodeArticle;
use App\Repository\TreeNodeRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * (Re)génération à la demande de l'article wiki d'une rubrique (ROLE_ADMIN),
 * déclenchée depuis le bouton admin du wiki PUBLIC. Asynchrone (file Messenger) :
 * la rédaction IA est longue, on ne bloque ni la requête ni le proxy.
 */
final class AdminNodeArticleController
{
    public function __construct(
        private readonly TreeNodeRepository $nodes,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/api/admin/nodes/{id}/regenerate-article', name: 'admin_node_regenerate_article', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(int $id): JsonResponse
    {
        if (null === $this->nodes->find($id)) {
            return new JsonResponse(['error' => 'Rubrique introuvable.'], 404);
        }

        $this->bus->dispatch(new GenerateNodeArticle($id));

        return new JsonResponse([
            'ok' => true,
            'message' => 'Génération lancée. L\'article apparaîtra dans 1 à 2 minutes (rafraîchissez la page).',
        ], 202);
    }
}
