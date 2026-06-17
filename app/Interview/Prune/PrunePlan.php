<?php

namespace App\Interview\Prune;

/**
 * The prune plan: the candidate rows (volume-weighted, tagged) plus the completeness
 * state. The hard gate — a blueprint is confirmable only when every non-fringe spoke
 * has a decision — is computed here. Fringe rows are the Routing-layer handoff and are
 * excluded from the gate.
 */
final class PrunePlan
{
    /**
     * @param  list<PruneRow>  $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly bool $confirmed = false,
    ) {}

    /**
     * @return list<PruneRow>
     */
    public function decidable(): array
    {
        return array_values(array_filter($this->rows, fn (PruneRow $r) => ! $r->isFringe()));
    }

    /**
     * @return list<PruneRow>
     */
    public function pending(): array
    {
        return array_values(array_filter($this->decidable(), fn (PruneRow $r) => ! $r->isDecided()));
    }

    /**
     * @return list<PruneRow>
     */
    public function fringe(): array
    {
        return array_values(array_filter($this->rows, fn (PruneRow $r) => $r->isFringe()));
    }

    public function isComplete(): bool
    {
        return $this->pending() === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'confirmed' => $this->confirmed,
            'complete' => $this->isComplete(),
            'pending' => count($this->pending()),
            'decidable' => count($this->decidable()),
            'fringe' => count($this->fringe()),
            'rows' => array_map(fn (PruneRow $r) => $r->toArray(), $this->rows),
        ];
    }
}
