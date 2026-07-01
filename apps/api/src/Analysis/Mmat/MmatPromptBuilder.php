<?php

declare(strict_types=1);

namespace App\Analysis\Mmat;

use App\Ai\Llm\LlmMessage;
use App\Catalog\MmatChecklist;
use App\Entity\Publication;

/**
 * Construit le prompt MMAT. Étape 0 = applicabilité (étude EMPIRIQUE ; les revues
 * systématiques et travaux non empiriques sont hors périmètre). Étape 1 = choix de la
 * CATÉGORIE (qualitative, essai randomisé, non randomisée, descriptive, méthodes
 * mixtes). Étape 2 = 2 questions de filtrage + 5 critères de la catégorie retenue, en
 * oui/non/impossible à déterminer, avec citation verbatim quand possible. JSON strict.
 */
final class MmatPromptBuilder
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
        $categories = [];
        foreach (MmatChecklist::CATEGORIES as $key => $label) {
            $categories[] = \sprintf('- %s : %s', $key, $label);
        }
        $catList = implode("\n", $categories);

        $screening = [];
        foreach (MmatChecklist::SCREENING as $key => $text) {
            $screening[] = \sprintf('- %s : %s', $key, $text);
        }
        $screenList = implode("\n", $screening);

        $blocks = [];
        foreach (MmatChecklist::CRITERIA as $cat => $criteria) {
            $lines = [];
            foreach ($criteria as $key => $text) {
                $lines[] = \sprintf('  - %s : %s', $key, $text);
            }
            $blocks[] = MmatChecklist::categoryLabel($cat)." (category = $cat) :\n".implode("\n", $lines);
        }
        $criteriaList = implode("\n", $blocks);

        return <<<TXT
            Tu es un assistant d'évaluation critique. Tu appliques l'outil MMAT (Mixed
            Methods Appraisal Tool, Hong et al. 2018), conçu pour les études EMPIRIQUES.

            ÉTAPE 0 — APPLICABILITÉ. Si l'objet N'EST PAS une étude empirique primaire (ex.
            revue systématique — utiliser AMSTAR-2 —, revue narrative, éditorial, opinion,
            protocole, travail purement théorique ou de modélisation), réponds
            "applicable": false et N'évalue PAS les items.

            ÉTAPE 1 — CATÉGORIE. Si c'est une étude empirique, choisis UNE seule catégorie
            MMAT parmi :
            $catList

            ÉTAPE 2 — Réponds aux 2 questions de FILTRAGE (communes) :
            $screenList

            puis aux 5 CRITÈRES (c1…c5) de la catégorie choisie UNIQUEMENT :
            $criteriaList

            Chaque réponse vaut "yes", "no" ou "cant_tell" :
            - yes = le critère est clairement satisfait ;
            - no = le critère n'est clairement pas satisfait ;
            - cant_tell = le texte fourni ne permet pas de trancher (ne PAS deviner).

            Règles STRICTES :
            - N'invente RIEN. Pour une réponse "yes", fournis si possible dans "quote" une
              phrase EXACTE (verbatim, langue d'origine) du texte qui l'appuie. Si l'élément
              n'est pas décrit dans le texte fourni, réponds "cant_tell".
            - Réponds pour s1, s2 et c1 à c5 (les cinq critères de la catégorie choisie).
            - "study_design" : un mot-clé court anglais (qualitative, rct, cohort,
              case_control, cross_sectional, mixed_methods, other).
            - "summary" : 2-3 phrases FR sur les forces/faiblesses. PAS de note chiffrée.
            - Réponds UNIQUEMENT par le JSON, sans texte autour, sans bloc de code.

            Schéma de sortie :
            {
              "study_design": "qualitative|rct|cohort|case_control|cross_sectional|mixed_methods|other",
              "category": "qualitative|quant_rct|quant_nonrandomized|quant_descriptive|mixed_methods",
              "applicable": true,
              "items": {
                "s1": {"answer": "yes|no|cant_tell", "quote": "phrase verbatim ou null"},
                "s2": {"answer": "…", "quote": null},
                "c1": {"answer": "…", "quote": null},
                "c2": {"answer": "…", "quote": null},
                "c3": {"answer": "…", "quote": null},
                "c4": {"answer": "…", "quote": null},
                "c5": {"answer": "…", "quote": null}
              },
              "summary": "synthèse en 2-3 phrases"
            }

            Si "applicable" est false, renvoie {"study_design": "…", "applicable": false,
            "summary": "…"} sans "category" ni "items".
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
