<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\UserApiClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Façade des MODULES SciencesWiki (cf. docs/Modules/SPECS.md §8) : page hub d'accès
 * aux modules + proxy vers les modules standalone (le JWT de session est ajouté en
 * en-tête Bearer, comme pour l'API). N'affecte PAS l'analyse legacy.
 */
final class LabController extends AbstractController
{
    public function __construct(
        private readonly UserApiClient $user,
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'ANALYS_BASE_URL')]
        private readonly string $analysesBaseUrl = 'http://analyses',
    ) {
    }

    /** Page d'accès aux modules, filtrée par rôle. */
    #[Route('/{_locale}/labo', name: 'lab_hub', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function hub(): Response
    {
        if (!$this->user->isLogged()) {
            return $this->redirectToRoute('login', ['back' => '/labo']);
        }

        $modules = [];
        if ($this->user->hasRole('ROLE_RESEARCHER') || $this->user->hasRole('ROLE_COMITE')) {
            $modules[] = [
                'slug' => 'analyses',
                'name' => 'Analyses scientifiques',
                'icon' => '🔬',
                'description' => "Routage et évaluation méthodologique composite des publications (AXIS, RoB 2, AMSTAR 2, MMAT…).",
                'href' => $this->generateUrl('lab_analyses_ui'),
            ];
        }

        return $this->render('lab/hub.html.twig', ['modules' => $modules]);
    }

    /** Interface chercheur du module analyses (formulaire + suivi + résultats). */
    #[Route('/{_locale}/labo/analyses', name: 'lab_analyses_ui', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function analysesUi(): Response
    {
        if (!$this->user->isLogged()) {
            return $this->redirectToRoute('login', ['back' => '/labo/analyses']);
        }
        if (!$this->user->hasRole('ROLE_RESEARCHER') && !$this->user->hasRole('ROLE_COMITE')) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('lab/analyses.html.twig', [
            'canReview' => $this->user->hasRole('ROLE_COMITE'),
        ]);
    }

    /**
     * Proxy vers le module « analyses » (standalone) : ajoute le JWT de session en Bearer
     * et relaie la réponse telle quelle (JSON ou PDF). L'accès de section (rôles) est
     * appliqué par le module lui-même.
     */
    #[Route('/{_locale}/labo/analyses/{path}', name: 'lab_analyses_proxy', requirements: ['_locale' => 'fr', 'path' => '.+'], methods: ['GET', 'POST', 'PATCH'])]
    public function analysesProxy(string $path, Request $request): Response
    {
        if (!$this->user->isLogged()) {
            return new JsonResponse(['error' => 'Non authentifié.'], 401);
        }
        $token = $this->user->token();
        if (!\is_string($token)) {
            return new JsonResponse(['error' => 'Session invalide.'], 401);
        }

        $headers = ['Authorization' => 'Bearer '.$token];
        $options = ['headers' => $headers, 'timeout' => 240];
        if (!$request->isMethod('GET')) {
            $headers['Content-Type'] = 'application/json';
            $options['headers'] = $headers;
            $options['body'] = $request->getContent();
        }
        $qs = $request->getQueryString();
        $url = rtrim($this->analysesBaseUrl, '/').'/'.ltrim($path, '/').(null !== $qs && '' !== $qs ? '?'.$qs : '');

        try {
            $response = $this->httpClient->request($request->getMethod(), $url, $options);
            $contentType = $response->getHeaders(false)['content-type'][0] ?? 'application/json';

            return new Response($response->getContent(false), $response->getStatusCode(), ['Content-Type' => $contentType]);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Module analyses momentanément indisponible.'], 502);
        }
    }
}
