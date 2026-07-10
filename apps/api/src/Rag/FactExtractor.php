<?php

declare(strict_types=1);

namespace App\Rag;

use App\Ai\Llm\LlmClient;
use App\Ai\Llm\LlmMessage;
use App\Entity\Publication;
use App\Entity\Question;
use App\Service\SettingsService;

/**
 * Extracteur de faits sourcés — 1er appel du pipeline de rédaction d'article en 2 temps.
 * À partir de la question et des sources, produit un JSON de faits RÉELLEMENT soutenus par
 * les sources (citation verbatim, périmètre, niveau de preuve). Le rédacteur (2e appel) ne
 * pourra utiliser QUE ces faits → fiabilité anti-hallucination bien supérieure à un appel unique.
 */
final class FactExtractor
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * @param list<Publication> $sources
     *
     * @return array{facts:list<array<string,mixed>>,sufficient:bool,notes:?string,block:string}
     */
    public function extract(Question $question, array $sources): array
    {
        $messages = [
            LlmMessage::system($this->system()),
            LlmMessage::user($this->user($question, $sources)),
        ];
        $opts = ['temperature' => 0.0, 'max_tokens' => 3000, 'model' => $this->settings->extractorModel(), 'json' => true];

        $data = $this->parse($this->llm->complete($messages, $opts)->content, \count($sources));

        return $data + ['block' => $this->formatBlock($data)];
    }

    private function system(): string
    {
        return <<<'TXT'
            Tu es un EXTRACTEUR de faits sourcés pour une encyclopédie de vulgarisation. À partir
            de la QUESTION et des SOURCES numérotées, tu extrais UNIQUEMENT les faits réellement
            soutenus par les sources fournies. N'invente RIEN ; n'ajoute aucun fait de mémoire.

            Pour CHAQUE fait :
            - "claim"  : le fait, formulé clairement en une phrase ;
            - "sources": la liste des numéros de source qui l'appuient (ex. [1] ou [1,3]) ;
            - "quote"  : une citation verbatim COURTE (langue d'origine) tirée d'une source, qui
              étaye le fait ;
            - "scope"  : le périmètre (pays, période, population, juridiction…) ou "non précisé" ;
            - "level"  : le niveau de preuve parmi "revue_systematique", "recommandation",
              "essai", "cohorte", "transversale", "cas", "opinion", "autre".

            Écarte tout ce qui n'est pas soutenu par une source. Si les sources se contredisent,
            garde les deux faits en le signalant dans "notes". Réponds UNIQUEMENT en JSON :
            {
              "sufficient": true,
              "facts": [
                {"claim":"…","sources":[1],"quote":"…","scope":"…","level":"cohorte"}
              ],
              "notes": "limites, contradictions ou périmètres à signaler (ou null)"
            }
            "sufficient" = false si les sources ne permettent pas de traiter sérieusement la question.
            TXT;
    }

    /** @param list<Publication> $sources */
    private function user(Question $question, array $sources): string
    {
        $lines = ['QUESTION : '.$question->getText(), '', 'SOURCES :'];
        if ([] === $sources) {
            $lines[] = '(aucune source disponible)';
        }
        foreach ($sources as $i => $source) {
            $authors = implode(', ', array_map(static fn (array $a): string => $a['name'], $source->getAuthors()));
            $lines[] = \sprintf(
                "[%d] %s — %s (%s). DOI:%s\n    Résumé : %s",
                $i + 1,
                $source->getTitle(),
                '' !== $authors ? $authors : 'auteurs inconnus',
                $source->getPublicationDate()?->format('Y') ?? 's.d.',
                $source->getDoi() ?? 'n/a',
                mb_substr($source->getAbstract() ?? '(pas de résumé)', 0, 700),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{facts:list<array<string,mixed>>,sufficient:bool,notes:?string}
     */
    private function parse(string $raw, int $sourceCount): array
    {
        $json = trim((string) preg_replace('/^```[a-z]*\s*|\s*```$/mi', '', trim($raw)));
        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['facts' => [], 'sufficient' => false, 'notes' => null];
        }
        if (!\is_array($data)) {
            return ['facts' => [], 'sufficient' => false, 'notes' => null];
        }

        $facts = [];
        foreach (\is_array($data['facts'] ?? null) ? $data['facts'] : [] as $f) {
            if (!\is_array($f) || '' === trim((string) ($f['claim'] ?? ''))) {
                continue;
            }
            // Ne conserve que des numéros de source valides (1..N).
            $srcs = array_values(array_filter(array_map(
                static fn ($n): int => (int) $n,
                \is_array($f['sources'] ?? null) ? $f['sources'] : [],
            ), static fn (int $n): bool => $n >= 1 && $n <= $sourceCount));
            $facts[] = [
                'claim' => trim((string) $f['claim']),
                'sources' => $srcs,
                'quote' => trim((string) ($f['quote'] ?? '')),
                'scope' => trim((string) ($f['scope'] ?? '')) ?: 'non précisé',
                'level' => trim((string) ($f['level'] ?? '')) ?: 'autre',
            ];
        }

        return [
            'facts' => $facts,
            'sufficient' => (bool) ($data['sufficient'] ?? (\count($facts) > 0)),
            'notes' => '' !== trim((string) ($data['notes'] ?? '')) ? trim((string) $data['notes']) : null,
        ];
    }

    /** @param array{facts:list<array<string,mixed>>,sufficient:bool,notes:?string} $data */
    private function formatBlock(array $data): string
    {
        if ([] === $data['facts']) {
            return "(aucun fait n'a pu être extrait des sources fournies)";
        }
        $lines = [];
        foreach ($data['facts'] as $f) {
            $refs = [] !== $f['sources'] ? '['.implode(',', $f['sources']).']' : '[?]';
            $lines[] = \sprintf(
                '- %s %s — %s (niveau : %s ; périmètre : %s)%s',
                $refs,
                $f['claim'],
                '' !== $f['quote'] ? '« '.$f['quote'].' »' : '',
                $f['level'],
                $f['scope'],
                '',
            );
        }
        if (null !== $data['notes']) {
            $lines[] = 'NOTES : '.$data['notes'];
        }

        return implode("\n", $lines);
    }
}
