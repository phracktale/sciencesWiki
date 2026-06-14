<?php

declare(strict_types=1);

namespace App\Tests\Harvester\Connector\OpenAlex;

use App\Enum\OaStatus;
use App\Harvester\Connector\OpenAlex\OpenAlexMapper;
use PHPUnit\Framework\TestCase;

final class OpenAlexMapperTest extends TestCase
{
    public function testMapWork(): void
    {
        $raw = $this->fixture();
        $pub = (new OpenAlexMapper())->map($raw);

        self::assertSame('openalex', $pub->sourceCode);
        self::assertSame('W2741809807', $pub->idInSource);
        self::assertSame('https://doi.org/10.7717/peerj.4375', $pub->doi);
        self::assertSame('The state of OA', $pub->title);
        self::assertSame('We study open access.', $pub->abstract);
        self::assertSame('2018-02-13', $pub->publicationDate?->format('Y-m-d'));
        self::assertSame('en', $pub->language);
        self::assertSame('PeerJ', $pub->venue);
        self::assertSame('article', $pub->type);
        self::assertSame('cc-by', $pub->license);
        self::assertSame(OaStatus::Gold, $pub->oaStatus);
        self::assertSame('https://peerj.com/articles/4375.pdf', $pub->oaUrl);
        self::assertTrue($pub->fulltextAvailable);

        self::assertSame('W2741809807', $pub->externalIds['openalex']);
        self::assertSame('5826323674', $pub->externalIds['pmcid'] ?? null);

        self::assertCount(2, $pub->authors);
        self::assertSame('Heather Piwowar', $pub->authors[0]->name);
        self::assertSame('0000-0003-1613-5981', $pub->authors[0]->orcid);
        self::assertSame(0, $pub->authors[0]->position);
        self::assertSame('Jason Priem', $pub->authors[1]->name);
        self::assertSame(1, $pub->authors[1]->position);
    }

    /**
     * @return array<string,mixed>
     */
    private function fixture(): array
    {
        return [
            'id' => 'https://openalex.org/W2741809807',
            'doi' => 'https://doi.org/10.7717/peerj.4375',
            'title' => 'The state of OA',
            'display_name' => 'The state of OA',
            'publication_date' => '2018-02-13',
            'language' => 'en',
            'type' => 'article',
            'ids' => [
                'openalex' => 'https://openalex.org/W2741809807',
                'pmcid' => 'https://www.ncbi.nlm.nih.gov/pmc/articles/5826323674',
            ],
            'abstract_inverted_index' => [
                'We' => [0],
                'study' => [1],
                'open' => [2],
                'access.' => [3],
            ],
            'open_access' => [
                'is_oa' => true,
                'oa_status' => 'gold',
                'oa_url' => 'https://peerj.com/articles/4375.pdf',
            ],
            'primary_location' => [
                'license' => 'cc-by',
                'source' => ['display_name' => 'PeerJ'],
            ],
            'authorships' => [
                [
                    'author' => [
                        'display_name' => 'Heather Piwowar',
                        'orcid' => 'https://orcid.org/0000-0003-1613-5981',
                    ],
                    'raw_affiliation_strings' => ['Impactstory'],
                ],
                [
                    'author' => ['display_name' => 'Jason Priem'],
                ],
            ],
        ];
    }
}
