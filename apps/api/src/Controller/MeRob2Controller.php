<?php

declare(strict_types=1);

namespace App\Controller;

use App\Analysis\Message\AppraiseRob2Message;
use App\Analysis\Rob2\Rob2Serializer;
use App\Entity\Publication;
use App\Repository\PublicationRepository;
use App\Repository\Rob2AppraisalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Déclenchement à la demande de l'évaluation RoB 2 (risque de biais d'un essai
 * randomisé) par les rôles recherche/pédagogie. ASYNCHRONE comme AXIS : POST =
 * dispatch (ou résultat caché) ; GET status = polling. L'affichage public reste
 * gated comité.
 */
final class MeRob2Controller
{
    public function __construct(
        private readonly PublicationRepository $publications,
        private readonly Rob2AppraisalRepository $appraisals,
        private readonly Rob2Serializer $serializer,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/api/me/rob2/{id}', name: 'me_rob2_appraise', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function byId(int $id): JsonResponse
    {
        return $this->dispatch($this->publications->find($id));
    }

    #[Route('/api/me/rob2', name: 'me_rob2_appraise_doi', methods: ['POST'])]
    public function byDoi(Request $request): JsonResponse
    {
        return $this->dispatch($this->resolveByDoi($request->getContent()));
    }

    #[Route('/api/me/rob2/status', name: 'me_rob2_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $doi = (string) $request->query->get('doi', '');
        $id = $request->query->getInt('id');
        $publication = '' !== $doi
            ? $this->publications->findOneByDoi($this->normalizeDoi($doi))
            : ($id > 0 ? $this->publications->find($id) : null);

        return $this->state($publication);
    }

    private function dispatch(?Publication $publication): JsonResponse
    {
        if (null === $publication) {
            return new JsonResponse(['status' => 'not_found', 'error' => 'Étude introuvable dans le corpus.'], 404);
        }

        $existing = $this->appraisals->findForPublication($publication);
        if (null !== $existing) {
            return new JsonResponse($this->ready($publication, $existing));
        }

        if (null === $publication->getRob2AppraisingAt()) {
            $publication->setRob2AppraisingAt(new \DateTimeImmutable());
            $this->em->flush();
            $this->bus->dispatch(new AppraiseRob2Message((int) $publication->getId()));
        }

        return new JsonResponse([
            'status' => 'pending',
            'publication' => $this->pubInfo($publication),
            'message' => 'Évaluation RoB 2 lancée. Le résultat apparaîtra dans environ une minute.',
        ], 202);
    }

    private function state(?Publication $publication): JsonResponse
    {
        if (null === $publication) {
            return new JsonResponse(['status' => 'not_found'], 404);
        }
        $existing = $this->appraisals->findForPublication($publication);
        if (null !== $existing) {
            return new JsonResponse($this->ready($publication, $existing));
        }
        if (null !== $publication->getRob2AppraisingAt()) {
            return new JsonResponse(['status' => 'pending', 'publication' => $this->pubInfo($publication)], 202);
        }

        return new JsonResponse(['status' => 'none', 'publication' => $this->pubInfo($publication)]);
    }

    /** @return array<string,mixed> */
    private function ready(Publication $publication, object $appraisal): array
    {
        $data = $this->serializer->serialize($appraisal);
        $data['status'] = 'ready';
        $data['publication'] = $this->pubInfo($publication);

        return $data;
    }

    /** @return array<string,mixed> */
    private function pubInfo(Publication $publication): array
    {
        return [
            'id' => $publication->getId(),
            'title' => $publication->getTitle(),
            'year' => $publication->getPublicationDate()?->format('Y'),
            'doi' => $publication->getDoi(),
        ];
    }

    private function resolveByDoi(string $body): ?Publication
    {
        $data = json_decode($body ?: '[]', true);
        $doi = \is_array($data) ? $this->normalizeDoi((string) ($data['doi'] ?? '')) : '';

        return '' !== $doi ? $this->publications->findOneByDoi($doi) : null;
    }

    private function normalizeDoi(string $doi): string
    {
        return (string) preg_replace('#^https?://(dx\.)?doi\.org/#i', '', mb_strtolower(trim($doi)));
    }
}
