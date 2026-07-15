<?php

declare(strict_types=1);

namespace App\Controller;

use App\Analysis\Amstar2\Amstar2Serializer;
use App\Analysis\Message\AppraiseAmstar2Message;
use App\Entity\Publication;
use App\Repository\Amstar2AppraisalRepository;
use App\Repository\PublicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Service\StudyAccess;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Déclenchement à la demande de l'évaluation AMSTAR-2 (confiance dans une revue
 * systématique) par les rôles recherche/pédagogie. ASYNCHRONE comme AXIS/RoB 2.
 */
final class MeAmstar2Controller
{
    public function __construct(
        private readonly PublicationRepository $publications,
        private readonly Amstar2AppraisalRepository $appraisals,
        private readonly Amstar2Serializer $serializer,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly StudyAccess $access,
    ) {
    }

    #[Route('/api/me/amstar2/{id}', name: 'me_amstar2_appraise', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function byId(int $id): JsonResponse
    {
        return $this->dispatch($this->access->accessible($this->publications->find($id)));
    }

    #[Route('/api/me/amstar2', name: 'me_amstar2_appraise_doi', methods: ['POST'])]
    public function byDoi(Request $request): JsonResponse
    {
        return $this->dispatch($this->resolveByDoi($request->getContent()));
    }

    #[Route('/api/me/amstar2/status', name: 'me_amstar2_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $doi = (string) $request->query->get('doi', '');
        $id = $request->query->getInt('id');
        $publication = '' !== $doi
            ? $this->publications->findOneByDoi($this->normalizeDoi($doi))
            : ($id > 0 ? $this->publications->find($id) : null);

        return $this->state($this->access->accessible($publication));
    }

    private function dispatch(?Publication $publication): JsonResponse
    {
        if (null === $publication) {
            return new JsonResponse(['status' => 'not_found', 'error' => 'Étude introuvable dans le corpus.'], 404);
        }

        // Résumé seul : on bloque et on invite à déposer le PDF (texte intégral requis).
        if (!$publication->isFulltextStored()) {
            return \App\Analysis\AbstractOnlyGuard::response($publication);
        }

        $existing = $this->appraisals->findForPublication($publication);
        if (null !== $existing) {
            return new JsonResponse($this->ready($publication, $existing));
        }

        if (null === $publication->getAmstar2AppraisingAt()) {
            $publication->setAmstar2AppraisingAt(new \DateTimeImmutable());
            $this->em->flush();
            $this->bus->dispatch(new AppraiseAmstar2Message((int) $publication->getId()));
        }

        return new JsonResponse([
            'status' => 'pending',
            'publication' => $this->pubInfo($publication),
            'message' => 'Évaluation AMSTAR-2 lancée. Le résultat apparaîtra dans environ une minute.',
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
        if (null !== $publication->getAmstar2AppraisingAt()) {
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

        return '' !== $doi ? $this->access->accessible($this->publications->findOneByDoi($doi)) : null;
    }

    private function normalizeDoi(string $doi): string
    {
        return (string) preg_replace('#^https?://(dx\.)?doi\.org/#i', '', mb_strtolower(trim($doi)));
    }
}
