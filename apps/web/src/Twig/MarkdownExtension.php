<?php

declare(strict_types=1);

namespace App\Twig;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Filtre Twig `md` : rend du Markdown en HTML (articles wiki, réponses…).
 * Configuration sûre : le HTML brut dans le contenu est ÉCHAPPÉ (pas d'injection),
 * les liens dangereux neutralisés. Tables + autoliens activés.
 */
final class MarkdownExtension extends AbstractExtension
{
    private readonly MarkdownConverter $converter;

    public function __construct()
    {
        $env = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
        ]);
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new AutolinkExtension());
        $env->addExtension(new TableExtension());

        $this->converter = new MarkdownConverter($env);
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('md', $this->toHtml(...), ['is_safe' => ['html']]),
        ];
    }

    public function toHtml(?string $markdown): string
    {
        if (null === $markdown || '' === trim($markdown)) {
            return '';
        }

        return $this->converter->convert($markdown)->getContent();
    }
}
