<?php

declare(strict_types=1);

namespace App\Controller;

use App\Analysis\Axis\AxisAppraiser;
use App\Analysis\Axis\AxisSerializer;
use App\Repository\PublicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Déclenchement à la demande de l'évaluation méthodologique AXIS par un
 * utilisateur des espaces recherche/pédagogie (ROLE_RESEARCHER / TEACHER /
 * STUDENT — cf. access_control). L'outil dans la « boîte à outils » du chercheur :
 * il l'applique sur l'étude qu'il veut et reçoit DIRECTEMENT le résultat (les
 * garde-fous d'AxisAppraiser disent si l'étude n'est pas évaluable : non
 * applicable, ou texte insuffisant). L'affichage PUBLIC reste, lui, gated comité.
 */
final class MeAxisController
{
    public function __construct(
        private readonly PublicationRepository $publications,
        private readonly AxisAppraiser $appraiser,
        private readonly AxisSerializer $serializer,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/me/axis/{id}', name: 'me_axis_appraise', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(int $id): JsonResponse
    {
        $publication = $this->publications->find($id);
        if (null === $publication) {
            return new JsonResponse(['error' => 'Publication introuvable.'], 404);
        }

        // reappraise = true : l'utilisateur peut relancer (le corpus/texte a pu changer).
        $appraisal = $this->appraiser->appraiseForPublication($publication, null, true);
        if (null === $appraisal) {
            return new JsonResponse([
                'error' => 'Étude non évaluable : ni résumé ni texte intégral exploitable.',
            ], 422);
        }
        $this->em->flush(); // appraiseForPublication persiste sans flusher.

        $data = $this->serializer->serialize($appraisal);
        $data['publication'] = [
            'id' => $publication->getId(),
            'title' => $publication->getTitle(),
            'year' => $publication->getPublicationDate()?->format('Y'),
            'doi' => $publication->getDoi(),
        ];

        return new JsonResponse($data);
    }
}
