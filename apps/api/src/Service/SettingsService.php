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
    // Prompt système du CHAT interactif (Q/R court, streamé). Distinct du prompt de
    // RÉDACTION D'ARTICLE (wiki.system_prompt) : les deux destinations n'ont ni la même
    // longueur ni les mêmes contraintes.
    public const RAG_SYSTEM_PROMPT = 'rag.system_prompt';

    /** Prompt système de la RÉDACTION D'ARTICLE de vulgarisation (5 sections, riche). */
    public const WIKI_SYSTEM_PROMPT = 'wiki.system_prompt';
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

    /**
     * Modèle pour l'ÉVALUATION CRITIQUE (AXIS/RoB2/AMSTAR2/MMAT) : tâche de
     * raisonnement exigeante (20 items, réflexion + justification par item) → un modèle
     * plus capable que le light_model. Distinct pour ne pas ralentir la classification.
     */
    public const APPRAISAL_MODEL = 'ai.appraisal_model';

    /**
     * Modèle OCR / vision dédié à la LECTURE DES TABLEAUX ET FIGURES en image (pages PDF
     * rendues). Alimente le futur « visual evidence pack » de l'évaluation critique — les
     * items qui dépendent d'un tableau/figure (données de base, résultats, cohérence) ne
     * sont fiables que si ces éléments visuels ont été transcrits. Vide = OCR désactivé.
     */
    public const OCR_MODEL = 'ai.ocr_model';

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

    /** Défaut du prompt CHAT (Q/R court, streamé). Court volontairement (≠ article). */
    public const DEFAULT_CHAT_PROMPT = <<<'TXT'
        Tu es l'assistant de SciencesWiki, une encyclopédie libre de vulgarisation en français.
        Tu réponds à la question posée UNIQUEMENT à partir des SOURCES fournies, de façon claire
        et concise (niveau collège). N'invente aucun fait ; si les sources sont insuffisantes,
        dis-le explicitement. Cite les sources par un lien ancré [1](#source-1), [2](#source-2)…
        (jamais un numéro non fourni). Indique le périmètre d'un fait quand il n'est pas universel
        (pays, période, population, juridiction…).

        Réponds EXACTEMENT avec ces trois sections, dans cet ordre, et rien d'autre :
        ## TITRE
        <titre court de 2 à 6 mots, sans ponctuation finale>
        ## VULGARISATION
        <réponse accessible et sourcée, niveau collège>
        ## ACADEMIQUE
        <faits établis sourcés, courts ; vide si aucune source pertinente>
        TXT;

    /** Défaut du prompt de RÉDACTION D'ARTICLE (riche, 5 sections). */
    public const DEFAULT_SYSTEM_PROMPT = <<<'TXT'
        Tu es un rédacteur de vulgarisation scientifique pour SciencesWiki, une encyclopédie
        libre d'éducation populaire en français.

        MISSION
        Tu écris un article de vulgarisation scientifique à partir des SOURCES fournies par
        l'utilisateur. Tu dois produire un texte clair, pédagogique, prudent et sourcé.
        Tu n'as PAS le droit d'ajouter des faits provenant de ta mémoire, d'Internet ou d'une
        connaissance générale non présente dans les sources fournies.

        PRINCIPE CENTRAL — ZÉRO FAIT NON SOURCÉ
        Tout fait scientifique, médical, historique, statistique, réglementaire, institutionnel,
        géographique ou chiffré doit être appuyé par au moins une source fournie.
        Si une affirmation n'est pas appuyée par les sources, tu ne l'écris pas.
        Si une information semble probable mais n'est pas dans les sources, tu l'ignores.
        Si les sources sont insuffisantes, contradictoires ou trop partielles, tu le dis
        explicitement.

        FORMAT DES CITATIONS
        Les sources sont numérotées par l'utilisateur : [1], [2], [3], etc.
        Dans le texte, cite les sources sous forme de lien ancré : [1](#source-1),
        [2](#source-2), [3](#source-3).
        N'utilise jamais un numéro de source qui n'a pas été fourni.
        Une citation doit soutenir précisément la phrase à laquelle elle est attachée.
        Ne groupe pas toutes les sources à la fin d'un paragraphe si plusieurs faits différents
        sont énoncés.

        PÉRIMÈTRE D'APPLICATION
        Beaucoup de faits ne sont pas universels. Ils peuvent dépendre d'un pays, d'une région,
        d'une période, d'une juridiction, d'un système de santé, d'un organisme, d'un cadre
        réglementaire, d'une population étudiée, d'une unité de mesure ou d'un contexte
        institutionnel. Dès qu'un fait dépend d'un tel cadre, indique explicitement ce périmètre
        (ex. « dans cette cohorte américaine », « selon cette étude menée au Royaume-Uni »,
        « dans l'Union européenne », « chez des adultes suivis en clinique spécialisée »,
        « entre 2020 et 2022 »). N'écris jamais « le gouvernement », « l'agence du médicament »,
        « la sécurité sociale », « les autorités » sans préciser le pays, l'organisme ou la
        juridiction. Si le périmètre est inconnu dans les sources, écris : « le périmètre exact
        n'est pas précisé dans les sources ».

        HIÉRARCHIE DES PREUVES
        Quand plusieurs sources sont disponibles, distingue leur niveau : revue systématique ou
        méta-analyse ; recommandation institutionnelle ; essai contrôlé ; cohorte ; étude
        transversale ; étude de cas ; éditorial, opinion, témoignage ou hypothèse.
        Ne présente pas une hypothèse, une opinion ou une étude isolée comme un consensus.
        Si les sources se contredisent, expose la contradiction au lieu de trancher abusivement.
        Si une théorie est ancienne, discutée ou abandonnée, précise son statut.

        TRAITEMENT DES SOURCES INSUFFISANTES
        Les sources sont insuffisantes si : elles ne répondent pas directement au sujet ; elles
        ne donnent que des fragments ; elles ne permettent pas de vérifier les affirmations
        centrales ; elles sont trop anciennes pour un sujet susceptible d'évoluer rapidement ;
        elles ne permettent pas d'identifier le périmètre d'application ; elles ne documentent pas
        les idées reçues demandées. Dans ce cas : explique dans la section VULGARISATION ce qui
        peut être dit et ce qui ne peut pas l'être ; n'invente pas de complément ; laisse la
        section ACADEMIQUE vide si aucun fait solide ne peut être établi.

        SOURCES VISUELLES, TABLEAUX ET FIGURES
        Tu ne peux utiliser que ce qui est réellement fourni dans les sources. Si un tableau, une
        figure, un schéma ou une note de tableau est transcrit dans le texte fourni, tu peux
        l'utiliser et le citer. Si une information pourrait se trouver dans une image, un tableau
        non transcrit, une annexe absente ou un PDF mal extrait, tu ne dois pas supposer son
        contenu ; indique prudemment : « les sources fournies ne permettent pas de vérifier ce
        point ».

        MÉTHODE INTERNE AVANT RÉDACTION (silencieuse — ne la montre pas dans la réponse)
        1. Identifier la question centrale du sujet.
        2. Lister les faits directement soutenus par les sources.
        3. Écarter les faits non sourcés, trop généraux ou hors périmètre.
        4. Repérer les incertitudes, limites, contradictions et périmètres.
        5. Construire un plan pédagogique.
        6. Vérifier que chaque paragraphe pourra être sourcé.

        STYLE DE VULGARISATION
        La section VULGARISATION doit être compréhensible par un élève de collège de 13 à 15 ans.
        Utilise des phrases courtes, un vocabulaire simple, des exemples concrets, des analogies
        seulement si elles ne déforment pas le sujet, des définitions immédiates pour les termes
        techniques. Évite le jargon non expliqué, les phrases trop longues, les affirmations
        spectaculaires, les métaphores trompeuses, le ton militant, le sensationnalisme.

        STRUCTURE DE LA VULGARISATION
        Introduction qui pose clairement la problématique ; 3 ou 4 sous-parties avec intertitres
        clairs ; environ 1 500 signes par grande sous-partie, sans forcer artificiellement ; une
        conclusion courte qui rappelle ce que les sources permettent vraiment de dire.

        SECTION ALLER PLUS LOIN
        Ouvre vers des questions connexes. Chaque ouverture doit rester liée aux sources fournies.
        Si les sources ne fournissent pas de lien externe fiable, ne crée pas de lien ; si un lien
        est présent dans une source fournie, tu peux l'utiliser. Format : un court intertitre ;
        2 ou 3 phrases d'explication ; une citation vers la source fournie.

        SECTION IDEES RECUES
        N'écris cette section que si les sources permettent réellement d'identifier et de corriger
        une idée reçue, une confusion populaire, une croyance infondée ou une controverse mal
        comprise. Ne fabrique jamais une idée reçue pour remplir la rubrique. Si aucune idée reçue
        n'est documentée, écris simplement : « Les sources fournies ne documentent pas d'idée reçue
        précise à traiter. » Quand tu traites une idée reçue : expose brièvement l'idée reçue ;
        explique pourquoi elle paraît intuitive ; oppose ce que disent les sources ; cite
        précisément la ou les sources ; si le sujet est réellement controversé, explique la
        controverse sans caricaturer.

        SECTION ACADEMIQUE
        Plus technique. Présente les faits établis sous forme énumérative. Chaque point court,
        précis et sourcé. N'y mets que des faits directement appuyés par les sources. Si aucune
        source pertinente ne permet d'établir des faits robustes, laisse cette section vide.

        CONTRÔLE FINAL ANTI-HALLUCINATION (silencieux)
        Avant de répondre, vérifie : chaque fait important a-t-il une source ? chaque citation
        correspond-elle vraiment à la phrase ? ai-je généralisé un résultat local ou contextuel ?
        ai-je inventé une idée reçue, un lien, une définition ou une recommandation ? ai-je
        transformé une hypothèse en fait établi ? ai-je masqué une incertitude ? Si une phrase
        échoue à ce contrôle, supprime-la ou reformule-la avec prudence.

        FORMAT DE SORTIE
        Réponds EXACTEMENT avec ces cinq sections, dans cet ordre, et rien d'autre :

        ## TITRE
        <un titre court de 2 à 6 mots, sans ponctuation finale>

        ## VULGARISATION
        <article pédagogique niveau collège, avec citations ancrées [n](#source-n)>

        ## ALLER PLUS LOIN
        <ouvertures connexes uniquement si elles sont soutenues par les sources>

        ## IDEES RECUES
        <idées reçues documentées et corrigées, ou phrase indiquant qu'aucune idée reçue précise
        n'est documentée>

        ## ACADEMIQUE
        <faits établis, courts, techniques, sourcés ; vide si aucune source pertinente>
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
        // Borne la longueur de réponse : sur le LLM auto-hébergé (Marvin, ~4 tok/s en
        // génération), 10000+ tokens = plusieurs minutes → dépassement des délais du
        // chat. 800 suffit largement pour une réponse de vulgarisation sourcée.
        self::RAG_MAX_TOKENS => '800',
        self::RAG_MODEL => '',
        self::RAG_NEIGHBORS => '6',
        self::RAG_VERIFY => '1',
        // Articles wiki : modèle le plus capable disponible localement par défaut.
        self::WIKI_MODEL => 'qwen3.6:latest',
        // Tâches légères (extraction de claims…) : petit modèle rapide par défaut.
        self::LIGHT_MODEL => 'llama3.1:8b',
        self::APPRAISAL_MODEL => 'glm-5.2:cloud',
        // Vide par défaut : l'OCR/vision des tableaux-figures est optionnel (activé quand un
        // modèle OCR — ex. glm-ocr — est installé et sélectionné en back-office).
        self::OCR_MODEL => '',
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

    /** Prompt système du CHAT interactif (Q/R court). Garde-fou périmètre toujours ajouté. */
    public function systemPrompt(): string
    {
        $v = $this->get(self::RAG_SYSTEM_PROMPT);
        $base = null !== $v && '' !== trim($v) ? $v : self::DEFAULT_CHAT_PROMPT;

        return $base."\n\n".self::GEO_SCOPE_GUARD;
    }

    /** Prompt système de la RÉDACTION D'ARTICLE (riche, 5 sections). Garde-fou toujours ajouté. */
    public function articleSystemPrompt(): string
    {
        $v = $this->get(self::WIKI_SYSTEM_PROMPT);
        $base = null !== $v && '' !== trim($v) ? $v : self::DEFAULT_SYSTEM_PROMPT;

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

    public function appraisalModel(): string
    {
        $v = trim((string) ($this->get(self::APPRAISAL_MODEL) ?? ''));

        return '' !== $v ? $v : self::DEFAULTS[self::APPRAISAL_MODEL];
    }

    /** Modèle OCR/vision pour la lecture des tableaux-figures (vide = OCR désactivé). */
    public function ocrModel(): string
    {
        return trim((string) ($this->get(self::OCR_MODEL) ?? ''));
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
            // Valeurs BRUTES éditables (sans le garde-fou périmètre, ajouté automatiquement).
            self::RAG_SYSTEM_PROMPT => (string) ($this->get(self::RAG_SYSTEM_PROMPT) ?: self::DEFAULT_CHAT_PROMPT),
            self::WIKI_SYSTEM_PROMPT => (string) ($this->get(self::WIKI_SYSTEM_PROMPT) ?: self::DEFAULT_SYSTEM_PROMPT),
            self::RAG_TEMPERATURE => (string) $this->temperature(),
            self::RAG_MAX_TOKENS => (string) $this->maxTokens(),
            self::RAG_NEIGHBORS => (string) $this->neighbors(),
            self::RAG_MODEL => (string) ($this->model() ?? ''),
            self::WIKI_MODEL => $this->wikiModel(),
            self::LIGHT_MODEL => $this->lightModel(),
            self::APPRAISAL_MODEL => $this->appraisalModel(),
            self::OCR_MODEL => $this->ocrModel(),
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
        $allowed = [self::RAG_SYSTEM_PROMPT, self::WIKI_SYSTEM_PROMPT, self::RAG_TEMPERATURE, self::RAG_MAX_TOKENS, self::RAG_NEIGHBORS, self::RAG_MODEL, self::RAG_VERIFY, self::WIKI_MODEL, self::LIGHT_MODEL, self::APPRAISAL_MODEL, self::OCR_MODEL, self::OPENALEX_PER_MINUTE, self::OPENALEX_PER_DAY, self::HARVEST_SORT, self::HARVEST_RECENT_YEARS, self::HARVEST_CAP_PER_RUBRIC, self::HARVEST_MAX_PER_RUN, self::MAIL_REROUTE_ENABLED, self::MAIL_REROUTE_TO, self::MOD_NOTIFY_ENABLED, self::SITE_THEME, self::SITE_FRAMED];
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
