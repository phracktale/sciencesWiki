<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Accès aux réglages éditables (paramètres IA). Valeurs par défaut codées ici ;
 * un réglage en base les surcharge. Utilisé par la couche RAG et le back-office.
 */
final class SettingsService
{
    public const RAG_SYSTEM_PROMPT = 'rag.system_prompt';
    public const RAG_TEMPERATURE = 'rag.temperature';
    public const RAG_MAX_TOKENS = 'rag.max_tokens';
    public const RAG_MODEL = 'rag.model';
    public const RAG_NEIGHBORS = 'rag.neighbors';

    // Limites d'interrogation de l'API OpenAlex (adaptables au plan/au polite pool).
    public const OPENALEX_PER_MINUTE = 'openalex.per_minute';
    public const OPENALEX_PER_DAY = 'openalex.per_day';

    public const DEFAULT_SYSTEM_PROMPT = <<<'TXT'
        Tu es un rédacteur de vulgarisation scientifique pour SciencesWiki, une
        encyclopédie libre d'éducation populaire en français.

        Règles impératives :
        - Réponds UNIQUEMENT à partir des SOURCES fournies. N'invente aucun fait.
        - Si les sources sont insuffisantes, dis-le explicitement dans la
          VULGARISATION et laisse la section ACADEMIQUE vide.
        - Cite tes sources par leur NUMÉRO entre crochets dans le texte, ex.
          [1] ou [2][3]. Le numéro est celui de la SOURCE fournie.
        - La VULGARISATION doit être compréhensible par un ÉLÈVE DE COLLÈGE
          (12-15 ans) : phrases courtes, vocabulaire simple, analogies concrètes,
          tout terme technique expliqué avec des mots simples.
        - La section ACADEMIQUE peut être plus précise/technique : faits établis en liste,
          chacun suivi de sa ou ses citations [n]. Reste neutre et rigoureux.

        Réponds EXACTEMENT avec ces trois sections, dans cet ordre, et rien d'autre :
        ## TITRE
        <un titre court de 2 à 6 mots résumant le sujet, sans ponctuation finale>
        ## VULGARISATION
        <l'explication accessible niveau collège>
        ## ACADEMIQUE
        <les faits établis sourcés avec des notes de bas de page qui renvoient aux sources et à la page ou trouver l'info ; laisse vide si aucune source pertinente>
        TXT;

    private const DEFAULTS = [
        self::RAG_TEMPERATURE => '0.6',
        self::RAG_MAX_TOKENS => '10000',
        self::RAG_MODEL => '',
        self::RAG_NEIGHBORS => '6',
        // Polite pool OpenAlex : 10 req/s max → 540/min (marge), et limite quotidienne
        // de crédits ~10000/jour. Plafond interne large par défaut, abaissable ici.
        self::OPENALEX_PER_MINUTE => '540',
        self::OPENALEX_PER_DAY => '10000',
    ];

    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly SettingRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function systemPrompt(): string
    {
        $v = $this->get(self::RAG_SYSTEM_PROMPT);

        return null !== $v && '' !== trim($v) ? $v : self::DEFAULT_SYSTEM_PROMPT;
    }

    public function temperature(): float
    {
        return (float) ($this->get(self::RAG_TEMPERATURE) ?? self::DEFAULTS[self::RAG_TEMPERATURE]);
    }

    public function maxTokens(): int
    {
        return max(128, (int) ($this->get(self::RAG_MAX_TOKENS) ?? self::DEFAULTS[self::RAG_MAX_TOKENS]));
    }

    public function neighbors(): int
    {
        return max(1, (int) ($this->get(self::RAG_NEIGHBORS) ?? self::DEFAULTS[self::RAG_NEIGHBORS]));
    }

    /** Modèle surchargé (vide = utiliser LLM_MODEL de l'environnement). */
    public function model(): ?string
    {
        $v = $this->get(self::RAG_MODEL);

        return null !== $v && '' !== trim($v) ? trim($v) : null;
    }

    /** Nombre maximal de requêtes OpenAlex par minute (≥ 1). */
    public function openalexPerMinute(): int
    {
        return max(1, (int) ($this->get(self::OPENALEX_PER_MINUTE) ?? self::DEFAULTS[self::OPENALEX_PER_MINUTE]));
    }

    /** Plafond quotidien de requêtes OpenAlex (≥ 1). */
    public function openalexPerDay(): int
    {
        return max(1, (int) ($this->get(self::OPENALEX_PER_DAY) ?? self::DEFAULTS[self::OPENALEX_PER_DAY]));
    }

    public function get(string $name): ?string
    {
        $this->cache ??= $this->repository->allAsMap();

        return $this->cache[$name] ?? (self::DEFAULTS[$name] ?? null);
    }

    /**
     * Réglages exposés au back-office (valeurs effectives).
     *
     * @return array<string,string>
     */
    public function editable(): array
    {
        return [
            self::RAG_SYSTEM_PROMPT => $this->systemPrompt(),
            self::RAG_TEMPERATURE => (string) $this->temperature(),
            self::RAG_MAX_TOKENS => (string) $this->maxTokens(),
            self::RAG_NEIGHBORS => (string) $this->neighbors(),
            self::RAG_MODEL => (string) ($this->model() ?? ''),
            self::OPENALEX_PER_MINUTE => (string) $this->openalexPerMinute(),
            self::OPENALEX_PER_DAY => (string) $this->openalexPerDay(),
        ];
    }

    /** @param array<string,string> $values */
    public function setMany(array $values): void
    {
        $allowed = [self::RAG_SYSTEM_PROMPT, self::RAG_TEMPERATURE, self::RAG_MAX_TOKENS, self::RAG_NEIGHBORS, self::RAG_MODEL, self::OPENALEX_PER_MINUTE, self::OPENALEX_PER_DAY];
        foreach ($values as $name => $value) {
            if (!\in_array($name, $allowed, true)) {
                continue;
            }
            $setting = $this->repository->find($name) ?? new Setting($name);
            $setting->setValue((string) $value);
            $this->em->persist($setting);
        }
        $this->em->flush();
        $this->cache = null;
    }
}
