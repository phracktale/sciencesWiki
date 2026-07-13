<?php

declare(strict_types=1);

namespace Analyses\Framework\Rob2;

use Analyses\Framework\AbstractRichFramework;
use Analyses\Framework\FrameworkInterface;

/**
 * RoB 2 (Cochrane Risk of Bias 2, essais randomisés) — CALIBRÉ « riche » au niveau AXIS.
 * Jugement par domaine sur l'échelle low / some_concerns / high, chaque domaine cadré par ses
 * questions de signalisation officielles. Applicable aux seuls essais randomisés (étape 0).
 */
final class Rob2Framework extends AbstractRichFramework implements FrameworkInterface
{
    public function id(): string
    {
        return 'rob2';
    }

    public function metadata(): array
    {
        return [
            'name' => 'RoB 2',
            'version' => '2.0',
            'framework_type' => 'risk_of_bias',
            'supported_designs' => ['randomized_controlled_trial', 'cluster_randomized_trial', 'crossover_trial'],
            'supported_domains' => ['*'],
            'required_inputs' => ['full_text'],
            'dimensions' => ['risk_of_bias'],
            'incompatibilities' => ['systematic_review', 'meta_analysis'],
            'criteria_count' => \count($this->richItems()),
        ];
    }

    /** @return list<array{id: string, dimension: string, question: string}> */
    public function criteria(): array
    {
        return array_map(
            static fn (array $it): array => ['id' => $it['id'], 'dimension' => $it['section'], 'question' => $it['question']],
            $this->richItems(),
        );
    }

