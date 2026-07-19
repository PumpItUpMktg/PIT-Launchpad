<?php

namespace App\KeywordGenerator\Corpus;

/**
 * Normalizes a keyword to a canonical form so the corpus dedupes close variants onto one row:
 * case-folded, punctuation-stripped, whitespace-collapsed, and each token singularized (so "french
 * drains" and "French Drain" collapse to "french drain"). The canonical is the dedup KEY; the corpus
 * still stores the human display term separately. Deterministic — same input always yields the same
 * canonical, so re-accumulation is stable.
 */
final class KeywordNormalizer
{
    public function canonical(string $term): string
    {
        $t = mb_strtolower(trim($term));
        $t = (string) preg_replace('/[^a-z0-9\s]+/', ' ', $t); // drop punctuation/symbols
        $t = trim((string) preg_replace('/\s+/', ' ', $t));
        if ($t === '') {
            return '';
        }

        $tokens = array_map(fn (string $w): string => $this->singularize($w), explode(' ', $t));

        return implode(' ', $tokens);
    }

    /** Naive, deterministic singularizer — enough to collapse plural search variants, not linguistics. */
    private function singularize(string $word): string
    {
        if (mb_strlen($word) <= 3 || str_ends_with($word, 'ss')) {
            return $word;
        }
        if (str_ends_with($word, 'ies')) {
            return mb_substr($word, 0, -3).'y';
        }
        foreach (['ches', 'shes', 'xes', 'zes', 'ses'] as $suffix) {
            if (str_ends_with($word, $suffix)) {
                return mb_substr($word, 0, -2);
            }
        }
        if (str_ends_with($word, 's')) {
            return mb_substr($word, 0, -1);
        }

        return $word;
    }
}
