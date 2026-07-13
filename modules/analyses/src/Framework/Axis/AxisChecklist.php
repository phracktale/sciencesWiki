<?php

declare(strict_types=1);

namespace Analyses\Framework\Axis;

/**
 * Les 20 items AXIS (Downes MJ et al., BMJ Open 2016;6:e011458) — repris VERBATIM du
 * système d'analyse legacy de SciencesWiki (App\Catalog\AxisChecklist). `help` = texte
 * d'aide officiel condensé. {@see self::REVERSE} : items où un « oui » est DÉFAVORABLE.
 */
final class AxisChecklist
{
    /** @var array<string, array{section: string, text: string, help: string}> */
    public const ITEMS = [
        'q1' => ['section' => 'Introduction', 'text' => 'Les objectifs de l’étude étaient-ils clairs ?', 'help' => 'L’objectif indique si l’étude traite une question appropriée et clairement ciblée ; idéalement énoncé au début du résumé ET en fin d’introduction. Sans lui, difficile d’évaluer l’atteinte des objectifs et plusieurs autres items.'],
        'q2' => ['section' => 'Méthodes', 'text' => 'Le plan d’étude était-il adapté aux objectifs énoncés ?', 'help' => 'Un devis transversal décrit une population à un instant T (prévalence, associations/différences entre groupes). Juger s’il convient aux objectifs — il n’établit ni causalité ni temporalité.'],
        'q3' => ['section' => 'Méthodes', 'text' => 'La taille de l’échantillon était-elle justifiée ?', 'help' => 'Chercher une justification a priori (calcul de puissance) et la méthode employée. Trop petit → sous-puissé (erreur de type II). Tenir compte du clustering. Une absence de justification ou une contrainte devrait être déclarée.'],
        'q4' => ['section' => 'Méthodes', 'text' => 'La population cible / de référence était-elle clairement définie ?', 'help' => 'La population cible = population globale visée (celle sur qui conclure, ou la population à risque). Doit être clairement définie, sinon les inférences peuvent être inappropriées.'],
        'q5' => ['section' => 'Méthodes', 'text' => 'La base de sondage était-elle issue d’une population représentant fidèlement la population cible ?', 'help' => 'La base de sondage = liste/source de recrutement ; elle doit rester représentative de la population cible. Se méfier de l’échantillonnage de convenance (échantillons biaisés).'],
        'q6' => ['section' => 'Méthodes', 'text' => 'Le processus de sélection avait-il toutes les chances de produire des participants représentatifs de la population cible ?', 'help' => 'Comment on passe de la base aux participants (cœur du biais de sélection). Regarder les critères d’inclusion/exclusion et la chance égale d’inclusion (randomisation). Attention au « healthy worker effect » et à l’auto-sélection.'],
        'q7' => ['section' => 'Méthodes', 'text' => 'Des mesures ont-elles été prises pour traiter et catégoriser les non-répondants ?', 'help' => 'Les non-répondants sont-ils identifiés et catégorisés ? Des statistiques de base (âge, sexe, CSP) peuvent servir de comparateur. Ils peuvent former un groupe spécifique → décalage des données de base.'],
        'q8' => ['section' => 'Méthodes', 'text' => 'Les variables d’exposition et de résultat mesurées étaient-elles adaptées aux objectifs ?', 'help' => 'Validité de mesure : les variables mesurent-elles bien les concepts visés par les objectifs ? Des mesures inappropriées → biais de classification.'],
        'q9' => ['section' => 'Méthodes', 'text' => 'Les variables ont-elles été mesurées avec des instruments éprouvés, pilotés ou déjà publiés ?', 'help' => 'Fiabilité de mesure : instruments reproductibles (mêmes résultats par un autre chercheur), de standards reconnus ; un instrument nouveau doit être testé, un questionnaire piloté.'],
        'q10' => ['section' => 'Méthodes', 'text' => 'Est-il clair ce qui a servi à déterminer la significativité statistique et/ou les estimations de précision (p, IC) ?', 'help' => 'Les méthodes statistiques, logiciels et seuils doivent être clairement indiqués (p-value, souvent 0,05 ; intervalles de confiance pour la précision), même pour des statistiques descriptives.'],
        'q11' => ['section' => 'Méthodes', 'text' => 'Les méthodes (y compris statistiques) étaient-elles suffisamment décrites pour être reproduites ?', 'help' => 'Reproductibilité : des informations manquantes, même petites, gênent l’interprétation des résultats et de la discussion (on ignore si les bonnes méthodes ont été employées).'],
        'q12' => ['section' => 'Résultats', 'text' => 'Les données de base étaient-elles décrites de façon adéquate ?', 'help' => 'L’analyse descriptive résume l’échantillon et les mesures ; elle montre le recrutement et la représentativité. Sinon → estimations inexactes de prévalence/incidence/facteurs de risque.'],
        'q13' => ['section' => 'Résultats', 'text' => 'Le taux de réponse soulève-t-il des inquiétudes quant à un biais de non-réponse ?', 'help' => 'ITEM INVERSÉ (un « oui » est défavorable). Un effort de quantification de la non-réponse doit exister ; juger si le taux risque un biais de non-réponse (non-répondants substantiellement différents du reste).'],
        'q14' => ['section' => 'Résultats', 'text' => 'Le cas échéant, l’information sur les non-répondants était-elle décrite ?', 'help' => 'De l’information sur les non-répondants était-elle disponible et étaient-ils comparables aux répondants ? Cela aide à répondre à l’item 13.'],
        'q15' => ['section' => 'Résultats', 'text' => 'Les résultats étaient-ils cohérents en interne ?', 'help' => 'Explorer les nombres (texte, figures, tableaux) : « les comptes tombent-ils juste » (100 recrutés → 100 dans les tableaux/texte) ? Les données manquantes doivent être déclarées et expliquées.'],
        'q16' => ['section' => 'Résultats', 'text' => 'Les résultats étaient-ils présentés pour toutes les analyses décrites dans les méthodes ?', 'help' => 'Toutes les méthodes décrites débouchent-elles sur des résultats ? Des résultats manquants laissent craindre un report sélectif (résultats non voulus omis).'],
        'q17' => ['section' => 'Discussion', 'text' => 'Les discussions et conclusions des auteurs étaient-elles justifiées par les résultats ?', 'help' => 'Considérer l’étude dans son ensemble ; une conclusion plus définitive que ce que l’étude permet est un signal. Examiner : tous les résultats liés à l’objectif, biais de sélection, non-réponse, confusion (confounding), résultats non significatifs.'],
        'q18' => ['section' => 'Discussion', 'text' => 'Les limites de l’étude ont-elles été discutées ?', 'help' => 'Toute recherche a des limites ; leur discussion montre la compréhension du devis par les auteurs. L’absence est une inquiétude sur la compréhension globale de l’étude.'],
        'q19' => ['section' => 'Autre', 'text' => 'Existait-il des sources de financement ou des conflits d’intérêts susceptibles d’affecter l’interprétation des auteurs ?', 'help' => 'ITEM INVERSÉ (un « oui » est défavorable). Financements et conflits doivent être déclarés ; ils peuvent, même inconsciemment, orienter l’interprétation. « Non déclaré » n’équivaut PAS à « non ».'],
        'q20' => ['section' => 'Autre', 'text' => 'Un avis éthique ou le consentement des participants a-t-il été obtenu ?', 'help' => 'Approbation d’un comité d’éthique et/ou consentement, qui doivent être obtenus avant toute recherche sur une personne ou un animal.'],
    ];

    /** Items à polarité INVERSÉE : un « Oui » est défavorable. @var list<string> */
    public const REVERSE = ['q13', 'q19'];

    /** @return list<string> clés ordonnées q1…q20 */
    public static function keys(): array
    {
        return array_keys(self::ITEMS);
    }
}
