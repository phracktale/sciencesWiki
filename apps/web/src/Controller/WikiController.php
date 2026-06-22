<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ApiClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Front public de l'encyclopédie (rendu serveur Twig, consomme l'API).
 * URLs arborescentes : /{chemin/de/slugs} pour une rubrique, /q/{id} pour une Q/R.
 */
final class WikiController extends AbstractController
{
    public function __construct(
        private readonly ApiClient $api,
        private readonly \App\Service\UserApiClient $user,
        private readonly \App\Service\AdminCsrf $csrf,
    ) {
    }

    /** Espace chercheur (réservé ROLE_RESEARCHER) : outils de recherche. */
    #[Route('/{_locale}/chercheur', name: 'researcher_dashboard', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function researcher(): Response
    {
        if (!$this->user->isLogged()) {
            return $this->redirectToRoute('login', ['back' => '/fr/chercheur']);
        }
        if (!$this->user->canResearch()) {
            $this->addFlash('error', 'Espace réservé aux chercheurs (ROLE_RESEARCHER).');

            return $this->redirectToRoute('home');
        }

        return $this->render('wiki/researcher.html.twig');
    }

    /** Revue de littérature assistée (RAG sourcé, flux SSE) — réservé ROLE_RESEARCHER. */
    #[Route('/{_locale}/chercheur/revue-litterature', name: 'literature_review', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function literatureReview(): Response
    {
        if (!$this->user->isLogged()) {
            return $this->redirectToRoute('login', ['back' => '/fr/chercheur/revue-litterature']);
        }
        if (!$this->user->canResearch()) {
            $this->addFlash('error', 'Espace réservé aux chercheurs (ROLE_RESEARCHER).');

            return $this->redirectToRoute('home');
        }

        return $this->render('wiki/literature_review.html.twig');
    }

    /** Export PDF propre d'une revue de littérature (dompdf, markdown rendu serveur). */
    #[Route('/{_locale}/chercheur/revue-litterature/pdf', name: 'literature_review_pdf', requirements: ['_locale' => 'fr'], methods: ['POST'])]
    public function literatureReviewPdf(Request $request): Response
    {
        if (!$this->user->isLogged() || !$this->user->canResearch()) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->csrf->isValid($request)) {
            return new Response('Jeton de sécurité invalide.', Response::HTTP_FORBIDDEN);
        }

        $markdown = (string) $request->request->get('markdown', '');
        if ('' === trim($markdown)) {
            return new Response('Revue vide.', Response::HTTP_BAD_REQUEST);
        }
        // Garde-fou : CommonMark exige de l'UTF-8 valide (sinon exception).
        if (!mb_check_encoding($markdown, 'UTF-8')) {
            $markdown = mb_convert_encoding($markdown, 'UTF-8', 'UTF-8');
        }
        $sources = json_decode((string) $request->request->get('sources', '[]'), true);

        $html = $this->renderView('pdf/literature_review.html.twig', [
            'topic' => trim((string) $request->request->get('topic', '')) ?: 'Revue de littérature',
            'markdown' => $markdown,
            'sources' => \is_array($sources) ? $sources : [],
            'date' => date('d/m/Y'),
        ]);

