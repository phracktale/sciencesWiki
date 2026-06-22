<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AdminCsrf;
use App\Service\UserApiClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Identité contributeur (rédacteurs/comité/modérateurs/admins) côté front :
 * connexion, déconnexion, et relais des écritures (édition d'article, révision
 * de réponse) vers l'API avec le jeton de l'utilisateur.
 */
final class ContribController extends AbstractController
{
    public function __construct(
        private readonly UserApiClient $user,
        private readonly AdminCsrf $csrf,
    ) {
    }

    #[Route('/{_locale}/connexion', name: 'login', requirements: ['_locale' => 'fr'], methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        // Déjà connecté et on retombe sur le formulaire → on l'envoie vers son
        // dashboard (rôle le plus élevé), ou vers la cible « back » si elle est sûre.
        if ($request->isMethod('GET') && $this->user->isLogged()) {
            return $this->redirect($this->postLoginTarget((string) $request->query->get('back', '')));
        }

        if ($request->isMethod('POST')) {
            if (!$this->csrf->isValid($request)) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');

                return $this->redirectToRoute('login');
            }
            $ok = $this->user->login(
                (string) $request->request->get('email'),
                (string) $request->request->get('password'),
            );
            if ($ok) {
                $this->addFlash('success', 'Connecté·e en tant que '.$this->user->displayName().'.');

                return $this->redirect($this->postLoginTarget((string) $request->request->get('back', '')));
            }
            $this->addFlash('error', 'Identifiants invalides.');
        }

        return $this->render('wiki/login.html.twig', ['back' => (string) $request->query->get('back', '')]);
    }

    /**
     * Destination après authentification : la cible « back » si elle est locale et
     * sûre, sinon le dashboard du rôle le plus élevé (admin > chercheur > accueil).
     */
    private function postLoginTarget(string $back): string
    {
        if ('' !== $back && str_starts_with($back, '/') && !str_starts_with($back, '//')) {
            return $back;
        }
        if ($this->user->hasRole('ROLE_ADMIN')) {
            return $this->generateUrl('admin_dashboard');
        }
        if ($this->user->hasRole('ROLE_RESEARCHER')) {
            return $this->generateUrl('researcher_dashboard');
        }

        return $this->generateUrl('home');
    }

    #[Route('/{_locale}/deconnexion', name: 'logout', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function logout(Request $request): Response
    {
        $this->user->logout();
        $this->addFlash('success', 'Déconnecté·e.');

        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('home'));
    }

    #[Route('/{_locale}/article/{id}/edit', name: 'article_edit', requirements: ['_locale' => 'fr', 'id' => '\d+'], methods: ['POST'])]
    public function editArticle(int $id, Request $request): Response
    {
        if (!$this->csrf->isValid($request)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
        } else {
            $body = ['articleMd' => (string) $request->request->get('article_md')];
            if ($request->request->get('validate')) {
                $body['validate'] = true;
            }
            $res = $this->user->send('PATCH', '/api/nodes/'.$id.'/article', $body);
            $this->addFlash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Article enregistré.' : ('Échec : '.($res['data']['error'] ?? 'erreur')));
        }

        return $this->back($request);
    }

    #[Route('/{_locale}/article/{id}/valider', name: 'article_validate', requirements: ['_locale' => 'fr', 'id' => '\d+'], methods: ['POST'])]
    public function validateArticle(int $id, Request $request): Response
    {
        if (!$this->csrf->isValid($request)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
        } else {
            $res = $this->user->send('POST', '/api/nodes/'.$id.'/article/validate', []);
            $this->addFlash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Article validé.' : ('Échec : '.($res['data']['error'] ?? 'erreur')));
        }

        return $this->back($request);
    }

    #[Route('/{_locale}/reponse/{id}/valider', name: 'answer_validate', requirements: ['_locale' => 'fr', 'id' => '\d+'], methods: ['POST'])]
    public function validateAnswer(int $id, Request $request): Response
    {
        if (!$this->csrf->isValid($request)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
        } else {
            $res = $this->user->send('POST', '/api/answers/'.$id.'/validate', []);
            $this->addFlash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Réponse validée par le comité.' : ('Échec : '.($res['data']['error'] ?? 'erreur')));
        }

        return $this->back($request);
    }

    #[Route('/{_locale}/reponse/{id}/edit', name: 'answer_edit', requirements: ['_locale' => 'fr', 'id' => '\d+'], methods: ['POST'])]
    public function editAnswer(int $id, Request $request): Response
    {
        if (!$this->csrf->isValid($request)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
        } else {
            $res = $this->user->send('POST', '/api/answers/'.$id.'/revise', [
                'vulgarization' => (string) $request->request->get('vulgarization'),
                'academic' => (string) $request->request->get('academic'),
                'summary' => trim((string) $request->request->get('summary')) ?: 'Révision via le front',
            ]);
            $this->addFlash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Réponse révisée.' : ('Échec : '.($res['data']['error'] ?? 'erreur')));
        }

        return $this->back($request);
    }

    private function back(Request $request): Response
    {
        return $this->redirect($request->request->get('back') ?: ($request->headers->get('referer') ?: $this->generateUrl('home')));
    }
}
