<?php

declare(strict_types=1);

namespace Analyses\Analyzer;

use Analyses\Ontology\StudyDesign;
use Analyses\Sdk\LlmPort;
use Analyses\Service\SettingsService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Empreinte d'étude (SPECS §7.2) : détecte le plan d'étude, la finalité, les domaines
 * et modalités à partir des métadonnées + plein texte, via le LLM. Sépare la
 * classification (fond) de la restitution.
 */
final class StudyFingerprinter
{
    public function __construct(
        private readonly LlmPort $llm,
        private readonly SettingsService $settings,
        #[Autowire(env: 'ANALYS_EXTRACTOR_MODEL')]
        private readonly string $model = 'glm-4.7-flash:latest',
    ) {
    }

    /**
     * @param array<string, mixed> $meta métadonnées de publication (title, abstract, type…)
     *
     * @return array<string, mixed> fingerprint normalisé
     */
    public function fingerprint(array $meta, string $fulltext): array
    {
        $designs = implode(', ', array_map(static fn (StudyDesign $d): string => $d->value, StudyDesign::cases()));
        $source = '' !== trim($fulltext) ? $fulltext : (string) ($meta['abstract'] ?? '');
        $excerpt = mb_substr($source, 0, 12000);

        $prompt = <<<PROMPT
            Tu es un méthodologiste de la recherche. À partir des métadonnées et du texte,
            identifie le plan d'étude PRINCIPAL. Réponds STRICTEMENT en JSON, sans commentaire.

            Métadonnées :
            - titre : {$this->str($meta['title'] ?? '')}
            - type déclaré : {$this->str($meta['type'] ?? '')}

            Texte (extrait) :
            {$excerpt}

            Codes de plan d'étude autorisés : {$designs}

            Format de réponse JSON :
            {
              "study_design": "<un code de la liste, ou 'unknown'>",
              "confidence": <nombre entre 0 et 1>,
              "publication_type": "<type>",
              "objectives": ["<codes de finalité: efficacy, safety, causality, association, prevalence, diagnosis, prognosis, prediction...>"],
              "domains": ["<codes de domaine: medicine, psychology, epidemiology, artificial_intelligence...>"],
              "modalities": ["<codes de modalité: tabular, survey, questionnaire, medical_imaging, source_code...>"],
              "rationale": "<justification brève en français>"
            }
            Si le plan ne peut être déterminé, utilise "unknown" avec une confiance basse.
            PROMPT;

        $out = $this->llm->generateJson($prompt, $this->settings->extractorModel() ?: $this->model);

        $design = StudyDesign::tryFrom((string) ($out['study_design'] ?? '')) ?? StudyDesign::Unknown;
        $confidence = (float) ($out['confidence'] ?? 0);
        $confidence = max(0.0, min(1.0, $confidence));

        return [
            'study_design' => $design->value,
            'design_label' => $design->label(),
            'confidence' => $confidence,
            'publication_type' => $this->str($out['publication_type'] ?? null) ?: null,
            'objectives' => $this->codeList($out['objectives'] ?? []),
            'domains' => $this->codeList($out['domains'] ?? []),
            'modalities' => $this->codeList($out['modalities'] ?? []),
            'rationale' => $this->str($out['rationale'] ?? null) ?: null,
            'fulltext_available' => '' !== trim($fulltext),
        ];
    }

    private function str(mixed $v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }

    /**
     * @return list<string>
     */
    private function codeList(mixed $v): array
    {
        if (!\is_array($v)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($x): string => is_scalar($x) ? trim((string) $x) : '',
            $v,
        ), static fn (string $s): bool => '' !== $s));
    }
}
