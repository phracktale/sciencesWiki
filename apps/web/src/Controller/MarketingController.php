<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Surface marketing/éditoriale au thème CRT « IBM monochrome » (templates/crt/*) :
 * accueil de présentation, 4 landing pages par public, tarifs, soutien (cagnotte),
 * mentions légales et contact. Routes explicites (priorité 0) : elles priment sur
 * la route catch-all des rubriques wiki (priority -10).
 */
final class MarketingController extends AbstractController
{
    /** Accueil de présentation (hub vers les 4 publics + tarifs/soutien). */
    #[Route('/{_locale}/decouvrir', name: 'crt_discover', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function discover(): Response
    {
        return $this->render('crt/discover.html.twig');
    }

    #[Route('/{_locale}/pour/chercheurs', name: 'crt_researchers', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function researchers(): Response
    {
        return $this->render('crt/researchers.html.twig');
    }

    #[Route('/{_locale}/pour/journalistes', name: 'crt_journalists', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function journalists(): Response
    {
        return $this->render('crt/journalists.html.twig');
    }

    #[Route('/{_locale}/pour/enseignants', name: 'crt_teachers', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function teachers(): Response
    {
        return $this->render('crt/teachers.html.twig');
    }

    #[Route('/{_locale}/pour/grand-public', name: 'crt_public', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function publicAudience(): Response
    {
        return $this->render('crt/public.html.twig');
    }

    #[Route('/{_locale}/manifeste', name: 'crt_manifesto', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function manifesto(): Response
    {
        return $this->render('crt/manifesto.html.twig');
    }

    #[Route('/{_locale}/tarifs', name: 'crt_pricing', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function pricing(): Response
    {
        return $this->render('crt/pricing.html.twig');
    }

    #[Route('/{_locale}/soutenir', name: 'crt_donate', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function donate(): Response
    {
        return $this->render('crt/donate.html.twig', [
            // URL de la plateforme de cagnotte (HelloAsso, Liberapay, Stripe…).
            // Tant qu'elle est null, la page invite à nous écrire (cagnotte en cours d'ouverture).
            'donate_url' => null,
        ]);
    }

    #[Route('/{_locale}/mentions-legales', name: 'crt_legal', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function legal(): Response
    {
        return $this->render('crt/legal.html.twig');
    }

    #[Route('/{_locale}/contact', name: 'crt_contact', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function contact(): Response
    {
        return $this->render('crt/contact.html.twig');
    }
}
