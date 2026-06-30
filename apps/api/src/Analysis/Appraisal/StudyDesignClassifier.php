<?php

declare(strict_types=1);

namespace App\Analysis\Appraisal;

use App\Ai\Llm\LlmClient;
use App\Ai\Llm\LlmMessage;
use App\Entity\Publication;
use App\Service\SettingsService;

/**
 * Détecte le DEVIS d'une étude (à partir du titre + résumé) via le LLM, puis en
 * déduit les outils d'évaluation critique applicables ({@see AppraisalToolRegistry}).
 * Persiste study_design / appraisal_tools / classified_at sur la publication (le
 * flush incombe à l'appelant). Léger (résumé seul, peu de tokens, modèle light).
 */
final class StudyDesignClassifier
{
    private const LLM_TIMEOUT = 60;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly SettingsService $settings,
        private readonly AppraisalToolRegistry $registry,
    ) {
    }

    public function classify(Publication $publication): void
    {
        $design = $this->detect($publication);
        $publication
            ->setStudyDesign($design)
            ->setAppraisalTools($this->registry->toolsForDesign($design))
            ->setClassifiedAt(new \DateTimeImmutable());
    }

    private function detect(Publication $publication): string
    {
        $abstract = trim((string) ($publication->getAbstract() ?? $publication->getAbstractFr() ?? ''));
        // Sans résumé, on ne devine pas le devis de façon fiable → « autre » (classée
        // quand même pour ne pas re-tenter en boucle).
        if ('' === $abstract) {
            return 'other';
        }

        $opts = ['temperature' => 0.0, 'max_tokens' => 60, 'model' => $this->settings->lightModel(), 'timeout' => self::LLM_TIMEOUT];
        $raw = $this->llm->complete($this->messages($publication, $abstract), $opts)->content;

        return $this->parseDesign($raw);
    }

    /** @return list<LlmMessage> */
    private function messages(Publication $publication, string $abstract): array
    {
        $keys = implode(', ', $this->registry->designKeys());
        $system = <<<TXT
            Tu es un méthodologiste. À partir du titre et du résumé d'un article
            scientifique, détermine le DEVIS de l'étude (study design) et réponds par
            UNE seule des clés suivantes :
            $keys

            Repères :
            - rct = essai contrôlé randomisé ; non_randomized_trial = intervention non randomisée.
            - cohort = suivi prospectif/rétrospectif ; case_control = cas-témoins.
            - cross_sectional = transversale / enquête de prévalence à un instant T.
            - diagnostic_accuracy = sensibilité/spécificité d'un test.
            - systematic_review = revue systématique ou méta-analyse.
            - qualitative = entretiens, ethnographie… ; mixed_methods = quanti + quali.
            - case_report = cas isolé ou série de cas ; prognostic = facteurs pronostiques/prédiction.
            - economic = évaluation économique.
            - non_empirical = théorique, modélisation/simulation, revue narrative, éditorial,
              article de méthode — AUCUNE donnée empirique sur des sujets.
            - other = si vraiment indéterminé.

            Réponds UNIQUEMENT par un JSON strict : {"design": "<clé>"}. Aucun autre texte.
            TXT;

        return [
            LlmMessage::system($system),
            LlmMessage::user('TITRE : '.$publication->getTitle()."\n\nRÉSUMÉ :\n".$abstract),
        ];
    }

    private function parseDesign(string $raw): string
    {
        $json = trim($raw);
        // Retire un éventuel bloc de code ```json … ```.
        $json = (string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $json);
        $data = json_decode($json, true);
        $design = \is_array($data) ? (string) ($data['design'] ?? '') : '';
        $design = strtolower(trim($design));

        return isset(AppraisalToolRegistry::DESIGNS[$design]) ? $design : 'other';
    }
}
