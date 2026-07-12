<?php

declare(strict_types=1);

namespace Analyses\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Réglages du module (table isolée analys_setting), configurables par l'admin sans
 * redéploiement. Chaque réglage a un repli sur la variable d'environnement / défaut.
 */
final class SettingsService
{
    /** @var list<string> Clés modifiables via la page d'administration. */
    public const EDITABLE = [
        'analys.default_model',
        'analys.extractor_model',
        'analys.threshold.human_review',
        'analys.frameworks.enabled',
    ];

    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly Connection $db,
        #[Autowire(env: 'ANALYS_MODEL')]
        private readonly string $envModel = 'glm-5.2:cloud',
        #[Autowire(env: 'ANALYS_EXTRACTOR_MODEL')]
        private readonly string $envExtractor = 'glm-4.7-flash:latest',
        #[Autowire(env: 'ANALYS_HUMAN_REVIEW_THRESHOLD')]
        private readonly float $envThreshold = 0.75,
    ) {
    }

    public function get(string $name): ?string
    {
        return $this->all()[$name] ?? null;
    }

    public function set(string $name, string $value): void
    {
        $this->db->executeStatement(
            'INSERT INTO analys_setting(name, value) VALUES(:n, :v) ON CONFLICT(name) DO UPDATE SET value = EXCLUDED.value',
            ['n' => $name, 'v' => $value],
        );
        $this->cache = null;
    }

    /** @param array<string, mixed> $values */
    public function setMany(array $values): void
    {
        foreach ($values as $k => $v) {
            if (\in_array($k, self::EDITABLE, true)) {
                $this->set($k, trim((string) $v));
            }
        }
    }

    public function analysisModel(): string
    {
        $v = $this->get('analys.default_model');

        return null !== $v && '' !== $v ? $v : $this->envModel;
    }

    public function extractorModel(): string
    {
        $v = $this->get('analys.extractor_model');

        return null !== $v && '' !== $v ? $v : $this->envExtractor;
    }

    public function humanReviewThreshold(): float
    {
        $v = $this->get('analys.threshold.human_review');

        return null !== $v && is_numeric($v) ? (float) $v : $this->envThreshold;
    }

    /**
     * Référentiels activés (ids) ; null = tous. Permet à l'admin de désactiver un référentiel.
     *
     * @return list<string>|null
     */
    public function enabledFrameworks(): ?array
    {
        $v = $this->get('analys.frameworks.enabled');
        if (null === $v || '' === trim($v)) {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $v))));
    }

    /** @return array<string, string> valeurs effectives des réglages modifiables */
    public function editable(): array
    {
        return [
            'analys.default_model' => $this->analysisModel(),
            'analys.extractor_model' => $this->extractorModel(),
            'analys.threshold.human_review' => (string) $this->humanReviewThreshold(),
            'analys.frameworks.enabled' => $this->get('analys.frameworks.enabled') ?? '',
        ];
    }

    /** @return array<string, string> */
    private function all(): array
    {
        if (null !== $this->cache) {
            return $this->cache;
        }

        try {
            /** @var array<string, string> $rows */
            $rows = $this->db->fetchAllKeyValue('SELECT name, value FROM analys_setting');
        } catch (\Throwable) {
            $rows = []; // table pas encore migrée
        }

        return $this->cache = $rows;
    }
}
