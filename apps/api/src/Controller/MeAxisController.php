<?php

declare(strict_types=1);

namespace App\Controller;

use App\Analysis\Axis\AxisSerializer;
use App\Analysis\Message\AppraisePublicationMessage;
use App\Entity\Publication;
use App\Repository\AxisAppraisalRepository;
use App\Repository\PublicationRepository;
use App\Service\StudyAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Déclenchement à la demande de l'évaluation méthodologique AXIS par un
 * utilisateur des espaces recherche/pédagogie (ROLE_RESEARCHER / TEACHER /
 * STUDENT — cf. access_control). ASYNCHRONE comme la moisson : l'appel LLM dure
 * ~1 min → on dispatche un message (worker « analysis ») et on ne bloque NI la
 * requête NI le proxy. L'outil web récupère le résultat par POLLING (GET status).
 * Si l'évaluation existe déjà, on la renvoie immédiatement (cache).
 * L'affichage PUBLIC reste, lui, gated comité.
 */
final class MeAxisController
{
    public function __construct(
        private readonly PublicationRepository $publications,
        private readonly AxisAppraisalRepository $appraisals,
        private readonly AxisSerializer $serializer,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly StudyAccess $access,
    ) {
    }

    #[Route('/api/me/axis/{id}', name: 'me_axis_appraise', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function byId(int $id, Request $request): JsonResponse
    {
        // ?force=1 : bouton « Refaire l'évaluation » → purge et recalcul (nouveau modèle…).
        return $this->dispatch($this->access->accessible($this->publications->find($id)), $request->query->getBoolean('force'));
    }

    /** Par DOI (le plus naturel : le chercheur a le DOI du papier). Body : {doi}. */
    #[Route('/api/me/axis', name: 'me_axis_appraise_doi', methods: ['POST'])]
    public function byDoi(Request $request): JsonResponse
    {
        return $this->dispatch($this->resolveByDoi($request->getContent()), $request->query->getBoolean('force'));
    }

    /** Polling : l'outil interroge l'état jusqu'à ready (puis affiche le résultat). */
    #[Route('/api/me/axis/status', name: 'me_axis_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $doi = (string) $request->query->get('doi', '');
        $id = $request->query->getInt('id');
        $publication = '' !== $doi
            ? $this->publications->findOneByDoi($this->normalizeDoi($doi))
            : ($id > 0 ? $this->publications->find($id) : null);

        return $this->state($this->access->accessible($publication));
    }

    /** POST : renvoie le résultat s'il existe (cache), sinon met en file et renvoie « pending ». */
    private function dispatch(?Publication $publication, bool $force = false): JsonResponse
    {
        if (null === $publication) {
            return new JsonResponse(['status' => 'not_found', 'error' => 'Étude introuvable dans le corpus.'], 404);
        }

        // Résumé seul : on bloque et on invite à déposer le PDF (texte intégral requis).
        if (!$publication->isFulltextStored()) {
            return \App\Analysis\AbstractOnlyGuard::response($publication);
        }

        // Ré-évaluation forcée : on purge l'évaluation existante AVANT de dispatcher, pour
        // que le polling reparte en « pending » (sinon l'ancien résultat serait renvoyé).
        if ($force) {
            $this->appraisals->deleteForPublication($publication);
            if (null === $publication->getAxisAppraisingAt()) {
                $publication->setAxisAppraisingAt(new \DateTimeImmutable());
            }
            $this->em->flush();
            $this->bus->dispatch(new AppraisePublicationMessage((int) $publication->getId(), true));

            return new JsonResponse([
                'status' => 'pending',
                'publication' => $this->pubInfo($publication),
                'message' => 'Ré-évaluation lancée. Le nouveau résultat apparaîtra dans environ une minute.',
            ], 202);
        }

        // Déjà évaluée → résultat immédiat (cache).
        $existing = $this->appraisals->findForPublication($publication);
        if (null !== $existing) {
            return new JsonResponse($this->ready($publication, $existing));
        }

        // Pas encore en file → on pose le marqueur et on dispatche (idempotent : un
        // seul job à la fois par publication, protège du double-clic).
        if (null === $publication->getAxisAppraisingAt()) {
            $publication->setAxisAppraisingAt(new \DateTimeImmutable());
            $this->em->flush();
            $this->bus->dispatch(new AppraisePublicationMessage((int) $publication->getId()));
        }

        return new JsonResponse([
            'status' => 'pending',
            'publication' => $this->pubInfo($publication),
            'message' => 'Évaluation lancée. Le résultat apparaîtra dans environ une minute.',
        ], 202);
    }

    /** GET status : ready (résultat) / pending (en cours) / none (jamais lancée ou non évaluable). */
    private function state(?Publication $publication): JsonResponse
    {
        if (null === $publication) {
            return new JsonResponse(['status' => 'not_found'], 404);
        }
        $existing = $this->appraisals->findForPublication($publication);
        if (null !== $existing) {
            return new JsonResponse($this->ready($publication, $existing));
        }
        if (null !== $publication->getAxisAppraisingAt()) {
            return new JsonResponse(['status' => 'pending', 'publication' => $this->pubInfo($publication)], 202);
        }

        // Ni résultat ni job en cours : jamais demandée, ou terminée sans résultat
        // (étude non évaluable : ni résumé ni texte intégral exploitable).
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

    /** Tolère un DOI collé sous forme d'URL (https://doi.org/10.xxxx). */
    private function normalizeDoi(string $doi): string
    {
        return (string) preg_replace('#^https?://(dx\.)?doi\.org/#i', '', trim($doi));
    }
}
