<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AdminApiClient;
use App\Service\ApiClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Back-office d'administration (ROLE_ADMIN). Authentification par JWT (obtenu
 * de l'API, conservé en session). Édition de la taxonomie et déplacement des
 * questions (cf. spec §7).
 */
final class AdminController extends AbstractController
{
    public function __construct(
        private readonly ApiClient $api,
        private readonly AdminApiClient $admin,
        private readonly \App\Service\AdminCsrf $csrf,
    ) {
    }

    #[Route('/admin/login', name: 'admin_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isValid($request)) {
                $this->addFlash('error', 'Jeton de sécurité invalide, réessayez.');

                return $this->redirectToRoute('admin_login');
            }
            $ok = $this->admin->login(
                (string) $request->request->get('email'),
                (string) $request->request->get('password'),
            );
            if ($ok) {
                return $this->redirectToRoute('admin_dashboard');
            }
            $this->addFlash('error', 'Identifiants invalides ou compte non administrateur.');
        }

        return $this->render('admin/login.html.twig');
    }

    #[Route('/admin/logout', name: 'admin_logout', methods: ['GET'])]
    public function logout(): Response
    {
        $this->admin->logout();

        return $this->redirectToRoute('admin_login');
    }

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }

        return $this->render('admin/dashboard.html.twig', [
            'domains' => $this->api->domains(),
            'stats' => $this->admin->adminStats(),
        ]);
    }

    #[Route('/admin/settings', name: 'admin_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }

        if ($request->isMethod('POST')) {
            if (!$this->csrf->isValid($request)) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');

                return $this->redirectToRoute('admin_settings');
            }
            $result = $this->admin->saveSettings([
                'rag.system_prompt' => (string) $request->request->get('system_prompt'),
                'rag.temperature' => (string) $request->request->get('temperature'),
                'rag.max_tokens' => (string) $request->request->get('max_tokens'),
                'rag.neighbors' => (string) $request->request->get('neighbors'),
                'rag.model' => (string) $request->request->get('model'),
            ]);
            $this->addFlash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Paramètres IA enregistrés.' : 'Échec de l\'enregistrement.');

            return $this->redirectToRoute('admin_settings');
        }

        return $this->render('admin/settings.html.twig', ['settings' => $this->admin->getSettings()]);
    }

    #[Route('/admin/articles', name: 'admin_articles', methods: ['GET'])]
    public function articles(Request $request): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }

        return $this->render('admin/articles.html.twig', [
            'data' => $this->admin->articles(
                trim((string) $request->query->get('q', '')),
                max(1, (int) $request->query->get('page', '1')),
            ),
        ]);
    }

    #[Route('/admin/users', name: 'admin_users', methods: ['GET', 'POST'])]
    public function users(Request $request): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }

        if ($request->isMethod('POST')) {
            if (!$this->csrf->isValid($request)) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');

                return $this->redirectToRoute('admin_users');
            }
            $op = (string) $request->request->get('op');
            if ('create' === $op) {
                $res = $this->admin->createUser(
                    trim((string) $request->request->get('email')),
                    trim((string) $request->request->get('name')),
                    $request->request->all('roles'),
                );
                if ($res['ok']) {
                    $this->addFlash('success', \sprintf('Utilisateur créé. Mot de passe temporaire à transmettre : %s', $res['data']['temporaryPassword'] ?? '—'));
                } else {
                    $this->addFlash('error', (string) ($res['data']['error'] ?? 'Échec.'));
                }
            } elseif ('roles' === $op) {
                $res = $this->admin->updateUserRoles((int) $request->request->get('id'), $request->request->all('roles'));
                $this->addFlash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Rôles mis à jour.' : 'Échec.');
            }

            return $this->redirectToRoute('admin_users');
        }

        return $this->render('admin/users.html.twig', ['data' => $this->admin->users()]);
    }

    #[Route('/admin/harvest', name: 'admin_harvest', methods: ['GET'])]
    public function harvest(): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }

        return $this->render('admin/harvest.html.twig', ['status' => $this->admin->harvestStatus()]);
    }

    /** Polling AJAX du suivi de moisson (le navigateur ne peut pas joindre /api/admin directement). */
    #[Route('/admin/harvest/status.json', name: 'admin_harvest_status', methods: ['GET'])]
    public function harvestStatus(): JsonResponse
    {
        if (!$this->admin->isLogged()) {
            return new JsonResponse(['error' => 'Non authentifié.'], 401);
        }

        return new JsonResponse($this->admin->harvestStatus());
    }

    #[Route('/admin/openalex-search', name: 'admin_openalex_search', methods: ['GET'])]
    public function openalexSearch(Request $request): Response
    {
        if (!$this->admin->isLogged()) {
            return new JsonResponse(['items' => []], 401);
        }

        return new JsonResponse(['items' => $this->admin->openalexSearch(trim((string) $request->query->get('q', '')))]);
    }

    #[Route('/admin/r/{slug}', name: 'admin_node', methods: ['GET'])]
    public function node(string $slug): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }
        $node = $this->api->node($slug);
        if (null === $node) {
            throw $this->createNotFoundException('Rubrique introuvable.');
        }

        return $this->render('admin/node.html.twig', [
            'node' => $node,
            'answers' => $this->api->answers($slug),
        ]);
    }

    #[Route('/admin/action', name: 'admin_action', methods: ['POST'])]
    public function action(Request $request): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }
        if (!$this->csrf->isValid($request)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('admin_dashboard');
        }

        $action = (string) $request->request->get('action');
        $backSlug = (string) $request->request->get('back', '');

        $result = match ($action) {
            'rename' => $this->admin->renameNode(
                (int) $request->request->get('id'),
                trim((string) $request->request->get('label')),
                trim((string) $request->request->get('description')) ?: null,
            ),
            'add-child' => $this->admin->createNode(
                trim((string) $request->request->get('label')),
                (int) $request->request->get('parentId'),
                trim((string) $request->request->get('description')) ?: null,
            ),
            'move-node' => $this->moveNodeBySlug(
                (int) $request->request->get('id'),
                trim((string) $request->request->get('targetSlug')),
            ),
            'move-question' => $this->moveQuestionBySlug(
                (int) $request->request->get('questionId'),
                trim((string) $request->request->get('targetSlug')),
            ),
            'graft' => $this->admin->graftChildren((int) $request->request->get('id')),
            'harvest' => $this->admin->harvestNode((int) $request->request->get('id')),
            default => ['ok' => false, 'status' => 400, 'data' => ['error' => 'Action inconnue.']],
        };

        if ($result['ok']) {
            $this->addFlash('success', (string) ($result['data']['message'] ?? 'Action effectuée.'));
            // Création d'une rubrique : on suit le nouveau slug si fourni.
            if ('add-child' === $action && isset($result['data']['slug'])) {
                return $this->redirectToRoute('admin_node', ['slug' => $result['data']['slug']]);
            }
        } else {
            $this->addFlash('error', (string) ($result['data']['error'] ?? 'Erreur ('.$result['status'].').'));
        }

        return '' !== $backSlug
            ? $this->redirectToRoute('admin_node', ['slug' => $backSlug])
            : $this->redirectToRoute('admin_dashboard');
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    private function moveNodeBySlug(int $id, string $targetSlug): array
    {
        $target = $this->api->node($targetSlug);
        if (null === $target || !isset($target['id'])) {
            return ['ok' => false, 'status' => 404, 'data' => ['error' => 'Rubrique cible « '.$targetSlug.' » introuvable.']];
        }

        return $this->admin->moveNode($id, (int) $target['id']);
    }

    /** @return array{ok:bool,status:int,data:array<string,mixed>} */
    private function moveQuestionBySlug(int $questionId, string $targetSlug): array
    {
        $target = $this->api->node($targetSlug);
        if (null === $target || !isset($target['id'])) {
            return ['ok' => false, 'status' => 404, 'data' => ['error' => 'Rubrique cible « '.$targetSlug.' » introuvable.']];
        }

        return $this->admin->moveQuestion($questionId, (int) $target['id']);
    }
}
