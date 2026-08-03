<?php

namespace App\KeywordGenerator\Pipeline;

/**
 * The estimated shape of an on-demand ranking pull ({@see SitePipelineRefresher::trackNow}) — how
 * many DataForSEO tasks it will post and their approximate cost — computed BEFORE it runs so the
 * operator confirms against a real number. Task counts are exact; the dollar figure is indicative
 * (config-driven per-task rates).
 */
final class PositionPullEstimate
{
    public function __construct(
        public readonly int $keywords,
        public readonly int $gridPoints,
        public readonly int $organicTasks,
        public readonly int $localTasks,
        public readonly float $estimatedCost,
        public readonly bool $hasHost,
        public readonly bool $hasPriorityMarket,
    ) {}

    public function totalTasks(): int
    {
        return $this->organicTasks + $this->localTasks;
    }

    /** True when there is nothing to pull (no scored keywords, or no organic host and no local market). */
    public function isEmpty(): bool
    {
        return $this->totalTasks() === 0;
    }

    public function costLabel(): string
    {
        return '$'.number_format($this->estimatedCost, 2);
    }
}
