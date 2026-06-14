<?php

declare(strict_types=1);

namespace App\Harvester\Connector\Arxiv;

use App\Enum\OaStatus;
use App\Harvester\Dto\RawAuthor;
use App\Harvester\Dto\RawPublication;

/**
 * Convertit un enregistrement arXiv (OAI-PMH, format « arXiv ») déjà extrait en
 * tableau, vers une {@see RawPublication}. Classe pure (testable).
 *
 * @phpstan-type ArxivRecord array{
 *     id:string, created?:?string, doi?:?string, title?:?string,
 *     abstract?:?string, categories?:?string, license?:?string,
 *     journal_ref?:?string, authors?:list<array{keyname?:string,forenames?:string,affiliation?:?string}>
 * }
 */
final class ArxivMapper
{
    public const SOURCE_CODE = 'arxiv';

    private const ABS_BASE = 'https://arxiv.org/abs/';

    /**
     * @param array<string,mixed> $record
     */
    public function map(array $record): RawPublication
    {
        $id = trim((string) ($record['id'] ?? ''));
        $license = ArxivLicense::normalize(isset($record['license']) ? (string) $record['license'] : null);

        return new RawPublication(
            sourceCode: self::SOURCE_CODE,
            idInSource: $id,
            doi: isset($record['doi']) && '' !== (string) $record['doi'] ? (string) $record['doi'] : null,
            title: self::clean((string) ($record['title'] ?? '')),
            externalIds: ['arxiv' => $id],
            abstract: self::cleanOrNull($record['abstract'] ?? null),
            publicationDate: self::parseDate($record['created'] ?? null),
            language: null,
            venue: self::cleanOrNull($record['journal_ref'] ?? null) ?? 'arXiv',
            type: 'preprint',
            license: $license,
            oaStatus: OaStatus::Green,
            oaUrl: '' !== $id ? self::ABS_BASE.$id : null,
            fulltextAvailable: true,
            authors: self::authors($record['authors'] ?? []),
        );
    }

    /**
     * @param array<int,mixed> $rawAuthors
     *
     * @return list<RawAuthor>
     */
    private static function authors(array $rawAuthors): array
    {
        $authors = [];
        $position = 0;
        foreach ($rawAuthors as $author) {
            if (!\is_array($author)) {
                continue;
            }
            $forenames = trim((string) ($author['forenames'] ?? ''));
            $keyname = trim((string) ($author['keyname'] ?? ''));
            $name = trim($forenames.' '.$keyname);
            if ('' === $name) {
                continue;
            }

            $affiliation = isset($author['affiliation']) && '' !== trim((string) $author['affiliation'])
                ? trim((string) $author['affiliation'])
                : null;

            $authors[] = new RawAuthor($name, null, $affiliation, $position);
            ++$position;
        }

        return $authors;
    }

    private static function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === trim($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return false === $date ? null : $date;
    }

    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    private static function cleanOrNull(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $clean = self::clean($value);

        return '' === $clean ? null : $clean;
    }
}
