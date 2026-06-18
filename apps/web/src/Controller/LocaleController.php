<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Aiguillage des langues : la racine « / » redirige vers la langue du navigateur
 * (parmi les langues disponibles) ; à défaut de traduction, vers le français.
 * Les anciennes URLs sans préfixe de langue sont redirigées vers leur équivalent
 * localisé (301).
 */
final class LocaleController extends AbstractController
{
    /** Langues réellement disponibles (traduites). Ajouter ici quand on en ajoute. */
    private const SUPPORTED = ['fr'];

    #[Route('/', name: 'locale_root', methods: ['GET'])]
    public function root(Request $request): Response
    {
        return $this->redirectToRoute('home', ['_locale' => $this->preferred($request)]);
    }

    /**
     * Repli pour toute URL sans préfixe de langue (ex. /medicine, /q/6, /le-projet).
     * Priorité très basse : ne s'active que si aucune route localisée n'a matché.
     */
    #[Route('/{path}', name: 'locale_fallback', requirements: ['path' => '.+'], priority: -50, methods: ['GET'])]
    public function fallback(string $path, Request $request): Response
    {
        return $this->redirect('/'.$this->preferred($request).'/'.ltrim($path, '/'), Response::HTTP_MOVED_PERMANENTLY);
    }

    /** Meilleure langue disponible selon Accept-Language ; français par défaut. */
    private function preferred(Request $request): string
    {
        $pref = $request->getPreferredLanguage(self::SUPPORTED);

        return \in_array($pref, self::SUPPORTED, true) ? $pref : 'fr';
    }
}
