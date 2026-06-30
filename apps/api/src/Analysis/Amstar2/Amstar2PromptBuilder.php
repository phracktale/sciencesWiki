<?php

declare(strict_types=1);

namespace App\Analysis\Amstar2;

use App\Ai\Llm\LlmMessage;
use App\Catalog\Amstar2Checklist;
use App\Entity\Publication;

/**
 * Construit le prompt AMSTAR-2 (confiance dans une revue systématique). Étape 0 =
 * applicabilité (revue systématique / méta-analyse seulement) ; puis les 16 items
 * (oui/oui partiel/non), avec citation verbatim pour les réponses positives quand
 * possible. JSON strict, température 0 côté appelant.
 */
final class Amstar2PromptBuilder
{
    /** @return list<LlmMessage> */
    public function build(Publication $publication, string $sourceText): array
    {
        return [
            LlmMessage::system($this->system()),
            LlmMessage::user($this->user($publication, $sourceText)),
        ];
    }

    private function system(): string
    {
        $items = [];
        foreach (Amstar2Checklist::ITEMS as $key => $text) {
            $crit = Amstar2Checklist::isCritical($key) ? ' [DOMAINE CRITIQUE]' : '';
            $items[] = \sprintf('- %s%s : %s', $key, $crit, $text);
        }
        $list = implode("\n", $items);

        return <<<TXT
            Tu es un assistant d'évaluation critique. Tu appliques l'outil AMSTAR-2 (Shea
            et al., BMJ 2017), conçu UNIQUEMENT pour les REVUES SYSTÉMATIQUES et
            MÉTA-ANALYSES.

            ÉTAPE 0 — APPLICABILITÉ. Détermine d'abord le design. Si ce N'EST PAS une revue
            systématique / méta-analyse (ex. essai randomisé, étude transversale, cohorte,
            revue narrative simple, éditorial), réponds "applicable": false et N'évalue PAS
            les items.

            ÉTAPE 1 — Si c'est une revue systématique, réponds aux 16 items par "yes",
            "partial_yes" ou "no" :
            - yes = l'élément est clairement présent / correctement réalisé ;
            - partial_yes = partiellement présent ou incomplet ;
            - no = absent OU non rapporté (convention AMSTAR-2 : un élément non rapporté
              est compté comme "no").
            $list

            Règles STRICTES :
            - N'invente RIEN. Pour une réponse "yes" ou "partial_yes", fournis si possible
              dans "quote" une phrase EXACTE (verbatim, langue d'origine) du texte qui
              l'appuie. Si l'élément n'est pas décrit dans le texte fourni, réponds "no".
            - "study_design" : un mot-clé court anglais (systematic_review, meta_analysis,
              rct, cross_sectional, cohort, narrative_review, other).
            - "summary" : 2-3 phrases FR sur les forces/faiblesses. PAS de note chiffrée.
            - Réponds UNIQUEMENT par le JSON, sans texte autour, sans bloc de code.

            Schéma de sortie :
            {
              "study_design": "systematic_review|meta_analysis|rct|cross_sectional|cohort|narrative_review|other",
              "applicable": true,
              "items": {
                "q1": {"answer": "yes|partial_yes|no", "quote": "phrase verbatim ou null"},
                "…": {"answer": "…", "quote": null},
                "q16": {"answer": "yes|partial_yes|no", "quote": null}
              },
              "summary": "synthèse en 2-3 phrases"
            }

            Si "applicable" est false, renvoie {"study_design": "…", "applicable": false,
            "summary": "…"} sans le bloc "items".
            TXT;
    }

    private function user(Publication $publication, string $sourceText): string
    {
        $parts = ['TITRE : '.$publication->getTitle()];
        if ('' !== trim($sourceText)) {
            $parts[] = "TEXTE DE L'ARTICLE (résumé et, si disponible, extrait du texte intégral) :\n".trim($sourceText);
        }

        return implode("\n\n", $parts);
    }
}
