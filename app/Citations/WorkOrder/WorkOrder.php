<?php

namespace App\Citations\WorkOrder;

use Illuminate\Support\Carbon;

/**
 * A prioritized batch of citation work for one location (§ Citations, PR6): the canonical NAP a VA submits
 * (identical for every line) plus the ordered directory lines, and a summary of what's included and what was
 * deferred over budget. Rendered to PDF/CSV for the VA.
 */
final readonly class WorkOrder
{
    /**
     * @param  array<string, mixed>  $nap  the canonical NAP snapshot the VA submits
     * @param  list<WorkOrderLine>  $lines
     * @param  array{total: int, free: int, paid: int, paid_cost: float, deferred_over_budget: int}  $summary
     */
    public function __construct(
        public string $locationId,
        public array $nap,
        public array $lines,
        public array $summary,
        public Carbon $generatedAt,
    ) {}

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }
}
