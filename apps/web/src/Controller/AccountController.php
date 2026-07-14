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
 * Compte personnel unifié : point d'entrée COMMUN à tous les rôles, présentant l'identité de
 * l'utilisateur, l'édition de son profil et des RUBRIQUES filtrées selon ses rôles (espace
 * chercheur, classeur d'analyses, comité, espace enseignant/élève, administration…). Ne remplace
 * aucun espace existant : il les regroupe. N'affecte pas l'analyse legacy.
 */
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly UserApiClient $user,
        private readonly AdminCsrf $csrf,
    ) {
    }

    #[Route('/{_locale}/mon-compte', name: 'account', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function index(): Response
    {
        if (!$this->user->isLogged()) {
            return $this->redirectToRoute('login', ['back' => '/mon-compte']);
        }

        // Recharge le profil pour un formulaire fidèle (et complète une session ancienne).
        $me = $this->user->refreshMe();

        $isStudent = $this->user->hasRole('ROLE_STUDENT');
        $isAdmin = $this->user->hasRole('ROLE_ADMIN');

        // Rubriques : chaque entrée est affichée si sa condition de rôle est vraie. L'ordre va
        // du plus courant (tout profil) au plus spécifique.
        $rubrics = [
            ['show' => true, 'route' => 'chat', 'icon' => '💬', 'name' => 'Assistant IA',
                'desc' => "Poser des questions à l'assistant scientifique sourcé."],
            ['show' => $this->user->canResearch(), 'route' => 'researcher_dashboard', 'icon' => '🔬', 'name' => 'Espace chercheur',
                'desc' => 'Outils de recherche : exploration, analyse méthodologique, revues.'],
            ['show' => $this->user->canResearch() || $this->user->hasRole('ROLE_COMITE'), 'route' => 'lab_classeur', 'icon' => '📁', 'name' => 'Mon classeur',
                'desc' => 'Vos analyses méthodologiques, sauvegardées et horodatées.'],
            ['show' => $this->user->canResearch(), 'route' => 'literature_reviews', 'icon' => '📚', 'name' => 'Revues de littérature',
                'desc' => 'Vos revues de littérature assistées, sauvegardées.'],
            ['show' => $this->user->canResearch() || $this->user->hasRole('ROLE_COMITE'), 'route' => 'lab_hub', 'icon' => '🧪', 'name' => 'Labo (modules)',
                'desc' => 'Modules et outils avancés accessibles selon votre rôle.'],
            ['show' => $this->user->canValidate(), 'route' => 'committee_dashboard', 'icon' => '⚖️', 'name' => 'Comité — validations',
                'desc' => 'Réponses et articles en attente de relecture et de validation.'],
            ['show' => $this->user->canUseAxis(), 'route' => 'my_studies', 'icon' => '📑', 'name' => 'Mes études',
                'desc' => 'Vos évaluations d’études (outil d’analyse).'],
            ['show' => $this->user->canTeach(), 'route' => 'teacher_dashboard', 'icon' => '👩‍🏫', 'name' => 'Espace enseignant',
                'desc' => 'Gestion de classe et suivi des élèves.'],
            ['show' => $isStudent && !$this->user->canTeach(), 'route' => 'student_dashboard', 'icon' => '🎓', 'name' => 'Espace élève',
                'desc' => 'Vos outils d’apprentissage et votre classe.'],
            ['show' => $isAdmin, 'route' => 'admin_dashboard', 'icon' => '⚙️', 'name' => 'Administration',
                'desc' => 'Tableau de bord d’administration.'],
        ];

        $rubrics = array_values(array_filter($rubrics, static fn (array $r): bool => $r['show']));

        return $this->render('account/index.html.twig', [
            'rubrics' => $rubrics,
            'roles' => $this->displayRoles(),
            'profile' => [
                'realName' => (string) ($me['realName'] ?? ''),
                'pseudo' => (string) ($me['pseudo'] ?? ''),
                'affiliation' => (string) ($me['affiliation'] ?? ''),
                'orcid' => (string) ($me['orcid'] ?? ''),
                'bio' => (string) ($me['bio'] ?? ''),
                'email' => (string) ($me['email'] ?? ''),
            ],
        ]);
    }

    /** Enregistre l'édition du profil (relais PATCH /api/me), puis rafraîchit la session. */
    #[Route('/{_locale}/mon-compte/profil', name: 'account_profile_save', requirements: ['_locale' => 'fr'], methods: ['POST'])]
    public function saveProfile(Request $request): Response
    {
        if (!$this->user->isLogged()) {
            return $this->redirectToRoute('login', ['back' => '/mon-compte']);
        }
        if (!$this->csrf->isValid($request)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('account');
        }

        $res = $this->user->send('PATCH', '/api/me', [
            'realName' => trim((string) $request->request->get('real_name')),
            'pseudo' => trim((string) $request->request->get('pseudo')),
            'affiliation' => trim((string) $request->request->get('affiliation')),
            'orcid' => trim((string) $request->request->get('orcid')),
            'bio' => trim((string) $request->request->get('bio')),
        ]);

        if ($res['ok']) {
            $this->user->refreshMe();
            $this->addFlash('success', 'Profil mis à jour.');
        } else {
            $this->addFlash('error', 'Échec : '.($res['data']['error'] ?? 'profil non enregistré.'));
        }

        return $this->redirectToRoute('account');
    }

    /**
     * Rôles « métier » lisibles (on masque les rôles techniques implicites de la hiérarchie).
     *
     * @return list<string>
     */
    private function displayRoles(): array
    {
        $labels = [
            'ROLE_ADMIN' => 'Administrateur',
            'ROLE_MODERATEUR' => 'Modérateur',
            'ROLE_COMITE' => 'Comité scientifique',
            'ROLE_REDACTEUR' => 'Rédacteur',
            'ROLE_RESEARCHER' => 'Chercheur',
            'ROLE_TEACHER' => 'Enseignant',
            'ROLE_STUDENT' => 'Élève',
        ];

        $out = [];
        foreach ($labels as $role => $label) {
            if ($this->user->hasRole($role)) {
                $out[] = $label;
            }
        }

        return [] !== $out ? $out : ['Membre'];
    }
}
