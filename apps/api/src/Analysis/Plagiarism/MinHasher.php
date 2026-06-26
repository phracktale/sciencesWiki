<?php

declare(strict_types=1);

namespace App\Analysis\Plagiarism;

/**
 * Signature MinHash (P=128) + bandes LSH d'un ensemble de shingles (cf. spec-plagiat.md
 * §2, §4.1). La signature estime le Jaccard (rappel) ; les bandes LSH permettent de
 * retrouver en sous-quadratique les chunks « co-bucketés » sans comparer toutes les paires.
 *
 * Hachage universel par la technique « h1 + i·h2 mod p » : on dérive P fonctions de
 * hachage de DEUX hachages de base, sans stocker 128 couples (a,b).
 */
final class MinHasher
{
    /** Nombre de permutations (taille de signature). */
    public const PERMUTATIONS = 128;

    /** Lignes par bande LSH → PERMUTATIONS / BAND_ROWS bandes (ici 32). Seuil ~0,42. */
    public const BAND_ROWS = 4;

    /** Premier de Mersenne 2^31 - 1 (modulo des hachages). */
    private const PRIME = 2147483647;

    /**
     * Signature MinHash d'un ensemble de shingles.
     *
     * @param list<string> $shingles
     *
     * @return list<int> PERMUTATIONS entiers (< 2^31)
     */
    public function signature(array $shingles): array
    {
        $sig = array_fill(0, self::PERMUTATIONS, self::PRIME);
        foreach ($shingles as $shingle) {
            $h1 = $this->baseHash($shingle, 'a');
            $h2 = $this->baseHash($shingle, 'b') | 1; // impair = bon pas
            for ($i = 0; $i < self::PERMUTATIONS; ++$i) {
                $v = ($h1 + $i * $h2) % self::PRIME;
                if ($v < $sig[$i]) {
                    $sig[$i] = $v;
                }
            }
        }

        return $sig;
    }

    /**
     * Hachages de bande LSH : la signature est découpée en bandes de BAND_ROWS lignes,
     * chaque bande est hachée. Deux chunks partageant ≥1 band_hash sont candidats.
     *
     * @param list<int> $signature
     *
     * @return list<int> un hash (31 bits) par bande, indexé par numéro de bande
     */
    public function bands(array $signature): array
    {
        $bands = [];
        $count = intdiv(\count($signature), self::BAND_ROWS);
        for ($b = 0; $b < $count; ++$b) {
            $slice = \array_slice($signature, $b * self::BAND_ROWS, self::BAND_ROWS);
            $bands[] = crc32($b.':'.implode(',', $slice)) & 0x7FFFFFFF;
        }

        return $bands;
    }

    /** Sérialise la signature en binaire (P × uint32 ≈ 512 o). */
    public function pack(array $signature): string
    {
        return pack('N*', ...$signature);
    }

    /**
     * @return list<int>
     */
    public function unpack(string $blob): array
    {
        $vals = unpack('N*', $blob);

        return false === $vals ? [] : array_values(array_map('intval', $vals));
    }

    private function baseHash(string $shingle, string $seed): int
    {
        return crc32($seed.':'.$shingle) & 0x7FFFFFFF;
    }
}
