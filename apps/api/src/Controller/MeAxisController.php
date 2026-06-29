<?php

declare(strict_types=1);

namespace App\Controller;

use App\Analysis\Axis\AxisAppraiser;
use App\Analysis\Axis\AxisSerializer;
use App\Entity\Publication;
use App\Repository\PublicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Déclenchement à la demande de l'évaluation méthodologique AXIS par un
 * utilisateur des espaces recherche/pédagogie (ROLE_RESEARCHER / TEACHER /
 * STUDENT — cf. access_control). L'outil dans la « boîte à outils » du chercheur :
 * il l'applique sur l'étude qu'il veut (par DOI ou identifiant) et reçoit
 * DIRECTEMENT le résultat (les garde-fous d'AxisAppraiser disent si l'étude n'est
 * pas évaluable). L'affichage PUBLIC reste, lui, gated comité.
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
    public function byId(int $id): JsonResponse
    {
        return $this->run($this->publications->find($id));
    }

    /** Par DOI (le plus naturel : le chercheur a le DOI du papier). Body : {doi}. */
    #[Route('/api/me/axis', name: 'me_axis_appraise_doi', methods: ['POST'])]
    public function byDoi(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent() ?: '[]', true);
        $doi = \is_array($data) ? trim((string) ($data['doi'] ?? '')) : '';
        // Tolère un DOI collé sous forme d'URL (https://doi.org/10.xxxx).
        $doi = (string) preg_replace('#^https?://(dx\.)?doi\.org/#i', '', $doi);
        if ('' === $doi) {
            return new JsonResponse(['error' => 'DOI manquant.'], 422);
        }

        return $this->run($this->publications->findOneByDoi($doi));
    }

    private function run(?Publication $publication): JsonResponse
    {
        if (null === $publication) {
            return new JsonResponse(['error' => 'Étude introuvable dans le corpus.'], 404);
        }
        // L'évaluation d'une étude APPLICABLE (grille complète sur résumé + texte
        // intégral) dépasse la limite PHP par défaut (30 s) : on la lève pour ce job.
        @set_time_limit(0);

        // reappraise = false : on RÉUTILISE une évaluation existante (instantané). Robustesse
        // clé : si un 1er clic a expiré au proxy (étude lente, ~1 min), l'évaluation a quand
        // même été persistée côté API → un 2e clic renvoie le résultat immédiatement.
        $appraisal = $this->appraiser->appraiseForPublication($publication, null, false);
        if (null === $appraisal) {
            return new JsonResponse(['error' => 'Étude non évaluable : ni résumé ni texte intégral exploitable.'], 422);
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
