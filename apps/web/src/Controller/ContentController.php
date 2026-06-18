<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages éditoriales statiques (projet, philosophie, origine). Routes explicites :
 * elles priment sur la route catch-all des rubriques.
 */
final class ContentController extends AbstractController
{
    #[Route('/le-projet', name: 'project', methods: ['GET'])]
    public function project(): Response
    {
        return $this->render('content/project.html.twig');
    }

    #[Route('/process-de-publication', name: 'process', methods: ['GET'])]
    public function process(): Response
    {
        return $this->render('content/process.html.twig');
    }
}
