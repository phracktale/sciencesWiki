<?php

declare(strict_types=1);

namespace App\Analysis\Claim;

use App\Ai\Llm\LlmMessage;
use App\Entity\Publication;

/**
 * Construit le prompt d'extraction structurée des assertions d'une publication
 * (cf. docs/spec-controverses-lacunes.md §5.1). Force un JSON unique ;
 * température 0 côté appelant pour la reproductibilité.
 */
final class ClaimPromptBuilder
{
    private const SYSTEM = <<<'TXT'
        Tu es un assistant d'extraction de données scientifiques. À partir du TITRE, du
        RÉSUMÉ et de la CONCLUSION d'un article, extrais chaque RELATION CAUSALE OU
        CORRÉLATIONNELLE testée, sous forme d'un tableau JSON STRICT.

        Règles :
        - N'invente RIEN. Si une information est absente, mets null.
        - Une entrée par couple (exposition, résultat) effectivement étudié.
        - "exposure" et "outcome" : libellés COURTS (1 à 5 mots), en ANGLAIS, le
          nom canonique du facteur et du résultat — PAS une phrase, PAS de méthode
          ni de détail, et JAMAIS deux langues mélangées (ex. "vitamin D",
          "bone fractures" ; pas "utilisation de vitamine D running supplementation").
        - "direction" décrit le signe du résultat RAPPORTÉ par les auteurs, pas ton avis.
        - "quote" doit être une phrase EXACTE de l'article (verbatim, langue d'origine)
          justifiant l'entrée.
        - Réponds UNIQUEMENT par le JSON, sans texte autour, sans bloc de code.

        Schéma de chaque entrée :
        {
          "exposure": "facteur/intervention étudié (string)",
          "outcome": "résultat/effet mesuré (string)",
          "direction": "positive|negative|null|mixed|unclear",
          "method": "meta_analysis|rct|cohort|case_control|observational|in_vivo|in_vitro|modeling|review|other",
          "confidence": "high|moderate|low",
          "population": "string|null",
          "sample_size": "integer|null",
          "effect_size": "string|null  (ex. 'OR 1.8, IC95 1.2-2.7')",
          "stated_limitations": "string|null",
          "future_work": ["pistes futures explicitement réclamées", "..."],
          "quote": "phrase verbatim de l'article"
        }

        Sortie attendue : {"claims": [ …entrées… ]}  (tableau vide si rien d'extractible)
        TXT;

    /**
     * @return list<LlmMessage>
     */
    public function build(Publication $publication, ?string $conclusion = null): array
    {
        return [
            LlmMessage::system(self::SYSTEM),
            LlmMessage::user($this->user($publication, $conclusion)),
        ];
    }

    private function user(Publication $publication, ?string $conclusion): string
    {
        // Privilégie le résumé d'origine (langue de l'article) ; repli traduction FR.
        $abstract = $publication->getAbstract() ?? $publication->getAbstractFr() ?? '';

        $parts = ['TITRE : '.$publication->getTitle()];
        if ('' !== trim($abstract)) {
            $parts[] = "RÉSUMÉ :\n".trim($abstract);
        }
        if (null !== $conclusion && '' !== trim($conclusion)) {
            $parts[] = "CONCLUSION :\n".trim($conclusion);
        }

        return implode("\n\n", $parts);
    }
}
