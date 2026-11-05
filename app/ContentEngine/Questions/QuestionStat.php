<?php

namespace App\ContentEngine\Questions;

/**
 * One query's aggregate over the mining window, straight from the Search Console store: what searchers
 * typed, how often the site showed for it, how often they clicked, where it ranked, and which URL carried
 * it. Immutable; the miner builds these from SQL aggregates and never re-weights what GSC reported.
 */
final class QuestionStat
{
    /**
     * @param  float|null  $position  impression-weighted average position over the window (null when GSC gave none)
     * @param  float|null  $bestPosition  the best single-day position seen
     * @param  int  $peakDaily  the largest single-day impression total (the onset signal)
     * @param  array<string, array{impressions: int, clicks: int, position: float|null}>  $urls  per ranking URL
     */
    public function __construct(
        public readonly string $query,
        public readonly int $impressions,
        public readonly int $clicks,
        public readonly ?float $position,
        public readonly ?float $bestPosition,
        public readonly int $daysSeen,
        public readonly string $firstSeen,
        public readonly string $lastSeen,
        public readonly int $peakDaily,
        public readonly array $urls = [],
    ) {}

    public function ctr(): float
    {
        return $this->impressions > 0 ? $this->clicks / $this->impressions : 0.0;
    }

    /** The URL that carried the most impressions for this query, null when none was recorded. */
    public function topUrl(): ?string
    {
        if ($this->urls === []) {
            return null;
        }
        $urls = $this->urls;
        uasort($urls, fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);

        return (string) array_key_first($urls);
    }

    /** @param  array<string, array{impressions: int, clicks: int, position: float|null}>  $urls */
    public function withUrls(array $urls): self
    {
        return new self(
            $this->query, $this->impressions, $this->clicks, $this->position, $this->bestPosition,
            $this->daysSeen, $this->firstSeen, $this->lastSeen, $this->peakDaily, $urls,
        );
    }
}
