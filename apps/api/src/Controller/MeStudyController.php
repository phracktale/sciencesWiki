<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CorpusSubmission;
use App\Entity\Publication;
use App\Entity\Source;
use App\Entity\User;
use App\Enum\ApiType;
use App\Enum\SubmissionStatus;
use App\Harvester\Ai\FulltextIngester;
use App\Harvester\Dto\RawPublication;
use App\Harvester\Pipeline\PublicationImporter;
use App\Repository\CorpusSubmissionRepository;
use App\Repository\PublicationRepository;
use App\Repository\SourceRepository;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dépôt d'une étude (PDF) par un utilisateur des espaces recherche/pédagogie
 * (ROLE_RESEARCHER / TEACHER / STUDENT) EN VUE d'une évaluation critique, quand
 * l'étude n'est pas dans le corpus. L'étude déposée est PRIVÉE (listed_in_corpus=false,
 * liée à l'uploadeur) : invisible des recherches et des autres. Le texte est extrait
 * par GROBID (chunks) — suffisant pour l'évaluation, qui n'a pas besoin de l'embedding.
 *
 * L'intégration au corpus public est une étape séparée et MODÉRÉE (submitToCorpus →
 * CorpusSubmission → validation comité), cf. AdminCorpusSubmissionController.
 */
final class MeStudyController
{
    private const MAX_PDF_BYTES = 30_000_000;
    private const SOURCE_CODE = 'user-upload';

    public function __construct(
        private readonly SourceRepository $sources,
        private readonly PublicationRepository $publications,
        private readonly PublicationImporter $importer,
        private readonly FulltextIngester $fulltext,
        private readonly CorpusSubmissionRepository $submissions,
        private readonly EntityManagerInterface $em,
        private readonly ActivityLogger $activity,
        private readonly Security $security,
    ) {
    }

