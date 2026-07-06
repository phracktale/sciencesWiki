<?php

declare(strict_types=1);

namespace App\Analysis\Axis;

use App\Ai\Llm\LlmMessage;
use App\Catalog\AxisChecklist;
use App\Entity\Publication;

/**
 * Construit le prompt d'évaluation AXIS d'une publication
 * (cf. docs/spec-axis-articles.md §5.1). Étape 0 = applicabilité (l'outil ne vaut
 * que pour les études transversales) ; puis les 20 items, réponses Oui/Non/Indéterminé
 * ancrées par une citation verbatim pour chaque réponse défavorable. JSON strict,
 * température 0 côté appelant.
 */
final class AxisPromptBuilder
{
    /**
     * @return list<LlmMessage>
     */
    public function build(Publication $publication, string $sourceText): array
    {
        return [
            LlmMessage::system($this->system()),
            LlmMessage::user($this->user($publication, $sourceText)),
        ];
    }

    private function system(): string
    {
        $questions = [];
        foreach (AxisChecklist::ITEMS as $key => $item) {
            $flag = \in_array($key, AxisChecklist::REVERSE, true) ? ' [un « oui » est DÉFAVORABLE]' : '';
            $questions[] = \sprintf('- %s (%s) : %s%s', $key, $item['section'], $item['text'], $flag);
        }
        $list = implode("\n", $questions);

        return <<<TXT
            Tu es un assistant d'évaluation critique d'articles scientifiques. Tu appliques
            l'outil AXIS (Appraisal tool for Cross-Sectional Studies, Downes et al., BMJ Open
            2016), conçu UNIQUEMENT pour les ÉTUDES TRANSVERSALES (cross-sectional / enquêtes
            de prévalence à un instant T).

            ÉTAPE 0 — APPLICABILITÉ. Détermine d'abord le design de l'étude. Si ce N'EST PAS
            une étude transversale (ex. essai randomisé, cohorte, cas-témoins, revue
            systématique, méta-analyse, in vivo/in vitro, modélisation), réponds
            "applicable": false et N'évalue PAS les 20 items (AXIS serait hors-sujet).

            ÉTAPE 1 — Si l'étude est transversale, évalue les 20 items ci-dessous :
            $list

            Règles :
            - Pour CHAQUE item, fournis TOUJOURS quatre éléments :
                • "answer"    : une modalité CANONIQUE parmi :
                    "yes"     = critère clairement rempli ;
                    "partial" = partiellement rempli / rempli avec réserve ;
                    "no"      = non rempli ;
                    "na"      = non applicable à CE type d'étude (item hors-sujet) ;
                    "unclear" = information réellement absente du texte.
                • "verdict"   : un LIBELLÉ court et NUANCÉ en français reflétant ta réponse,
                  à la manière d'un relecteur — ex. « Oui », « Oui, avec réserve »,
                  « Oui, avec prudence », « Partiellement », « Partiellement clair »,
                  « Non », « Non applicable », « Indéterminé ». Sois précis et nuancé.
                • "reasoning" : ta RÉFLEXION en une phrase claire en français expliquant
                  pourquoi (JAMAIS vide) ;
                • "quote"     : une phrase EXACTE (verbatim, langue d'origine) du texte qui
                  étaye ta réponse si elle existe, sinon null.
            - N'invente RIEN : fonde-toi uniquement sur le texte fourni. Si l'information est
              réellement absente, réponds "unclear".
            - "study_design" : un mot-clé court en anglais (cross-sectional, rct, cohort,
              case_control, systematic_review, meta_analysis, in_vivo, in_vitro, modeling, other).
            - "summary" : une RÉFLEXION GÉNÉRALE de 2 à 4 phrases en français (forces et
              faiblesses méthodologiques d'ensemble). N'attribue PAS de note chiffrée.
            - Réponds UNIQUEMENT par le JSON, sans texte autour, sans bloc de code.

            Schéma de sortie :
            {
              "study_design": "cross-sectional|rct|cohort|case_control|systematic_review|meta_analysis|in_vivo|in_vitro|modeling|other",
              "applicable": true,
              "items": {
                "q1": {"answer": "yes|partial|no|na|unclear", "verdict": "libellé nuancé en français", "reasoning": "ta réflexion", "quote": "phrase verbatim ou null"},
                "…": {"answer": "…", "verdict": "…", "reasoning": "…", "quote": null},
                "q20": {"answer": "yes|partial|no|na|unclear", "verdict": "…", "reasoning": "…", "quote": null}
              },
              "summary": "réflexion générale en 2-4 phrases"
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
