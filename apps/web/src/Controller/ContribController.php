<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AdminApiClient;
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
        private readonly AdminApiClient $admin,
        private readonly AdminCsrf $csrf,
    ) {
    }

    #[Route('/{_locale}/connexion', name: 'login', requirements: ['_locale' => 'fr'], methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isValid($request)) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');

                return $this->redirectToRoute('login');
            }
            $email = (string) $request->request->get('email');
            $password = (string) $request->request->get('password');
            $ok = $this->user->login($email, $password);
            if ($ok) {
                // Un admin connecté côté front ouvre aussi la session back-office
                // (mêmes identifiants) → accès direct au tableau de bord.
                if ($this->user->hasRole('ROLE_ADMIN')) {
                    $this->admin->login($email, $password);
                }
                $this->addFlash('success', 'Connecté·e en tant que '.$this->user->displayName().'.');
                $back = (string) $request->request->get('back', '');

                return $this->redirect('' !== $back ? $back : $this->generateUrl('home'));
            }
            $this->addFlash('error', 'Identifiants invalides.');
        }

        return $this->render('wiki/login.html.twig', ['back' => (string) $request->query->get('back', '')]);
    }

    #[Route('/{_locale}/deconnexion', name: 'logout', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function logout(Request $request): Response
    {
        $this->user->logout();
        $this->admin->logout();
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
