<?php

namespace App\KeywordGenerator\Cadence;

/**
 * The scheduled sampling plan after the budget ceiling is applied.
 */
final class SamplingPlan
{
    /**
     * @param  list<SamplingTask>  $included
     * @param  list<SamplingTask>  $dropped
     */
    public function __construct(
        public readonly array $included,
        public readonly array $dropped,
        public readonly float $totalCost,
    ) {}

    public function includes(string $ref): bool
    {
        foreach ($this->included as $task) {
            if ($task->ref === $ref) {
                return true;
            }
        }

        return false;
    }

    public function dropped(string $ref): bool
    {
        foreach ($this->dropped as $task) {
            if ($task->ref === $ref) {
                return true;
            }
        }

        return false;
    }
}
