<?php

declare(strict_types=1);

namespace App\Analysis\Axis;

use App\Catalog\AxisChecklist;
use App\Enum\AxisAnswer;
use App\Enum\AxisApplicability;

/**
 * Parse/répare la sortie texte du LLM en évaluation AXIS structurée
 * (cf. docs/spec-axis-articles.md §5). Même approche que {@see App\Analysis\Claim
 * \ClaimJsonParser} : retrait des fences ```, décodage JSON, réparation grossière,
 * puis validation (applicabilité + 20 items).
 *
 * Pur : `parse()` renvoie null si le texte est indécodable (à réessayer). Une
 * évaluation « non applicable » (autre design) est un SUCCÈS, pas un échec.
 */
final class AxisJsonParser
{
    public function parse(string $raw): ?ParsedAxisAppraisal
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

        // Non applicable : on n'exige pas les 20 items (la grille n'est pas exécutée).
        if (AxisApplicability::NotApplicable === $applicability) {
            return new ParsedAxisAppraisal($applicability, $design, [], [], $this->str($data['summary'] ?? null));
        }

        [$answers, $justifications] = $this->items($data['items'] ?? null);

        return new ParsedAxisAppraisal(
            $applicability,
            $design,
            $answers,
            $justifications,
            $this->str($data['summary'] ?? null),
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    private function applicability(array $data, ?string $design): AxisApplicability
    {
        // Champ explicite prioritaire ; sinon déduit du design ; sinon incertain.
        $raw = $data['applicability'] ?? null;
        if (\is_string($raw) && null !== ($a = AxisApplicability::tryFrom($raw))) {
            return $a;
        }
        if (\array_key_exists('applicable', $data)) {
            return true === $data['applicable'] ? AxisApplicability::Applicable : AxisApplicability::NotApplicable;
        }
        if (null !== $design) {
            $d = strtolower($design);

            return str_contains($d, 'cross') || str_contains($d, 'transvers')
                ? AxisApplicability::Applicable
                : AxisApplicability::NotApplicable;
        }

        return AxisApplicability::Uncertain;
    }

    /**
     * Mappe le bloc « items » → réponses + détails (réflexion + citation). Tolère deux
     * formes : { "q1": "yes" } ou { "q1": {"answer":"yes","reasoning":"…","quote":"…"} }.
     *
     * @return array{0:array<string,AxisAnswer>,1:array<string,array{reasoning:?string,quote:?string}>}
     */
    private function items(mixed $items): array
    {
        $answers = [];
        $justifications = [];
        if (!\is_array($items)) {
            return [$answers, $justifications];
        }
        foreach (AxisChecklist::keys() as $key) {
            $entry = $items[$key] ?? null;
            if (null === $entry) {
                continue;
            }
            if (\is_array($entry)) {
                $answer = AxisAnswer::tryFrom((string) ($entry['answer'] ?? ''));
                $verdict = $this->str($entry['verdict'] ?? null);
                $evidenceType = $this->str($entry['evidence_type'] ?? null);
                $confidence = $this->str($entry['confidence'] ?? null);
                $reasoning = $this->str($entry['reasoning'] ?? null);
                $quote = $this->str($entry['quote'] ?? null);
            } else {
                $answer = AxisAnswer::tryFrom((string) $entry);
                $verdict = null;
                $evidenceType = null;
                $confidence = null;
                $reasoning = null;
                $quote = null;
            }
            if (null === $answer) {
                continue;
            }
            $answers[$key] = $answer;
            $justifications[$key] = ['verdict' => $verdict, 'evidence_type' => $evidenceType, 'confidence' => $confidence, 'reasoning' => $reasoning, 'quote' => $quote];
        }

        return [$answers, $justifications];
    }

    private function stripFences(string $raw): string
    {
        $raw = trim($raw);
        $raw = (string) preg_replace('/^```[a-z]*\s*|\s*```$/mi', '', $raw);

        return trim($raw);
    }

    /** Isole le premier objet JSON équilibré (réparation grossière). */
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
