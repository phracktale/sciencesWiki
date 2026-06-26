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
        private readonly \App\Service\UserApiClient $user,
        private readonly \App\Service\AdminCsrf $csrf,
        private readonly \Symfony\Contracts\HttpClient\HttpClientInterface $httpClient,
        private readonly \App\Service\ThemeService $theme,
    ) {
    }

    /** Connexion UNIQUE : on redirige l'ancienne URL BO vers le formulaire commun. */
    #[Route('/admin/login', name: 'admin_login', methods: ['GET'])]
    public function login(): Response
    {
        if ($this->admin->isLogged()) {
            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->redirectToRoute('login', ['back' => '/admin']);
    }

    #[Route('/admin/logout', name: 'admin_logout', methods: ['GET'])]
    public function logout(): Response
    {
        $this->admin->logout();
        $this->user->logout();

        return $this->redirectToRoute('admin_login');
    }

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function dashboard(Request $request): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }

        // Multi-types (cases à cocher par famille) + compat ancien ?type=
        $types = array_values(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            array_merge($request->query->all('types'), [$request->query->get('type', '')]),
        )));

        return $this->render('admin/dashboard.html.twig', [
            'domains' => $this->api->domains(),
            'stats' => $this->admin->adminStats($types),
        ]);
    }

    /** Ancienne URL : redirige vers Paramétrages › Général. */
    #[Route('/admin/settings', name: 'admin_settings', methods: ['GET'])]
    public function settings(): Response
    {
        return $this->admin->isLogged() ? $this->redirectToRoute('admin_settings_general') : $this->redirectToRoute('admin_login');
    }

    #[Route('/admin/settings/general', name: 'admin_settings_general', methods: ['GET', 'POST'])]
    public function settingsGeneral(Request $request): Response
    {
        return $this->saveSettingsPage($request, 'admin_settings_general', 'admin/settings_general.html.twig', static fn (Request $r): array => [
            'site.theme' => 'crt' === $r->request->get('site_theme') ? 'crt' : 'legacy',
            'site.framed' => $r->request->get('site_framed') ? '1' : '0',
            'mail.reroute_enabled' => $r->request->get('reroute_enabled') ? '1' : '0',
            'mail.reroute_to' => trim((string) $r->request->get('reroute_to')),
            'mod.notify_enabled' => $r->request->get('mod_notify_enabled') ? '1' : '0',
        ]);
    }

    #[Route('/admin/settings/ia', name: 'admin_settings_ai', methods: ['GET', 'POST'])]
    public function settingsAi(Request $request): Response
    {
        return $this->saveSettingsPage($request, 'admin_settings_ai', 'admin/settings_ai.html.twig', static fn (Request $r): array => [
            'rag.system_prompt' => (string) $r->request->get('system_prompt'),
            'rag.temperature' => (string) $r->request->get('temperature'),
            'rag.max_tokens' => (string) $r->request->get('max_tokens'),
            'rag.neighbors' => (string) $r->request->get('neighbors'),
            'rag.model' => (string) $r->request->get('model'),
            'wiki.model' => (string) $r->request->get('wiki_model'),
            'ai.light_model' => (string) $r->request->get('light_model'),
        ], withModels: true);
    }

    #[Route('/admin/settings/moisson', name: 'admin_settings_harvest', methods: ['GET', 'POST'])]
    public function settingsHarvest(Request $request): Response
    {
        return $this->saveSettingsPage($request, 'admin_settings_harvest', 'admin/settings_harvest.html.twig', static fn (Request $r): array => [
            'openalex.per_minute' => (string) $r->request->get('openalex_per_minute'),
            'openalex.per_day' => (string) $r->request->get('openalex_per_day'),
            'harvest.sort' => (string) $r->request->get('harvest_sort'),
            'harvest.recent_years' => (string) $r->request->get('harvest_recent_years'),
            'harvest.cap_per_rubric' => (string) $r->request->get('harvest_cap_per_rubric'),
            'harvest.max_per_run' => (string) $r->request->get('harvest_max_per_run'),
        ]);
    }

    /** @param callable(Request):array<string,string> $extract */
    private function saveSettingsPage(Request $request, string $route, string $template, callable $extract, bool $withModels = false): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isValid($request)) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');

                return $this->redirectToRoute($route);
            }
            $result = $this->admin->saveSettings($extract($request));
            // Le thème est lu en cache côté front : on invalide pour un effet immédiat.
            $this->theme->forget();
            $this->addFlash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Paramètres enregistrés.' : 'Échec de l\'enregistrement.');

            return $this->redirectToRoute($route);
        }
        $params = ['settings' => $this->admin->getSettings()];
        if ($withModels) {
            $params['models'] = $this->admin->models();
        }

        return $this->render($template, $params);
    }

    #[Route('/admin/questions', name: 'admin_questions', methods: ['GET'])]
    public function questions(Request $request): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }

        return $this->render('admin/questions.html.twig', [
            'data' => $this->api->latestQuestionsPage(30, max(1, (int) $request->query->get('page', '1'))),
        ]);
    }

    #[Route('/admin/wiki', name: 'admin_wiki', methods: ['GET'])]
    public function wikiList(Request $request): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }

        return $this->render('admin/wiki_list.html.twig', [
            'data' => $this->admin->wikiArticles(
                trim((string) $request->query->get('q', '')),
                trim((string) $request->query->get('status', '')),
            ),
        ]);
    }

    #[Route('/admin/wiki/{id}', name: 'admin_wiki_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function wikiDetail(int $id): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }
        $article = $this->admin->wikiArticle($id);
        if (null === $article) {
            $this->addFlash('error', 'Article introuvable.');

            return $this->redirectToRoute('admin_wiki');
        }

        return $this->render('admin/wiki_detail.html.twig', ['article' => $article]);
    }

    #[Route('/admin/duplications', name: 'admin_duplications', methods: ['GET'])]
    public function duplications(): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }

        return $this->render('admin/duplications.html.twig', ['data' => $this->admin->duplications()]);
    }

    #[Route('/admin/duplications/{id}/review', name: 'admin_duplication_review', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reviewDuplication(int $id, Request $request): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }
        if (!$this->csrf->isValid($request)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('admin_duplications');
        }
        $res = $this->admin->reviewDuplication($id, (string) $request->request->get('status'));
        $this->addFlash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Décision enregistrée.' : 'Échec de l\'enregistrement.');

        return $this->redirectToRoute('admin_duplications');
    }

    #[Route('/admin/upload-pdf', name: 'admin_pdf_upload', methods: ['GET'])]
    public function uploadPdfForm(): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }

        return $this->render('admin/upload_pdf.html.twig');
    }

    #[Route('/admin/upload-pdf', name: 'admin_pdf_upload_submit', methods: ['POST'])]
    public function uploadPdfSubmit(Request $request): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }
        if (!$this->csrf->isValid($request)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('admin_pdf_upload');
        }
        $file = $request->files->get('pdf');
        if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            $this->addFlash('error', 'Aucun PDF fourni.');

            return $this->redirectToRoute('admin_pdf_upload');
        }
        $res = $this->admin->uploadPdf($file, [
            'title' => trim((string) $request->request->get('title')),
            'doi' => trim((string) $request->request->get('doi')),
            'year' => trim((string) $request->request->get('year')),
            'venue' => trim((string) $request->request->get('venue')),
            'abstract' => trim((string) $request->request->get('abstract')),
        ]);
        if ($res['ok']) {
            $this->addFlash('success', ($res['data']['message'] ?? 'PDF importé.').' ('.($res['data']['chunks'] ?? 0).' fragments)');
        } else {
            $this->addFlash('error', 'Échec : '.($res['data']['error'] ?? 'erreur'));
        }

        return $this->redirectToRoute('admin_pdf_upload');
    }

    /** Bouton admin du wiki PUBLIC : lance la (re)génération IA de l'article d'une rubrique. */
    #[Route('/admin/node/{id}/regenerate-article', name: 'admin_node_regenerate_article', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function regenerateNodeArticle(int $id, Request $request): Response
    {
        $back = (string) $request->request->get('back', '/');
        $back = str_starts_with($back, '/') ? $back : '/';
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }
        if (!$this->csrf->isValid($request)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirect($back);
        }
        $res = $this->admin->regenerateNodeArticle($id);
        $this->addFlash($res['ok'] ? 'success' : 'error', $res['ok']
            ? ($res['data']['message'] ?? 'Génération lancée.')
            : 'Échec : '.($res['data']['error'] ?? 'erreur'));

        return $this->redirect($back);
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
            'type' => trim((string) $request->query->get('type', '')),
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

    /** Demandes « Nous rejoindre » (vue back-office). */
    #[Route('/admin/join-requests', name: 'admin_join', methods: ['GET'])]
    public function joinRequests(Request $request): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }

        return $this->render('admin/join.html.twig', [
            'data' => $this->admin->joinRequests(trim((string) $request->query->get('status', ''))),
            'status' => trim((string) $request->query->get('status', '')),
        ]);
    }

    /** Promouvoir (attribuer un rôle) ou rejeter une demande « Nous rejoindre ». */
    #[Route('/admin/join-requests/{id}/{op}', name: 'admin_join_op', requirements: ['id' => '\d+', 'op' => 'promote|reject'], methods: ['POST'])]
    public function joinOp(int $id, string $op, Request $request): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }
        if (!$this->csrf->isValid($request)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('admin_join');
        }
        if ('reject' === $op) {
            $res = $this->admin->rejectJoinRequest($id);
            $this->addFlash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Demande rejetée.' : 'Échec.');
        } else {
            $res = $this->admin->promoteJoinRequest($id, trim((string) $request->request->get('role')));
            if ($res['ok']) {
                $tmp = $res['data']['temporaryPassword'] ?? null;
                $this->addFlash('success', 'Promu : '.($res['data']['email'] ?? '').' → '.($res['data']['role'] ?? '')
                    .($tmp ? ' · mot de passe temporaire : '.$tmp.' (compte créé)' : ' (compte existant mis à jour)').'. E-mail de bienvenue envoyé (si Mailer configuré).');
            } else {
                $this->addFlash('error', 'Échec : '.($res['data']['error'] ?? 'erreur'));
            }
        }

        return $this->redirectToRoute('admin_join');
    }

    /** Propositions de roadmap (vue back-office). */
    #[Route('/admin/roadmap', name: 'admin_roadmap', methods: ['GET'])]
    public function roadmapProposals(Request $request): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }
        $status = trim((string) $request->query->get('status', ''));

        return $this->render('admin/roadmap.html.twig', [
            'data' => $this->admin->roadmapProposals($status),
            'status' => $status,
        ]);
    }

    /** Changer le statut d'une proposition de roadmap. */
    #[Route('/admin/roadmap/{id}/status', name: 'admin_roadmap_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function roadmapStatus(int $id, Request $request): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }
        if (!$this->csrf->isValid($request)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('admin_roadmap');
        }
        $res = $this->admin->setRoadmapStatus($id, trim((string) $request->request->get('status')));
        $this->addFlash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Statut mis à jour.' : 'Échec : '.($res['data']['error'] ?? 'erreur'));

        return $this->redirectToRoute('admin_roadmap');
    }

    /** Inscriptions newsletter par cible (vue back-office). */
    #[Route('/admin/newsletter', name: 'admin_newsletter', methods: ['GET'])]
    public function newsletterSignups(Request $request): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }

        return $this->render('admin/newsletter.html.twig', [
            'data' => $this->admin->newsletterSignups(trim((string) $request->query->get('audience', ''))),
            'audience' => trim((string) $request->query->get('audience', '')),
        ]);
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

    /** Actions admin sur une question depuis la page réponse (ROLE_ADMIN). */
    #[Route('/admin/q/{id}/{op}', name: 'admin_question_op', requirements: ['id' => '\d+', 'op' => 'edit|regenerate|delete'], methods: ['POST'])]
    public function questionOp(int $id, string $op, Request $request): Response
    {
        if (!$this->admin->isLogged()) {
            return $this->redirectToRoute('admin_login');
        }
        if (!$this->csrf->isValid($request)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('home', ['_locale' => 'fr']);
        }
        $back = (int) $request->request->get('back', '0'); // id de la réponse courante

        if ('delete' === $op) {
            $res = $this->admin->deleteQuestion($id);
            $this->addFlash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Question et réponses supprimées.' : 'Échec de la suppression.');

            return $this->redirectToRoute('home', ['_locale' => 'fr']);
        }

        if ('edit' === $op) {
            $res = $this->admin->editQuestion(
                $id,
                trim((string) $request->request->get('text')),
                trim((string) $request->request->get('title')) ?: null,
            );
            $this->addFlash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Question éditée. Pensez à régénérer la réponse.' : 'Échec de l\'édition.');

            return $back > 0 ? $this->redirectToRoute('answer', ['_locale' => 'fr', 'id' => $back]) : $this->redirectToRoute('home', ['_locale' => 'fr']);
        }

        // regenerate
        $res = $this->admin->regenerateQuestion($id);
        if ($res['ok'] && isset($res['data']['answerId'])) {
            $this->addFlash('success', 'Réponse régénérée.');

            return $this->redirectToRoute('answer', ['_locale' => 'fr', 'id' => (int) $res['data']['answerId']]);
        }
        $this->addFlash('error', 'Échec de la régénération : '.($res['data']['error'] ?? 'erreur'));

        return $back > 0 ? $this->redirectToRoute('answer', ['_locale' => 'fr', 'id' => $back]) : $this->redirectToRoute('home', ['_locale' => 'fr']);
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
