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
        private readonly \App\Service\PdfAssets $pdfAssets,
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

    /** Export PDF d'une revue ad hoc (depuis la page de génération). */
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
        $sources = json_decode((string) $request->request->get('sources', '[]'), true);

        return $this->reviewPdf(
            trim((string) $request->request->get('topic', '')),
            $markdown,
            \is_array($sources) ? $sources : [],
            trim((string) $request->request->get('rubric', '')) ?: null,
        );
    }

    /** Enregistre la revue courante dans la bibliothèque du chercheur (proxy API). */
    #[Route('/{_locale}/chercheur/revues/save', name: 'literature_review_save', requirements: ['_locale' => 'fr'], methods: ['POST'])]
    public function saveReview(Request $request): JsonResponse
    {
        if (!$this->user->isLogged() || !$this->user->canResearch()) {
            return new JsonResponse(['error' => 'Accès refusé.'], 403);
        }
        if (!$this->csrf->isValid($request)) {
            return new JsonResponse(['error' => 'Jeton de sécurité invalide.'], 403);
        }
        $sources = json_decode((string) $request->request->get('sources', '[]'), true);
        // Garde-fou : json_encode (UserApiClient) échoue sur de l'UTF-8 invalide.
        $utf8 = static fn (string $s): string => mb_check_encoding($s, 'UTF-8') ? $s : (string) mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        $res = $this->user->send('POST', '/api/literature-reviews', [
            'topic' => $utf8((string) $request->request->get('topic', '')),
            'rubric' => $utf8((string) $request->request->get('rubric', '')),
            'markdown' => $utf8((string) $request->request->get('markdown', '')),
            'sources' => \is_array($sources) ? $sources : [],
        ]);

        return new JsonResponse($res['data'], $res['ok'] ? 201 : (0 !== $res['status'] ? $res['status'] : 502));
    }

    /** « Mes revues » : bibliothèque des revues sauvegardées. */
    #[Route('/{_locale}/chercheur/revues', name: 'literature_reviews', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function savedReviews(): Response
    {
        if ($r = $this->researcherRedirect('/fr/chercheur/revues')) {
            return $r;
        }
        $res = $this->user->send('GET', '/api/literature-reviews');

        return $this->render('wiki/literature_reviews.html.twig', ['reviews' => $res['data']['items'] ?? []]);
    }

    /** PDF d'une revue sauvegardée. */
    #[Route('/{_locale}/chercheur/revues/{id}/pdf', name: 'literature_review_saved_pdf', requirements: ['_locale' => 'fr', 'id' => '\d+'], methods: ['GET'])]
    public function savedReviewPdf(int $id): Response
    {
        if ($r = $this->researcherRedirect('/fr/chercheur/revues')) {
            return $r;
        }
        $res = $this->user->send('GET', '/api/literature-reviews/'.$id);
        if (!$res['ok']) {
            throw $this->createNotFoundException('Revue introuvable.');
        }
        $d = $res['data'];

        return $this->reviewPdf((string) ($d['topic'] ?? ''), (string) ($d['markdown'] ?? ''), \is_array($d['sources'] ?? null) ? $d['sources'] : [], isset($d['rubric']) ? (string) $d['rubric'] : null);
    }

    /** Export Markdown d'une revue sauvegardée. */
    #[Route('/{_locale}/chercheur/revues/{id}/markdown', name: 'literature_review_saved_md', requirements: ['_locale' => 'fr', 'id' => '\d+'], methods: ['GET'])]
    public function savedReviewMarkdown(int $id): Response
    {
        if ($r = $this->researcherRedirect('/fr/chercheur/revues')) {
            return $r;
        }
        $res = $this->user->send('GET', '/api/literature-reviews/'.$id);
        if (!$res['ok']) {
            throw $this->createNotFoundException('Revue introuvable.');
        }
        $d = $res['data'];
        $md = '# Revue de littérature — '.($d['topic'] ?? '')."\n\n".($d['markdown'] ?? '')."\n\n## Bibliographie\n\n";
        foreach (($d['sources'] ?? []) as $s) {
            $authors = \is_array($s['authors'] ?? null) ? implode(', ', $s['authors']) : '';
            $md .= '['.($s['n'] ?? '?').'] '.($s['title'] ?? '').('' !== $authors ? ' — '.$authors : '')
                .(isset($s['year']) ? ' ('.$s['year'].')' : '')
                .(isset($s['doi']) && $s['doi'] ? '. DOI: '.$s['doi'] : '')
                .(isset($s['oaUrl']) && $s['oaUrl'] ? '. '.$s['oaUrl'] : '')."\n";
        }

        return new Response($md, Response::HTTP_OK, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$this->reviewSlug((string) ($d['topic'] ?? '')).'.md"',
        ]);
    }

    /** Suppression d'une revue sauvegardée. */
    #[Route('/{_locale}/chercheur/revues/{id}/supprimer', name: 'literature_review_delete', requirements: ['_locale' => 'fr', 'id' => '\d+'], methods: ['POST'])]
    public function deleteReview(int $id, Request $request): Response
    {
        if ($r = $this->researcherRedirect('/fr/chercheur/revues')) {
            return $r;
        }
        if ($this->csrf->isValid($request)) {
            $res = $this->user->send('DELETE', '/api/literature-reviews/'.$id);
            $this->addFlash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Revue supprimée.' : 'Échec de la suppression.');
        } else {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
        }

        return $this->redirectToRoute('literature_reviews');
    }

    private function researcherRedirect(string $back): ?Response
    {
        if (!$this->user->isLogged()) {
            return $this->redirectToRoute('login', ['back' => $back]);
        }
        if (!$this->user->canResearch()) {
            $this->addFlash('error', 'Espace réservé aux chercheurs (ROLE_RESEARCHER).');

            return $this->redirectToRoute('home');
        }

        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $sources
     */
    private function reviewPdf(string $topic, string $markdown, array $sources, ?string $rubric = null): Response
    {
        // Garde-fou : CommonMark exige de l'UTF-8 valide (sinon exception).
        if (!mb_check_encoding($markdown, 'UTF-8')) {
            $markdown = (string) mb_convert_encoding($markdown, 'UTF-8', 'UTF-8');
        }
        $html = $this->renderView('pdf/review_body.html.twig', [
            'topic' => '' !== trim($topic) ? $topic : 'Revue de littérature',
            'rubric' => $rubric,
            'markdown' => $markdown,
            'sources' => $sources,
        ]);

        // Gabarit PDF (charte/en-tête) en fond + texte stampé dans la zone définie.
        $pdf = new \App\Pdf\TemplatePdf('P', 'pt', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('SciencesWiki');
        $pdf->SetAuthor('SciencesWiki');
        $pdf->SetTitle($topic);
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(true);
        $pdf->setHeaderMargin(0);
        $pdf->setFooterMargin(0);
        // Zone de texte : X=43, Y=103, L=510, H=680 (pt) → marges + saut de page.
        $pdf->SetMargins(43, 103, 42);
        $pdf->SetAutoPageBreak(true, 59);
        $pdf->SetFont('dejavusans', '', 10.5);
        $pdf->setFooterDate(date('d/m/Y'));
        $pdf->loadTemplate($this->pdfAssets->templatePath());
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');

        return new Response((string) $pdf->Output('revue.pdf', 'S'), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->reviewSlug($topic).'.pdf"',
        ]);
    }

    private function reviewSlug(string $topic): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $topic));
        $slug = trim($slug, '-');

        return 'revue-'.('' !== $slug ? mb_substr($slug, 0, 60) : 'litterature');
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
