<?php

declare(strict_types=1);

namespace Analyses\Framework;

/**
 * Construit un prompt système « riche » (structure AXIS) pour N'IMPORTE quel {@see RichFramework} :
 * présentation de l'outil, étape 0 d'applicabilité éventuelle, échelle de réponse, doctrine de
 * sévérité, cadrage item par item (attendu + grille par niveau + où chercher), doctrine
 * d'ancrage strict à 3 niveaux et schéma de sortie JSON riche identique à AXIS. La sortie est
 * donc analysable par le même code ({@see \Analyses\Analyzer\AbstractRichAnalyzer}).
 */
final class RichPromptBuilder
{
    public function system(RichFramework $f): string
    {
        $scale = [];
        foreach ($f->answerScale() as $value => $meaning) {
            $scale[] = \sprintf('  • "%s" : %s', $value, $meaning);
        }
        $scaleTxt = implode("\n", $scale);
        $answerValues = implode(' | ', array_map(static fn (string $v): string => '"'.$v.'"', array_keys($f->answerScale())));

        $blocks = [];
        foreach ($f->richItems() as $it) {
            $rev = $it['reverse'] ? ' [ITEM INVERSÉ — un « oui » est DÉFAVORABLE]' : '';
            $lines = [\sprintf('%s — %s%s  (section : %s)', $it['id'], $it['question'], $rev, $it['section'])];
            if ('' !== $it['help']) {
                $lines[] = 'aide : '.$it['help'];
            }
            $lines[] = 'expected : '.$it['expected'];
            foreach ($it['levels'] as $answer => $rule) {
                $lines[] = $answer.' : '.$rule;
            }
            if ($it['na']) {
                $lines[] = 'na : uniquement si l’item ne s’applique réellement pas à cette étude (explique pourquoi).';
            }
            if ('' !== $it['special']) {
                $lines[] = 'Règle spéciale : '.$it['special'];
            }
            $lines[] = 'Où chercher : '.$it['where'];
            $lines[] = 'requires_visual_check : '.($it['visual'] ? 'true par défaut (dépend probablement d’un tableau/figure non transcrit).' : 'false, sauf si la réponse dépend d’un tableau/figure non transcrit.');
            $blocks[] = implode("\n", $lines);
        }
        $itemsTxt = implode("\n\n", $blocks);

        $applicability = '';
        if (null !== ($note = $f->applicabilityNote())) {
            $applicability = <<<TXT

                ÉTAPE 0 — APPLICABILITÉ. Détermine d'abord le design de l'étude. $note
                Si le référentiel est hors-sujet, réponds "applicable": false et N'évalue PAS les items.

                TXT;
        }

        $doctrine = '' !== $f->doctrine() ? "\n".$f->doctrine()."\n" : '';

        return <<<TXT
            {$f->toolIntro()}
            $applicability
            ÉCHELLE DE RÉPONSE — le champ "answer" vaut STRICTEMENT l'une de : {$answerValues}.
            $scaleTxt

            DOCTRINE DE DÉCISION (sévérité) — une revue critique doit être EXIGEANTE. Ne retiens
            pas la réponse la plus favorable simplement parce que l'article paraît sérieux : une
            simple DESCRIPTION n'est pas une JUSTIFICATION. En cas de doute entre deux niveaux,
            retiens le plus sévère JUSTIFIABLE (ne surévalue jamais).
            $doctrine
            CADRAGE ITEM PAR ITEM

            Règle commune — pour chaque item, distingue toujours :
            1. ce que l’article affirme explicitement ;
            2. ce que l’article permet seulement d’inférer ;
            3. ce qui est absent du texte fourni ;
            4. ce qui pourrait être dans un tableau, une figure, une annexe ou une note non transcrite.
            En cas de doute entre une réponse conclusive et « {$f->unclearAnswer()} », choisis « {$f->unclearAnswer()} ».

            $itemsTxt

            SOURCES DISPONIBLES : tu ne reçois que le TEXTE extrait de l'article (résumé + texte
            intégral quand disponible). Tu N'AS PAS de rendu image des pages : tableaux, figures et
            notes de tableau ne te sont accessibles QUE s'ils ont été transcrits dans le texte.
            Avant de conclure « absent », cherche dans TOUTES les sections fournies.

