<?php

namespace App\ContentEngine\Feeds;

use App\ContentEngine\FunnelResult;

/**
 * The per-feed ingest verdict, broken into the funnel's stages so a 0-candidates
 * outcome is legible: where did the items go — dropped at the cheap pre-filter
 * (empty/junk), merged away as same-story duplicates, rejected by the scorer
 * (brand-safety / off-silo / below threshold), parked borderline, or routed to a
 * candidate. Recency is a backfill-only stage, so it is not counted here (the
 * steady-state ingest scores whatever the feed currently serves).
 */
final class FeedIngestReport
{
    public function __construct(
        public readonly string $feedId,
        public readonly string $label,
        public readonly int $fetched,
        public readonly int $prefilteredOut,
        public readonly int $deduped,
        public readonly int $scoreRejected,
        public readonly int $routed,
        public readonly int $parked,
        public readonly int $refreshMarked,
        public readonly ?string $error = null,
        public readonly int $durationMs = 0,
    ) {}

    public static function unfetched(string $feedId, string $label, ?string $error): self
    {
        return new self($feedId, $label, 0, 0, 0, 0, 0, 0, 0, $error);
    }

    /** Stamp the wall-clock this feed took (set by FeedIngestor once fetch+route completes). */
    public function withDuration(int $ms): self
    {
        return new self(
            $this->feedId, $this->label, $this->fetched, $this->prefilteredOut, $this->deduped,
            $this->scoreRejected, $this->routed, $this->parked, $this->refreshMarked, $this->error, $ms,
        );
    }

    public static function fromFunnel(string $feedId, string $label, int $fetched, FunnelResult $funnel): self
    {
        $prefilteredOut = count(array_filter($funnel->dropped, fn (array $d) => $d['reason'] === 'pre_filter'));
        // Both external_id (already_ingested) and URL-identity (duplicate_url) skips are dedups, not score
        // rejections — count them together and exclude from scoreRejected so the residual folds them into deduped.
        $deduplicated = count(array_filter($funnel->dropped, fn (array $d) => in_array($d['reason'], ['already_ingested', 'duplicate_url'], true)));
        // Everything else in `dropped` is a scorer rejection (brand-safety / off-silo / competitor / below
        // threshold) or a backpressure skip.
        $scoreRejected = count($funnel->dropped) - $prefilteredOut - $deduplicated;
        $routed = count($funnel->created);
        $parked = count($funnel->parked);
        $refreshMarked = count($funnel->refreshMarked);

        // Deduped = article-identity skips (already_ingested) + same-story clustering merges (items collapsed
        // before scoring). The residual absorbs the clustering merges automatically.
        $deduped = max(0, ($fetched - $prefilteredOut) - ($routed + $parked + $refreshMarked + $scoreRejected));

        return new self($feedId, $label, $fetched, $prefilteredOut, $deduped, $scoreRejected, $routed, $parked, $refreshMarked);
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toLog(): array
    {
        return [
            'feed_id' => $this->feedId,
            'label' => $this->label,
            'fetched' => $this->fetched,
            'prefiltered_out' => $this->prefilteredOut,
            'deduped' => $this->deduped,
            'score_rejected' => $this->scoreRejected,
            'routed' => $this->routed,
            'parked' => $this->parked,
            'refresh_marked' => $this->refreshMarked,
            'error' => $this->error,
            'duration_ms' => $this->durationMs,
        ];
    }

    public function line(): string
    {
        if ($this->error !== null) {
            return "unfetched — {$this->error} ({$this->durationMs}ms)";
        }

        return sprintf(
            'fetched %d → prefiltered-out %d → deduped %d → score-rejected %d → routed %d (parked %d, refresh %d) [%dms]',
            $this->fetched, $this->prefilteredOut, $this->deduped, $this->scoreRejected, $this->routed, $this->parked, $this->refreshMarked, $this->durationMs,
        );
    }
}
