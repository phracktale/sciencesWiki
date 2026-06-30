<?php

declare(strict_types=1);

namespace App\Analysis\Amstar2;

use App\Catalog\Amstar2Checklist;

/**
 * Parse/répare la sortie LLM en évaluation AMSTAR-2 structurée (même approche que
 * {@see App\Analysis\Axis\AxisJsonParser}). Pur : renvoie null si indécodable. Un
 * « non applicable » (autre design qu'une revue systématique) est un SUCCÈS.
 */
final class Amstar2JsonParser
{
    public function parse(string $raw): ?ParsedAmstar2Appraisal
    {
        $json = $this->stripFences($raw);
        if ('' === $json) {
            return null;
        }

        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
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

        $design = $this->str($data['study_design'] ?? null);
        $applicability = $this->applicability($data, $design);

        if ('not_applicable' === $applicability) {
            return new ParsedAmstar2Appraisal($applicability, $design, [], [], $this->str($data['summary'] ?? null));
        }

        [$answers, $justifications] = $this->items($data['items'] ?? null);

        return new ParsedAmstar2Appraisal($applicability, $design, $answers, $justifications, $this->str($data['summary'] ?? null));
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return 'applicable'|'not_applicable'|'uncertain'
     */
    private function applicability(array $data, ?string $design): string
    {
        if (\array_key_exists('applicable', $data)) {
            return true === $data['applicable'] ? 'applicable' : 'not_applicable';
        }
        if (null !== $design) {
            $d = strtolower($design);

            return str_contains($d, 'systematic') || str_contains($d, 'meta') || str_contains($d, 'revue') || str_contains($d, 'review')
                ? 'applicable'
                : 'not_applicable';
        }

        return 'uncertain';
    }

    /**
     * Tolère { "q1": "yes" } ou { "q1": {"answer":"yes","quote":"…"} }.
     *
     * @return array{0:array<string,string>,1:array<string,string>}
     */
    private function items(mixed $items): array
    {
        $answers = [];
        $justifications = [];
        if (!\is_array($items)) {
            return [$answers, $justifications];
        }
        foreach (Amstar2Checklist::keys() as $key) {
            $entry = $items[$key] ?? null;
            if (null === $entry) {
                continue;
            }
            if (\is_array($entry)) {
                $answer = strtolower(trim((string) ($entry['answer'] ?? '')));
                $quote = $this->str($entry['quote'] ?? null);
            } else {
                $answer = strtolower(trim((string) $entry));
                $quote = null;
            }
            if (!Amstar2Checklist::isAnswer($answer)) {
                continue;
            }
            $answers[$key] = $answer;
            if (null !== $quote) {
                $justifications[$key] = $quote;
            }
        }

        return [$answers, $justifications];
    }

    private function stripFences(string $raw): string
    {
        $raw = trim($raw);
        $raw = (string) preg_replace('/^```[a-z]*\s*|\s*```$/mi', '', $raw);

        return trim($raw);
    }

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
}
