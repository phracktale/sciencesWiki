<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\UserApiClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Compte personnel unifié : point d'entrée COMMUN à tous les rôles, présentant l'identité de
 * l'utilisateur et des RUBRIQUES filtrées selon ses rôles (espace chercheur, classeur d'analyses,
 * espace enseignant/élève, rédaction, administration…). Ne remplace aucun espace existant : il
 * les regroupe. N'affecte pas l'analyse legacy (rubrique « Mes études » reste inchangée).
 */
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly UserApiClient $user,
    ) {
    }

    #[Route('/{_locale}/mon-compte', name: 'account', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function index(): Response
    {
        if (!$this->user->isLogged()) {
            return $this->redirectToRoute('login', ['back' => '/mon-compte']);
        }

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
            ['show' => $this->user->canUseAxis(), 'route' => 'my_studies', 'icon' => '📑', 'name' => 'Mes études',
                'desc' => 'Vos évaluations d’études (outil d’analyse).'],
            ['show' => $this->user->canTeach(), 'route' => 'teacher_dashboard', 'icon' => '👩‍🏫', 'name' => 'Espace enseignant',
                'desc' => 'Gestion de classe et suivi des élèves.'],
            ['show' => $isStudent && !$this->user->canTeach(), 'route' => 'student_dashboard', 'icon' => '🎓', 'name' => 'Espace élève',
                'desc' => 'Vos outils d’apprentissage et votre classe.'],
            ['show' => $this->user->canEdit(), 'route' => 'contribute', 'icon' => '✍️', 'name' => 'Contribuer',
                'desc' => 'Proposer et rédiger du contenu encyclopédique.'],
            ['show' => $isAdmin, 'route' => 'admin_dashboard', 'icon' => '⚙️', 'name' => 'Administration',
                'desc' => 'Tableau de bord d’administration.'],
        ];

        $rubrics = array_values(array_filter($rubrics, static fn (array $r): bool => $r['show']));

        return $this->render('account/index.html.twig', [
            'rubrics' => $rubrics,
            'roles' => $this->displayRoles(),
        ]);
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
