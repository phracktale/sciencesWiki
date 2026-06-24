<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Rubrique « Développeur » (pied de page) : sert la documentation technique
 * Markdown de `content/developer/` rendue via le filtre Twig `| md`.
 *
 * Routes explicites (requirements _locale=fr) → priment sur la catch-all des
 * rubriques wiki (priority -10, cf. WikiController). La liste blanche `DOCS`
 * empêche toute traversée de chemin (seuls ces slugs sont servis).
 */
final class DeveloperController extends AbstractController
{
    /**
     * Slug public → [fichier Markdown, titre court]. L'ordre fixe le sommaire.
     *
     * @var array<string, array{file: string, title: string}>
     */
    private const DOCS = [
        'architecture'     => ['file' => '01-architecture.md',       'title' => 'Architecture'],
        'fonctionnalites'  => ['file' => '06-fonctionnalites.md',     'title' => 'Référence fonctionnelle'],
        'choix-techniques' => ['file' => '02-choix-techniques.md',   'title' => 'Choix techniques'],
        'demarrage'        => ['file' => '03-demarrage.md',          'title' => 'Démarrage & dev'],
        'conventions'      => ['file' => '04-conventions-de-code.md', 'title' => 'Conventions de code'],
        'contribution'     => ['file' => '05-contribution-et-pr.md',  'title' => 'Contribuer & PR'],
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/{_locale}/developpeur', name: 'developer', requirements: ['_locale' => 'fr'], methods: ['GET'])]
    public function index(): Response
    {
        return $this->renderDoc('README.md', null);
    }

    #[Route('/{_locale}/developpeur/{slug}', name: 'developer_doc', requirements: ['_locale' => 'fr', 'slug' => '[a-z-]+'], methods: ['GET'])]
    public function doc(string $slug): Response
    {
        if (!isset(self::DOCS[$slug])) {
            throw new NotFoundHttpException(sprintf('Document développeur inconnu : %s', $slug));
        }

        return $this->renderDoc(self::DOCS[$slug]['file'], $slug);
    }

    private function renderDoc(string $file, ?string $currentSlug): Response
    {
        $path = $this->projectDir.'/content/developer/'.$file;
        if (!is_file($path)) {
            throw new NotFoundHttpException(sprintf('Fichier de documentation absent : %s', $file));
        }

        $markdown = (string) file_get_contents($path);

        return $this->render('content/developer.html.twig', [
            'content' => $this->rewriteCrossLinks($markdown),
            'docs' => self::DOCS,
            'current' => $currentSlug,
            'title' => $currentSlug !== null ? self::DOCS[$currentSlug]['title'] : 'Documentation Développeur',
        ]);
    }

    /**
     * Réécrit les liens inter-documents du Markdown (`](01-architecture.md)`,
     * `](README.md#section)`) vers les URL du site, pour qu'ils restent
     * cliquables une fois rendus en page. Les ancres `#...` sont préservées.
     */
    private function rewriteCrossLinks(string $markdown): string
    {
        $map = ['README.md' => $this->generateUrl('developer', ['_locale' => 'fr'])];
        foreach (self::DOCS as $slug => $doc) {
            $map[$doc['file']] = $this->generateUrl('developer_doc', ['_locale' => 'fr', 'slug' => $slug]);
        }

        return preg_replace_callback(
            '/\]\((README\.md|\d{2}-[a-z-]+\.md)(#[^)]*)?\)/',
            static fn (array $m): string => ']('.($map[$m[1]] ?? $m[1]).($m[2] ?? '').')',
            $markdown,
        );
    }
}
