<?php

namespace App\Client\Dashboard;

use Illuminate\Support\Carbon;

/**
 * A time frame the client dashboard renders against (§ Client Dashboard v1, PR 6). Two exist: the default
 * "since launch" (from the site's go-live date to today) and a trailing "last 28 days". `priorStart`/`priorEnd`
 * are the immediately-preceding equal-length window, used for momentum deltas ("▲6 since last period").
 */
final readonly class Frame
{
    public function __construct(
        public string $key,       // since_launch | last_28
        public string $label,
        public Carbon $start,
        public Carbon $end,
        public Carbon $priorStart,
        public Carbon $priorEnd,
    ) {}

    public function startDate(): string
    {
        return $this->start->toDateString();
    }

    public function endDate(): string
    {
        return $this->end->toDateString();
    }
}
