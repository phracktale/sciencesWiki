<?php

declare(strict_types=1);

namespace App\Analysis\Rob2;

use App\Catalog\Rob2Checklist;

/**
 * Parse/répare la sortie LLM en évaluation RoB 2 structurée (même approche que
 * {@see App\Analysis\Axis\AxisJsonParser}). Pur : renvoie null si indécodable.
 * Un « non applicable » (autre design qu'un essai randomisé) est un SUCCÈS.
 */
final class Rob2JsonParser
{
    public function parse(string $raw): ?ParsedRob2Appraisal
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
            return new ParsedRob2Appraisal($applicability, $design, [], $this->str($data['summary'] ?? null));
        }

        return new ParsedRob2Appraisal($applicability, $design, $this->domains($data['domains'] ?? null), $this->str($data['summary'] ?? null));
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

            return str_contains($d, 'rct') || str_contains($d, 'random') || str_contains($d, 'randomis')
                ? 'applicable'
                : 'not_applicable';
        }

        return 'uncertain';
    }

    /**
     * @return array<string,array{judgement:string,quote:?string,rationale:?string}>
     */
    private function domains(mixed $domains): array
    {
        $out = [];
        if (!\is_array($domains)) {
            return $out;
        }
        foreach (Rob2Checklist::keys() as $key) {
            $entry = $domains[$key] ?? null;
            if (!\is_array($entry)) {
                continue;
            }
            $judgement = strtolower(trim((string) ($entry['judgement'] ?? '')));
            if (!Rob2Checklist::isJudgement($judgement)) {
                continue;
            }
            $out[$key] = [
                'judgement' => $judgement,
                'quote' => $this->str($entry['quote'] ?? null),
                'rationale' => $this->str($entry['rationale'] ?? null),
            ];
        }

        return $out;
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