    public function toolIntro(): string
    {
        return <<<TXT
            Tu es un méthodologiste appliquant l'outil Cochrane RoB 2 (Risk of Bias 2, Sterne et al.,
            BMJ 2019) pour juger le RISQUE DE BIAIS d'un ESSAI CONTRÔLÉ RANDOMISÉ, domaine par domaine.
            Pour chaque domaine, tu émets un jugement porté par ses questions de signalisation :
            « low » (risque faible), « some_concerns » (quelques préoccupations) ou « high » (risque élevé).
            Le jugement porte sur un résultat rapporté précis : si l'article mêle plusieurs résultats,
            raisonne sur le résultat PRIMAIRE de l'essai.
            TXT;
    }

    public function applicabilityNote(): ?string
    {
        return "RoB 2 ne s'applique qu'aux ESSAIS RANDOMISÉS (individuels, en grappes ou croisés). Si l'étude n'est pas randomisée (cohorte, cas-témoins, transversale, revue, avant/après non randomisé), réponds \"applicable\": false.";
    }

    public function answerScale(): array
    {
        return [
            'low' => 'risque de biais faible — les garde-fous méthodologiques attendus sont présents et documentés.',
            'some_concerns' => 'quelques préoccupations — le domaine est partiellement maîtrisé, ou une information clé manque tout en laissant présumer une conduite correcte.',
            'high' => 'risque de biais élevé — un défaut méthodologique susceptible de fausser le résultat est présent (ou un garde-fou essentiel est absent de façon vérifiée).',
            'unclear' => 'information insuffisante dans le texte fourni pour juger le domaine.',
        ];
    }

    public function unclearAnswer(): string
    {
        return 'unclear';
    }

    public function doctrine(): string
    {
        return "Le jugement global d'un essai suit le maillon le plus faible : un seul domaine « high » suffit à préoccuper. Ne conclus « low » pour un domaine que si les protections attendues sont explicitement rapportées ; l'absence de description n'est PAS une preuve de bonne conduite (au mieux « some_concerns », « high » si un garde-fou essentiel est vérifié absent).";
    }

    /** @return list<array{id: string, section: string, question: string, help: string, expected: string, levels: array<string, string>, where: string, visual: bool, reverse: bool, na: bool, special: string}> */
    public function richItems(): array
    {
        return [
            self::item(
                'rob2.d1', 'Domaine 1 — Randomisation',
                "Le processus de randomisation protège-t-il contre les biais de sélection (séquence aléatoire, assignation dissimulée, comparabilité initiale) ?",
                "Questions de signalisation : la séquence d'allocation était-elle vraiment aléatoire ? était-elle dissimulée jusqu'à l'inclusion ? les caractéristiques initiales suggèrent-elles un problème de randomisation ?",
                "Pour « low » : méthode de génération aléatoire décrite (table de nombres, logiciel, randomisation centralisée…) ET dissimulation de l'allocation (enveloppes opaques scellées, allocation centralisée/pharmacie) ET absence de déséquilibre initial suspect.",
                [
                    'low' => "génération aléatoire ET dissimulation de l'allocation explicitement décrites, sans déséquilibre initial évocateur d'un échec.",
                    'some_concerns' => "randomisation mentionnée mais méthode OU dissimulation non décrite, sans indice de problème.",
                    'high' => "séquence non aléatoire (alternance, date de naissance, numéro de dossier), allocation non dissimulée, OU déséquilibre initial faisant suspecter un échec de randomisation.",
                ],
                "Methods (randomization, allocation concealment, sequence generation), Table 1 (baseline characteristics), CONSORT flow diagram.",
                special: "« Randomisé » sans description de la génération ni de la dissimulation ⇒ au mieux « some_concerns », jamais « low ».",
            ),
            self::item(
                'rob2.d2', 'Domaine 2 — Écarts aux interventions',
                "Le résultat est-il protégé des biais liés aux écarts par rapport aux interventions prévues (aveugle, adhérence, analyse conforme à l'assignation) ?",
                "Questions de signalisation : participants/soignants en aveugle du bras ? des écarts liés au contexte de l'essai sont-ils survenus, et ont-ils affecté le résultat ? l'analyse est-elle en intention de traiter (ITT) ?",
                "Pour « low » : participants et soignants en aveugle (ou écarts improbables/sans impact) ET analyse en intention de traiter (tous les randomisés analysés dans leur bras).",
                [
                    'low' => "aveugle des participants/soignants (ou impact des écarts négligeable) ET analyse ITT appropriée.",
                    'some_concerns' => "absence d'aveugle plausible mais sans écart documenté affectant le résultat, OU analyse légèrement dérogeant à l'ITT sans effet clair.",
                    'high' => "écarts liés au contexte de l'essai affectant le résultat, non-respect important de l'assignation, OU analyse per-protocole/excluant des randomisés d'une manière susceptible de biaiser.",
                ],
                "Methods (blinding/masking, intervention delivery, statistical analysis, ITT), Results (adherence, protocol deviations).",
                special: "Pour un résultat objectif (ex. mortalité), l'absence d'aveugle des participants pèse moins ; pour un résultat subjectif comportemental, elle pèse davantage.",
            ),
            self::item(
                'rob2.d3', 'Domaine 3 — Données manquantes',
                "Le résultat est-il disponible pour (quasi) tous les participants randomisés, ou l'absence de données est-elle sans biais ?",
                "Questions de signalisation : données de résultat disponibles pour tous/presque tous ? sinon, preuve que le résultat n'est pas biaisé par les données manquantes ? le fait qu'une donnée manque pourrait-il dépendre de sa vraie valeur ?",
                "Pour « low » : données disponibles pour la quasi-totalité des randomisés, OU analyse de sensibilité/imputation montrant que le résultat est robuste à l'attrition.",
                [
                    'low' => "données de résultat quasi complètes, OU preuve que l'attrition n'a pas biaisé le résultat (taux faible et équilibré, ou analyse de sensibilité robuste).",
                    'some_concerns' => "données manquantes notables mais équilibrées entre bras, sans preuve directe d'absence de biais.",
                    'high' => "attrition importante ou différentielle entre bras, raisons de sortie liées au résultat, sans analyse traitant le biais potentiel.",
                ],
                "CONSORT flow diagram, Results (attrition, loss to follow-up, missing data), Methods (imputation, sensitivity analysis).",
                visual: true,
                special: "Un taux de suivi élevé et symétrique justifie « low » même sans imputation ; un attrition asymétrique justifie rarement « low ».",
            ),
            self::item(
                'rob2.d4', 'Domaine 4 — Mesure du résultat',
                "La mesure du résultat est-elle exempte de biais (méthode appropriée, comparable entre bras, évaluateurs en aveugle) ?",
                "Questions de signalisation : la méthode de mesure était-elle appropriée et identique entre bras ? les évaluateurs du résultat connaissaient-ils l'intervention reçue ? cette connaissance a-t-elle pu influencer la mesure ?",
                "Pour « low » : méthode de mesure appropriée, appliquée identiquement aux deux bras, avec évaluateurs en aveugle OU résultat objectif peu influençable par la connaissance du bras.",
                [
                    'low' => "mesure appropriée et identique entre bras ET évaluateurs en aveugle (ou résultat objectif non influençable).",
                    'some_concerns' => "aveugle des évaluateurs non décrit pour un résultat modérément subjectif, sans indice d'influence.",
                    'high' => "évaluateurs non aveugles pour un résultat subjectif (jugement clinique, échelle auto-rapportée), OU méthode de mesure différente entre bras.",
                ],
                "Methods (outcome assessment, blinding of assessors, instruments), Results.",
                special: "Un résultat auto-rapporté dans un essai non aveugle relève typiquement de « high » pour ce domaine.",
            ),
            self::item(
                'rob2.d5', 'Domaine 5 — Sélection du résultat rapporté',
                "Le résultat rapporté est-il exempt de sélection (plan d'analyse pré-spécifié, pas de cherry-picking parmi mesures/analyses/sous-groupes) ?",
                "Questions de signalisation : les données ont-elles été analysées selon un plan pré-spécifié finalisé avant le déblindage ? le résultat rapporté a-t-il été choisi parmi plusieurs mesures ou plusieurs analyses du même critère ?",
                "Pour « low » : protocole/registre pré-enregistré (ex. numéro d'essai), résultats rapportés conformes au plan pré-spécifié, sans mesure ou analyse sélectionnée a posteriori.",
                [
                    'low' => "plan d'analyse pré-enregistré et résultats conformes au protocole, sans signe de sélection.",
                    'some_concerns' => "pas de protocole accessible mais rapport cohérent et complet, sans indice de sélection.",
                    'high' => "écart entre critères pré-enregistrés et rapportés, critère primaire modifié, ou résultat visiblement sélectionné parmi plusieurs mesures/analyses/sous-groupes.",
                ],
                "Trial registration (ClinicalTrials.gov, ISRCTN), protocol, Methods (pre-specified outcomes), comparison outcomes annoncés vs rapportés.",
                special: "Un numéro d'enregistrement d'essai cité, avec critère primaire cohérent, soutient « low » ; son absence totale justifie au minimum « some_concerns ».",
            ),
        ];
    }
}
