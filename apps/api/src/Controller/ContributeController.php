<?php

declare(strict_types=1);

namespace App\Controller;

use App\Harvester\Ai\FulltextIngester;
use App\Service\ActivityLogger;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dépôt public de la version auteur d'un article (gated par un jeton sécurisé à
 * usage unique). Le déposant ouvre le lien reçu par e-mail, téléverse son PDF ;
 * le texte est extrait, vectorisé et intégré au RAG en direct.
 */
final class ContributeController
{
    private const MAX_PDF_BYTES = 30_000_000;

    public function __construct(
        private readonly Connection $conn,
        private readonly FulltextIngester $fulltext,
        private readonly ActivityLogger $activity,
    ) {
    }

    /** Validité du jeton + métadonnées de l'article à confirmer par le déposant. */
    #[Route('/api/contribute/{token}', name: 'api_contribute_info', methods: ['GET'], requirements: ['token' => '[a-f0-9]{32,64}'])]
    public function info(string $token): JsonResponse
    {
        $row = $this->lookup($token);
        if (null === $row) {
            return new JsonResponse(['valid' => false, 'error' => 'Lien invalide, déjà utilisé ou expiré.'], 404);
        }

        return new JsonResponse([
            'valid' => true,
            'title' => $row['title'],
            'doi' => $row['doi'],
            'venue' => $row['venue'],
            'authors' => null !== $row['authors'] ? explode('|', (string) $row['authors']) : [],
            'expiresAt' => $row['expires_at'],
        ]);
    }

    #[Route('/api/contribute/{token}', name: 'api_contribute_upload', methods: ['POST'], requirements: ['token' => '[a-f0-9]{32,64}'])]
    public function upload(string $token, Request $request): JsonResponse
    {
        $row = $this->lookup($token);
        if (null === $row) {
            return new JsonResponse(['error' => 'Lien invalide, déjà utilisé ou expiré.'], 404);
        }

        $file = $request->files->get('pdf');
        if (null === $file) {
            return new JsonResponse(['error' => 'Aucun fichier reçu.'], 422);
        }
        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        if ('pdf' !== $ext || $file->getSize() > self::MAX_PDF_BYTES) {
            return new JsonResponse(['error' => 'Fichier invalide : PDF de 30 Mo maximum attendu.'], 422);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'contrib_');
        if (false === $tmp) {
            return new JsonResponse(['error' => 'Stockage temporaire indisponible.'], 500);
        }
        try {
            $file->move(\dirname($tmp), basename($tmp));
            // Garde-fou : entête %PDF.
            if (!str_starts_with((string) file_get_contents($tmp, false, null, 0, 5), '%PDF')) {
                return new JsonResponse(['error' => 'Le fichier n\'est pas un PDF valide.'], 422);
            }

            $chunks = $this->fulltext->ingestUploadedPdf((int) $row['publication_id'], $tmp);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Échec du traitement : '.$e->getMessage()], 500);
        } finally {
            @unlink($tmp);
        }

        if ($chunks < 1) {
            return new JsonResponse(['error' => 'Texte non extractible de ce PDF (scan image ?). Merci d\'envoyer un PDF avec texte.'], 422);
        }

        // Jeton à usage unique.
        $this->conn->executeStatement('UPDATE contribution_token SET used_at = now() WHERE token = :t', ['t' => $token]);
        $this->activity->log('contribution', 'author_pdf', 'auteur', \sprintf('Version auteur déposée : « %s » (%d fragments)', (string) $row['title'], $chunks), ['publicationId' => (int) $row['publication_id']], $request->getClientIp());

        return new JsonResponse(['ok' => true, 'chunks' => $chunks, 'message' => 'Merci ! Votre article est désormais indexé et exploité dans les réponses.']);
    }

    /** @return array<string,mixed>|null jeton valide (non utilisé, non expiré) + infos article */
    private function lookup(string $token): ?array
    {
        $row = $this->conn->executeQuery(
            "SELECT ct.publication_id, ct.expires_at, p.title, p.doi, p.venue,
                    (SELECT string_agg(a.name, '|' ORDER BY au.position)
                       FROM authorship au JOIN author a ON a.id = au.author_id
                      WHERE au.publication_id = p.id) AS authors
               FROM contribution_token ct JOIN publication p ON p.id = ct.publication_id
              WHERE ct.token = :t AND ct.used_at IS NULL AND ct.expires_at > now()",
            ['t' => $token],
        )->fetchAssociative();

        return false === $row ? null : $row;
    }
}
