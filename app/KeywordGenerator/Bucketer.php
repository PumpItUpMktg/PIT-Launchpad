<?php

namespace App\KeywordGenerator;

use App\Models\Silo;
use Illuminate\Support\Collection;

/**
 * Buckets a query into a silo via rule_set include/exclude matching. A query
 * that matches no silo is the gap signal (returns null).
 *
 * Routing terms are the UNION of a silo's `include_patterns` (the broad routing terms — pillar head +
 * silo name) and its `seed_terms` (the specific spoke head keywords). Considering both is what lets a
 * keyword like "crawl space sump pump installation" file under Crawl Space via its exact seed term
 * rather than falling to Sump Pumps on the "sump pump" substring — the match is scored by the LENGTH of
 * the longest matching pattern, so the most SPECIFIC silo wins a contested keyword.
 */
class Bucketer
{
    /**
     * @param  Collection<int, Silo>  $silos
     */
    public function bucket(string $query, Collection $silos): ?Silo
    {
        $haystack = mb_strtolower($query);
        $best = null;
        $bestScore = 0;

        foreach ($silos as $silo) {
            $ruleSet = is_array($silo->rule_set) ? $silo->rule_set : [];
            $exclude = is_array($ruleSet['exclude_patterns'] ?? null) ? $ruleSet['exclude_patterns'] : [];

            if ($this->matchesAny($haystack, $exclude)) {
                continue;
            }

            // Match on include_patterns AND seed_terms together — the specific spoke heads route too,
            // not just the broad pillar phrase.
            $include = array_merge(
                is_array($ruleSet['include_patterns'] ?? null) ? $ruleSet['include_patterns'] : [],
                is_array($ruleSet['seed_terms'] ?? null) ? $ruleSet['seed_terms'] : [],
            );

            $score = $this->matchStrength($haystack, $include);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $silo;
            }
        }

        return $best;
    }

    /**
     * @param  array<int, mixed>  $patterns
     */
    private function matchesAny(string $haystack, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = mb_strtolower((string) $pattern);
            if ($pattern !== '' && str_contains($haystack, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The specificity of the best match: the character length of the LONGEST pattern the query
     * contains (0 if none). Longer match = more specific silo, so a keyword lands in the silo whose
     * fullest term it carries rather than one that merely shares a short substring.
     *
     * @param  array<int, mixed>  $patterns
     */
    private function matchStrength(string $haystack, array $patterns): int
    {
        $strength = 0;
        foreach ($patterns as $pattern) {
            $pattern = mb_strtolower((string) $pattern);
            if ($pattern !== '' && str_contains($haystack, $pattern)) {
                $strength = max($strength, mb_strlen($pattern));
            }
        }

        return $strength;
    }
}
