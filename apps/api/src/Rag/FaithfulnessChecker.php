<?php

declare(strict_types=1);

namespace App\Rag;

use App\Ai\Llm\LlmClient;
use App\Ai\Llm\LlmMessage;
use App\Entity\Publication;
use App\Service\SettingsService;

/**
 * Vérification de fidélité (anti-hallucination) : après rédaction, repère les
 * affirmations NON soutenues par les sources et insère un marqueur « [réf. nécessaire] »
 * (façon Wikipedia), en particulier sur les CHIFFRES/dates/pourcentages. Le texte
 * n'est pas réécrit : on annote, l'humain tranche en relecture.
 *
 * Principes : vérificateur ≠ rédacteur (modèle léger, distinct), conservateur (au
 * doute, on marque), best-effort (en cas d'échec on ne dégrade pas le contenu).
 *
 * Le « texte source » est générique (résumé aujourd'hui) : le jour où l'on dispose
 * des passages plein texte (locator GROBID), il suffira d'enrichir sourceText().
 */
final class FaithfulnessChecker
{
    /** Marqueur inséré après une affirmation non soutenue. */
    public const MARKER = '[réf. nécessaire]';

    /** Au-delà, on découpe le texte en blocs pour borner la sortie par appel. */
    private const BATCH_CHARS = 1800;

    private const SYSTEM = <<<'TXT'
        Tu es un VÉRIFICATEUR DE FIDÉLITÉ, strict et conservateur. On te donne des
        SOURCES (titre + résumé) et un TEXTE rédigé censé s'appuyer dessus.

        Ta tâche : repérer les affirmations factuelles du TEXTE qui NE SONT PAS
        explicitement soutenues par au moins une SOURCE — en priorité les CHIFFRES,
        pourcentages, dates, tailles d'échantillon, noms propres et relations causales,
        ainsi que les généralisations qui dépassent ce que disent les sources.

        Règles IMPÉRATIVES :
        - Renvoie le TEXTE EXACTEMENT à l'identique (mêmes mots, même ordre, même
          markdown), en insérant le marqueur « [réf. nécessaire] » IMMÉDIATEMENT après
          chaque phrase ou affirmation non soutenue.
        - Au moindre doute → marque (mieux vaut un marqueur de trop qu'un fait non vérifié).
        - Ne reformule RIEN, ne supprime RIEN, n'ajoute AUCUN autre commentaire ni balise.
        - Une affirmation correctement appuyée par une source : n'y touche pas.
        - Les titres de section et le texte déjà cité [n] correctement : ne marque pas.
        TXT;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * Annoter le texte avec des marqueurs « [réf. nécessaire] ». Renvoie le texte
     * inchangé si la vérification est désactivée, sans sources, ou en cas d'échec.
     *
     * @param list<Publication> $sources
     */
    public function annotate(string $text, array $sources): string
    {
        $text = trim($text);
        if (!$this->settings->verifyFaithfulness() || '' === $text || [] === $sources) {
            return $text;
        }

        $sourcesBlock = $this->sourcesBlock($sources);
        $out = [];
        foreach ($this->batches($text) as $batch) {
            $out[] = $this->annotateBatch($batch, $sourcesBlock);
        }

        return implode("\n\n", $out);
    }

    private function annotateBatch(string $text, string $sourcesBlock): string
    {
        $messages = [
            LlmMessage::system(self::SYSTEM),
            LlmMessage::user($sourcesBlock."\n\nTEXTE :\n".$text),
        ];
        // Vérificateur ≠ rédacteur : modèle léger, température nulle (déterministe).
        $opts = ['temperature' => 0.0, 'max_tokens' => 2000, 'model' => $this->settings->lightModel(), 'timeout' => 300];

        try {
            $annotated = trim($this->llm->complete($messages, $opts)->content);
        } catch (\Throwable) {
            return $text; // best-effort : ne pas dégrader le contenu
        }

        // Garde-fou anti-réécriture : si le vérificateur a perdu du contenu (sortie
        // tronquée ou reformulée), on conserve l'original plutôt que de risquer une perte.
        $stripped = trim(str_replace(self::MARKER, '', $annotated));
        if ('' === $annotated || mb_strlen($stripped) < (int) (mb_strlen($text) * 0.7)) {
            return $text;
        }

        return $annotated;
    }

    /**
     * Découpe en blocs (paragraphes regroupés) pour borner la sortie de chaque appel
     * — indispensable sur les longs articles (sinon troncature → repli sans annotation).
     *
     * @return list<string>
     */
    private function batches(string $text): array
    {
        $paras = preg_split('/\n\s*\n/', $text) ?: [$text];
        $batches = [];
        $cur = '';
        foreach ($paras as $p) {
            if ('' !== $cur && mb_strlen($cur) + mb_strlen($p) > self::BATCH_CHARS) {
                $batches[] = $cur;
                $cur = '';
            }
            $cur .= ('' === $cur ? '' : "\n\n").$p;
        }
        if ('' !== $cur) {
            $batches[] = $cur;
        }

        return [] !== $batches ? $batches : [$text];
    }

    /**
     * Texte de référence par source (aujourd'hui : titre + résumé ; demain : passage
     * plein texte via le locator).
     *
     * @param list<Publication> $sources
     */
    private function sourcesBlock(array $sources): string
    {
        $lines = ['SOURCES :'];
        foreach ($sources as $i => $s) {
            $lines[] = \sprintf("[%d] %s\n    %s", $i + 1, $s->getTitle(), mb_substr($s->getAbstract() ?? '(pas de résumé)', 0, 900));
        }

        return implode("\n", $lines);
    }
}
