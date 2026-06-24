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

    /**
     * Vérification de fidélité : après rédaction, une passe marque « [réf. nécessaire] »
     * les affirmations non soutenues par les sources (cf. stratégie anti-hallucination).
     * '1' = activée. Le vérificateur utilise le modèle léger (≠ rédacteur).
     */
    public const RAG_VERIFY = 'rag.verify';

    /** Modèle dédié à la rédaction des articles encyclopédiques (distinct des Q/R). */
    public const WIKI_MODEL = 'wiki.model';

    /**
     * Modèle « léger » pour les tâches peu exigeantes en raisonnement (extraction
     * de claims structurés, etc.) — rapide et bon marché, distinct des modèles de
     * rédaction. Cf. docs/spec-controverses-lacunes.md §5.
     */
    public const LIGHT_MODEL = 'ai.light_model';

    // Limites d'interrogation de l'API OpenAlex (adaptables au plan/au polite pool).
    public const OPENALEX_PER_MINUTE = 'openalex.per_minute';
    public const OPENALEX_PER_DAY = 'openalex.per_day';

    // Stratégie de moisson (librement paramétrable en back-office).
    public const HARVEST_SORT = 'harvest.sort';                 // tri OpenAlex
    public const HARVEST_RECENT_YEARS = 'harvest.recent_years'; // 0 = pas de limite d'années
    public const HARVEST_CAP_PER_RUBRIC = 'harvest.cap_per_rubric'; // 0 = illimité
    public const HARVEST_MAX_PER_RUN = 'harvest.max_per_run';   // taille de lot par exécution

    // Réacheminement e-mail : si activé, TOUS les e-mails sortants sont redirigés
    // vers l'adresse paramétrée (tests / pré-prod / supervision Brevo).
    public const MAIL_REROUTE_ENABLED = 'mail.reroute_enabled'; // '0' | '1'
    public const MAIL_REROUTE_TO = 'mail.reroute_to';           // adresse de réacheminement

    // Notifier les modérateurs par e-mail des nouvelles propositions de correction/contenu.
    public const MOD_NOTIFY_ENABLED = 'mod.notify_enabled';     // '0' | '1'

    // Thème visuel du front public : 'legacy' (clair d'origine) ou 'crt' (tube
    // cathodique monochrome). Lu publiquement par le front (cf. /api/public-settings).
    public const SITE_THEME = 'site.theme';                     // 'legacy' | 'crt'
    public const SITE_FRAMED = 'site.framed';                   // '1' = mode fenêtré (cadre terminal partout)

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

    /**
     * Garde-fou IMPÉRATIF appliqué à TOUTE génération IA de la plateforme (ajouté
     * au prompt système au moment de l'exécution, donc inamovible). Objectif :
     * ne jamais présenter comme universel ce qui dépend d'un périmètre particulier.
     */
    public const GEO_SCOPE_GUARD = <<<'TXT'
        RÈGLE IMPÉRATIVE — PÉRIMÈTRE D'APPLICATION.
        Beaucoup de faits ne sont PAS universels : ils dépendent d'un périmètre
        particulier — pays, région, juridiction, système de santé, organisme,
        cadre réglementaire ou législatif, devise, unité de mesure, période,
        population étudiée ou contexte institutionnel. Dès qu'une affirmation, une
        étude, une statistique, une loi, une recommandation, un dispositif
        (financement, organisation, autorité, programme…) ou une pratique ne vaut
        que dans un tel cadre, tu DOIS indiquer EXPLICITEMENT le périmètre concerné
        (ex. « aux États-Unis », « dans l'Union européenne », « selon le système de
        santé britannique », « d'après une cohorte japonaise de 2021 »).
        N'emploie JAMAIS de référence implicite à un cadre national ou institutionnel
        — par exemple « la coordination fédérale », « la sécurité sociale »,
        « l'agence du médicament », « le gouvernement » — sans nommer le pays ou la
        juridiction. Ne généralise jamais un résultat propre à un contexte. Si le
        périmètre est inconnu ou ambigu dans les sources, signale-le explicitement
        plutôt que de laisser croire à une portée universelle.
        TXT;

    private const DEFAULTS = [
        self::RAG_TEMPERATURE => '0.6',
        self::RAG_MAX_TOKENS => '10000',
        self::RAG_MODEL => '',
        self::RAG_NEIGHBORS => '6',
        self::RAG_VERIFY => '1',
        // Articles wiki : modèle le plus capable disponible localement par défaut.
        self::WIKI_MODEL => 'qwen3.6:latest',
        // Tâches légères (extraction de claims…) : petit modèle rapide par défaut.
        self::LIGHT_MODEL => 'llama3.1:8b',
        // Polite pool OpenAlex : 10 req/s max → 540/min (marge), et limite quotidienne
        // de crédits ~10000/jour. Plafond interne large par défaut, abaissable ici.
        self::OPENALEX_PER_MINUTE => '540',
        self::OPENALEX_PER_DAY => '100000',
        // Par défaut : les plus cités, sur les 5 dernières années, plafonné à 3000/rubrique.
        self::HARVEST_SORT => 'cited_by_count:desc',
        self::HARVEST_RECENT_YEARS => '5',
        self::HARVEST_CAP_PER_RUBRIC => '3000',
        self::HARVEST_MAX_PER_RUN => '500',
        self::MAIL_REROUTE_ENABLED => '0',
        self::MAIL_REROUTE_TO => '',
        self::MOD_NOTIFY_ENABLED => '1',
        // Thème par défaut : l'ancien (clair). Bascule en 'crt' depuis le back-office.
        self::SITE_THEME => 'legacy',
        self::SITE_FRAMED => '1',
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
        $base = null !== $v && '' !== trim($v) ? $v : self::DEFAULT_SYSTEM_PROMPT;

        // Garde-fou périmètre toujours ajouté (inamovible, même si prompt personnalisé).
        return $base."\n\n".self::GEO_SCOPE_GUARD;
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

    /** Modèle Q/R surchargé (vide = utiliser LLM_MODEL de l'environnement). */
    public function model(): ?string
    {
        $v = $this->get(self::RAG_MODEL);

        return null !== $v && '' !== trim($v) ? trim($v) : null;
    }

    /** Vérification de fidélité activée (marquage « [réf. nécessaire] »). */
    public function verifyFaithfulness(): bool
    {
        return '0' !== trim((string) ($this->get(self::RAG_VERIFY) ?? '1'));
    }

    /** Modèle de rédaction des articles wiki (défaut : le plus capable local). */
    public function wikiModel(): string
    {
        $v = trim((string) ($this->get(self::WIKI_MODEL) ?? ''));

        return '' !== $v ? $v : self::DEFAULTS[self::WIKI_MODEL];
    }

    /** Modèle léger pour les tâches peu exigeantes (extraction…), défaut rapide. */
    public function lightModel(): string
    {
        $v = trim((string) ($this->get(self::LIGHT_MODEL) ?? ''));

        return '' !== $v ? $v : self::DEFAULTS[self::LIGHT_MODEL];
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

    /** Tri OpenAlex de la moisson (vide = ordre par défaut de l'API). */
    public function harvestSort(): string
    {
        return trim((string) ($this->get(self::HARVEST_SORT) ?? ''));
    }

    /** Fenêtre de récence en années (0 = pas de limite). */
    public function harvestRecentYears(): int
    {
        return max(0, (int) ($this->get(self::HARVEST_RECENT_YEARS) ?? '0'));
    }

    /** Plafond de publications moissonnées par rubrique (0 = illimité). */
    public function harvestCapPerRubric(): int
    {
        return max(0, (int) ($this->get(self::HARVEST_CAP_PER_RUBRIC) ?? '0'));
    }

    /** Nombre de travaux traités par exécution de moisson (≥ 1). */
    public function harvestMaxPerRun(): int
    {
        return max(1, (int) ($this->get(self::HARVEST_MAX_PER_RUN) ?? '500'));
    }

    public function mailRerouteEnabled(): bool
    {
        return '1' === trim((string) ($this->get(self::MAIL_REROUTE_ENABLED) ?? '0'));
    }

    public function mailRerouteTo(): string
    {
        return trim((string) ($this->get(self::MAIL_REROUTE_TO) ?? ''));
    }

    public function moderatorNotifyEnabled(): bool
    {
        return '1' === trim((string) ($this->get(self::MOD_NOTIFY_ENABLED) ?? '1'));
    }

    /** Thème du front public : 'legacy' ou 'crt' (repli 'legacy' si valeur inconnue). */
    public function siteTheme(): string
    {
        $v = trim((string) ($this->get(self::SITE_THEME) ?? 'legacy'));

        return \in_array($v, ['legacy', 'crt'], true) ? $v : 'legacy';
    }

    /** Mode fenêtré (cadre « terminal ») du thème CRT, appliqué partout (front + admin). */
    public function siteFramed(): bool
    {
        return '0' !== trim((string) ($this->get(self::SITE_FRAMED) ?? '1'));
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
            self::WIKI_MODEL => $this->wikiModel(),
            self::LIGHT_MODEL => $this->lightModel(),
            self::OPENALEX_PER_MINUTE => (string) $this->openalexPerMinute(),
            self::OPENALEX_PER_DAY => (string) $this->openalexPerDay(),
            self::HARVEST_SORT => $this->harvestSort(),
            self::HARVEST_RECENT_YEARS => (string) $this->harvestRecentYears(),
            self::HARVEST_CAP_PER_RUBRIC => (string) $this->harvestCapPerRubric(),
            self::HARVEST_MAX_PER_RUN => (string) $this->harvestMaxPerRun(),
            self::MAIL_REROUTE_ENABLED => $this->mailRerouteEnabled() ? '1' : '0',
            self::MAIL_REROUTE_TO => $this->mailRerouteTo(),
            self::MOD_NOTIFY_ENABLED => $this->moderatorNotifyEnabled() ? '1' : '0',
            self::SITE_THEME => $this->siteTheme(),
            self::SITE_FRAMED => $this->siteFramed() ? '1' : '0',
            self::RAG_VERIFY => $this->verifyFaithfulness() ? '1' : '0',
        ];
    }

    /** @param array<string,string> $values */
    public function setMany(array $values): void
    {
        $allowed = [self::RAG_SYSTEM_PROMPT, self::RAG_TEMPERATURE, self::RAG_MAX_TOKENS, self::RAG_NEIGHBORS, self::RAG_MODEL, self::RAG_VERIFY, self::WIKI_MODEL, self::LIGHT_MODEL, self::OPENALEX_PER_MINUTE, self::OPENALEX_PER_DAY, self::HARVEST_SORT, self::HARVEST_RECENT_YEARS, self::HARVEST_CAP_PER_RUBRIC, self::HARVEST_MAX_PER_RUN, self::MAIL_REROUTE_ENABLED, self::MAIL_REROUTE_TO, self::MOD_NOTIFY_ENABLED, self::SITE_THEME, self::SITE_FRAMED];
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
