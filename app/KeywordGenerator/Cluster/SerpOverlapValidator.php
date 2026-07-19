<?php

namespace App\KeywordGenerator\Cluster;

use App\Integrations\Serp\SerpProvider;
use App\Models\KeywordCorpus;

/**
 * Validates a cluster's coherence by comparing the SERPs of its top two head candidates — the ONLY SERP
 * spend in the pipeline (full-corpus SERP clustering is out of scope). High organic-domain overlap ⇒ the
 * two candidates share intent, keep the cluster together (`confirmed`); low overlap ⇒ a split signal
 * (`split_signal`). A cluster with one candidate is `skipped` (nothing to compare). Every SERP call is
 * counted so the spend is visible.
 */
final class SerpOverlapValidator
{
    private int $calls = 0;

    public function __construct(private readonly SerpProvider $serp) {}

    /** SERP calls made so far — the spend log for the run. */
    public function callCount(): int
    {
        return $this->calls;
    }

    /**
     * @param  list<KeywordCorpus>  $candidates  the top head candidates (2)
     * @return 'confirmed'|'split_signal'|'skipped'
     */
    public function validate(array $candidates, ?float $threshold = null): string
    {
        if (count($candidates) < 2) {
            return 'skipped';
        }
        $threshold ??= (float) config('launchpad.keyword_first.serp_overlap', 0.4);

        $a = $this->domains($candidates[0]->term);
        $b = $this->domains($candidates[1]->term);
        if ($a === [] || $b === []) {
            return 'skipped';
        }

        return $this->jaccard($a, $b) >= $threshold ? 'confirmed' : 'split_signal';
    }

    /**
     * @return list<string>
     */
    private function domains(string $query): array
    {
        $this->calls++;

        return array_values(array_unique($this->serp->results($query)->domains()));
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function jaccard(array $a, array $b): float
    {
        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique([...$a, ...$b]));

        return $union === 0 ? 0.0 : $intersection / $union;
    }
}