    /**
     * Dépose un PDF → GROBID → étude privée prête à évaluer. Multipart :
     * pdf (requis), title (requis), doi/year/venue/abstract (optionnels).
     */
    #[Route('/api/me/study/upload', name: 'me_study_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        if (null === $user) {
            return new JsonResponse(['error' => 'Non authentifié.'], 401);
        }

        $file = $request->files->get('pdf');
        if (null === $file) {
            return new JsonResponse(['error' => 'Aucun fichier reçu.'], 422);
        }
        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        if ('pdf' !== $ext || $file->getSize() > self::MAX_PDF_BYTES) {
            return new JsonResponse(['error' => 'Fichier invalide : PDF de 30 Mo maximum attendu.'], 422);
        }
        $tmp = $file->getPathname();
        if (!str_starts_with((string) file_get_contents($tmp, false, null, 0, 5), '%PDF')) {
            return new JsonResponse(['error' => 'Le fichier n\'est pas un PDF valide.'], 422);
        }

        $title = trim((string) $request->request->get('title'));
        if ('' === $title) {
            return new JsonResponse(['error' => 'Le titre est obligatoire.'], 422);
        }
        $doi = $this->normalizeDoi((string) $request->request->get('doi'));

        // Dédup par DOI : ne jamais dupliquer une étude déjà présente.
        if ('' !== $doi) {
            $existing = $this->publications->findOneByDoi($doi);
            if (null !== $existing) {
                return $this->handleExisting($existing, $user);
            }
        }

        $venue = trim((string) $request->request->get('venue')) ?: null;
        $abstract = trim((string) $request->request->get('abstract')) ?: null;
        $yearRaw = trim((string) $request->request->get('year'));
        $date = ('' !== $yearRaw && ctype_digit($yearRaw)) ? new \DateTimeImmutable($yearRaw.'-01-01') : null;

        // Source « dépôt utilisateur » (get-or-create ; jamais moissonnée).
        $source = $this->sources->findOneByCode(self::SOURCE_CODE);
        if (null === $source) {
            $source = new Source(self::SOURCE_CODE, 'Dépôt utilisateur (évaluation critique)', ApiType::Rest);
            $this->em->persist($source);
            $this->em->flush();
        }

        $raw = new RawPublication(
            sourceCode: self::SOURCE_CODE,
            idInSource: '' !== $doi ? $doi : 'upload-'.bin2hex(random_bytes(6)),
            doi: '' !== $doi ? $doi : null,
            title: $title,
            externalIds: [],
            abstract: $abstract,
            publicationDate: $date,
            venue: $venue,
            type: 'article',
        );
        $this->importer->reset();
        $result = $this->importer->import($raw, $source);
        $publication = $result->publication;

        // Étude PRIVÉE : hors corpus public tant que non validée par le comité.
        $publication->setSubmittedBy($user)->setListedInCorpus(false);
        $this->em->flush();
        $pubId = (int) $publication->getId();

        // GROBID → chunks (texte intégral pour l'évaluation ; PDF non persisté).
        try {
            $chunks = $this->fulltext->ingestUploadedPdf($pubId, $tmp);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Échec de l\'extraction GROBID : '.$e->getMessage(), 'publicationId' => $pubId], 500);
        }
        if ($chunks < 1) {
            return new JsonResponse(['error' => 'Texte non extractible de ce PDF (scan image ?). Fournissez un PDF avec couche texte.', 'publicationId' => $pubId], 422);
        }

        $this->activity->log(
            'contribution',
            'user_study_upload',
            $user->getUserIdentifier(),
            \sprintf('Étude déposée pour évaluation : « %s » (%d fragments)', $title, $chunks),
            ['publicationId' => $pubId],
            $request->getClientIp(),
        );

        return new JsonResponse([
            'ok' => true,
            'publicationId' => $pubId,
            'doi' => $publication->getDoi(),
            'chunks' => $chunks,
            'private' => true,
            'inCorpus' => false,
            'message' => 'Étude déposée et prête à évaluer. Elle reste privée tant que vous ne demandez pas son ajout au corpus.',
        ]);
    }

    /**
     * Demande d'ajout de l'étude déposée au corpus public (validation comité).
     * Crée une CorpusSubmission « en attente ». L'étude reste privée jusqu'à validation.
     */
    #[Route('/api/me/study/{id}/submit-to-corpus', name: 'me_study_submit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function submitToCorpus(int $id, Request $request): JsonResponse
    {
        $user = $this->currentUser();
        if (null === $user) {
            return new JsonResponse(['error' => 'Non authentifié.'], 401);
        }
        $pub = $this->publications->find($id);
        // Ownership : seule une étude PRIVÉE déposée par l'utilisateur peut être proposée.
        if (null === $pub || $pub->getSubmittedBy()?->getId() !== $user->getId()) {
            return new JsonResponse(['error' => 'Étude introuvable.'], 404);
        }
        if ($pub->isListedInCorpus()) {
            return new JsonResponse(['ok' => true, 'status' => 'in_corpus', 'message' => 'Cette étude est déjà dans le corpus.']);
        }

        $existing = $this->submissions->findForPublication($pub);
        if (null !== $existing) {
            $label = $existing->getStatus()->label();

            return new JsonResponse([
                'ok' => SubmissionStatus::Rejected !== $existing->getStatus(),
                'status' => $existing->getStatus()->value,
                'message' => match ($existing->getStatus()) {
                    SubmissionStatus::Pending => 'Proposition déjà envoyée : en attente d\'examen par le comité.',
                    SubmissionStatus::Approved => 'Déjà acceptée : intégration au corpus en cours.',
                    SubmissionStatus::Rejected => 'Cette proposition a été refusée par le comité ('.$label.').',
                },
            ]);
        }

        $data = json_decode($request->getContent() ?: '[]', true);
        $note = \is_array($data) ? trim((string) ($data['note'] ?? '')) : '';
        $submission = new CorpusSubmission($pub, $user, '' !== $note ? $note : null);
        $this->em->persist($submission);
        $this->em->flush();

        $this->activity->log(
            'contribution',
            'corpus_submission',
            $user->getUserIdentifier(),
            \sprintf('Proposition d\'ajout au corpus : « %s »', $pub->getTitle()),
            ['publicationId' => $id],
            $request->getClientIp(),
        );

        return new JsonResponse([
            'ok' => true,
            'status' => 'pending',
            'message' => 'Proposition envoyée. Le comité l\'examinera avant l\'ajout au corpus.',
        ], 201);
    }

    /**
     * Espace « mes études » : les études déposées par l'utilisateur + leur statut
     * (privée / en attente / dans le corpus / refusée).
     */
    #[Route('/api/me/studies', name: 'me_studies', methods: ['GET'])]
    public function studies(): JsonResponse
    {
        $user = $this->currentUser();
        if (null === $user) {
            return new JsonResponse(['error' => 'Non authentifié.'], 401);
        }
        $items = [];
        foreach ($this->publications->findBySubmitter($user, 100) as $pub) {
            $sub = $this->submissions->findForPublication($pub);
            $items[] = [
                'id' => $pub->getId(),
                'title' => $pub->getTitle(),
                'doi' => $pub->getDoi(),
                'year' => $pub->getPublicationDate()?->format('Y'),
                'inCorpus' => $pub->isListedInCorpus(),
                'submission' => null === $sub ? null : [
                    'status' => $sub->getStatus()->value,
                    'label' => $sub->getStatus()->label(),
                ],
            ];
        }

        return new JsonResponse(['items' => $items]);
    }

    /**
     * Une publication de même DOI existe déjà : on ne duplique pas.
     * - Publique (corpus) → on renvoie son id : l'utilisateur évalue l'existante.
     * - Privée à lui → on la réutilise.
     * - Privée à un autre → conflit (déjà déposée, en cours d'examen).
     */
    private function handleExisting(Publication $existing, User $user): JsonResponse
    {
        if ($existing->isListedInCorpus()) {
            return new JsonResponse([
                'ok' => true,
                'publicationId' => $existing->getId(),
                'doi' => $existing->getDoi(),
                'private' => false,
                'inCorpus' => true,
                'message' => 'Cette étude est déjà dans le corpus : vous pouvez l\'évaluer directement.',
            ]);
        }
        $owner = $existing->getSubmittedBy();
        if (null !== $owner && $owner->getId() === $user->getId()) {
            return new JsonResponse([
                'ok' => true,
                'publicationId' => $existing->getId(),
                'doi' => $existing->getDoi(),
                'private' => true,
                'inCorpus' => false,
                'message' => 'Vous avez déjà déposé cette étude ; elle est prête à évaluer.',
            ]);
        }

        return new JsonResponse([
            'error' => 'Cette étude a déjà été déposée par un autre utilisateur et est en cours d\'examen.',
        ], 409);
    }

    private function currentUser(): ?User
    {
        $u = $this->security->getUser();

        return $u instanceof User ? $u : null;
    }

    /** Tolère un DOI collé sous forme d'URL (https://doi.org/10.xxxx). */
    private function normalizeDoi(string $doi): string
    {
        return (string) preg_replace('#^https?://(dx\.)?doi\.org/#i', '', trim($doi));
    }
}
