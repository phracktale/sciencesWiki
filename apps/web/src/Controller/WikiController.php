<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ApiClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Front public de l'encyclopédie (rendu serveur Twig, consomme l'API).
 */
final class WikiController extends AbstractController
{
    public function __construct(private readonly ApiClient $api)
    {
    }

    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('wiki/home.html.twig', ['domains' => $this->api->domains()]);
    }

    #[Route('/n/{slug}', name: 'node', methods: ['GET'])]
    public function node(string $slug): Response
    {
        $node = $this->api->node($slug);
        if (null === $node) {
            throw $this->createNotFoundException('Nœud introuvable.');
        }

        return $this->render('wiki/node.html.twig', [
            'node' => $node,
            'answers' => $this->api->answers($slug),
        ]);
    }
}
