<?php

declare(strict_types=1);

namespace App\Analysis\Plagiarism;

use App\Repository\ChunkFingerprintRepository;

/**
 * Calcule et persiste les empreintes MinHash/LSH des fragments d'une publication
 * (cf. docs/spec-plagiat.md §5, étage 0). Partagé par la commande de drain et le
 * handler async. Idempotent : on purge avant de recalculer.
 */
final class PublicationFingerprinter
{
    public function __construct(
        private readonly ChunkFingerprintRepository $repository,
        private readonly Shingler $shingler,
        private readonly MinHasher $minHasher,
    ) {
    }

    /** @return int nombre de fragments empreintés */
    public function fingerprint(int $publicationId): int
    {
        $this->repository->deleteForPublication($publicationId);

        $done = 0;
        foreach ($this->repository->chunksOf($publicationId) as $chunk) {
            $shingles = $this->shingler->shingles($chunk['content']);
            if ([] === $shingles) {
                continue;
            }
            $signature = $this->minHasher->signature($shingles);
            $this->repository->insertFingerprint(
                $chunk['id'],
                $publicationId,
                base64_encode($this->minHasher->pack($signature)),
                \count($shingles),
                $this->minHasher->bands($signature),
            );
            ++$done;
        }

        return $done;
    }
}
