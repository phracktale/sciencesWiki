<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Source;
use App\Enum\ApiType;
use App\Harvester\Ai\FulltextIngester;
use App\Harvester\Dto\RawPublication;
use App\Harvester\Pipeline\PublicationImporter;
use App\Repository\SourceRepository;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Import DIRECT d'une source PDF par un admin (ROLE_ADMIN) : crée/retrouve la
 * publication (dédup DOI), puis extrait le plein texte via GROBID → publication_chunk
 * (réutilise FulltextIngester::ingestUploadedPdf). Alimente le corpus avec des sources
 * absentes d'OpenAlex ou pour obtenir le plein texte d'un PDF en main. L'embedding +
 * le placement sont assurés ensuite par les drains habituels.
 *
 * Distinct du dépôt auteur tokenisé (ContributeController) : ici, pas de jeton, c'est
 * un import admin, et la publication peut être créée de toutes pièces.
 */
final class AdminPdfUploadController
{
    private const MAX_PDF_BYTES = 30_000_000;
    private const SOURCE_CODE = 'manual';

    public function __construct(
        private readonly SourceRepository $sources,
        private readonly PublicationImporter $importer,
        private readonly FulltextIngester $fulltext,
        private readonly EntityManagerInterface $em,
        private readonly ActivityLogger $activity,
        private readonly Security $security,
    ) {
    }

    #[Route('/api/admin/publications/upload-pdf', name: 'admin_pdf_upload', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
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
        $doi = trim((string) $request->request->get('doi')) ?: null;
        $venue = trim((string) $request->request->get('venue')) ?: null;
        $abstract = trim((string) $request->request->get('abstract')) ?: null;
        $yearRaw = trim((string) $request->request->get('year'));
        $date = ('' !== $yearRaw && ctype_digit($yearRaw)) ? new \DateTimeImmutable($yearRaw.'-01-01') : null;

        // Source « dépôt manuel » (get-or-create ; apiType Rest, jamais moissonnée).
        $source = $this->sources->findOneByCode(self::SOURCE_CODE);
        if (null === $source) {
            $source = new Source(self::SOURCE_CODE, 'Dépôt manuel (PDF)', ApiType::Rest);
            $this->em->persist($source);
            $this->em->flush();
        }

        // Crée (ou retrouve par DOI) la publication via le pipeline d'import standard.
        $raw = new RawPublication(
            sourceCode: self::SOURCE_CODE,
            idInSource: $doi ?? 'upload-'.bin2hex(random_bytes(6)),
            doi: $doi,
            title: $title,
            externalIds: [],
            abstract: $abstract,
            publicationDate: $date,
            venue: $venue,
            type: 'article',
        );
        $this->importer->reset();
        $result = $this->importer->import($raw, $source);
        $this->em->flush();
        $pubId = (int) $result->publication->getId();

        // GROBID → chunks (PDF lu depuis le tmp d'upload, jamais persisté à long terme).
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
            'admin_pdf_upload',
            $this->security->getUser()?->getUserIdentifier() ?? 'admin',
            \sprintf('PDF importé : « %s » (%d fragments)', $title, $chunks),
            ['publicationId' => $pubId],
            $request->getClientIp(),
        );

        return new JsonResponse([
            'ok' => true,
            'publicationId' => $pubId,
            'chunks' => $chunks,
            'created' => $result->created,
            'message' => $result->created
                ? 'Publication créée et plein texte indexé.'
                : 'Publication existante : plein texte (re)indexé.',
        ]);
    }
}
