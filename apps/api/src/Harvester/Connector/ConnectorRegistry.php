<?php

declare(strict_types=1);

namespace App\Harvester\Connector;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Annuaire des connecteurs de source, indexé par leur code.
 */
final class ConnectorRegistry
{
    /** @var array<string,SourceConnector> */
    private array $connectors = [];

    /**
     * @param iterable<SourceConnector> $connectors
     */
    public function __construct(
        #[AutowireIterator('app.source_connector')]
        iterable $connectors,
    ) {
        foreach ($connectors as $connector) {
            $this->connectors[$connector->code()] = $connector;
        }
    }

    public function get(string $code): SourceConnector
    {
        return $this->connectors[$code]
            ?? throw new \InvalidArgumentException(\sprintf('Aucun connecteur pour la source « %s ».', $code));
    }

    public function has(string $code): bool
    {
        return isset($this->connectors[$code]);
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->connectors);
    }
}
