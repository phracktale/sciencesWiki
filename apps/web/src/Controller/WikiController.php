<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ApiClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Front public de l'encyclopédie (rendu serveur Twig, consomme l'API).
 * URLs arborescentes : /{chemin/de/slugs} pour une rubrique, /q/{id} pour une Q/R.
 */
final class WikiController extends AbstractController
{
    public function __construct(private readonly ApiClient $api)
    {
    }

    #[Route('/{_locale}', name: 'home', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('wiki/home.html.twig', [
            'domains' => $this->api->domains(),
            'latest' => $this->api->latestQuestions(10),
            'stats' => $this->api->stats(),
        ]);
    }

    #[Route('/{_locale}/q/{id}', name: 'answer', requirements: ['id' => '\d+', '_locale' => 'fr'], methods: ['GET'])]
    public function answer(int $id): Response
    {
        $answer = $this->api->answer($id);
        if (null === $answer) {
            throw $this->createNotFoundException('Réponse introuvable.');
        }

        $slug = $answer['node']['slug'] ?? null;
        $node = \is_string($slug) ? $this->api->node($slug) : null;

        return $this->render('wiki/answer.html.twig', [
            'answer' => $answer,
            'node' => $node,
        ]);
    }

    /**
     * Rubrique par chemin arborescent. Le dernier segment est le slug (unique) ;
     * si le chemin ne correspond pas au chemin canonique, redirection 301 (SEO).
     */
    #[Route('/{_locale}/{path}', name: 'node', requirements: ['path' => '.+', '_locale' => 'fr'], priority: -10, methods: ['GET'])]
    public function node(string $path, string $_locale): Response
    {
        $path = trim($path, '/');
        $segments = explode('/', $path);
        $slug = end($segments) ?: '';

        $node = $this->api->node($slug);
        if (null === $node) {
            throw $this->createNotFoundException('Rubrique introuvable.');
        }

        $crumbs = $node['breadcrumb'] ?? [];
        $canonical = implode('/', array_map(static fn (array $c): string => (string) $c['slug'], $crumbs));
        if ('' !== $canonical && $canonical !== $path) {
            return $this->redirectToRoute('node', ['_locale' => $_locale, 'path' => $canonical], Response::HTTP_MOVED_PERMANENTLY);
        }

        return $this->render('wiki/node.html.twig', [
            'node' => $node,
            'path' => $canonical,
            'answers' => $this->api->answers($slug),
            'corpusCount' => $this->api->nodeCorpus($slug),
            'childrenStats' => $this->api->nodeChildrenStats($slug),
        ]);
    }
}
