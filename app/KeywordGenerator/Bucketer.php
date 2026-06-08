<?php

namespace App\KeywordGenerator;

use App\Models\Silo;
use Illuminate\Support\Collection;

/**
 * Buckets a query into a silo via rule_set include/exclude matching. A query
 * that matches no silo is the gap signal (returns null).
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
            $include = $ruleSet['include_patterns'] ?? $ruleSet['seed_terms'] ?? [];
            $exclude = $ruleSet['exclude_patterns'] ?? [];

            if ($this->matchesAny($haystack, $exclude)) {
                continue;
            }

            $score = $this->matchCount($haystack, $include);
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
     * @param  array<int, mixed>  $patterns
     */
    private function matchCount(string $haystack, array $patterns): int
    {
        $count = 0;
        foreach ($patterns as $pattern) {
            $pattern = mb_strtolower((string) $pattern);
            if ($pattern !== '' && str_contains($haystack, $pattern)) {
                $count++;
            }
        }

        return $count;
    }
}
