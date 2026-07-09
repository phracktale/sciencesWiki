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
            // Intitulé + texte d'aide OFFICIEL (annexe explicative AXIS) : le modèle
            // applique chaque critère selon la définition des auteurs.
            $questions[] = \sprintf("- %s (%s) : %s%s\n  Aide : %s", $key, $item['section'], $item['text'], $flag, $item['help']);
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

            ÉTAPE 1 — Si l'étude est transversale, évalue les 20 items ci-dessous. Chaque item
            est accompagné de son texte d'aide OFFICIEL (« Aide : ») : appuie-toi dessus pour
            décider, comme un relecteur qui aurait le manuel AXIS sous les yeux.
            $list

            Cas particulier — RECENSEMENT (census) : si la population cible ET les participants
            sont identiques (recensement exhaustif), les items q5, q6 et q7 ne s'appliquent en
            théorie pas → réponds "na" pour ces trois items (sauf si le recrutement reste flou).

            DOCTRINE DE DÉCISION (sévérité) — une revue critique doit être EXIGEANTE. Ne coche
            pas « yes » simplement parce que l'article paraît sérieux : une simple DESCRIPTION
            n'est pas une JUSTIFICATION. En cas de doute entre deux niveaux, retiens le plus
            sévère JUSTIFIABLE (ne surévalue jamais). Règles spécifiques :
            - q3 (taille d'échantillon) : "yes" UNIQUEMENT si calcul de puissance / justification
              statistique / justification a priori explicite ; "partial" si justification pragmatique
              explicite mais sans calcul ; "no" ou "unclear" si simple description du nombre inclus.
              Décrire « N patients » n'est PAS justifier la taille.
            - q11 (méthodes reproductibles) : "yes" UNIQUEMENT si méthodes, instruments, seuils ET
              analyses sont assez décrits pour une réplication ; "partial" si les analyses sont
              décrites mais qu'un composant clinique/protocolaire est propriétaire ou indisponible
              (ex. entretien « semi-structuré propriétaire ») ; sinon "no"/"unclear".
            - q13 (non-réponse — INVERSÉ) : si l'étude N'EST PAS une enquête avec taux de réponse,
              N'INVENTE PAS de taux de réponse. Évalue les exclusions/pertes comme un biais de
              SÉLECTION : des exclusions importantes non décrites de façon comparative → "unclear"
              (ou "na") en signalant le risque de biais de sélection dans "reasoning" ; ne coche pas
              un « oui d'inquiétude » fondé sur un taux de réponse inexistant.
            - q16 (analyses rapportées) : "yes" UNIQUEMENT si TOUTES les analyses annoncées ont des
              résultats chiffrés ; "partial" si certaines ne sont rapportées que narrativement
              (« non significatif ») sans statistiques complètes.
            - q19 (financement / conflits — INVERSÉ) : ne CONFONDS JAMAIS « absence de conflit
              déclaré » et « absence de déclaration ». Si les conflits sont déclarés absents mais le
              financement n'est pas mentionné → "partial" ou "unclear" (conflits ok, financement non
              documenté), et surtout PAS un « oui » de conflit.
            - q2 (plan d'étude) : un devis transversal EST adapté à une question d'ASSOCIATION
              (« Oui, avec prudence ») ; ne pénalise pas les mots « predictor/effect » tant que les
              auteurs ne revendiquent pas une CAUSALITÉ.
            - q4 (population cible) : une population de référence CLINIQUE clairement décrite (ex.
              « adultes référés pour évaluation d'un TDAH ») suffit pour "yes".

            SOURCES DISPONIBLES : tu ne reçois que le TEXTE extrait de l'article (résumé + texte
            intégral quand disponible). Tu N'AS PAS de rendu image des pages : tableaux, figures et
            notes de tableau ne te sont accessibles QUE s'ils ont été transcrits dans le texte.
            Avant de conclure « absent », cherche dans TOUTES les sections fournies (résumé,
            méthodes, résultats, tableaux transcrits, discussion, limites, déclarations éthiques et
            de financement).

            Règles de sortie — pour CHAQUE item, fournis une ANALYSE STRUCTURÉE (JAMAIS un simple
            résumé du verdict) :
                • "answer"        : "yes" | "partial" | "no" | "na" | "unclear" (selon la doctrine).
                • "verdict"       : libellé court nuancé en français (« Oui, avec prudence »…).
                • "expected"      : ce que la grille AXIS EXIGE pour répondre « yes » à CET item
                  (énonce la règle AVANT de juger).
                • "evidence_found": ce que l'article fournit RÉELLEMENT sur ce point, ou « rien trouvé ».
                • "analysis"      : la COMPARAISON explicite entre l'attendu et le trouvé (le motif
                  de ta réponse). JAMAIS vide.
                • "limitations"   : ce qui manque, est ambigu, ou repose sur une inférence.
                • "evidence"      : liste de 0 à 5 preuves, chacune
                  { "source_type": "text|table|figure", "section": "ex. Methods/Participants",
                    "quote": "phrase verbatim (langue d'origine) ou transcription courte" }.
                • "evidence_type" : "explicit_quote" (le texte l'affirme, citation à l'appui) |
                  "visual_table" | "visual_figure" (transcription d'un tableau/figure présente dans le
                  texte) | "absence_from_full_text" (tu as vérifié TOUT le texte fourni et l'info n'y
                  est pas) | "absence_from_extracted_text_only" (non trouvé dans le texte extrait, mais
                  pourrait figurer dans un tableau/figure image non analysé → PRUDENCE) | "inference".
                • "confidence"    : "high" | "medium" | "low" ("high" INTERDIT si evidence_type vaut
                  "inference" ou "absence_from_extracted_text_only").
                • "requires_visual_check" : true si la réponse dépend probablement d'un tableau/figure
                  non transcrit (typiquement q12, q15, q16 ; parfois q10, q19).
            - ANCRAGE STRICT (3 niveaux) : une réponse "yes"/"partial"/"no" n'est valable que si
              (1) une preuve verbatim est réellement présente dans le texte, OU (2) elle repose sur
              une absence VÉRIFIÉE sur tout le texte ("absence_from_full_text"). Une simple
              "absence_from_extracted_text_only" ou une "inference" NE SUFFIT PAS : réponds "unclear".
              Une justification non sourcée ne doit JAMAIS faire pencher la balance.
            - N'invente RIEN. "study_design" : mot-clé anglais (cross-sectional, rct, cohort,
              case_control, systematic_review, meta_analysis, in_vivo, in_vitro, modeling, other).
            - "summary" : réflexion générale de 2 à 4 phrases (forces/faiblesses). PAS de note chiffrée.
            - Réponds UNIQUEMENT par le JSON, sans texte autour, sans bloc de code.

            Schéma de sortie (par item) :
            {
              "study_design": "cross-sectional|rct|cohort|case_control|systematic_review|meta_analysis|in_vivo|in_vitro|modeling|other",
              "applicable": true,
              "items": {
                "q1": {
                  "answer": "yes|partial|no|na|unclear", "verdict": "…",
                  "expected": "ce qu'AXIS exige pour un oui",
                  "evidence_found": "ce que l'article fournit",
                  "analysis": "comparaison attendu vs trouvé",
                  "limitations": "ce qui manque / ambigu / inféré",
                  "evidence": [{"source_type": "text", "section": "Methods", "quote": "verbatim"}],
                  "evidence_type": "explicit_quote|visual_table|visual_figure|absence_from_full_text|absence_from_extracted_text_only|inference",
                  "confidence": "high|medium|low",
                  "requires_visual_check": false
                },
                "…": { … }, "q20": { … }
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