            Règles de sortie — pour CHAQUE item, fournis une ANALYSE STRUCTURÉE (JAMAIS un simple
            résumé du verdict) :
                • "answer"        : l'une des valeurs de l'échelle ci-dessus.
                • "verdict"       : libellé court nuancé en français.
                • "expected"      : ce que le référentiel EXIGE pour la meilleure réponse à CET item.
                • "evidence_found": ce que l'article fournit RÉELLEMENT, ou « rien trouvé ».
                • "analysis"      : la COMPARAISON explicite entre l'attendu et le trouvé. JAMAIS vide.
                • "limitations"   : ce qui manque, est ambigu, ou repose sur une inférence.
                • "evidence"      : liste de 0 à 5 preuves, chacune
                  { "source_type": "text|table|figure", "section": "ex. Methods",
                    "quote": "phrase verbatim (langue d'origine) ou transcription courte",
                    "evidence_type": "explicit_quote|visual_table|visual_figure|absence_from_full_text|absence_from_extracted_text_only|inference" }.
                • "overall_evidence_type" : type de preuve global de l'item.
                • "confidence"    : "high" | "medium" | "low" ("high" INTERDIT si inference ou absence_from_extracted_text_only).
                • "requires_visual_check" : true si la réponse dépend probablement d'un tableau/figure non transcrit.

            ANCRAGE STRICT — preuve, absence et inférence.
            Une réponse CONCLUSIVE (toute réponse sauf « {$f->unclearAnswer()} »/« na ») n'est valable que si elle repose sur :
              1. Preuve explicite : une citation verbatim (ou transcription courte) qui étaye la réponse → evidence_type = "explicit_quote".
              2. Combinaison cohérente de plusieurs passages distincts, chacun cité → evidence_type = "explicit_quote".
              3. Absence VÉRIFIÉE sur tout le texte fourni → evidence_type = "absence_from_full_text".
            Si la conclusion résulte d'un raisonnement indirect → evidence_type = "inference" et confidence ≠ "high".
            Si l'information manque du texte extrait mais pourrait être dans un tableau/figure/annexe non analysé →
            evidence_type = "absence_from_extracted_text_only", confidence = "low", et la réponse DOIT être « {$f->unclearAnswer()} ».
            Une justification non sourcée ne doit jamais faire pencher vers une réponse conclusive.

            N'invente RIEN. "study_design" : mot-clé anglais (cross-sectional, rct, cohort,
            case_control, systematic_review, meta_analysis, in_vivo, in_vitro, modeling, other).
            "summary" : réflexion générale de 2 à 4 phrases (forces/faiblesses). PAS de note chiffrée.
            Réponds UNIQUEMENT par le JSON, sans texte autour, sans bloc de code.

            Schéma : {"study_design":"…","applicable":true,"items":{"<id_item>":{"answer":"…","verdict":"…",
            "expected":"…","evidence_found":"…","analysis":"…","limitations":"…","evidence":[{"source_type":"text",
            "section":"…","quote":"…","evidence_type":"explicit_quote"}],"overall_evidence_type":"…",
            "requires_visual_check":false}, "…":{…}}, "summary":"…"}
            Les clés de "items" sont EXACTEMENT les identifiants d'item fournis ci-dessus.
            Si "applicable" est false, renvoie {"study_design":"…","applicable":false,"summary":"…"} sans "items".
            TXT;
    }

    public function user(string $title, string $sourceText): string
    {
        $parts = ['TITRE : '.$title];
        if ('' !== trim($sourceText)) {
            $parts[] = "TEXTE DE L'ARTICLE (résumé et, si disponible, extrait du texte intégral) :\n".trim($sourceText);
        }

        return implode("\n\n", $parts);
    }
}
