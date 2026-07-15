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

    /**
     * Assistant IA (Open WebUI en iframe, SSO forward-auth) — tout profil connecté.
     * La page est protégée par login ; l'iframe ne charge donc que pour un
     * utilisateur déjà authentifié → pas de redirection dans l'iframe.
     */
    #[Route('/{_locale}/chat', name: 'chat', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function chat(): Response
    {
        if (!$this->user->isLogged()) {
            return $this->redirectToRoute('login', ['back' => '/fr/chat']);
        }

        return $this->render('wiki/chat.html.twig');
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

    /** Espace enseignant : gestion de classe + outils pédagogiques. */
    #[Route('/{_locale}/enseignant', name: 'teacher_dashboard', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function teacher(): Response
    {
        if (!$this->user->isLogged()) {
            return $this->redirectToRoute('login', ['back' => '/fr/enseignant']);
        }
        if (!$this->user->canTeach()) {
            $this->addFlash('error', 'Espace réservé aux enseignants (ROLE_TEACHER).');

            return $this->redirectToRoute('home');
        }

        return $this->render('wiki/teacher.html.twig', ['classes' => $this->user->myClasses()['classes'] ?? []]);
    }

    /** Crée une classe (depuis l'espace enseignant). */
    #[Route('/{_locale}/enseignant/classe', name: 'teacher_class_create', requirements: ['_locale' => 'fr'], methods: ['POST'])]
    public function createClass(Request $request): Response
    {
        if (!$this->user->isLogged() || !$this->user->canTeach()) {
            return $this->redirectToRoute('teacher_dashboard');
        }
        if (!$this->csrf->isValid($request)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
        } else {
            $res = $this->user->createClass(trim((string) $request->request->get('name', '')));
            $this->addFlash($res['ok'] ? 'success' : 'error', $res['ok']
                ? 'Classe « '.($res['data']['name'] ?? '').' » créée.'
                : (string) ($res['data']['error'] ?? 'Échec de la création.'));
        }

        return $this->redirectToRoute('teacher_dashboard');
    }

    /** Invite un élève dans une classe (par e-mail). */
    #[Route('/{_locale}/enseignant/classe/{id}/inviter', name: 'teacher_class_invite', requirements: ['_locale' => 'fr', 'id' => '\d+'], methods: ['POST'])]
    public function inviteStudent(int $id, Request $request): Response
    {
        if (!$this->user->isLogged() || !$this->user->canTeach()) {
            return $this->redirectToRoute('teacher_dashboard');
        }
        if (!$this->csrf->isValid($request)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
        } else {
            $res = $this->user->inviteStudent($id, trim((string) $request->request->get('email', '')));
            $this->addFlash($res['ok'] ? 'success' : 'error', (string) ($res['data']['message'] ?? $res['data']['error'] ?? 'Échec de l’invitation.'));
        }

        return $this->redirectToRoute('teacher_dashboard');
    }

    /** Espace élève : ses classes + outils. */
    #[Route('/{_locale}/eleve', name: 'student_dashboard', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function student(): Response
    {
        if (!$this->user->isLogged()) {
            return $this->redirectToRoute('login', ['back' => '/fr/eleve']);
        }
        if (!$this->user->hasRole('ROLE_STUDENT')) {
            $this->addFlash('error', 'Espace réservé aux élèves (ROLE_STUDENT).');

            return $this->redirectToRoute('home');
        }

        return $this->render('wiki/student.html.twig', ['classes' => $this->user->joinedClasses()['classes'] ?? []]);
    }

    /** Page de jonction d'une classe via un lien d'invitation (token). Accessible non connecté. */
    #[Route('/{_locale}/classe/rejoindre/{token}', name: 'class_join', requirements: ['_locale' => 'fr', 'token' => '[a-f0-9]{16,}'], methods: ['GET', 'POST'])]
    public function classJoin(string $token, Request $request): Response
    {
        $self = '/fr/classe/rejoindre/'.$token;

        if ($request->isMethod('POST')) {
            if (!$this->user->isLogged()) {
                return $this->redirectToRoute('login', ['back' => $self]);
            }
            if (!$this->csrf->isValid($request)) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');

                return $this->redirect($self);
            }
            $res = $this->user->joinClass($token);
            $this->addFlash($res['ok'] ? 'success' : 'error', (string) ($res['data']['message'] ?? $res['data']['error'] ?? 'Échec.'));

            return $res['ok'] ? $this->redirectToRoute('student_dashboard') : $this->redirect($self);
        }

        // On normalise en booléens simples (le template évite ainsi les tests Twig
        // « is defined » sur des clés potentiellement absentes).
        $preview = $this->user->classInvitationPreview($token);
        $found = \is_array($preview) && isset($preview['className']);

        return $this->render('wiki/class_join.html.twig', [
            'joinUrl' => $self,
            'logged' => $this->user->isLogged(),
            'found' => $found,
            'expired' => $found && ($preview['expired'] ?? false),
            'accepted' => $found && ($preview['accepted'] ?? false),
            'className' => $found ? (string) $preview['className'] : '',
            'teacher' => $found ? (string) ($preview['teacher'] ?? '') : '',
        ]);
    }

    /**
     * Boîte à outils — évaluation méthodologique AXIS à la demande sur une étude
     * (chercheur / enseignant / élève). Saisie de l'identifiant de l'étude →
     * appel API → affichage du résultat (ou du verrou d'applicabilité).
     */
    // Page unifiée des analyses (recherche + upload + pré-diag du devis + un bouton par
    // grille applicable). Nom de route conservé (`axis_tool`) pour ne pas casser les
    // nombreux path('axis_tool') ; l'URL publique est désormais /fr/analyses.
    /** Ancienne URL de l'outil → redirection permanente vers /fr/analyses (marque-pages). */
    #[Route('/{_locale}/outils/axis', name: 'axis_tool_legacy', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function axisToolLegacy(): Response
    {
        return $this->redirectToRoute('axis_tool', ['_locale' => 'fr'], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/{_locale}/analyses', name: 'axis_tool', requirements: ['_locale' => 'fr'], methods: ['GET', 'POST'])]
    public function axisTool(Request $request): Response
    {
        if (!$this->user->isLogged()) {
            return $this->redirectToRoute('login', ['back' => '/fr/analyses']);
        }
        if (!$this->user->canUseAxis()) {
            $this->addFlash('error', 'Outil réservé aux espaces recherche / pédagogie.');

            return $this->redirectToRoute('home');
        }

        $result = null;
        $pending = null;
        $error = null;
        $candidates = null;
        $toolStates = [];
        $privateStudy = null;
        $abstractOnly = null;
        $query = trim((string) $request->request->get('query', ''));
        $doi = trim((string) $request->request->get('doi', ''));
        $id = (int) $request->request->get('id', 0);

        $tool = $request->request->get('tool', 'axis');
        $tool = \in_array($tool, ['axis', 'rob2', 'amstar2', 'mmat'], true) ? $tool : 'axis';
        // « Refaire l'évaluation » : purge et recalcul (nouveau modèle…).
        $force = '1' === $request->request->get('force');

        if ($request->isMethod('POST')) {
            if (!$this->csrf->isValid($request)) {
                $error = 'Jeton de sécurité invalide.';
            } elseif ($id > 0) {
                // Étude déposée (privée) évaluée par son id.
                $this->triggerAppraisalById($id, $tool, $result, $pending, $error, $abstractOnly, $force);
                if ('1' === $request->request->get('private')) {
                    $privateStudy = ['id' => $id];
                }
            } elseif ('' !== $doi) {
                // Clic sur un résultat (ou DOI fourni) → mise en file / résultat caché.
                $this->triggerAppraisal($doi, $tool, $result, $pending, $error, $abstractOnly, $force);
            } elseif ('' !== $query) {
                if (1 === preg_match('#10\.\d{4,9}/\S+#', $query, $m)) {
                    // L'utilisateur a collé un DOI : on évalue directement.
                    $this->triggerAppraisal($m[0], $tool, $result, $pending, $error, $abstractOnly);
                } else {
                    $this->searchCandidates($query, $candidates, $toolStates, $error);
                }
            } else {
                $error = 'Saisissez un titre, des mots-clés ou un DOI.';
            }
        } elseif ('' !== ($gq = trim((string) $request->query->get('q', '')))) {
            // Arrivée via le bouton « Analyser » (GET ?q=DOI|titre) : on affiche l'étude
            // et ses boutons d'analyse. Le déclenchement, lui, reste en POST + CSRF.
            $query = $gq;
            $this->searchCandidates($query, $candidates, $toolStates, $error);
        }

        return $this->render('wiki/axis_tool.html.twig', [
            'result' => $result, 'pending' => $pending, 'error' => $error,
            'candidates' => $candidates, 'toolStates' => $toolStates, 'query' => $query,
            'privateStudy' => $privateStudy, 'abstractOnly' => $abstractOnly,
        ]);
    }

    /**
     * Dépôt d'un PDF d'étude (absente du corpus) pour évaluation critique : upload API
     * → étude PRIVÉE → déclenchement de l'évaluation. Rend l'outil avec le résultat en
     * cours + la possibilité de proposer l'ajout au corpus.
     */
    #[Route('/{_locale}/outils/axis/deposer', name: 'axis_deposit', requirements: ['_locale' => 'fr'], methods: ['POST'])]
    public function axisDeposit(Request $request): Response
    {
        if (!$this->user->isLogged() || !$this->user->canUseAxis()) {
            return $this->redirectToRoute('axis_tool', ['_locale' => 'fr']);
        }
        if (!$this->csrf->isValid($request)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('axis_tool', ['_locale' => 'fr']);
        }
        $file = $request->files->get('pdf');
        if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            $this->addFlash('error', 'Veuillez joindre un fichier PDF.');

            return $this->redirectToRoute('axis_tool', ['_locale' => 'fr']);
        }
        // Upload rejeté par PHP (trop volumineux…) : tmp_name vide → ne PAS construire le
        // multipart (sinon « Path must not be empty »). Message explicite à la place.
        if (!$file->isValid() || '' === (string) $file->getPathname()) {
            $tooBig = \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true);
            $this->addFlash('error', $tooBig
                ? 'Le PDF est trop volumineux (limite 40 Mo).'
                : 'Le fichier n’a pas pu être reçu. Réessayez.');

            return $this->redirectToRoute('axis_tool', ['_locale' => 'fr']);
        }
        $tool = $request->request->get('tool', 'axis');
        $tool = \in_array($tool, ['axis', 'rob2', 'amstar2', 'mmat'], true) ? $tool : 'axis';

        $res = $this->user->uploadStudy($file, [
            'title' => trim((string) $request->request->get('title', '')),
            'doi' => trim((string) $request->request->get('doi', '')),
            'year' => trim((string) $request->request->get('year', '')),
            'venue' => trim((string) $request->request->get('venue', '')),
            'abstract' => trim((string) $request->request->get('abstract', '')),
        ]);
        if (!$res['ok']) {
            $this->addFlash('error', (string) ($res['data']['error'] ?? 'Échec du dépôt de l’étude.'));

            return $this->redirectToRoute('axis_tool', ['_locale' => 'fr']);
        }

        $pubId = (int) ($res['data']['publicationId'] ?? 0);
        $inCorpus = (bool) ($res['data']['inCorpus'] ?? false);
        $result = null;
        $pending = null;
        $error = null;
        $this->triggerAppraisalById($pubId, $tool, $result, $pending, $error);

        return $this->render('wiki/axis_tool.html.twig', [
            'result' => $result, 'pending' => $pending, 'error' => $error,
            'candidates' => null, 'toolStates' => [], 'query' => '',
            // Étude privée → proposer l'ajout au corpus (sauf si déjà dans le corpus).
            'privateStudy' => $inCorpus ? null : ['id' => $pubId], 'abstractOnly' => null,
        ]);
    }

    /**
     * Proxy JSON : demande d'ajout d'une étude déposée au corpus (validation comité).
     */
    #[Route('/{_locale}/outils/axis/corpus', name: 'axis_corpus', requirements: ['_locale' => 'fr'], methods: ['POST'])]
    public function axisCorpus(Request $request): JsonResponse
    {
        if (!$this->user->isLogged() || !$this->user->canUseAxis()) {
            return new JsonResponse(['ok' => false, 'error' => 'Accès refusé.'], 403);
        }
        if (!$this->csrf->isValidToken((string) ($request->request->get('_csrf') ?? $request->headers->get('X-CSRF-Token', '')))) {
            return new JsonResponse(['ok' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }
        $id = (int) $request->request->get('id', 0);
        if ($id < 1) {
            return new JsonResponse(['ok' => false, 'error' => 'Étude invalide.'], 422);
        }
        $res = $this->user->submitStudyToCorpus($id, trim((string) $request->request->get('note', '')));

        return new JsonResponse($res['data'] + ['ok' => (bool) ($res['data']['ok'] ?? $res['ok'])], $res['status'] > 0 ? $res['status'] : 502);
    }

    /** Espace « mes études » : études déposées par l'utilisateur + leur statut. */
    #[Route('/{_locale}/mes-etudes', name: 'my_studies', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function myStudies(): Response
    {
        if (!$this->user->isLogged()) {
            return $this->redirectToRoute('login', ['back' => '/fr/mes-etudes']);
        }
        if (!$this->user->canUseAxis()) {
            $this->addFlash('error', 'Espace réservé aux espaces recherche / pédagogie.');

            return $this->redirectToRoute('home');
        }

        return $this->render('wiki/my_studies.html.twig', ['studies' => $this->user->myStudies()['items'] ?? []]);
    }

    /**
     * Met en file l'évaluation d'un OUTIL (axis | rob2) en asynchrone, ou renvoie le
     * résultat déjà calculé (cache). L'appel API est court (dispatch) ; l'outil poll ensuite.
     *
     * @param array<string,mixed>|null $result
     * @param array<string,mixed>|null $pending
     */
    /**
     * Recherche d'études candidates (titre/mots-clés/DOI) + pré-détection des outils
     * d'évaluation applicables par DOI (devis classé en asynchrone si besoin).
     *
     * @param array<int,mixed>|null   $candidates
     * @param array<string,mixed>     $toolStates
     */
    private function searchCandidates(string $query, ?array &$candidates, array &$toolStates, ?string &$error): void
    {
        $res = $this->user->send('GET', '/api/search?type=publications&limit=15&q='.rawurlencode($query));
        $candidates = $res['ok'] ? ($res['data']['results'] ?? []) : [];
        if ([] === $candidates) {
            $error = 'Aucune étude trouvée pour « '.$query.' ».';

            return;
        }
        $dois = array_values(array_filter(array_map(static fn (array $c): ?string => $c['doi'] ?? null, $candidates)));
        if ([] !== $dois) {
            $toolStates = $this->user->appraisalTools($dois)['results'] ?? [];
        }
    }

    private function triggerAppraisal(string $doi, string $tool, ?array &$result, ?array &$pending, ?string &$error, ?array &$abstractOnly = null, bool $force = false): void
    {
        $path = \in_array($tool, ['rob2', 'amstar2', 'mmat'], true) ? '/api/me/'.$tool : '/api/me/axis';
        $res = $this->user->send('POST', $path.($force ? '?force=1' : ''), ['doi' => $doi]);
        $data = $res['data'];
        switch ($data['status'] ?? null) {
            case 'ready':
                $result = ['tool' => $tool] + $data; // (axis ne pose pas 'tool' ; rob2 oui)
                break;
            case 'pending':
                $pending = ['doi' => $doi, 'tool' => $tool, 'publication' => $data['publication'] ?? null];
                break;
            case 'abstract_only':
                $abstractOnly = ['tool' => $tool, 'message' => $data['message'] ?? null, 'publication' => $data['publication'] ?? []];
                break;
            case 'not_found':
                $error = 'Cette étude (DOI '.$doi.') n’est pas présente dans le corpus.';
                break;
            default:
                $error = (string) ($data['error'] ?? ('Échec (HTTP '.$res['status'].').'));
        }
    }

    /**
     * Comme triggerAppraisal mais par id de publication (étude déposée / privée).
     *
     * @param array<string,mixed>|null $result
     * @param array<string,mixed>|null $pending
     */
    private function triggerAppraisalById(int $id, string $tool, ?array &$result, ?array &$pending, ?string &$error, ?array &$abstractOnly = null, bool $force = false): void
    {
        $path = '/api/me/'.(\in_array($tool, ['rob2', 'amstar2', 'mmat'], true) ? $tool : 'axis').'/'.$id;
        $res = $this->user->send('POST', $path.($force ? '?force=1' : ''));
        $data = $res['data'];
        switch ($data['status'] ?? null) {
            case 'ready':
                $result = ['tool' => $tool] + $data;
                break;
            case 'pending':
                $pending = ['id' => $id, 'doi' => '', 'tool' => $tool, 'publication' => $data['publication'] ?? null];
                break;
            case 'abstract_only':
                $abstractOnly = ['tool' => $tool, 'message' => $data['message'] ?? null, 'publication' => $data['publication'] ?? []];
                break;
            case 'not_found':
                $error = 'Étude introuvable.';
                break;
            default:
                $error = (string) ($data['error'] ?? ('Échec (HTTP '.$res['status'].').'));
        }
    }

    /** Polling de l'état d'une évaluation (axis | rob2), interrogé par l'outil. */
    #[Route('/{_locale}/outils/axis/statut', name: 'axis_status', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function axisStatus(Request $request): JsonResponse
    {
        if (!$this->user->isLogged() || !$this->user->canUseAxis()) {
            return new JsonResponse(['status' => 'denied'], 403);
        }
        $tool = $request->query->get('tool', 'axis');
        $tool = \in_array($tool, ['axis', 'rob2', 'amstar2', 'mmat'], true) ? $tool : 'axis';
        $doi = (string) $request->query->get('doi', '');
        $id = (int) $request->query->get('id', 0);
        // Étude déposée (privée) → polling par id ; sinon par DOI.
        $q = $id > 0 ? 'id='.$id : 'doi='.rawurlencode($doi);
        $res = $this->user->send('GET', '/api/me/'.$tool.'/status?'.$q);

        return new JsonResponse(['status' => (string) ($res['data']['status'] ?? 'unknown')]);
    }

    /**
     * Analyses méthodologiques déjà calculées pour une étude (par id) — affichées dans
     * le panneau de détail (explorer & co.). RÉSERVÉ aux rôles outils : un public
     * anonyme ne voit que les analyses validées comité (exposées par /api/articles).
     */
    #[Route('/{_locale}/etude/{id}/analyses', name: 'study_appraisals', requirements: ['_locale' => 'fr', 'id' => '\d+'], methods: ['GET'])]
    public function studyAppraisals(int $id): JsonResponse
    {
        if (!$this->user->isLogged() || !$this->user->canUseAxis()) {
            return new JsonResponse(['appraisals' => new \stdClass(), 'role' => false]);
        }

        return new JsonResponse(['appraisals' => $this->user->existingAppraisals($id) ?: new \stdClass(), 'role' => true]);
    }

    /**
     * Ré-évaluation forcée d'une étude (bouton « Refaire l'évaluation »). Purge et
     * recalcule côté API (utile après changement de modèle). RÉSERVÉ aux rôles outils.
     */
    #[Route('/{_locale}/etude/{id}/reevaluer', name: 'study_reappraise', requirements: ['_locale' => 'fr', 'id' => '\d+'], methods: ['POST'])]
    public function studyReappraise(int $id, Request $request): JsonResponse
    {
        if (!$this->user->isLogged() || !$this->user->canUseAxis()) {
            return new JsonResponse(['status' => 'denied'], 403);
        }
        if (!$this->csrf->isValid($request)) {
            return new JsonResponse(['status' => 'csrf'], 403);
        }
        $res = $this->user->send('POST', '/api/me/axis/'.$id.'?force=1');

        return new JsonResponse(['status' => (string) ($res['data']['status'] ?? 'unknown')]);
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
    /**
     * PDF (à la volée, non stocké) de l'évaluation critique AXIS d'une étude, mis en forme
     * à la charte SciencesWiki. En-tête (type d'analyse + métas), résumé, méthodologie AXIS,
     * points d'attention IA, modèle/temps/tokens, 20 items, synthèse.
     */
    #[Route('/{_locale}/etude/{id}/axis/pdf', name: 'axis_pdf', requirements: ['_locale' => 'fr', 'id' => '\d+'], methods: ['GET'])]
    public function axisPdf(int $id): Response
    {
        if (!$this->user->isLogged()) {
            return $this->redirectToRoute('login', ['back' => '/fr/etude/'.$id.'/axis/pdf']);
        }
        $res = $this->user->send('GET', '/api/articles/'.$id);
        $article = $res['ok'] ? $res['data'] : null;
        $axis = \is_array($article) ? ($article['axis'] ?? null) : null;
        if (null === $axis) {
            $this->addFlash('error', 'Aucune évaluation AXIS disponible pour cette étude.');

            return $this->redirectToRoute('axis_tool', ['_locale' => 'fr']);
        }
        $html = $this->renderView('pdf/axis_analysis.html.twig', ['article' => $article, 'axis' => $axis]);

        return $this->stampPdf($html, 'Évaluation AXIS — '.(string) ($article['title'] ?? 'étude'), 'axis-'.$id, true);
    }

    /**
     * Rend un fragment HTML en PDF sur le gabarit charté (en-tête/logo/pied). Générique :
     * réutilisé par la revue de littérature et les analyses. $inline=true → ouverture en
     * ligne dans le navigateur ; sinon téléchargement.
     */
    private function stampPdf(string $html, string $title, string $filename, bool $inline = false): Response
    {
        $pdf = new \App\Pdf\TemplatePdf('P', 'pt', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('SciencesWiki');
        $pdf->SetAuthor('SciencesWiki');
        $pdf->SetTitle($title);
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(true);
        $pdf->setHeaderMargin(0);
        $pdf->setFooterMargin(0);
        $pdf->SetMargins(43, 103, 42);
        $pdf->SetAutoPageBreak(true, 59);
        $pdf->SetFont('dejavusans', '', 10.5);
        $pdf->setFooterDate(date('d/m/Y'));
        $pdf->loadTemplate($this->pdfAssets->templatePath());
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');

        return new Response((string) $pdf->Output($filename.'.pdf', 'S'), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$filename.'.pdf"',
        ]);
    }

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

        return $this->stampPdf($html, $topic, $this->reviewSlug($topic), false);
    }

    private function reviewSlug(string $topic): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $topic));
        $slug = trim($slug, '-');

        return 'revue-'.('' !== $slug ? mb_substr($slug, 0, 60) : 'litterature');
    }

    /** Accueil : présentation du projet + « nous rejoindre ». L'exploration de l'arbre est sur {@see explore()}. */
    #[Route('/{_locale}', name: 'home', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('wiki/home.html.twig');
    }

    /** Explorer : intro arbre des savoirs + lanceurs de domaines + stats + dernières questions. */
    #[Route('/{_locale}/explorer', name: 'explore', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function explore(): Response
    {
        return $this->render('wiki/explore.html.twig', [
            'domains' => $this->api->domains(),
            'latestFrame' => $this->api->latestQuestionsPage(5, 1),
            'stats' => $this->api->stats(),
        ]);
    }

    /** Toutes les questions publiques : liste paginée + moteur de recherche. */
    #[Route('/{_locale}/questions', name: 'all_questions', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function allQuestions(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $page = max(1, (int) $request->query->get('page', '1'));

        return $this->render('wiki/questions.html.twig', [
            'result' => $this->api->latestQuestionsPage(15, $page, $q ?: null),
            'q' => $q,
            'page' => $page,
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

    /** Formulaire de génération d'article de vulgarisation (async + notification e-mail). */
    #[Route('/{_locale}/generer-un-article', name: 'generate_article', requirements: ['_locale' => 'fr'], methods: ['GET', 'POST'])]
    public function generateArticle(Request $request): Response
    {
        $isLogged = $this->user->isLogged();
        $accountEmail = $isLogged ? (string) ($this->user->me()['email'] ?? '') : '';
        $result = null;
        $error = null;
        $topic = '';

        if ($request->isMethod('POST')) {
            if (!$this->csrf->isValid($request)) {
                $error = 'Jeton de sécurité invalide.';
            } else {
                $topic = trim((string) $request->request->get('topic'));
                $node = trim((string) $request->request->get('node'));
                $email = $isLogged ? $accountEmail : trim((string) $request->request->get('email'));
                if (mb_strlen($topic) < 8) {
                    $error = 'Sujet trop court (8 caractères minimum).';
                } elseif ('' === $node) {
                    $error = 'Choisissez un domaine.';
                } elseif (!$isLogged && ('' === $email || false === filter_var($email, \FILTER_VALIDATE_EMAIL))) {
                    $error = 'Un e-mail valide est obligatoire pour être averti.';
                } else {
                    $res = $this->user->send('POST', '/api/articles/generate', [
                        'topic' => $topic, 'node' => $node, 'email' => $email,
                        'name' => $isLogged ? $this->user->displayName() : '',
                    ]);
                    if (Response::HTTP_ACCEPTED === ($res['status'] ?? 0)) {
                        $result = (string) ($res['data']['message'] ?? 'Rédaction lancée.');
                    } else {
                        $error = (string) ($res['data']['error'] ?? 'Échec du lancement de la rédaction.');
                    }
                }
            }
        }

        return $this->render('wiki/generate_article.html.twig', [
            'domains' => $this->api->domains(),
            'accountEmail' => $accountEmail,
            'isLogged' => $isLogged,
            'result' => $result, 'error' => $error, 'topic' => $topic,
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
            // Locator : extrait source derrière chaque note [n] (vérifiabilité).
            'passages' => $this->api->answerPassages($id),
        ]);
    }

    /** PDF (à la volée, non stocké) d'un article de vulgarisation, mis à la charte SciencesWiki. */
    #[Route('/{_locale}/q/{id}/pdf', name: 'answer_pdf', requirements: ['id' => '\d+', '_locale' => 'fr'], methods: ['GET'])]
    public function answerPdf(int $id): Response
    {
        $answer = $this->api->answer($id);
        if (null === $answer) {
            throw $this->createNotFoundException('Article introuvable.');
        }
        $html = $this->renderView('pdf/article.html.twig', ['answer' => $answer]);

        return $this->stampPdf($html, (string) ($answer['title'] ?? 'Article'), 'article-'.$id, true);
    }

    /** Proxy de vote (session → JWT) : le navigateur appelle cette route même origine. */
    #[Route('/{_locale}/q/{id}/vote', name: 'answer_vote', requirements: ['id' => '\d+', '_locale' => 'fr'], methods: ['POST'])]
    public function vote(int $id, Request $request): JsonResponse
    {
        // Protection CSRF sans état (le vote est public/anonyme, pas de session forcée) :
        // on exige que la requête provienne de NOTRE origine. Un POST cross-site forgé
        // porte une origine différente (ou une absence d'origine sur navigation top-level,
        // que le fetch same-origin ne produit jamais) → rejeté.
        if (!$this->isSameOrigin($request)) {
            return new JsonResponse(['error' => 'Origine non autorisée.'], 403);
        }

        $value = (string) ($request->request->get('value') ?? '');
        $res = $this->api->voteAnswer($id, $value, $this->user->token(), $request->getClientIp());

        return new JsonResponse($res['data'], $res['ok'] ? 200 : (0 !== $res['status'] ? $res['status'] : 502));
    }

    /**
     * Vérifie que la requête provient de la même origine (défense CSRF sans état).
     * Compare l'hôte de l'en-tête Origin (ou, à défaut, Referer) à l'hôte courant.
     */
    private function isSameOrigin(Request $request): bool
    {
        $source = (string) ($request->headers->get('Origin') ?: $request->headers->get('Referer') ?: '');
        if ('' === $source) {
            return false; // un fetch same-origin envoie toujours Origin sur un POST.
        }
        $host = parse_url($source, \PHP_URL_HOST);

        return \is_string($host) && strtolower($host) === strtolower($request->getHost());
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
        $controversies = $this->api->controversies($slug);

        return $this->render('wiki/node.html.twig', [
            'node' => $node,
            'path' => $canonical,
            'answers' => $answers,
            'votes' => $votes['tallies'],
            'myVotes' => $votes['mine'],
            'corpusCount' => $this->api->nodeCorpus($slug),
            'childrenStats' => $this->api->nodeChildrenStats($slug),
            'analysis' => $controversies['node'],
            'controversies' => $controversies['controversies'],
            'gaps' => $controversies['gaps'],
        ]);
    }
}