        // dompdf : pas d'accès réseau (anti-SSRF), police DejaVu (Unicode/accents).
        $dompdf = new \Dompdf\Dompdf(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response($dompdf->output(), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="revue-litterature.pdf"',
        ]);
    }

    #[Route('/{_locale}', name: 'home', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('wiki/home.html.twig', [
            'domains' => $this->api->domains(),
            'latestFrame' => $this->api->latestQuestionsPage(5, 1),
            'stats' => $this->api->stats(),
        ]);
    }

    /** Fragment Turbo : page de 5 dernières Q/R (pagination dans le cadre). */
    #[Route('/_frame/latest-questions/{page}', name: 'latest_frame', requirements: ['page' => '\d+'], methods: ['GET'])]
    public function latestFrame(int $page): Response
    {
        return $this->render('wiki/_latest_frame.html.twig', [
            'latest' => $this->api->latestQuestionsPage(5, max(1, $page)),
        ]);
    }

    #[Route('/{_locale}/q/{id}', name: 'answer', requirements: ['id' => '\d+', '_locale' => 'fr'], methods: ['GET'])]
    public function answer(int $id, Request $request): Response
    {
        $answer = $this->api->answer($id);
        if (null === $answer) {
            throw $this->createNotFoundException('Réponse introuvable.');
        }

        $slug = $answer['node']['slug'] ?? null;
        $node = \is_string($slug) ? $this->api->node($slug) : null;
        $votes = $this->api->answerVotes([$id], $this->user->token(), $request->getClientIp());

        return $this->render('wiki/answer.html.twig', [
            'answer' => $answer,
            'node' => $node,
            'votes' => $votes['tallies'],
            'myVotes' => $votes['mine'],
        ]);
    }

    /** Proxy de vote (session → JWT) : le navigateur appelle cette route même origine. */
    #[Route('/{_locale}/q/{id}/vote', name: 'answer_vote', requirements: ['id' => '\d+', '_locale' => 'fr'], methods: ['POST'])]
    public function vote(int $id, Request $request): JsonResponse
    {
        $value = (string) ($request->request->get('value') ?? '');
        $res = $this->api->voteAnswer($id, $value, $this->user->token(), $request->getClientIp());

        return new JsonResponse($res['data'], $res['ok'] ? 200 : (0 !== $res['status'] ? $res['status'] : 502));
    }

    /** Moteur de recherche des articles encyclopédiques (rendu JS via /api/wiki/search). */
    #[Route('/{_locale}/wiki', name: 'wiki_search', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function wikiSearch(): Response
    {
        return $this->render('wiki/search.html.twig', [
            'domains' => $this->api->domains(),
        ]);
    }

/**
     * Explorateur d'articles d'un sous-domaine (recherche plein-texte + fiche
     * détaillée façon OpenAlex). Interactif : la liste et la fiche sont chargées
     * côté navigateur depuis l'API publique.
     */
    #[Route('/{_locale}/explorer/{slug}', name: 'explorer', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function explorer(string $slug): Response
    {
        $node = $this->api->node($slug);
        if (null === $node) {
            return $this->redirectToRoute('home', ['_locale' => 'fr']);
        }

        return $this->render('wiki/explorer.html.twig', ['node' => $node, 'slug' => $slug]);
    }

    /** Dépôt public de la version auteur d'un article (gated par jeton sécurisé). */
    #[Route('/{_locale}/contribuer/{token}', name: 'contribute', requirements: ['_locale' => 'fr', 'token' => '[a-f0-9]{32,64}'], methods: ['GET'])]
    public function contribute(string $token): Response
    {
        return $this->render('wiki/contribute.html.twig', ['token' => $token]);
    }

    /**
     * Rubrique par chemin arborescent. Le dernier segment est le slug (unique) ;
     * si le chemin ne correspond pas au chemin canonique, redirection 301 (SEO).
     */
    #[Route('/{_locale}/{path}', name: 'node', requirements: ['path' => '.+', '_locale' => 'fr'], priority: -10, methods: ['GET'])]
    public function node(string $path, string $_locale, Request $request): Response
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

        $answers = $this->api->answers($slug);
        $ids = array_values(array_filter(array_map(static fn (array $a): int => (int) ($a['id'] ?? 0), $answers)));
        $votes = $this->api->answerVotes($ids, $this->user->token(), $request->getClientIp());

        return $this->render('wiki/node.html.twig', [
            'node' => $node,
            'path' => $canonical,
            'answers' => $answers,
            'votes' => $votes['tallies'],
            'myVotes' => $votes['mine'],
            'corpusCount' => $this->api->nodeCorpus($slug),
            'childrenStats' => $this->api->nodeChildrenStats($slug),
        ]);
    }
}
