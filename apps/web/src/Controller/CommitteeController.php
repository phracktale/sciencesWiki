<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\UserApiClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tableau de bord du COMITÉ scientifique : liste les réponses (Q/R) et articles en attente de
 * relecture/validation, avec accès direct pour lire puis valider. Réservé aux rôles validant
 * (comité / modérateur / admin). La validation réutilise les routes existantes answer_validate
 * et article_validate (relayées à l'API, soumises aux voters de compétence par domaine).
 */
final class CommitteeController extends AbstractController
{
    public function __construct(
        private readonly UserApiClient $user,
    ) {
    }

    #[Route('/{_locale}/comite', name: 'committee_dashboard', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function index(): Response
    {
        if (!$this->user->isLogged()) {
            return $this->redirectToRoute('login', ['back' => '/comite']);
        }
        if (!$this->user->canValidate()) {
            throw $this->createAccessDeniedException();
        }

        $res = $this->user->send('GET', '/api/committee/queue');
        $data = $res['ok'] ? $res['data'] : ['answers' => [], 'articles' => [], 'counts' => ['answers' => 0, 'articles' => 0]];

        return $this->render('committee/index.html.twig', [
            'answers' => $data['answers'] ?? [],
            'articles' => $data['articles'] ?? [],
            'counts' => $data['counts'] ?? ['answers' => 0, 'articles' => 0],
            'unavailable' => !$res['ok'],
        ]);
    }
}
