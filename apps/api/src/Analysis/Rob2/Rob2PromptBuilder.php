<?php

declare(strict_types=1);

namespace App\Analysis\Rob2;

use App\Ai\Llm\LlmMessage;
use App\Catalog\Rob2Checklist;
use App\Entity\Publication;

/**
 * Construit le prompt RoB 2 (risque de biais d'un essai randomisé). Étape 0 =
 * applicabilité (l'outil ne vaut que pour les ESSAIS CONTRÔLÉS RANDOMISÉS) ; puis
 * les 5 domaines, chacun jugé low/some_concerns/high, avec citation verbatim
 * obligatoire pour tout « risque élevé ». JSON strict, température 0 côté appelant.
 */
final class Rob2PromptBuilder
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
        $domains = [];
        foreach (Rob2Checklist::DOMAINS as $key => $d) {
            $domains[] = \sprintf("- %s — %s : %s", $key, $d['title'], $d['focus']);
        }
        $list = implode("\n", $domains);

        return <<<TXT
            Tu es un assistant d'évaluation critique. Tu appliques l'outil RoB 2 (Risk of
            Bias 2, Sterne et al., BMJ 2019), conçu UNIQUEMENT pour les ESSAIS CONTRÔLÉS
            RANDOMISÉS (randomized controlled trials).

            ÉTAPE 0 — APPLICABILITÉ. Détermine d'abord le design. Si ce N'EST PAS un essai
            randomisé (ex. étude transversale, cohorte, cas-témoins, revue systématique,
            qualitative, in vitro), réponds "applicable": false et N'évalue PAS les domaines.

            ÉTAPE 1 — Si c'est un essai randomisé, juge les 5 DOMAINES DE BIAIS ci-dessous.
            Pour chaque domaine, attribue "judgement" ∈ {"low", "some_concerns", "high"} :
            - low = risque de biais faible (procédures adéquates et clairement décrites) ;
            - some_concerns = doute ou information manquante/incomplète ;
            - high = problème méthodologique avéré exposant à un biais sérieux.
            $list

            Règles STRICTES :
            - N'invente RIEN. Si l'information est absente du texte, mets "some_concerns"
              (c'est la réponse par défaut en cas d'incertitude, jamais "low" par défaut).
            - Pour TOUT domaine jugé "high", fournis dans "quote" une phrase EXACTE (verbatim,
              langue d'origine) du texte qui le prouve. Sans citation probante, mets
              "some_concerns". "rationale" = 1 phrase FR de justification.
            - "study_design" : un mot-clé court anglais (rct, cross_sectional, cohort,
              case_control, systematic_review, qualitative, other).
            - "summary" : 2-3 phrases FR sur les forces/faiblesses. PAS de note chiffrée.
            - Réponds UNIQUEMENT par le JSON, sans texte autour, sans bloc de code.

            Schéma de sortie :
            {
              "study_design": "rct|cross_sectional|cohort|case_control|systematic_review|qualitative|other",
              "applicable": true,
              "domains": {
                "d1": {"judgement": "low|some_concerns|high", "quote": "phrase verbatim ou null", "rationale": "1 phrase"},
                "d2": {"judgement": "…", "quote": null, "rationale": "…"},
                "d3": {"judgement": "…", "quote": null, "rationale": "…"},
                "d4": {"judgement": "…", "quote": null, "rationale": "…"},
                "d5": {"judgement": "…", "quote": null, "rationale": "…"}
              },
              "summary": "synthèse en 2-3 phrases"
            }

            Si "applicable" est false, renvoie {"study_design": "…", "applicable": false,
            "summary": "…"} sans le bloc "domains".
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
