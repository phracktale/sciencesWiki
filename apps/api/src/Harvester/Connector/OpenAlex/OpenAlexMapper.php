<?php

declare(strict_types=1);

namespace App\Harvester\Connector\OpenAlex;

use App\Enum\OaStatus;
use App\Harvester\Dto\RawAuthor;
use App\Harvester\Dto\RawPublication;

/**
 * Convertit un objet « work » OpenAlex en {@see RawPublication} normalisable.
 *
 * Classe pure (sans I/O) afin d'être testable avec une fixture JSON.
 */
final class OpenAlexMapper
{
    public const SOURCE_CODE = 'openalex';

    /**
     * @param array<string,mixed> $work
     */
    public function map(array $work): RawPublication
    {
        $openAlexId = self::shortId((string) ($work['id'] ?? ''));
        $title = (string) ($work['title'] ?? $work['display_name'] ?? '');

        $openAccess = \is_array($work['open_access'] ?? null) ? $work['open_access'] : [];
        $primaryLocation = \is_array($work['primary_location'] ?? null) ? $work['primary_location'] : [];
        $bestOaLocation = \is_array($work['best_oa_location'] ?? null) ? $work['best_oa_location'] : [];

        $oaUrl = $openAccess['oa_url'] ?? ($bestOaLocation['pdf_url'] ?? null);
        $isOa = (bool) ($openAccess['is_oa'] ?? false);

        return new RawPublication(
            sourceCode: self::SOURCE_CODE,
            idInSource: $openAlexId,
            doi: isset($work['doi']) ? (string) $work['doi'] : null,
            title: $title,
            externalIds: self::externalIds($work, $openAlexId),
            abstract: AbstractReconstructor::reconstruct($work['abstract_inverted_index'] ?? null),
            publicationDate: self::parseDate($work['publication_date'] ?? null),
            language: isset($work['language']) ? (string) $work['language'] : null,
            venue: self::venue($primaryLocation),
            type: isset($work['type']) ? (string) $work['type'] : null,
            license: $primaryLocation['license'] ?? ($bestOaLocation['license'] ?? null),
            oaStatus: OaStatus::fromApi($openAccess['oa_status'] ?? null),
            oaUrl: null !== $oaUrl ? (string) $oaUrl : null,
            fulltextAvailable: $isOa && null !== $oaUrl,
            authors: self::authors($work['authorships'] ?? []),
        );
    }

    /**
     * @param array<string,mixed> $work
     *
     * @return array<string,string>
     */
    private static function externalIds(array $work, string $openAlexId): array
    {
        $ids = ['openalex' => $openAlexId];
        $raw = \is_array($work['ids'] ?? null) ? $work['ids'] : [];

        if (isset($raw['pmcid'])) {
            $ids['pmcid'] = self::shortId((string) $raw['pmcid']);
        }
        if (isset($raw['pmid'])) {
            $ids['pmid'] = self::shortId((string) $raw['pmid']);
        }

        return $ids;
    }

    /**
     * @param array<int,mixed> $authorships
     *
     * @return list<RawAuthor>
     */
    private static function authors(array $authorships): array
    {
        $authors = [];
        $position = 0;
        foreach ($authorships as $authorship) {
            if (!\is_array($authorship)) {
                continue;
            }
            $author = \is_array($authorship['author'] ?? null) ? $authorship['author'] : [];
            $name = (string) ($author['display_name'] ?? '');
            if ('' === $name) {
                continue;
            }
            // Bornage aux longueurs de colonnes (author.name / affiliation = varchar 512).
            $name = self::clip($name, 500);

            $orcid = isset($author['orcid']) ? strtoupper(trim(self::shortId((string) $author['orcid']))) : null;
            if (null !== $orcid && ('' === $orcid || mb_strlen($orcid) > 32)) {
                $orcid = null; // ORCID invalide/aberrant : on ignore plutôt que de violer la contrainte
            }

            $affiliation = null;
            $rawAffiliations = $authorship['raw_affiliation_strings'] ?? null;
            if (\is_array($rawAffiliations) && isset($rawAffiliations[0])) {
                $affiliation = self::clip((string) $rawAffiliations[0], 500);
            }

            $authors[] = new RawAuthor($name, $orcid, $affiliation, $position);
            ++$position;
        }

        return $authors;
    }

    /**
     * @param array<string,mixed> $primaryLocation
     */
    private static function venue(array $primaryLocation): ?string
    {
        $source = \is_array($primaryLocation['source'] ?? null) ? $primaryLocation['source'] : [];
        $name = $source['display_name'] ?? null;

        return null !== $name ? self::clip((string) $name, 500) : null;
    }

    /** Tronque une chaîne à une longueur max (sécurité contre les colonnes varchar). */
    private static function clip(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }

    private static function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date ? null : $date;
    }

    /** Retire le préfixe URL des identifiants OpenAlex/ORCID/PMID. */
    private static function shortId(string $value): string
    {
        $value = trim($value);
        $pos = strrpos($value, '/');

        return false === $pos ? $value : substr($value, $pos + 1);
    }
}
