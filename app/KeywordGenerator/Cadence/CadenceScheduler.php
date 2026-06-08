<?php

namespace App\KeywordGenerator\Cadence;

/**
 * Honors a per-tenant budget ceiling: keeps forced event-trigger tasks, then
 * degrades the rest — lowest tier first, coverage markets before priority
 * markets, most expensive first within a band — until the plan fits.
 */
class CadenceScheduler
{
    /**
     * @param  list<SamplingTask>  $candidates
     */
    public function fit(array $candidates, float $budgetCeiling): SamplingPlan
    {
        $total = array_sum(array_map(fn (SamplingTask $t) => $t->costUnits, $candidates));

        $forced = array_values(array_filter($candidates, fn (SamplingTask $t) => $t->forced));
        $droppable = array_values(array_filter($candidates, fn (SamplingTask $t) => ! $t->forced));

        // Degradation order: lowest tier first, then coverage markets, then cost.
        usort($droppable, function (SamplingTask $a, SamplingTask $b) {
            return $a->tier->degradationRank() <=> $b->tier->degradationRank()      // lowest tier first
                ?: ($b->isCoverageMarket <=> $a->isCoverageMarket)                  // coverage markets first
                ?: ($b->costUnits <=> $a->costUnits);                               // most expensive first
        });

        $dropped = [];
        $running = $total;
        $index = 0;
        while ($running > $budgetCeiling && $index < count($droppable)) {
            $dropped[] = $droppable[$index];
            $running -= $droppable[$index]->costUnits;
            $index++;
        }

        $kept = array_slice($droppable, $index);
        $included = [...$forced, ...$kept];

        return new SamplingPlan(
            included: $included,
            dropped: $dropped,
            totalCost: array_sum(array_map(fn (SamplingTask $t) => $t->costUnits, $included)),
        );
    }
}
