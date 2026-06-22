<?php

declare(strict_types=1);

namespace App\Analysis\Claim;

use App\Enum\ClaimConfidence;
use App\Enum\ClaimDirection;
use App\Enum\ClaimMethod;

/**
 * Parse/répare la sortie texte du LLM en assertions structurées
 * (cf. docs/spec-controverses-lacunes.md §5). `LlmClient::complete()` ne renvoie
 * que du texte : on retire les fences ``` comme AnswerDrafter::analyze(), on
 * décode le JSON, puis on valide chaque entrée (enums + champs requis).
 *
 * Pur (sans LLM) : `parse()` renvoie null si le texte est indécodable, ce qui
 * signale à l'appelant ({@see ClaimExtractor}) qu'un nouvel essai est nécessaire.
 * Un tableau vide (rien d'extractible) est un succès, pas un échec.
 */
final class ClaimJsonParser
{
    /**
     * @return list<ParsedClaim>|null null = JSON indécodable (à réessayer)
     */
    public function parse(string $raw): ?array
    {
        $json = $this->stripFences($raw);
        if ('' === $json) {
            return null;
        }

        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Tentative de réparation : isole le premier objet {...} englobant.
            $repaired = $this->extractFirstObject($json);
            if (null === $repaired) {
                return null;
            }
            try {
                $data = json_decode($repaired, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
        }

        if (!\is_array($data)) {
            return null;
        }

        // Tolère {"claims":[...]} ou directement [...].
        $entries = $data['claims'] ?? $data;
        if (!\is_array($entries)) {
            return [];
        }

        $out = [];
        foreach ($entries as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $parsed = $this->mapEntry($entry);
            if (null !== $parsed) {
                $out[] = $parsed;
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $e */
    private function mapEntry(array $e): ?ParsedClaim
    {
        $exposure = $this->str($e['exposure'] ?? null);
        $outcome = $this->str($e['outcome'] ?? null);
        $quote = $this->str($e['quote'] ?? null);

        // Champs structurants requis : sans eux, l'entrée est inexploitable.
        if (null === $exposure || null === $outcome || null === $quote) {
            return null;
        }

        return new ParsedClaim(
            exposure: mb_substr($exposure, 0, 255),
            outcome: mb_substr($outcome, 0, 255),
            direction: ClaimDirection::tryFrom((string) ($e['direction'] ?? '')) ?? ClaimDirection::Unclear,
            method: ClaimMethod::tryFrom((string) ($e['method'] ?? '')) ?? ClaimMethod::Other,
            confidence: ClaimConfidence::tryFrom((string) ($e['confidence'] ?? '')) ?? ClaimConfidence::Low,
            population: $this->str($e['population'] ?? null),
            sampleSize: $this->intOrNull($e['sample_size'] ?? null),
            effectSize: $this->str($e['effect_size'] ?? null),
            statedLimitations: $this->str($e['stated_limitations'] ?? null),
            futureWork: $this->stringList($e['future_work'] ?? null),
            quote: $quote,
        );
    }

    /** Retire le texte autour du JSON et les fences Markdown (```json … ```). */
    private function stripFences(string $raw): string
    {
        $raw = trim($raw);
        $raw = (string) preg_replace('/^```[a-z]*\s*|\s*```$/mi', '', $raw);

        return trim($raw);
    }

    /** Isole le premier objet ou tableau JSON équilibré (réparation grossière). */
    private function extractFirstObject(string $s): ?string
    {
        $start = strpbrk($s, '{[');
        if (false === $start) {
            return null;
        }
        $open = $start[0];
        $close = '{' === $open ? '}' : ']';
        $depth = 0;
        $len = \strlen($s);
        $from = \strlen($s) - \strlen($start);
        for ($i = $from; $i < $len; ++$i) {
            if ($s[$i] === $open) {
                ++$depth;
            } elseif ($s[$i] === $close) {
                --$depth;
                if (0 === $depth) {
                    return substr($s, $from, $i - $from + 1);
                }
            }
        }

        return null;
    }

    private function str(mixed $v): ?string
    {
        if (!\is_string($v) && !\is_numeric($v)) {
            return null;
        }
        $s = trim((string) $v);

        return '' === $s || 'null' === strtolower($s) ? null : $s;
    }

    private function intOrNull(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }

    /** @return list<string> */
    private function stringList(mixed $v): array
    {
        if (!\is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $item) {
            $s = $this->str($item);
            if (null !== $s) {
                $out[] = $s;
            }
        }

        return $out;
    }
}
