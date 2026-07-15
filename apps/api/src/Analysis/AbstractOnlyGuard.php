<?php

declare(strict_types=1);

namespace App\Analysis;

use App\Entity\Publication;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Garde-fou « résumé seul » : une évaluation méthodologique fiable exige le TEXTE INTÉGRAL.
 * Si une publication ne dispose que de son résumé (full text non conservé), on BLOQUE le
 * déclenchement et on renvoie l'état {status: "abstract_only"} avec les métadonnées connues,
 * pour que le front invite l'utilisateur à récupérer le PDF (via le DOI) et à le déposer.
 * Partagé par les 4 déclencheurs (AXIS / RoB 2 / AMSTAR 2 / MMAT).
 */
final class AbstractOnlyGuard
{
    /** Réponse « résumé seul » avec les métadonnées pour préremplir le formulaire de dépôt. */
    public static function response(Publication $publication): JsonResponse
    {
        return new JsonResponse([
            'status' => 'abstract_only',
            'message' => "Seul le résumé de cette étude est disponible dans SciencesWiki. "
                ."Une évaluation méthodologique fiable nécessite le texte intégral : "
                ."récupérez le PDF de l’étude (via son DOI) et déposez-le pour lancer l’analyse.",
            'publication' => [
                'id' => $publication->getId(),
                'title' => $publication->getTitle(),
                'year' => $publication->getPublicationDate()?->format('Y'),
                'doi' => $publication->getDoi(),
                'venue' => $publication->getVenue(),
                'oaUrl' => $publication->getOaUrl(),
                'landingPageUrl' => $publication->getLandingPageUrl(),
                'abstract' => $publication->getAbstractFr() ?: $publication->getAbstract(),
            ],
        ], 200);
    }
}
