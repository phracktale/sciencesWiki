<?php

declare(strict_types=1);

namespace Analyses\Framework;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Registre des référentiels enregistrés (SPECS §7.4). Permet d'ajouter un référentiel
 * sans modifier le noyau : il suffit d'implémenter {@see FrameworkInterface}.
 */
final class FrameworkRegistry
{
    /** @var array<string, FrameworkInterface> */
    private array $frameworks = [];

    /**
     * @param iterable<FrameworkInterface> $frameworks
     */
    public function __construct(
        #[AutowireIterator('analyses.framework')]
        iterable $frameworks,
    ) {
        foreach ($frameworks as $framework) {
            $this->frameworks[$framework->id()] = $framework;
        }
    }

    public function get(string $id): ?FrameworkInterface
    {
        return $this->frameworks[$id] ?? null;
    }

    /** @return list<FrameworkInterface> */
    public function all(): array
    {
        return array_values($this->frameworks);
    }

    /** @return list<array<string, mixed>> */
    public function metadataList(): array
    {
        return array_map(
            static fn (FrameworkInterface $f): array => ['id' => $f->id(), ...$f->metadata()],
            $this->all(),
        );
    }
}
