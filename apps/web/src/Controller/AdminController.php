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
        private readonly \Symfony\Contracts\HttpClient\HttpClientInterface $httpClient,
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
                'openalex.per_minute' => (string) $request->request->get('openalex_per_minute'),
                'openalex.per_day' => (string) $request->request->get('openalex_per_day'),
                'harvest.sort' => (string) $request->request->get('harvest_sort'),
                'harvest.recent_years' => (string) $request->request->get('harvest_recent_years'),
                'harvest.cap_per_rubric' => (string) $request->request->get('harvest_cap_per_rubric'),
                'harvest.max_per_run' => (string) $request->request->get('harvest_max_per_run'),
            ]);
            $this->addFlash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Paramètres IA enregistrés.' : 'Échec de l\'enregistrement.');

            return $this->redirectToRoute('admin_settings');
        }

        return $this->render('admin/settings.html.twig', [
            'settings' => $this->admin->getSettings(),
            'models' => $this->admin->models(),
        ]);
    }

    #[Route('/admin/articles', name: 'admin_articles', methods: ['GET'])]
    public function articles(Request $request): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }

        $filters = [
            'journal' => (int) $request->query->get('journal', '0') ?: '',
            'indexation' => trim((string) $request->query->get('indexation', '')),
            'domain' => trim((string) $request->query->get('domain', '')),
            'pdf' => trim((string) $request->query->get('pdf', '')),
            'access' => trim((string) $request->query->get('access', '')),
            'sort' => trim((string) $request->query->get('sort', '')),
            'dir' => trim((string) $request->query->get('dir', '')),
        ];

        return $this->render('admin/articles.html.twig', [
            'data' => $this->admin->articles(
                trim((string) $request->query->get('q', '')),
                max(1, (int) $request->query->get('page', '1')),
                $filters,
            ),
            'filters' => $filters,
            'journalName' => trim((string) $request->query->get('journal_name', '')),
            'domains' => $this->api->domains(),
        ]);
    }

    /** Proxy d'autocomplete des revues (le JWT admin reste côté serveur). */
    #[Route('/admin/journals', name: 'admin_journals', methods: ['GET'])]
    public function journalsAutocomplete(Request $request): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->json(['items' => []], 401);
        }

        return $this->json(['items' => $this->admin->journalsSearch(trim((string) $request->query->get('q', '')))]);
    }

    #[Route('/admin/authors', name: 'admin_authors', methods: ['GET'])]
    public function authors(Request $request): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }

        return $this->render('admin/authors.html.twig', [
            'data' => $this->admin->authorsList(
                trim((string) $request->query->get('q', '')),
                max(1, (int) $request->query->get('page', '1')),
                trim((string) $request->query->get('sort', '')),
                trim((string) $request->query->get('dir', '')),
            ),
        ]);
    }

    #[Route('/admin/articles/{id}', name: 'admin_article', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function article(int $id): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }
        $pub = $this->admin->publication($id);
        if (null === $pub) {
            throw $this->createNotFoundException('Article introuvable.');
        }

        return $this->render('admin/article.html.twig', ['pub' => $pub]);
    }

    /** Génère un lien de dépôt auteur pour un article et l'affiche (à transmettre aux auteurs). */
    #[Route('/admin/articles/{id}/contribution-token', name: 'admin_article_contribution', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function articleContributionToken(int $id, Request $request): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }
        if (!$this->csrf->isValid($request)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('admin_article', ['id' => $id]);
        }
        $res = $this->admin->createContributionToken($id);
        if ($res['ok'] && isset($res['data']['path'])) {
            $url = $request->getSchemeAndHttpHost().$res['data']['path'];
            $this->addFlash('success', 'Lien de dépôt auteur (valable 90 j, usage unique) — à transmettre aux auteurs : '.$url);
        } else {
            $this->addFlash('error', 'Échec de génération : '.($res['data']['error'] ?? 'erreur inconnue'));
        }

        return $this->redirectToRoute('admin_article', ['id' => $id]);
    }

    /** Proxy du PDF en accès libre (même origine → visualiseur natif + impression). Anti-SSRF. */
    #[Route('/admin/articles/{id}/pdf', name: 'admin_article_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function articlePdf(int $id, Request $request): Response
    {
        if (!$this->admin->isLogged()) {
            return new Response('', 401);
        }
        $pub = $this->admin->publication($id);
        $url = \is_array($pub) ? ($pub['oaUrl'] ?? null) : null;
        if (!\is_string($url) || '' === $url) {
            return new Response('Aucun PDF en accès libre pour cet article.', 404);
        }

        // Suivi manuel des redirections avec validation anti-SSRF à chaque saut :
        // résolution + validation de toutes les IP, puis ÉPINGLAGE de la connexion
        // à l'IP validée (anti DNS-rebinding / IPv6).
        $current = $url;
        $response = null;
        for ($hop = 0; $hop < 5; ++$hop) {
            $p = parse_url($current);
            $scheme = strtolower($p['scheme'] ?? '');
            $host = trim((string) ($p['host'] ?? ''), '[]');
            if (!\in_array($scheme, ['http', 'https'], true) || '' === $host) {
                return new Response('URL non autorisée.', 422);
            }
            $pinnedIp = $this->validatedIp($host);
            if (null === $pinnedIp) {
                return new Response('URL non autorisée (hôte non public).', 422);
            }
            $options = ['timeout' => 30, 'max_redirects' => 0];
            if (!filter_var($host, \FILTER_VALIDATE_IP)) {
                $options['resolve'] = [$host => $pinnedIp];
            }
            $response = $this->httpClient->request('GET', $current, $options);
            $status = $response->getStatusCode();
            if ($status >= 300 && $status < 400) {
                $loc = $response->getHeaders(false)['location'][0] ?? null;
                if (null === $loc) {
                    break;
                }
                $current = str_starts_with($loc, 'http') ? $loc : rtrim($url, '/').'/'.ltrim($loc, '/');
                continue;
            }
            break;
        }
        if (null === $response || 200 !== $response->getStatusCode()) {
            return new Response('PDF inaccessible.', 502);
        }
        $type = strtolower($response->getHeaders(false)['content-type'][0] ?? '');
        $content = $response->getContent();
        if (!str_contains($type, 'pdf') && !str_starts_with($content, '%PDF')) {
            return new Response('La source n\'est pas un PDF direct (page éditeur).', 415);
        }

        $disposition = $request->query->getBoolean('dl') ? 'attachment' : 'inline';

        return new Response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="article-'.$id.'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Résout l'hôte (A + AAAA), valide que TOUTES les IP sont publiques, et renvoie
     * UNE IP validée à épingler. null si non résolu ou IP privée/réservée (échec fermé).
     */
    private function validatedIp(string $host): ?string
    {
        if (filter_var($host, \FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host) ? $host : null;
        }
        $ips = [];
        foreach (@dns_get_record($host, \DNS_A) ?: [] as $r) {
            if (isset($r['ip'])) {
                $ips[] = $r['ip'];
            }
        }
        foreach (@dns_get_record($host, \DNS_AAAA) ?: [] as $r) {
            if (isset($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        if ([] === $ips) {
            $ips = gethostbynamel($host) ?: [];
        }
        if ([] === $ips) {
            return null;
        }
        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                return null;
            }
        }

        return $ips[0];
    }

    /** IP publique uniquement (rejette privées/réservées + IPv4-mapped IPv6). */
    private function isPublicIp(string $ip): bool
    {
        // Rejette explicitement les IPv6 mappées/compatibles IPv4 (ex. ::ffff:169.254.169.254).
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m) || preg_match('/^::(\d+\.\d+\.\d+\.\d+)$/', $ip, $m)) {
            $ip = $m[1];
        }
        if ('0.0.0.0' === $ip) {
            return false;
        }

        return false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE);
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

    #[Route('/admin/activity', name: 'admin_activity', methods: ['GET'])]
    public function activity(Request $request): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }

        return $this->render('admin/activity.html.twig', [
            'data' => $this->admin->activity(
                trim((string) $request->query->get('category', '')),
                max(1, (int) $request->query->get('page', '1')),
            ),
        ]);
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

    /** Relance/annule une moisson depuis le suivi (fetch JSON ; CSRF dans le corps). */
    #[Route('/admin/harvest/{op}', name: 'admin_harvest_op', methods: ['POST'], requirements: ['op' => 'relaunch|cancel|delete'])]
    public function harvestOp(string $op, Request $request): JsonResponse
    {
        if (!$this->admin->isLogged()) {
            return new JsonResponse(['error' => 'Non authentifié.'], 401);
        }
        $payload = json_decode($request->getContent() ?: '[]', true) ?: [];
        if (!\is_array($payload) || !$this->csrf->isValidToken((string) ($payload['_csrf'] ?? ''))) {
            return new JsonResponse(['error' => 'Jeton de sécurité invalide.'], 419);
        }
        $nodeId = (int) ($payload['nodeId'] ?? 0);
        if ($nodeId <= 0) {
            return new JsonResponse(['error' => 'Rubrique inconnue.'], 422);
        }

        $result = match ($op) {
            'relaunch' => $this->admin->harvestNode($nodeId),
            'cancel' => $this->admin->cancelHarvest($nodeId),
            'delete' => $this->admin->deleteHarvestLine($nodeId),
            default => ['ok' => false, 'status' => 400, 'data' => ['error' => 'Action inconnue.']],
        };

        return new JsonResponse($result['data'], $result['ok'] ? 200 : ($result['status'] ?: 500));
    }

    /** Nettoyage du journal des moissons (doublons / terminées / tout). */
    #[Route('/admin/harvest/cleanup', name: 'admin_harvest_cleanup', methods: ['POST'])]
    public function harvestCleanup(Request $request): JsonResponse
    {
        if (!$this->admin->isLogged()) {
            return new JsonResponse(['error' => 'Non authentifié.'], 401);
        }
        $payload = json_decode($request->getContent() ?: '[]', true) ?: [];
        if (!\is_array($payload) || !$this->csrf->isValidToken((string) ($payload['_csrf'] ?? ''))) {
            return new JsonResponse(['error' => 'Jeton de sécurité invalide.'], 419);
        }
        $result = $this->admin->cleanupHarvest((string) ($payload['mode'] ?? ''));

        return new JsonResponse($result['data'], $result['ok'] ? 200 : ($result['status'] ?: 500));
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

    /** Upload d'une image de couverture pour une rubrique (stockée + servie par le web). */
    #[Route('/admin/r/{slug}/cover', name: 'admin_node_cover', methods: ['POST'])]
    public function uploadCover(string $slug, Request $request): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }
        if (!$this->csrf->isValid($request)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('admin_node', ['slug' => $slug]);
        }
        $node = $this->api->node($slug);
        $file = $request->files->get('cover');
        if (null === $node || !isset($node['id']) || null === $file) {
            $this->addFlash('error', 'Rubrique ou fichier manquant.');

            return $this->redirectToRoute('admin_node', ['slug' => $slug]);
        }

        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        if (!\in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || $file->getSize() > 5 * 1024 * 1024) {
            $this->addFlash('error', 'Image invalide (JPG/PNG/WebP, 5 Mo max).');

            return $this->redirectToRoute('admin_node', ['slug' => $slug]);
        }

        $dir = $this->getParameter('kernel.project_dir').'/public/uploads/domains';
        $name = $node['id'].'.'.$ext;
        try {
            $file->move($dir, $name);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Échec de l\'enregistrement : '.$e->getMessage());

            return $this->redirectToRoute('admin_node', ['slug' => $slug]);
        }
        // URL servie par le web, horodatée pour casser le cache navigateur.
        $this->admin->setNodeImage((int) $node['id'], '/uploads/domains/'.$name.'?v='.time());
        $this->addFlash('success', 'Image de couverture mise à jour.');

        return $this->redirectToRoute('admin_node', ['slug' => $slug]);
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
                trim((string) $request->request->get('image_url')) ?: null,
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
            'delete-question' => $this->admin->deleteQuestion((int) $request->request->get('questionId')),
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
