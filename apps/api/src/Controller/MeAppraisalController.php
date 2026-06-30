<?php

declare(strict_types=1);

namespace App\Controller;

use App\Analysis\Appraisal\AppraisalToolRegistry;
use App\Analysis\Message\ClassifyStudyMessage;
use App\Entity\Publication;
use App\Repository\PublicationRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Boîte à outils d'évaluation critique : pour une liste d'études (par DOI), renvoie
 * les outils applicables si le devis est déjà détecté ; sinon lance la détection en
 * asynchrone (worker) et renvoie « pending ». L'outil web affiche alors un bouton par
 * outil applicable (AXIS actif ; les autres « à venir »). Sous /api/me (connecté).
 */
final class MeAppraisalController
{
    private const MAX_DOIS = 20;

    public function __construct(
        private readonly PublicationRepository $publications,
        private readonly AppraisalToolRegistry $registry,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/api/me/appraisal/tools', name: 'me_appraisal_tools', methods: ['POST'])]
    public function tools(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent() ?: '[]', true);
        $dois = \is_array($data) && \is_array($data['dois'] ?? null) ? $data['dois'] : [];

        $results = [];
        foreach (\array_slice($dois, 0, self::MAX_DOIS) as $rawDoi) {
            $doi = $this->normalizeDoi((string) $rawDoi);
            if ('' === $doi || isset($results[$doi])) {
                continue;
            }
            $publication = $this->publications->findOneByDoi($doi);
            $results[$doi] = null === $publication ? ['found' => false] : $this->stateFor($publication);
        }

        return new JsonResponse(['results' => $results]);
    }

    /** @return array<string,mixed> */
    private function stateFor(Publication $publication): array
    {
        if (null === $publication->getClassifiedAt()) {
            // Pas encore classée → on lance la détection (idempotent côté handler).
            $this->bus->dispatch(new ClassifyStudyMessage((int) $publication->getId()));

            return ['found' => true, 'classified' => false];
        }

        $design = $publication->getStudyDesign();
        $toolKeys = $publication->getAppraisalTools() ?? [];

        return [
            'found' => true,
            'classified' => true,
            'design' => $design,
            'designLabel' => $this->registry->designLabel($design),
            'axisApplicable' => \in_array('axis', $toolKeys, true),
            'rob2Applicable' => \in_array('rob2', $toolKeys, true),
            'amstar2Applicable' => \in_array('amstar2', $toolKeys, true),
            'tools' => $this->registry->toolsMeta($toolKeys),
        ];
    }

    private function normalizeDoi(string $doi): string
    {
        return (string) preg_replace('#^https?://(dx\.)?doi\.org/#i', '', mb_strtolower(trim($doi)));
    }
}
